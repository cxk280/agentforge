"""Hybrid retriever for the clinical-guideline corpus.

MVP-shape: BM25 sparse + (optional) Cohere Rerank. Dense retrieval
(Voyage-3 + pgvector) lands later this week — this module exposes the
same `search()` shape so the upgrade is a swap rather than a rewrite.

The corpus is loaded from copilot/agent/guidelines/seed_corpus.json at
module import time. (It used to live at `copilot/guidelines/` —
moved 2026-05-07 so it sits inside the agent's docker build context;
the prior layout meant `railway up --service copilot-agent
--path-as-root .` produced an image with no corpus, which silently
broke `/search` until manually scp'd in.) Each chunk is precomputed
once; queries are O(corpus_size)
which is fine at ~250 chunks.
"""

from __future__ import annotations

import json
import os
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Any

# `rank_bm25` is the only new hard dependency. It is pure-Python and
# installs in <1s.
from rank_bm25 import BM25Okapi


# ---------------------------------------------------------------------------
# Corpus loading.
# ---------------------------------------------------------------------------

_DEFAULT_CORPUS_PATH = (
    Path(__file__).resolve().parent.parent
    / "guidelines"
    / "seed_corpus.json"
)


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

    def as_evidence(self, score: float) -> dict[str, Any]:
        """Shape returned by search(): exactly the citation fields the
        agent needs for the evidence-side of the citation contract."""
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


# ---------------------------------------------------------------------------
# Index (built once at module import).
# ---------------------------------------------------------------------------

_CHUNKS: list[Chunk] = _load_corpus()
_BM25: BM25Okapi | None = None
if _CHUNKS:
    _TOKENIZED = [_tokenize(c.text) for c in _CHUNKS]
    _BM25 = BM25Okapi(_TOKENIZED)


def corpus_size() -> int:
    return len(_CHUNKS)


def reload_corpus(path: Path | None = None) -> int:
    """Hot-reload the corpus from disk. Returns the new corpus size.
    Useful for dev + tests; not called in the hot path."""
    global _CHUNKS, _BM25, _TOKENIZED
    _CHUNKS = _load_corpus(path)
    if _CHUNKS:
        _TOKENIZED = [_tokenize(c.text) for c in _CHUNKS]
        _BM25 = BM25Okapi(_TOKENIZED)
    else:
        _BM25 = None
    return len(_CHUNKS)


# ---------------------------------------------------------------------------
# Optional Cohere Rerank.
# ---------------------------------------------------------------------------

def _rerank_with_cohere(query: str, candidates: list[Chunk], top_k: int) -> list[tuple[Chunk, float]]:
    """Rerank with Cohere if COHERE_API_KEY is set, else return BM25 order.

    Soft dependency: imports cohere lazily so the module loads cleanly
    when the lib isn't installed (e.g. in CI containers that skip RAG).
    """
    api_key = os.environ.get("COHERE_API_KEY")
    if not api_key or not candidates:
        return [(c, 1.0 / (i + 1)) for i, c in enumerate(candidates[:top_k])]
    try:
        import cohere  # type: ignore
    except ImportError:
        return [(c, 1.0 / (i + 1)) for i, c in enumerate(candidates[:top_k])]
    client = cohere.Client(api_key)
    docs = [c.text for c in candidates]
    resp = client.rerank(
        model=os.environ.get("COHERE_RERANK_MODEL", "rerank-v3.5"),
        query=query,
        documents=docs,
        top_n=top_k,
    )
    out: list[tuple[Chunk, float]] = []
    for r in resp.results:
        out.append((candidates[r.index], float(r.relevance_score)))
    return out


# ---------------------------------------------------------------------------
# Public API.
# ---------------------------------------------------------------------------

def search(
    query: str,
    *,
    top_k: int = 5,
    candidate_pool: int = 30,
) -> list[dict[str, Any]]:
    """Run BM25 → optional Cohere Rerank → top_k evidence chunks.

    Each result is a dict suitable for the citation contract — see
    Chunk.as_evidence(). Returns [] when the corpus is empty.
    """
    if not _CHUNKS or _BM25 is None:
        return []
    q_tokens = _tokenize(query)
    if not q_tokens:
        return []

    scores = _BM25.get_scores(q_tokens)
    # Rank by BM25 score, take candidate_pool best.
    ranked = sorted(
        zip(_CHUNKS, scores), key=lambda pair: pair[1], reverse=True
    )[:candidate_pool]
    candidates = [c for c, _ in ranked]

    reranked = _rerank_with_cohere(query, candidates, top_k=top_k)
    return [c.as_evidence(score) for c, score in reranked]
