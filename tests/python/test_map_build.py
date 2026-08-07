from __future__ import annotations

import fcntl
import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parents[2]
BUILD_SCRIPT = PROJECT_ROOT / "scripts" / "build-map-atomically.sh"
FIXTURES = PROJECT_ROOT / "tests" / "fixtures"


class BasemapPublicationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary_directory.cleanup)
        self.directory = Path(self.temporary_directory.name)
        self.output = self.directory / "australia.mbtiles"
        self.output_log = self.directory / "planetiler-output.log"
        self.bin_directory = self.directory / "bin"
        self.bin_directory.mkdir()
        (self.bin_directory / "java").symlink_to(FIXTURES / "fake-java")
        (self.bin_directory / "sqlite3").symlink_to(FIXTURES / "fake-sqlite3")
        self.environment = os.environ.copy()
        self.environment.update(
            {
                "PATH": f"{self.bin_directory}:{self.environment['PATH']}",
                "MAP_OUTPUT": str(self.output),
                "MAP_MINIMUM_TILES": "1000",
                "FAKE_PLANETILER_OUTPUT_LOG": str(self.output_log),
            }
        )

    def run_build(self, **environment_overrides: str) -> subprocess.CompletedProcess[str]:
        environment = self.environment | environment_overrides
        return subprocess.run(
            ["sh", str(BUILD_SCRIPT), "--download", "--area=australia"],
            check=False,
            capture_output=True,
            env=environment,
            text=True,
        )

    def temporary_artifacts(self) -> list[Path]:
        return list(self.directory.glob("australia.building-*"))

    def test_success_uses_mbtiles_temporary_name_and_replaces_atomically(self) -> None:
        self.output.write_text("existing-map\n", encoding="utf-8")

        result = self.run_build()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("generated-map\n", self.output.read_text(encoding="utf-8"))
        temporary_output = self.output_log.read_text(encoding="utf-8").strip()
        self.assertRegex(temporary_output, r"australia\.building-\d+\.mbtiles$")
        self.assertEqual([], self.temporary_artifacts())
        self.assertIn("Published 1001 map tiles", result.stdout)

    def test_failed_generation_preserves_output_and_removes_sidecars(self) -> None:
        self.output.write_text("existing-map\n", encoding="utf-8")

        result = self.run_build(FAKE_PLANETILER_FAIL="1")

        self.assertEqual(42, result.returncode)
        self.assertEqual("existing-map\n", self.output.read_text(encoding="utf-8"))
        self.assertEqual([], self.temporary_artifacts())

    def test_failed_validation_preserves_output_and_removes_sidecars(self) -> None:
        self.output.write_text("existing-map\n", encoding="utf-8")

        result = self.run_build(FAKE_SQLITE_INTEGRITY="corrupt")

        self.assertNotEqual(0, result.returncode)
        self.assertIn("integrity check failed", result.stderr)
        self.assertEqual("existing-map\n", self.output.read_text(encoding="utf-8"))
        self.assertEqual([], self.temporary_artifacts())

    def test_concurrent_build_is_rejected_without_touching_output(self) -> None:
        self.output.write_text("existing-map\n", encoding="utf-8")
        lock_path = Path(f"{self.output}.lock")

        with lock_path.open("w", encoding="utf-8") as lock_file:
            fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_build()

        self.assertNotEqual(0, result.returncode)
        self.assertIn("Another map build is already publishing", result.stderr)
        self.assertEqual("existing-map\n", self.output.read_text(encoding="utf-8"))
        self.assertFalse(self.output_log.exists())
        self.assertEqual([], self.temporary_artifacts())


class StaticAssetReferenceTests(unittest.TestCase):
    def test_local_source_map_references_exist(self) -> None:
        resources = PROJECT_ROOT / "public" / "resources"
        source_map_pattern = re.compile(r"sourceMappingURL=([^\s*]+)")

        for asset in resources.iterdir():
            if asset.suffix not in {".css", ".js"}:
                continue
            for reference in source_map_pattern.findall(asset.read_text(encoding="utf-8")):
                if reference.startswith(("data:", "http://", "https://")):
                    continue
                with self.subTest(asset=asset.name, reference=reference):
                    self.assertTrue((asset.parent / reference).is_file())
