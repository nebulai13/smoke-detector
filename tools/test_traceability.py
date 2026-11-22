#!/usr/bin/env python3
"""Tests for tools/traceability.py. Standard library only: python3 -m unittest tools/test_traceability.py

Every test builds a small repository (docs/SRS.md and a few test files) in a temporary directory and
runs the tool on it with --root, the way `make trace` runs it on the real repository.
"""
from __future__ import annotations

import subprocess
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

TOOL = Path(__file__).resolve().parent / "traceability.py"

SRS = textwrap.dedent(
    """\
    # SRS

    | ID | P | Requirement |
    |----|---|-------------|
    | FR-COMP-03 | M | A missing check-in counts as baseline (see also NFR-04). |
    | FR-PLAN-01 | S | Taper factors. |

    | ID | Requirement |
    |----|-------------|
    | NFR-04 | Same input, same result. |

    - LEG-01: Consent is recorded.
    """
)

PHP_TEST = textwrap.dedent(
    """\
    <?php
    final class ExampleTest extends TestCase
    {
        public function test_FR_COMP_03_missing_checkin_counts_as_baseline(): void {}
        public function test_NFR_04_same_input_same_result(): void {}
    }
    """
)


def make_repo(root: Path, srs: str = SRS, php_test: str | None = PHP_TEST) -> Path:
    (root / "docs").mkdir(parents=True)
    (root / "docs" / "SRS.md").write_text(srs, encoding="utf-8")
    unit = root / "backend" / "tests" / "Unit"
    unit.mkdir(parents=True)
    if php_test is not None:
        (unit / "ExampleTest.php").write_text(php_test, encoding="utf-8")
    return root


def run_tool(root: Path, *args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, "-I", str(TOOL), "--root", str(root), *args],
        capture_output=True,
        text=True,
        check=False,
    )


class TraceabilityTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp = Path(self._tmp.name)

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def test_counts_requirements_and_covered_ones(self) -> None:
        result = run_tool(make_repo(self.tmp / "repo"))
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("Requirements in SRS: 4 (1 must-have FRs)", result.stdout)
        self.assertIn("With at least one test: 2", result.stdout)
        self.assertIn("Must-have FRs with a test: 1 / 1", result.stdout)

    def test_checkout_below_a_folder_named_build_still_finds_the_tests(self) -> None:
        # The skip list is about folders inside the repository, not about where the checkout lives.
        for parent in ("build", "dist", "vendor"):
            with self.subTest(parent=parent):
                result = run_tool(make_repo(self.tmp / parent / "repo"))
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)
                self.assertIn("With at least one test: 2", result.stdout)

    def test_build_folder_inside_the_repository_is_still_skipped(self) -> None:
        root = make_repo(self.tmp / "repo")
        generated = root / "web" / "build"
        generated.mkdir(parents=True)
        (generated / "plan.test.ts").write_text("it('FR_PLAN_01 taper', () => {})", encoding="utf-8")
        result = run_tool(root)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("With at least one test: 2", result.stdout)

    def test_no_test_name_scanned_at_all_is_an_error(self) -> None:
        result = run_tool(make_repo(self.tmp / "repo", php_test=None))
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("no test", (result.stdout + result.stderr).lower())

    def test_unknown_id_in_a_test_name_is_an_error(self) -> None:
        php = PHP_TEST.replace("test_NFR_04_same", "test_FR_COMP_99_same")
        result = run_tool(make_repo(self.tmp / "repo", php_test=php))
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("FR-COMP-99", result.stdout)

    def test_three_digit_id_in_a_test_name_is_reported_not_ignored(self) -> None:
        php = PHP_TEST.replace("test_NFR_04_same", "test_FR_COMP_030_same")
        result = run_tool(make_repo(self.tmp / "repo", php_test=php))
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("FR-COMP-030", result.stdout)

    def test_id_in_srs_without_a_parsed_row_is_an_error(self) -> None:
        # A three-digit number and a bold id: both rows are skipped by the row parser.
        srs = SRS + "| FR-COMP-100 | M | Three digits. |\n| **FR-COMP-04** | M | Bold. |\n"
        result = run_tool(make_repo(self.tmp / "repo", srs=srs))
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("FR-COMP-100", result.stdout)
        self.assertIn("FR-COMP-04", result.stdout)

    def test_id_mentioned_in_running_text_that_has_its_own_row_is_fine(self) -> None:
        srs = SRS + "\nThe baseline rule (FR-PLAN-01) and NFR-04 apply here, see LEG-01.\n"
        result = run_tool(make_repo(self.tmp / "repo", srs=srs))
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_id_mentioned_in_running_text_without_a_row_is_an_error(self) -> None:
        srs = SRS + "\nSee also LEG-07 for the cookie rules.\n"
        result = run_tool(make_repo(self.tmp / "repo", srs=srs))
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("LEG-07", result.stdout)


if __name__ == "__main__":
    unittest.main()
