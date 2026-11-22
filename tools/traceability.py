#!/usr/bin/env python3
"""Requirement → test traceability report (HLD §9).

Reads requirement IDs from docs/SRS.md (FR-…, NFR-… and LEG-…) and looks for them in the names of
tests, where they appear as FR_COMP_03 / NFR_04 / LEG_03 (or with dashes).

Backend (PHP): only the names of test methods under backend/tests/Unit, backend/tests/Integration
and backend/tests/Support/Contract count ("function test_FR_ACC_03_…"). An ID in a comment, in a class doc block or in a helper under
backend/tests/Support says nothing about what is tested and is ignored. For the clients (not built
yet) the whole test file is searched.

"Covered" means that at least one test names the requirement. It does not mean the requirement is
done: parts of it may belong to a later build step (docs/HLD.md §10).

Exit codes:
  0  ok
  1  a test references a requirement ID that is not in the SRS (typo or removed requirement), the SRS
     mentions an ID that has no requirement row the parser can read (a bold ID, a three-digit number,
     a mention of a requirement that was never written down), or no test name was found at all
  2  --strict only: a must-have (M) functional requirement has no test
"""
from __future__ import annotations

import argparse
import re
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TEST_DIRS = ["backend/tests", "web", "ios", "android"]
TEST_SUFFIXES = {".php", ".ts", ".tsx", ".swift", ".kt"}
# Matched against the path inside the repository only: a checkout that happens to live below a folder
# called build or vendor must still be scanned.
SKIP_PARTS = {"vendor", "node_modules", "build", ".gradle", "DerivedData", "dist"}

FR_ROW = re.compile(r"^\|\s*(FR-[A-Z0-9]+-\d{2})\s*\|\s*([MSC])\s*\|")
NFR_ROW = re.compile(r"^\|\s*(NFR-\d{2})\s*\|")
LEG_ROW = re.compile(r"^-\s*(LEG-\d{2}):")
# Two or more digits: a three-digit ID in a test name is reported as unknown instead of being ignored.
ID_IN_TEST = re.compile(r"(?<![A-Za-z])(FR[_-][A-Z0-9]+[_-]\d{2,}|NFR[_-]\d{2,}|LEG[_-]\d{2,})(?!\d)")
# Every ID-shaped token anywhere in the SRS. Each one must belong to a row the parser above can read,
# otherwise a requirement row was silently skipped (or the text refers to a requirement that does not exist).
ID_IN_SRS = re.compile(r"(?<![A-Za-z0-9-])(FR-[A-Z0-9]+-\d{2,}|NFR-\d{2,}|LEG-\d{2,})(?!\d)")
# (Support/Contract holds the tests that every implementation of a repository must pass: real tests,
# run by the Unit and the Integration suite through the classes that use these traits.)
PHP_TEST_DIRS = ("backend/tests/Unit", "backend/tests/Integration", "backend/tests/Support/Contract")
PHP_TEST_NAME = re.compile(r"^\s*(?:final\s+)?public\s+function\s+(test\w*)\s*\(", re.MULTILINE)


def read_requirements(srs: Path) -> dict[str, str]:
    """Return {id: priority}. NFRs and legal requirements get priority '-'."""
    reqs: dict[str, str] = {}
    for line in srs.read_text(encoding="utf-8").splitlines():
        if m := FR_ROW.match(line):
            reqs[m.group(1)] = m.group(2)
        elif m := NFR_ROW.match(line) or LEG_ROW.match(line):
            reqs[m.group(1)] = "-"
    return reqs


def read_srs_mentions(srs: Path) -> set[str]:
    """Every ID-shaped token anywhere in the SRS: rows, running text, tables of open questions."""
    return set(ID_IN_SRS.findall(srs.read_text(encoding="utf-8")))


def read_test_references(root: Path) -> tuple[dict[str, set[str]], int]:
    """Return ({id: {relative test file, ...}}, number of test names or client test files scanned)."""
    refs: dict[str, set[str]] = defaultdict(set)
    scanned = 0
    for test_dir in TEST_DIRS:
        base = root / test_dir
        if not base.is_dir():
            continue
        for path in base.rglob("*"):
            relative_path = path.relative_to(root)
            if path.suffix not in TEST_SUFFIXES or SKIP_PARTS & set(relative_path.parts):
                continue
            relative = relative_path.as_posix()
            text = path.read_text(encoding="utf-8", errors="ignore")
            if test_dir == "backend/tests":
                # Test method names only, and only of real tests: not comments, not Support helpers.
                if not relative.startswith(PHP_TEST_DIRS):
                    continue
                names = PHP_TEST_NAME.findall(text)
                scanned += len(names)
                text = "\n".join(names)
            elif "test" not in path.name.lower():
                continue
            else:
                scanned += 1
            for raw in ID_IN_TEST.findall(text):
                refs[raw.replace("_", "-")].add(relative)
    return refs, scanned


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--strict", action="store_true", help="fail when a must-have FR has no test")
    parser.add_argument("--root", type=Path, default=ROOT, help="repository root (default: this checkout)")
    args = parser.parse_args()
    root = args.root.resolve()
    srs = root / "docs" / "SRS.md"

    reqs = read_requirements(srs)
    refs, scanned = read_test_references(root)
    if not reqs:
        print(f"No requirement IDs found in {srs}", file=sys.stderr)
        return 1
    if scanned == 0:
        # Zero coverage because nothing was read (wrong root, moved test folders) must not look like success.
        print(f"No test names found below {root}: nothing was scanned", file=sys.stderr)
        return 1
    unparsed = sorted(read_srs_mentions(srs) - set(reqs))

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

    failed = False
    if unparsed:
        print("\n## ERROR: the SRS mentions IDs that have no requirement row the tool can read\n")
        for req in unparsed:
            print(f"- {req}")
        failed = True
    if unknown:
        print("\n## ERROR: tests reference IDs that are not in the SRS\n")
        for req in unknown:
            print(f"- {req}: {', '.join(sorted(refs[req]))}")
        failed = True
    if failed:
        return 1
    if args.strict and uncovered_must:
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
