"""Tests for the hybrid sparse+dense retriever.

Run directly: `python copilot/agent/test_retriever_hybrid.py`
Or via pytest:  `pytest copilot/agent/test_retriever_hybrid.py`

These tests stub the Voyage client so the hybrid path can be exercised
without a network/API key. They lock in the contract the demo UI and
/search route depend on (per-component scores, contributors, fusion mode).
"""

from __future__ import annotations

import os
import sys
import types
import unittest
from pathlib import Path


def _install_fake_voyage() -> None:
    """Inject a deterministic Voyage stub before importing the retriever.

    The fake embedder returns 16-dim vectors derived from token-frequency
    so similar queries and chunks land near each other on cosine — enough
    to verify the dense leg activates and contributes to RRF without
    asserting on specific recall values."""
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


class HybridRetrieverTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        _install_fake_voyage()
        os.environ["VOYAGE_API_KEY"] = "stub"
        os.environ.pop("COHERE_API_KEY", None)
        # Import after the stub is installed so dense init picks it up.
        sys.path.insert(0, str(Path(__file__).resolve().parent))
        import rag.retriever as r  # noqa
        cls.r = r
        # Redirect the embeddings cache to /tmp so this test never
        # contaminates the repo's checked-in cache file.
        r._DEFAULT_EMBED_CACHE_PATH = Path("/tmp/test_hybrid_seed_embeddings.json")
        if r._DEFAULT_EMBED_CACHE_PATH.exists():
            r._DEFAULT_EMBED_CACHE_PATH.unlink()
        r.reload_corpus()

    def test_dense_enabled_when_voyage_key_present(self):
        self.assertTrue(self.r.dense_enabled())
        meta = self.r.retrieval_metadata()
        self.assertEqual(meta["retrieval_mode"], "hybrid_sparse_dense")
        self.assertEqual(meta["fusion"], "rrf")
        self.assertEqual(meta["rrf_k"], 60)

    def test_results_carry_per_component_scores(self):
        bundle = self.r.search_with_meta(
            "what is the A1c target for adults with diabetes?", top_k=3
        )
        self.assertGreater(len(bundle["results"]), 0)
        for r in bundle["results"]:
            # Every hybrid result must expose both sparse + dense scores
            # (the demo UI keys off these).
            self.assertIn("bm25_score", r)
            self.assertIn("dense_score", r)
            self.assertIn("rrf_score", r)
            self.assertIn(r["source"], {"sparse", "dense", "both"})
        self.assertIn("contributors", bundle["meta"])

    def test_disk_cache_written(self):
        self.assertTrue(self.r._DEFAULT_EMBED_CACHE_PATH.exists())

    def test_text_change_invalidates_dense_cache(self):
        """If a chunk's text mutates, the cache entry for that chunk must
        be reissued — otherwise we'd score the new text against stale
        vectors and get silently-wrong recall."""
        cache_before = self.r._DEFAULT_EMBED_CACHE_PATH.read_text()

        # Mutate the in-memory corpus and force a re-index without
        # disturbing the JSON file on disk.
        original_text = self.r._CHUNKS[0].text
        self.r._CHUNKS[0].text = original_text + " (amended)"
        self.r._TOKENIZED = [self.r._tokenize(c.text) for c in self.r._CHUNKS]
        self.r._BM25 = self.r.BM25Okapi(self.r._TOKENIZED)
        self.r._DENSE_VECTORS, self.r._DENSE_MODEL = self.r._build_dense_index(
            self.r._CHUNKS
        )

        cache_after = self.r._DEFAULT_EMBED_CACHE_PATH.read_text()
        self.assertNotEqual(cache_before, cache_after,
                            "cache should rewrite when chunk text changes")


class SparseOnlyFallbackTests(unittest.TestCase):
    """When VOYAGE_API_KEY is unset the retriever must keep working —
    /search and /chat must never 500 because dense isn't configured."""

    @classmethod
    def setUpClass(cls):
        os.environ.pop("VOYAGE_API_KEY", None)
        sys.path.insert(0, str(Path(__file__).resolve().parent))
        import rag.retriever as r  # noqa
        cls.r = r
        r.reload_corpus()

    def test_dense_disabled_metadata(self):
        meta = self.r.retrieval_metadata()
        self.assertFalse(meta["dense_enabled"])
        self.assertEqual(meta["retrieval_mode"], "sparse_only")
        self.assertEqual(meta["fusion"], "none")

    def test_results_still_returned(self):
        results = self.r.search("metformin contraindicated CKD", top_k=3)
        self.assertGreater(len(results), 0)
        for r in results:
            self.assertEqual(r["source"], "sparse")
            self.assertIsNone(r["dense_score"])


if __name__ == "__main__":
    unittest.main()
