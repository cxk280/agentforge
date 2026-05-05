"""Per-model cost table + helpers for the cost/latency report.

Submission deliverable (W2 spec page 6, "Cost and Latency Report"):
"Actual dev spend, projected production cost, p50/p95 latency, and
bottleneck analysis."

Public API:

    cost_for_usage(model, usage) -> CostBreakdown
        Maps an Anthropic Usage object (or its dict shape) into a USD
        cost broken out by input / output / cache_read / cache_write.

    estimate_per_turn(input_tok, output_tok, model=...) -> float
        Cheap one-shot for ad-hoc back-of-envelope math.

Pricing pinned 2026-05-05 from the Anthropic public pricing page.
Update the constants at the top of this file when prices change; the
test suite has a regression check that the math hasn't drifted.
"""

from __future__ import annotations

from dataclasses import asdict, dataclass
from typing import Any


# ---------------------------------------------------------------------------
# Pricing (USD per 1M tokens)
# ---------------------------------------------------------------------------

# Pinned 2026-05-05 from Anthropic's public pricing.
# Cache-read is 10% of input price; cache-write is 25% above input price.

_PRICING: dict[str, dict[str, float]] = {
    # Claude Sonnet 4.6 — production agent model.
    "claude-sonnet-4-6": {
        "input":  3.00,
        "output": 15.00,
        "cache_read":     0.30,
        "cache_creation": 3.75,
    },
    # Claude Haiku 4.5 — eval judge + verification model.
    "claude-haiku-4-5-20251001": {
        "input":  0.80,
        "output": 4.00,
        "cache_read":     0.08,
        "cache_creation": 1.00,
    },
    # Claude Opus 4.7 — used by the build-time agent (not the production
    # Co-Pilot). Listed so eval-time attributions can include it.
    "claude-opus-4-7": {
        "input":  15.00,
        "output": 75.00,
        "cache_read":     1.50,
        "cache_creation": 18.75,
    },
}

_DEFAULT_MODEL = "claude-sonnet-4-6"


@dataclass
class CostBreakdown:
    model: str
    input_tokens: int
    output_tokens: int
    cache_read_tokens: int
    cache_creation_tokens: int
    input_usd: float
    output_usd: float
    cache_read_usd: float
    cache_creation_usd: float
    total_usd: float

    def as_dict(self) -> dict[str, Any]:
        return asdict(self)


def _price(model: str, kind: str) -> float:
    table = _PRICING.get(model) or _PRICING.get(_DEFAULT_MODEL) or {}
    return float(table.get(kind, 0.0))


def cost_for_usage(model: str, usage: Any) -> CostBreakdown:
    """Compute USD cost from an Anthropic Usage object or a usage-shaped dict.

    The Anthropic Python SDK exposes input_tokens, output_tokens,
    cache_read_input_tokens, and cache_creation_input_tokens directly
    on the response.usage attribute. We accept either that object or a
    plain dict with the same keys (e.g. from a stored Langfuse trace).
    """
    def _g(attr: str) -> int:
        if usage is None:
            return 0
        if isinstance(usage, dict):
            return int(usage.get(attr) or 0)
        return int(getattr(usage, attr, 0) or 0)

    inp = _g("input_tokens")
    outp = _g("output_tokens")
    cread = _g("cache_read_input_tokens")
    cwrite = _g("cache_creation_input_tokens")

    pi = _price(model, "input")
    po = _price(model, "output")
    pcr = _price(model, "cache_read")
    pcw = _price(model, "cache_creation")

    input_usd = (inp / 1_000_000.0) * pi
    output_usd = (outp / 1_000_000.0) * po
    cache_read_usd = (cread / 1_000_000.0) * pcr
    cache_creation_usd = (cwrite / 1_000_000.0) * pcw

    return CostBreakdown(
        model=model,
        input_tokens=inp,
        output_tokens=outp,
        cache_read_tokens=cread,
        cache_creation_tokens=cwrite,
        input_usd=round(input_usd, 6),
        output_usd=round(output_usd, 6),
        cache_read_usd=round(cache_read_usd, 6),
        cache_creation_usd=round(cache_creation_usd, 6),
        total_usd=round(input_usd + output_usd + cache_read_usd + cache_creation_usd, 6),
    )


def estimate_per_turn(
    input_tokens: int = 0,
    output_tokens: int = 0,
    *,
    model: str = _DEFAULT_MODEL,
    cache_read_tokens: int = 0,
    cache_creation_tokens: int = 0,
) -> float:
    """Quick estimate without going through the dataclass."""
    return cost_for_usage(model, {
        "input_tokens": input_tokens,
        "output_tokens": output_tokens,
        "cache_read_input_tokens": cache_read_tokens,
        "cache_creation_input_tokens": cache_creation_tokens,
    }).total_usd


# ---------------------------------------------------------------------------
# Pretty-printer / report helpers
# ---------------------------------------------------------------------------

def usage_summary_line(model: str, usage: Any) -> str:
    """Single-line summary for log lines / NDJSON events."""
    cb = cost_for_usage(model, usage)
    return (
        f"{model}  in={cb.input_tokens}/cr={cb.cache_read_tokens}"
        f"/cw={cb.cache_creation_tokens}/out={cb.output_tokens}  "
        f"${cb.total_usd:.4f}"
    )
