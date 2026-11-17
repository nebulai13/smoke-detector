#!/usr/bin/env python3
"""Requirement → test traceability report (HLD §9).

Reads requirement IDs from docs/SRS.md and looks for them in test files, where they appear in test
names as FR_COMP_03 / NFR_04 (or with dashes).

Exit codes:
  0  ok
  1  a test references a requirement ID that is not in the SRS (typo or removed requirement)
  2  --strict only: a must-have (M) functional requirement has no test
"""
from __future__ import annotations

import argparse
import re
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRS = ROOT / "docs" / "SRS.md"
TEST_DIRS = ["backend/tests", "web", "ios", "android"]
TEST_SUFFIXES = {".php", ".ts", ".tsx", ".swift", ".kt"}
SKIP_PARTS = {"vendor", "node_modules", "build", ".gradle", "DerivedData", "dist"}

FR_ROW = re.compile(r"^\|\s*(FR-[A-Z0-9]+-\d{2})\s*\|\s*([MSC])\s*\|")
NFR_ROW = re.compile(r"^\|\s*(NFR-\d{2})\s*\|")
ID_IN_TEST = re.compile(r"(?<![A-Za-z])(FR[_-][A-Z0-9]+[_-]\d{2}|NFR[_-]\d{2})(?!\d)")


def read_requirements() -> dict[str, str]:
    """Return {id: priority}. NFRs get priority '-'."""
    reqs: dict[str, str] = {}
    for line in SRS.read_text(encoding="utf-8").splitlines():
        if m := FR_ROW.match(line):
            reqs[m.group(1)] = m.group(2)
        elif m := NFR_ROW.match(line):
            reqs[m.group(1)] = "-"
    return reqs


def read_test_references() -> dict[str, set[str]]:
    """Return {id: {relative test file, ...}}."""
    refs: dict[str, set[str]] = defaultdict(set)
    for test_dir in TEST_DIRS:
        base = ROOT / test_dir
        if not base.is_dir():
            continue
        for path in base.rglob("*"):
            if path.suffix not in TEST_SUFFIXES or SKIP_PARTS & set(path.parts):
                continue
            if test_dir != "backend/tests" and "test" not in path.name.lower():
                continue
            for raw in ID_IN_TEST.findall(path.read_text(encoding="utf-8", errors="ignore")):
                refs[raw.replace("_", "-")].add(str(path.relative_to(ROOT)))
    return refs


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--strict", action="store_true", help="fail when a must-have FR has no test")
    args = parser.parse_args()

    reqs = read_requirements()
    refs = read_test_references()
    if not reqs:
        print(f"No requirement IDs found in {SRS}", file=sys.stderr)
        return 1

    must = [r for r, p in reqs.items() if p == "M"]
    covered = [r for r in reqs if r in refs]
    must_covered = [r for r in must if r in refs]
    unknown = sorted(set(refs) - set(reqs))
    uncovered_must = [r for r in must if r not in refs]

    print("# Traceability report\n")
    print(f"- Requirements in SRS: {len(reqs)} ({len(must)} must-have FRs)")
    print(f"- With at least one test: {len(covered)}")
    print(f"- Must-have FRs with a test: {len(must_covered)} / {len(must)}\n")

    print("## Covered\n")
    for req in covered:
        print(f"- {req} [{reqs[req]}]: {', '.join(sorted(refs[req]))}")
    if not covered:
        print("- none yet")

    print("\n## Must-have FRs without a test\n")
    print(", ".join(uncovered_must) if uncovered_must else "none")

    if unknown:
        print("\n## ERROR: tests reference IDs that are not in the SRS\n")
        for req in unknown:
            print(f"- {req}: {', '.join(sorted(refs[req]))}")
        return 1
    if args.strict and uncovered_must:
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
