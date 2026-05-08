"""Hybrid retriever for the clinical-guideline corpus.

Pipeline: BM25 sparse + Voyage-AI dense → Reciprocal Rank Fusion →
optional Cohere Rerank → top_k. Every result carries the per-component
scores (`bm25_score`, `dense_score`, `rrf_score`, `rerank_score`,
final `score`) and a `source` tag so reviewers can see which retriever
surfaced each chunk.

The corpus is loaded from copilot/agent/guidelines/seed_corpus.json at
module import time. (It used to live at `copilot/guidelines/` —
moved 2026-05-07 so it sits inside the agent's docker build context;
the prior layout meant `railway up --service copilot-agent
--path-as-root .` produced an image with no corpus, which silently
broke `/search` until manually scp'd in.) Each chunk is precomputed
once; queries are O(corpus_size) which is fine at ~250 chunks.

Dense embeddings are cached on disk at
guidelines/seed_corpus_embeddings.json keyed by (chunk_id, model,
text_hash) so a fresh container hydrates without re-spending Voyage
budget. The cache is invalidated automatically when a chunk's text
changes.
"""

from __future__ import annotations

import hashlib
import json
import math
import os
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Any

# `rank_bm25` is the only sparse-side hard dependency. It is pure-Python
# and installs in <1s.
from rank_bm25 import BM25Okapi


# ---------------------------------------------------------------------------
# Corpus loading.
# ---------------------------------------------------------------------------

_DEFAULT_CORPUS_PATH = (
    Path(__file__).resolve().parent.parent
    / "guidelines"
    / "seed_corpus.json"
)
_DEFAULT_EMBED_CACHE_PATH = (
    Path(__file__).resolve().parent.parent
    / "guidelines"
    / "seed_corpus_embeddings.json"
)

# Voyage's general-purpose retrieval model. `voyage-3-lite` would be
# ~3x cheaper but produces 512-dim vectors with measurably worse recall
# on clinical text in our spot checks; `voyage-3` is 1024-dim and the
# corpus is tiny so cost is rounding-error.
DEFAULT_DENSE_MODEL = os.environ.get("VOYAGE_EMBED_MODEL", "voyage-3")
SPARSE_MODEL = "bm25-okapi"
RRF_K = 60  # standard Reciprocal Rank Fusion smoothing constant


@dataclass
class Chunk:
    """One unit of evidence from the corpus."""
    id: str
    source_id: str
    source_title: str
    source_url: str
    section: str
    page: int
    text: str

    def as_evidence(
        self,
        *,
        score: float,
        bm25_score: float | None,
        dense_score: float | None,
        rrf_score: float | None,
        rerank_score: float | None,
        source: str,
    ) -> dict[str, Any]:
        """Citation contract + retrieval-provenance fields. The agent's
        prompt only consumes `quote`/`source_id`/`source_url`/etc; the
        score-block is for the demo UI, /search clients, and evals."""
        return {
            "chunk_id": self.id,
            "source_type": "GuidelineChunk",
            "source_id": self.source_id,
            "source_title": self.source_title,
            "source_url": self.source_url,
            "section": self.section,
            "page": self.page,
            "quote": self.text,
            "score": round(float(score), 4),
            "bm25_score": None if bm25_score is None else round(float(bm25_score), 4),
            "dense_score": None if dense_score is None else round(float(dense_score), 4),
            "rrf_score": None if rrf_score is None else round(float(rrf_score), 6),
            "rerank_score": None if rerank_score is None else round(float(rerank_score), 4),
            "source": source,
        }


def _load_corpus(path: Path | None = None) -> list[Chunk]:
    p = path or _DEFAULT_CORPUS_PATH
    if not p.exists():
        return []
    raw = json.loads(p.read_text())
    chunks = []
    for c in raw.get("chunks", []):
        chunks.append(Chunk(
            id=c["id"],
            source_id=c["source_id"],
            source_title=c["source_title"],
            source_url=c["source_url"],
            section=c.get("section", ""),
            page=int(c.get("page") or 1),
            text=c["text"],
        ))
    return chunks


# ---------------------------------------------------------------------------
# Tokenizer (BM25's tokenizer must match between index + query).
# ---------------------------------------------------------------------------

_TOKEN_RE = re.compile(r"[a-z0-9][a-z0-9'\-]+")


def _tokenize(s: str) -> list[str]:
    """Lowercase, alnum-only tokens. Keeps clinical abbreviations like
    'a1c', 'bp', 'egfr' intact and drops punctuation."""
    return _TOKEN_RE.findall(s.lower())


def _text_hash(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()[:16]


# ---------------------------------------------------------------------------
# Dense embeddings (Voyage-AI) with on-disk cache.
# ---------------------------------------------------------------------------

def _voyage_client():
    """Return a Voyage client or None if the SDK / API key are missing.

    Soft dependency: the agent must boot in environments without a
    Voyage key (e.g. CI containers, local-without-secrets dev). When
    dense is unavailable, search() degrades to BM25-only and surfaces
    `dense_enabled=false` in retrieval metadata so the demo UI can
    label it accordingly.
    """
    api_key = os.environ.get("VOYAGE_API_KEY")
    if not api_key:
        return None
    try:
        import voyageai  # type: ignore
    except ImportError:
        return None
    return voyageai.Client(api_key=api_key)


def _load_embedding_cache(path: Path) -> dict[str, dict[str, Any]]:
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text())
    except (json.JSONDecodeError, OSError):
        return {}


def _save_embedding_cache(path: Path, cache: dict[str, dict[str, Any]]) -> None:
    try:
        path.write_text(json.dumps(cache, indent=2))
    except OSError:
        # Cache is best-effort. A read-only filesystem (e.g. some
        # container layouts) shouldn't break /search.
        pass


def _build_dense_index(
    chunks: list[Chunk],
    *,
    model: str = DEFAULT_DENSE_MODEL,
    cache_path: Path | None = None,
) -> tuple[list[list[float]] | None, str | None]:
    """Return (vectors, model) for the corpus, or (None, None) when
    dense is disabled. Hydrates and updates the on-disk cache."""
    client = _voyage_client()
    if client is None or not chunks:
        return None, None

    cache_path = cache_path or _DEFAULT_EMBED_CACHE_PATH
    cache = _load_embedding_cache(cache_path)
    cache.setdefault("model", model)
    cache.setdefault("embeddings", {})

    # If the cached model differs from the requested one, blow away the
    # vectors — mixing dimensions across chunks would silently corrupt
    # cosine scores.
    if cache.get("model") != model:
        cache = {"model": model, "embeddings": {}}

    misses: list[tuple[int, Chunk]] = []
    for i, c in enumerate(chunks):
        entry = cache["embeddings"].get(c.id)
        if not entry or entry.get("text_hash") != _text_hash(c.text):
            misses.append((i, c))

    if misses:
        try:
            resp = client.embed(
                [c.text for _, c in misses],
                model=model,
                input_type="document",
            )
        except Exception:
            # If Voyage is unreachable at boot, degrade to sparse-only
            # rather than crashing the agent. /search will report
            # dense_enabled=false until the network is healthy and the
            # corpus reloads.
            return None, None
        for (_, c), vec in zip(misses, resp.embeddings):
            cache["embeddings"][c.id] = {
                "text_hash": _text_hash(c.text),
                "vector": list(vec),
            }
        _save_embedding_cache(cache_path, cache)

    vectors: list[list[float]] = []
    for c in chunks:
        entry = cache["embeddings"].get(c.id)
        if not entry:
            return None, None
        vectors.append(entry["vector"])
    return vectors, model


def _embed_query(query: str, *, model: str) -> list[float] | None:
    client = _voyage_client()
    if client is None:
        return None
    try:
        resp = client.embed([query], model=model, input_type="query")
    except Exception:
        return None
    if not resp.embeddings:
        return None
    return list(resp.embeddings[0])


def _cosine(a: list[float], b: list[float]) -> float:
    dot = 0.0
    na = 0.0
    nb = 0.0
    for x, y in zip(a, b):
        dot += x * y
        na += x * x
        nb += y * y
    if na == 0.0 or nb == 0.0:
        return 0.0
    return dot / (math.sqrt(na) * math.sqrt(nb))


# ---------------------------------------------------------------------------
# Index (built once at module import).
# ---------------------------------------------------------------------------

_CHUNKS: list[Chunk] = _load_corpus()
_TOKENIZED: list[list[str]] = [_tokenize(c.text) for c in _CHUNKS] if _CHUNKS else []
_BM25: BM25Okapi | None = BM25Okapi(_TOKENIZED) if _TOKENIZED else None
_DENSE_VECTORS: list[list[float]] | None
_DENSE_MODEL: str | None
_DENSE_VECTORS, _DENSE_MODEL = _build_dense_index(_CHUNKS) if _CHUNKS else (None, None)


def corpus_size() -> int:
    return len(_CHUNKS)


def dense_enabled() -> bool:
    return _DENSE_VECTORS is not None


def retrieval_metadata() -> dict[str, Any]:
    """Self-describing metadata block — surfaced by /search and by the
    retrieval_hit graph event so demo viewers see exactly what stack ran."""
    return {
        "retrieval_mode": "hybrid_sparse_dense" if dense_enabled() else "sparse_only",
        "sparse_model": SPARSE_MODEL,
        "dense_model": _DENSE_MODEL,
        "dense_enabled": dense_enabled(),
        "fusion": "rrf" if dense_enabled() else "none",
        "rrf_k": RRF_K if dense_enabled() else None,
        "rerank_enabled": bool(os.environ.get("COHERE_API_KEY")),
        "corpus_size": corpus_size(),
    }


def reload_corpus(path: Path | None = None) -> int:
    """Hot-reload the corpus from disk. Returns the new corpus size.
    Useful for dev + tests; not called in the hot path."""
    global _CHUNKS, _BM25, _TOKENIZED, _DENSE_VECTORS, _DENSE_MODEL
    _CHUNKS = _load_corpus(path)
    if _CHUNKS:
        _TOKENIZED = [_tokenize(c.text) for c in _CHUNKS]
        _BM25 = BM25Okapi(_TOKENIZED)
        _DENSE_VECTORS, _DENSE_MODEL = _build_dense_index(_CHUNKS)
    else:
        _TOKENIZED = []
        _BM25 = None
        _DENSE_VECTORS, _DENSE_MODEL = None, None
    return len(_CHUNKS)


# ---------------------------------------------------------------------------
# Optional Cohere Rerank.
# ---------------------------------------------------------------------------

def _rerank_with_cohere(
    query: str, candidates: list[Chunk], top_k: int
) -> tuple[list[tuple[Chunk, float]], bool]:
    """Rerank with Cohere if COHERE_API_KEY is set. Returns
    (ranked, used_cohere). When Cohere isn't available we return the
    candidates in their incoming order — RRF already produced a sane
    ordering — and signal `used_cohere=False` so callers can label the
    final score as `rrf_score`, not `rerank_score`."""
    api_key = os.environ.get("COHERE_API_KEY")
    if not api_key or not candidates:
        return [(c, 0.0) for c in candidates[:top_k]], False
    try:
        import cohere  # type: ignore
    except ImportError:
        return [(c, 0.0) for c in candidates[:top_k]], False
    client = cohere.Client(api_key)
    docs = [c.text for c in candidates]
    try:
        resp = client.rerank(
            model=os.environ.get("COHERE_RERANK_MODEL", "rerank-v3.5"),
            query=query,
            documents=docs,
            top_n=top_k,
        )
    except Exception:
        return [(c, 0.0) for c in candidates[:top_k]], False
    out: list[tuple[Chunk, float]] = []
    for r in resp.results:
        out.append((candidates[r.index], float(r.relevance_score)))
    return out, True


# ---------------------------------------------------------------------------
# Public API.
# ---------------------------------------------------------------------------

def search(
    query: str,
    *,
    top_k: int = 5,
    candidate_pool: int = 30,
) -> list[dict[str, Any]]:
    """Hybrid retrieval: BM25 + dense → RRF → optional Cohere Rerank.

    Each result is a dict suitable for the citation contract — see
    Chunk.as_evidence(). Returns [] when the corpus is empty or the
    query tokenises to nothing.
    """
    if not _CHUNKS or _BM25 is None:
        return []
    q_tokens = _tokenize(query)
    if not q_tokens:
        return []

    # ---- Sparse leg --------------------------------------------------------
    bm25_scores = _BM25.get_scores(q_tokens)
    sparse_ranked = sorted(
        enumerate(bm25_scores), key=lambda pair: pair[1], reverse=True
    )[:candidate_pool]
    # rank position is 1-based — RRF wants 1-indexed ranks
    sparse_rank: dict[int, int] = {idx: r + 1 for r, (idx, _) in enumerate(sparse_ranked)}
    sparse_score: dict[int, float] = {idx: float(s) for idx, s in sparse_ranked}

    # ---- Dense leg ---------------------------------------------------------
    dense_rank: dict[int, int] = {}
    dense_score: dict[int, float] = {}
    used_dense = False
    if _DENSE_VECTORS is not None and _DENSE_MODEL is not None:
        q_vec = _embed_query(query, model=_DENSE_MODEL)
        if q_vec is not None:
            cosines = [
                (i, _cosine(q_vec, _DENSE_VECTORS[i]))
                for i in range(len(_CHUNKS))
            ]
            dense_ranked = sorted(cosines, key=lambda pair: pair[1], reverse=True)[
                :candidate_pool
            ]
            dense_rank = {idx: r + 1 for r, (idx, _) in enumerate(dense_ranked)}
            dense_score = {idx: float(s) for idx, s in dense_ranked}
            used_dense = True

    # ---- Reciprocal Rank Fusion -------------------------------------------
    # Standard RRF: score(d) = Σ_r 1 / (k + rank_r(d)). We only fuse if
    # dense ran; otherwise the BM25 ordering is the candidate ordering
    # and we tag every result `source="sparse"`.
    fused: dict[int, float] = {}
    if used_dense:
        for idx, r in sparse_rank.items():
            fused[idx] = fused.get(idx, 0.0) + 1.0 / (RRF_K + r)
        for idx, r in dense_rank.items():
            fused[idx] = fused.get(idx, 0.0) + 1.0 / (RRF_K + r)
    else:
        for idx, r in sparse_rank.items():
            fused[idx] = 1.0 / (RRF_K + r)

    fused_ranked_indices = sorted(fused.items(), key=lambda pair: pair[1], reverse=True)[
        :candidate_pool
    ]
    fused_chunks = [_CHUNKS[i] for i, _ in fused_ranked_indices]
    fused_score_by_idx = {i: s for i, s in fused_ranked_indices}

    # ---- Optional Cohere rerank on the fused candidates -------------------
    reranked, used_cohere = _rerank_with_cohere(query, fused_chunks, top_k=top_k)

    # Build evidence dicts. Need to recover the corpus index for each
    # reranked chunk to look up its component scores.
    chunk_to_idx = {c.id: _CHUNKS.index(c) for c in _CHUNKS}
    out: list[dict[str, Any]] = []
    for c, rerank_score in reranked:
        idx = chunk_to_idx[c.id]
        in_sparse = idx in sparse_rank
        in_dense = idx in dense_rank
        if in_sparse and in_dense:
            src = "both"
        elif in_dense:
            src = "dense"
        else:
            src = "sparse"
        bm = sparse_score.get(idx)
        dn = dense_score.get(idx) if used_dense else None
        rrf = fused_score_by_idx.get(idx)
        final = float(rerank_score) if used_cohere else float(rrf or 0.0)
        out.append(
            c.as_evidence(
                score=final,
                bm25_score=bm,
                dense_score=dn,
                rrf_score=rrf,
                rerank_score=rerank_score if used_cohere else None,
                source=src,
            )
        )
    return out


def search_with_meta(
    query: str,
    *,
    top_k: int = 5,
    candidate_pool: int = 30,
) -> dict[str, Any]:
    """Convenience wrapper that returns both the results and the
    retrieval metadata block — used by the /search HTTP route and the
    graph's evidence_retriever_node so the demo can label the stack.
    """
    results = search(query, top_k=top_k, candidate_pool=candidate_pool)
    meta = retrieval_metadata()
    # Per-call provenance: which retrievers actually contributed top_k.
    contributors: set[str] = set()
    for r in results:
        contributors.add(r.get("source", "sparse"))
    meta["contributors"] = sorted(contributors)
    return {"results": results, "meta": meta}
