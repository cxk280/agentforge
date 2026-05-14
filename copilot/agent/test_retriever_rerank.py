"""Tests for Cohere rerank-score propagation in the hybrid retriever.

Locks in the contract that when Cohere is enabled, every result carries
a non-null `rerank_score`, the final ordering matches Cohere's ranking,
and the per-result `score` equals the rerank score (not the upstream RRF
score). This is the "rerank propagation" proof the W2 reviewer asked us
to tighten (2026-05-09).

Both Voyage and Cohere are stubbed deterministically so the test runs
without network access or paid API keys, while still exercising the
real rerank/RRF/scoring code paths.

Run directly: `python copilot/agent/test_retriever_rerank.py`
"""

from __future__ import annotations

import os
import sys
import types
import unittest
from pathlib import Path


def _install_voyage_stub() -> None:
    fake = types.ModuleType("voyageai")

    class FakeResp:
        def __init__(self, vecs): self.embeddings = vecs

    class FakeClient:
        def __init__(self, api_key=None): pass
        def embed(self, texts, model=None, input_type=None):
            vecs = []
            for t in texts:
                v = [0.0] * 16
                for tok in t.lower().split():
                    v[hash(tok) % 16] += 1.0
                vecs.append(v)
            return FakeResp(vecs)

    fake.Client = FakeClient
    sys.modules["voyageai"] = fake


def _install_cohere_stub(*, score_by_query_term: bool = True) -> dict[str, list[float]]:
    """Inject a deterministic Cohere stub.

    The stub assigns a relevance score to each candidate based on the
    fraction of unique query tokens that appear in the candidate text.
    Score order is therefore stable across runs and lets the test
    assert specific orderings.

    Returns a dict keyed by query → list of relevance scores in the
    candidate order presented (useful for cross-checking).
    """
    fake = types.ModuleType("cohere")
    log: dict[str, list[float]] = {}

    class FakeResult:
        def __init__(self, index: int, score: float):
            self.index = index
            self.relevance_score = score

    class FakeRerankResp:
        def __init__(self, results): self.results = results

    class FakeClient:
        def __init__(self, api_key): self.api_key = api_key

        def rerank(self, *, model, query, documents, top_n):
            scores: list[float] = []
            q_tokens = {t.lower() for t in query.split() if t}
            for doc in documents:
                doc_tokens = {t.lower() for t in doc.split() if t}
                inter = q_tokens & doc_tokens
                # Bias toward docs that share more rare query tokens.
                scores.append(round(len(inter) / max(len(q_tokens), 1), 4))
            log[query] = list(scores)

            ordered = sorted(enumerate(scores), key=lambda p: p[1], reverse=True)
            results = [FakeResult(i, s) for i, s in ordered[:top_n]]
            return FakeRerankResp(results)

    fake.Client = FakeClient
    sys.modules["cohere"] = fake
    return log


def _import_retriever():
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    import rag.retriever as r  # noqa: F401
    return r


class CohereRerankPropagationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        _install_voyage_stub()
        cls.cohere_log = _install_cohere_stub()
        os.environ["VOYAGE_API_KEY"] = "stub-voyage"
        os.environ["COHERE_API_KEY"] = "stub-cohere"
        cls.r = _import_retriever()
        # Sandboxed embedding cache so we never touch the repo file.
        cls.r._DEFAULT_EMBED_CACHE_PATH = Path("/tmp/test_rerank_embeddings.json")
        if cls.r._DEFAULT_EMBED_CACHE_PATH.exists():
            cls.r._DEFAULT_EMBED_CACHE_PATH.unlink()
        cls.r.reload_corpus()

    def setUp(self):
        # The retriever_metadata reflects whether COHERE_API_KEY is set —
        # set/unset here per test in case other tests in the same process
        # mutated the env.
        os.environ["COHERE_API_KEY"] = "stub-cohere"

    def test_metadata_advertises_rerank_enabled(self):
        meta = self.r.retrieval_metadata()
        self.assertTrue(meta["rerank_enabled"])

    def test_every_result_carries_non_null_rerank_score(self):
        results = self.r.search("a1c target diabetes", top_k=5)
        self.assertGreater(len(results), 0)
        for hit in results:
            self.assertIsNotNone(
                hit["rerank_score"],
                msg=f"chunk {hit['chunk_id']} missing rerank_score "
                    f"despite COHERE_API_KEY being set",
            )

    def test_final_score_equals_rerank_score_when_cohere_used(self):
        results = self.r.search("metformin contraindicated CKD", top_k=5)
        for hit in results:
            self.assertAlmostEqual(
                hit["score"], hit["rerank_score"], places=4,
                msg=f"final score must equal rerank_score when Cohere is "
                    f"on; got score={hit['score']} vs rerank_score="
                    f"{hit['rerank_score']} for {hit['chunk_id']}",
            )

    def test_results_ordered_by_rerank_score_descending(self):
        results = self.r.search("blood pressure target hypertension", top_k=5)
        scores = [hit["rerank_score"] for hit in results]
        self.assertEqual(
            scores, sorted(scores, reverse=True),
            msg="reranked results must be in descending rerank_score order",
        )

    def test_per_component_scores_still_present(self):
        # Rerank must not strip the upstream provenance — bm25/dense/rrf
        # scores still carry through, otherwise the demo UI loses the
        # "which retriever surfaced this?" badge.
        results = self.r.search_with_meta("hba1c target", top_k=5)["results"]
        for hit in results:
            self.assertIn("bm25_score", hit)
            self.assertIn("dense_score", hit)
            self.assertIn("rrf_score", hit)
            self.assertIn("rerank_score", hit)
            self.assertIn(hit["source"], {"sparse", "dense", "both"})

    def test_score_propagates_through_search_with_meta(self):
        bundle = self.r.search_with_meta("treatment of stage-3 ckd", top_k=3)
        self.assertEqual(bundle["meta"]["rerank_enabled"], True)
        for hit in bundle["results"]:
            self.assertIsNotNone(hit["rerank_score"])


class CohereDisabledFallbackTests(unittest.TestCase):
    """When COHERE_API_KEY is unset, rerank_score must be None and the
    final `score` must equal the RRF score — proves the fallback path
    doesn't accidentally surface a stale rerank value."""

    @classmethod
    def setUpClass(cls):
        _install_voyage_stub()
        os.environ["VOYAGE_API_KEY"] = "stub-voyage"
        os.environ.pop("COHERE_API_KEY", None)
        cls.r = _import_retriever()
        cls.r._DEFAULT_EMBED_CACHE_PATH = Path("/tmp/test_rerank_disabled_embeddings.json")
        if cls.r._DEFAULT_EMBED_CACHE_PATH.exists():
            cls.r._DEFAULT_EMBED_CACHE_PATH.unlink()
        cls.r.reload_corpus()

    def test_metadata_advertises_rerank_disabled(self):
        os.environ.pop("COHERE_API_KEY", None)
        meta = self.r.retrieval_metadata()
        self.assertFalse(meta["rerank_enabled"])

    def test_results_carry_null_rerank_score(self):
        os.environ.pop("COHERE_API_KEY", None)
        results = self.r.search("a1c target", top_k=3)
        self.assertGreater(len(results), 0)
        for hit in results:
            self.assertIsNone(hit["rerank_score"])

    def test_final_score_equals_rrf_score_when_cohere_off(self):
        os.environ.pop("COHERE_API_KEY", None)
        results = self.r.search("a1c target", top_k=3)
        for hit in results:
            # Tolerate ~1e-4 rounding drift: the rerank pipeline rounds
            # the final score for JSON serialization, so 0.03125 (exact
            # RRF) can round to 0.0312 in the final field. Functional
            # equivalence is what we're asserting here, not bit-exact
            # float identity.
            self.assertAlmostEqual(hit["score"], hit["rrf_score"], delta=1e-3)


if __name__ == "__main__":
    unittest.main()
