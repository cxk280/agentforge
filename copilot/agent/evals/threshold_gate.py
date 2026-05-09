"""Eval threshold gate — exit non-zero if eval pass count < min_pass.

Replaces the bash `grep | awk | cut` one-liner that used to live in
.circleci/config.yml's "Enforce pass threshold" step. The bash version
silently exited 1 if run_evals.py changed its output format (no
matching grep → empty PASSED → "below threshold" → fail). This module
behaves the same way on missing/malformed TOTAL lines but does it via
testable Python.

Usage:
    python -m evals.threshold_gate /tmp/eval-output.txt --min-pass 4

Exit codes:
    0  passed >= min_pass
    1  passed < min_pass, OR TOTAL line absent / malformed
    2  output file not readable
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path


# Mirrors the print() in run_evals.py:
#     print(f"  TOTAL                  {total_passed}/{len(results)} passed ...")
_TOTAL_RE = re.compile(r"^\s*TOTAL\s+(\d+)\s*/\s*(\d+)\s+passed", re.MULTILINE)


def parse_total(text: str) -> tuple[int, int] | None:
    m = _TOTAL_RE.search(text)
    if m is None:
        return None
    return int(m.group(1)), int(m.group(2))


def gate(text: str, min_pass: int) -> tuple[int, str]:
    """Return (exit_code, message). exit_code is 0 on pass, 1 on fail."""
    if min_pass < 0:
        return 1, f"min_pass must be >= 0, got {min_pass}"
    parsed = parse_total(text)
    if parsed is None:
        return 1, "no TOTAL line found in eval output (run_evals.py output format changed?)"
    passed, total = parsed
    if passed < min_pass:
        return 1, f"only {passed}/{total} passed; required >= {min_pass}"
    return 0, f"{passed}/{total} passed (required >= {min_pass})"


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("output_file", type=Path,
                    help="Captured stdout of run_evals.py")
    ap.add_argument("--min-pass", type=int, required=True,
                    help="Minimum number of cases that must pass")
    args = ap.parse_args(argv)

    if not args.output_file.exists():
        print(f"x output file not found: {args.output_file}", file=sys.stderr)
        return 2
    try:
        text = args.output_file.read_text()
    except OSError as exc:
        print(f"x failed to read output file: {exc}", file=sys.stderr)
        return 2

    code, msg = gate(text, args.min_pass)
    stream = sys.stdout if code == 0 else sys.stderr
    prefix = "ok" if code == 0 else "x"
    print(f"{prefix} {msg}", file=stream)
    return code


if __name__ == "__main__":
    sys.exit(main())
