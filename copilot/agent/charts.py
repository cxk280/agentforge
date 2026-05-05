"""Lab-trend SVG sparkline generator.

W2 extension. Renders a small inline-SVG sparkline from a series of
(date, value) tuples — used by the /copilot/lab-trend endpoint to
visualise longitudinal labs that span both legacy FHIR Observations
and W2-extracted cp_extracted_facts rows.

Pure-Python, no matplotlib dependency. The output is a single <svg>
tag the chat UI can drop in via markdown image syntax (`![](.svg)`)
or PHP can echo directly into a copilot_*.php page.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Iterable

from xml.sax.saxutils import escape as _xe


@dataclass
class TrendPoint:
    date: str       # ISO 8601 'YYYY-MM-DD' (sortable as string)
    value: float
    flag: str = ""  # 'normal' | 'high' | 'low' | ''


def _fmt(v: float) -> str:
    if v == int(v):
        return f"{int(v)}"
    return f"{v:.2f}".rstrip("0").rstrip(".")


def render_sparkline(
    points: Iterable[TrendPoint],
    *,
    title: str = "",
    unit: str = "",
    width: int = 480,
    height: int = 140,
    ref_low: float | None = None,
    ref_high: float | None = None,
) -> str:
    """Return a self-contained <svg> string.

    Layout: title strip top, axis labels left, sparkline + dots, latest
    value annotated on the right. Reference range (ref_low..ref_high)
    rendered as a faint horizontal band.
    """
    pts = sorted(points, key=lambda p: p.date)
    if not pts:
        return _empty_svg(width, height, title)

    # Plot area inside the SVG.
    pad_l, pad_r, pad_t, pad_b = 50, 80, 28, 22
    plot_w = width - pad_l - pad_r
    plot_h = height - pad_t - pad_b

    values = [p.value for p in pts]
    vmin, vmax = min(values), max(values)
    if ref_low is not None:
        vmin = min(vmin, ref_low)
    if ref_high is not None:
        vmax = max(vmax, ref_high)
    if vmax == vmin:
        vmax = vmin + 1
    span = vmax - vmin

    def y_of(v: float) -> float:
        return pad_t + plot_h - ((v - vmin) / span) * plot_h

    def x_of(i: int) -> float:
        if len(pts) == 1:
            return pad_l + plot_w / 2
        return pad_l + (i / (len(pts) - 1)) * plot_w

    # Reference-range band.
    band = ""
    if ref_low is not None and ref_high is not None:
        y_top = y_of(ref_high)
        y_bot = y_of(ref_low)
        band = (
            f'<rect x="{pad_l}" y="{y_top:.1f}" '
            f'width="{plot_w}" height="{(y_bot - y_top):.1f}" '
            f'fill="#2d7a4f" fill-opacity="0.08" />'
        )

    # Polyline.
    pts_attr = " ".join(f"{x_of(i):.1f},{y_of(p.value):.1f}" for i, p in enumerate(pts))

    # Per-point dots, coloured by flag.
    dot_color = {"high": "#a83232", "low": "#a83232", "normal": "#2d7a4f", "": "#1f3a68"}
    dots = "".join(
        f'<circle cx="{x_of(i):.1f}" cy="{y_of(p.value):.1f}" r="3.5" '
        f'fill="{dot_color.get(p.flag or "", "#1f3a68")}" />'
        for i, p in enumerate(pts)
    )

    # First + last date labels at the bottom (avoids clutter for long series).
    first_lbl = (
        f'<text x="{x_of(0):.1f}" y="{height - 4}" font-size="10" '
        f'fill="#8A91A1" text-anchor="start">{_xe(pts[0].date)}</text>'
    )
    last_lbl = (
        f'<text x="{x_of(len(pts) - 1):.1f}" y="{height - 4}" font-size="10" '
        f'fill="#8A91A1" text-anchor="end">{_xe(pts[-1].date)}</text>'
    )

    # Latest-value annotation on the right.
    latest = pts[-1]
    latest_color = dot_color.get(latest.flag or "", "#1f3a68")
    annot = (
        f'<text x="{width - pad_r + 8}" y="{y_of(latest.value):.1f}" '
        f'font-size="13" font-weight="700" fill="{latest_color}" '
        f'dominant-baseline="middle">{_fmt(latest.value)}'
        f'{(" " + _xe(unit)) if unit else ""}</text>'
    )

    # Title + min/max axis ticks.
    title_el = ""
    if title:
        title_el = (
            f'<text x="{pad_l}" y="16" font-size="12" font-weight="600" '
            f'fill="#0D1B2A">{_xe(title)}</text>'
        )
    tick_max = (
        f'<text x="{pad_l - 6}" y="{pad_t + 4}" font-size="10" '
        f'fill="#8A91A1" text-anchor="end">{_fmt(vmax)}</text>'
    )
    tick_min = (
        f'<text x="{pad_l - 6}" y="{pad_t + plot_h:.1f}" font-size="10" '
        f'fill="#8A91A1" text-anchor="end">{_fmt(vmin)}</text>'
    )

    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" '
        f'width="{width}" height="{height}" role="img" aria-label="{_xe(title)}">'
        f'<rect x="0" y="0" width="{width}" height="{height}" fill="#FFFFFF"/>'
        f'{title_el}{band}'
        f'<polyline points="{pts_attr}" fill="none" stroke="#1f3a68" stroke-width="1.6"/>'
        f'{dots}{annot}{tick_max}{tick_min}{first_lbl}{last_lbl}'
        f'</svg>'
    )


def _empty_svg(width: int, height: int, title: str) -> str:
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" '
        f'width="{width}" height="{height}">'
        f'<rect width="{width}" height="{height}" fill="#FFFFFF" stroke="#E4E5E8"/>'
        f'<text x="{width / 2}" y="{height / 2}" font-size="13" fill="#8A91A1" '
        f'text-anchor="middle" dominant-baseline="middle">'
        f'No data points for {_xe(title) or "this lab"}</text></svg>'
    )
