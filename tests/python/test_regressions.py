from __future__ import annotations

import gzip
import importlib.util
import io
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from urllib.error import URLError


PROJECT_ROOT = Path(__file__).resolve().parents[2]
SRC_ROOT = PROJECT_ROOT / "src"
sys.path.insert(0, str(SRC_ROOT))


def load_terrain_builder():
    path = PROJECT_ROOT / "scripts" / "build-terrain-mbtiles.py"
    spec = importlib.util.spec_from_file_location("fuelau_terrain_builder", path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Unable to load {path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class TerrainPublicationTests(unittest.TestCase):
    def test_failed_build_preserves_existing_output(self) -> None:
        builder = load_terrain_builder()

        def failed_fetch(task, retries, timeout):
            zoom, x, y, _url = task
            return zoom, x, y, None, "injected failure"

        builder.fetch_task = failed_fetch

        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "terrain.mbtiles"
            original = b"existing-live-map"
            output.write_bytes(original)

            with self.assertRaises((RuntimeError, SystemExit)):
                builder.build(
                    str(output),
                    min_zoom=0,
                    max_zoom=0,
                    workers=1,
                    retries=0,
                    timeout=1,
                )

            self.assertEqual(original, output.read_bytes())

    def test_successful_build_atomically_replaces_existing_output(self) -> None:
        builder = load_terrain_builder()

        def successful_fetch(task, retries, timeout):
            zoom, x, y, _url = task
            return zoom, x, y, b"terrain-png", None

        builder.fetch_task = successful_fetch

        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "terrain.mbtiles"
            output.write_bytes(b"existing-live-map")

            builder.build(
                str(output),
                min_zoom=0,
                max_zoom=0,
                workers=1,
                retries=0,
                timeout=1,
            )

            import sqlite3

            connection = sqlite3.connect(output)
            try:
                self.assertEqual("ok", connection.execute("PRAGMA integrity_check").fetchone()[0])
                self.assertEqual(1, connection.execute("SELECT COUNT(*) FROM tiles").fetchone()[0])
            finally:
                connection.close()

    def test_overlapping_build_is_rejected_without_touching_output(self) -> None:
        builder = load_terrain_builder()

        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "terrain.mbtiles"
            original = b"existing-live-map"
            output.write_bytes(original)

            with builder.build_lock(str(output)):
                with self.assertRaises(RuntimeError):
                    builder.build(
                        str(output),
                        min_zoom=0,
                        max_zoom=0,
                        workers=1,
                        retries=0,
                        timeout=1,
                    )

            self.assertEqual(original, output.read_bytes())


class ImporterFreshnessTests(unittest.TestCase):
    def test_fpq_current_prices_do_not_regress_timestamp(self) -> None:
        from fpq_sync.cli import build_prices_current_sql

        sql = build_prices_current_sql(
            [
                {
                    "SiteId": 1,
                    "FuelId": 2,
                    "CollectionMethod": "T",
                    "TransactionDateUtc": "2026-01-01T00:00:00",
                    "Price": 1800,
                }
            ]
        )

        self.assertIn("IF(", sql.upper())
        self.assertIn("TRANSACTION_DATE_UTC", sql.upper())

    def test_cron_sync_jobs_use_non_overlapping_locks(self) -> None:
        cron = (PROJECT_ROOT / "docker" / "cron.d" / "fuelau").read_text(encoding="utf-8")
        sync_lines = [line for line in cron.splitlines() if "_sync.cli" in line and not line.lstrip().startswith("#")]

        self.assertGreater(len(sync_lines), 0)
        for line in sync_lines:
            with self.subTest(line=line):
                self.assertIn("flock -n", line)

    def test_qld_cron_splits_prices_from_daily_reference(self) -> None:
        cron = (PROJECT_ROOT / "docker" / "cron.d" / "fuelau").read_text(encoding="utf-8")
        active_lines = [
            line for line in cron.splitlines()
            if "fpq_sync.cli" in line and not line.lstrip().startswith("#")
        ]

        self.assertEqual(2, len(active_lines))
        self.assertTrue(any("fpq_sync.cli prices" in line and "0,30 * * * *" in line for line in active_lines))
        self.assertTrue(any("fpq_sync.cli daily-reference" in line for line in active_lines))
        self.assertFalse(any("fpq_sync.cli all" in line for line in active_lines))
        for line in active_lines:
            self.assertIn("/run/lock/fuelau-fpq-sync.lock", line)

    def test_all_current_snapshots_publish_atomically(self) -> None:
        from fpq_sync.cli import build_prices_current_sql as fpq_sql
        from nsw_sync.cli import build_prices_current_sql as nsw_sql
        from nt_sync.cli import build_prices_current_sql as nt_sql
        from sa_sync.cli import build_prices_current_sql as sa_sql
        from vic_sync.cli import build_prices_current_sql as vic_sql
        from wa_sync.cli import build_prices_current_sql as wa_sql

        builders_and_rows = [
            (
                fpq_sql,
                {
                    "SiteId": 1,
                    "FuelId": 2,
                    "CollectionMethod": "T",
                    "TransactionDateUtc": "2026-01-01T00:00:00",
                    "Price": 1800,
                },
            ),
            (
                sa_sql,
                {
                    "SiteId": 1,
                    "FuelId": 2,
                    "CollectionMethod": "T",
                    "TransactionDateUtc": "2026-01-01T00:00:00",
                    "Price": 180.0,
                },
            ),
            (
                nsw_sql,
                {
                    "state": "NSW",
                    "stationcode": "1",
                    "fueltype": "E10",
                    "lastupdated": "01/01/2026 00:00:00",
                    "price": 180.0,
                },
            ),
            (
                vic_sql,
                {
                    "station_id": "1",
                    "fuel_code": "E10",
                    "updated_at": "2026-01-01T00:00:00Z",
                    "is_available": True,
                    "price": 180.0,
                },
            ),
            (
                nt_sql,
                {
                    "station_id": "1",
                    "fuel_code": "E10",
                    "observed_at_utc": "2026-01-01T00:00:00Z",
                    "is_available": 1,
                    "price": 180.0,
                },
            ),
            (
                wa_sql,
                {
                    "station_id": "1",
                    "fuel_code": "1",
                    "price_date": "2026-01-01",
                    "price": 180.0,
                },
            ),
        ]

        for builder, row in builders_and_rows:
            with self.subTest(builder=builder.__module__):
                sql = builder([row]).upper()
                self.assertIn("CREATE TEMPORARY TABLE", sql)
                self.assertIn("START TRANSACTION", sql)
                self.assertIn("ON DUPLICATE KEY UPDATE", sql)
                self.assertIn("IF(", sql)
                self.assertIn("LEFT JOIN", sql)
                self.assertIn("COMMIT", sql)

    def test_high_frequency_importers_publish_change_aware_history(self) -> None:
        from fpq_sync.cli import build_prices_current_sql as fpq_sql
        from nsw_sync.cli import build_prices_current_sql as nsw_sql
        from nt_sync.cli import build_prices_current_sql as nt_sql
        from sa_sync.cli import build_prices_current_sql as sa_sql
        from vic_sync.cli import build_prices_current_sql as vic_sql

        builders_and_rows = [
            (fpq_sql, {"SiteId": 1, "FuelId": 2, "CollectionMethod": "T", "TransactionDateUtc": "2026-01-01T00:00:00", "Price": 1800}),
            (sa_sql, {"SiteId": 1, "FuelId": 2, "CollectionMethod": "T", "TransactionDateUtc": "2026-01-01T00:00:00", "Price": 180.0}),
            (nsw_sql, {"state": "NSW", "stationcode": "1", "fueltype": "E10", "lastupdated": "01/01/2026 00:00:00", "price": 180.0}),
            (vic_sql, {"station_id": "1", "fuel_code": "E10", "updated_at": "2026-01-01T00:00:00Z", "is_available": True, "price": 180.0}),
            (nt_sql, {"station_id": "1", "fuel_code": "E10", "observed_at_utc": "2026-01-01T00:00:00Z", "is_available": 1, "price": 180.0}),
        ]

        for builder, row in builders_and_rows:
            with self.subTest(builder=builder.__module__):
                sql = builder([row]).upper()
                self.assertIn("_SITE_PRICES_HISTORY", sql)
                self.assertIn("LAST_SEEN_AT", sql)
                self.assertIn("FUELAU_METRICS:", sql)
                self.assertIn("HISTORY_CHANGES", sql)
                self.assertIn("UNCHANGED", sql)

    def test_change_aware_stage_preserves_chronological_reversions(self) -> None:
        from fpq_sync.cli import build_prices_current_sql

        sql = build_prices_current_sql([
            {"SiteId": 1, "FuelId": 2, "CollectionMethod": "T", "TransactionDateUtc": "2026-01-01T00:00:00", "Price": 1800},
            {"SiteId": 1, "FuelId": 2, "CollectionMethod": "T", "TransactionDateUtc": "2026-01-01T00:30:00", "Price": 1900},
            {"SiteId": 1, "FuelId": 2, "CollectionMethod": "T", "TransactionDateUtc": "2026-01-01T01:00:00", "Price": 1800},
        ])

        self.assertIn("2026-01-01T00:00:00", sql)
        self.assertIn("2026-01-01T00:30:00", sql)
        self.assertIn("2026-01-01T01:00:00", sql)
        self.assertIn("ORDER BY `_fuelau_stage_id`", sql)

    def test_vic_and_nt_missing_rows_transition_to_unavailable(self) -> None:
        from nt_sync.cli import build_prices_current_sql as nt_sql
        from vic_sync.cli import build_prices_current_sql as vic_sql

        rows = {
            vic_sql: {"station_id": "1", "fuel_code": "E10", "updated_at": "2026-01-01T00:00:00Z", "is_available": True, "price": 180.0},
            nt_sql: {"station_id": "1", "fuel_code": "E10", "observed_at_utc": "2026-01-01T00:00:00Z", "is_available": 1, "price": 180.0},
        }
        for builder, row in rows.items():
            with self.subTest(builder=builder.__module__):
                sql = builder([row]).upper()
                self.assertIn("LIVE.`IS_AVAILABLE` = 0", sql)
                self.assertIn("LIVE.`PRICE` = NULL", sql)
                self.assertNotIn("DELETE LIVE", sql)

    def test_publication_metrics_are_parseable(self) -> None:
        from sync_utils import parse_publication_metrics

        metrics = parse_publication_metrics(
            "FUELAU_METRICS:api_rows=10,current_rows=8,"
            "history_changes=3,unchanged=7,expired=2"
        )
        self.assertEqual(10, metrics.api_rows_fetched)
        self.assertEqual(8, metrics.current_rows_published)
        self.assertEqual(3, metrics.history_changes)
        self.assertEqual(7, metrics.unchanged_observations)
        self.assertEqual(2, metrics.missing_rows_expired)

    def test_empty_snapshot_is_rejected_for_every_provider(self) -> None:
        from fpq_sync.cli import build_prices_current_sql as fpq_sql
        from nsw_sync.cli import build_prices_current_sql as nsw_sql
        from nt_sync.cli import build_prices_current_sql as nt_sql
        from sa_sync.cli import build_prices_current_sql as sa_sql
        from sync_utils import SnapshotValidationError
        from vic_sync.cli import build_prices_current_sql as vic_sql
        from wa_sync.cli import build_prices_current_sql as wa_sql

        for builder in (fpq_sql, sa_sql, nsw_sql, vic_sql, nt_sql, wa_sql):
            with self.subTest(builder=builder.__module__):
                with self.assertRaises(SnapshotValidationError):
                    builder([])

    def test_transient_http_failures_use_bounded_exponential_backoff(self) -> None:
        from sync_utils import retry_urlopen

        attempts = []
        delays = []

        def opener(request, timeout):
            attempts.append((request, timeout))
            if len(attempts) < 3:
                raise URLError("temporary")
            return "response"

        result = retry_urlopen(
            "https://example.invalid",
            timeout=5,
            attempts=4,
            base_delay_seconds=0.25,
            opener=opener,
            sleeper=delays.append,
        )

        self.assertEqual("response", result)
        self.assertEqual(3, len(attempts))
        self.assertEqual([0.25, 0.5], delays)

    def test_importers_prefer_least_privilege_sync_credentials(self) -> None:
        from sync_utils import sync_mysql_credentials

        self.assertEqual(
            ("fuelau_sync", "sync-password"),
            sync_mysql_credentials(
                {
                    "MYSQL_USERNAME": "fuelau_migrator",
                    "MYSQL_PASSWORD": "migration-password",
                    "MYSQL_SYNC_USERNAME": "fuelau_sync",
                    "MYSQL_SYNC_PASSWORD": "sync-password",
                }
            ),
        )
        self.assertEqual(
            ("legacy", "legacy-password"),
            sync_mysql_credentials(
                {
                    "MYSQL_USERNAME": "legacy",
                    "MYSQL_PASSWORD": "legacy-password",
                }
            ),
        )


class HistoryCleanupAuditTests(unittest.TestCase):
    def test_audit_queries_are_read_only_and_preserve_state_semantics(self) -> None:
        from history_cleanup import PROVIDERS
        from history_cleanup import build_audit_sql

        self.assertNotIn("wa", PROVIDERS)
        for provider, spec in PROVIDERS.items():
            with self.subTest(provider=provider):
                sql = build_audit_sql(spec)
                upper_sql = sql.upper()
                self.assertIn("START TRANSACTION READ ONLY", upper_sql)
                self.assertIn("ROW_NUMBER() OVER", upper_sql)
                self.assertIn("LAG(", upper_sql)
                self.assertIn("<=>", sql)
                self.assertNotIn("DELETE ", upper_sql)
                self.assertNotIn("UPDATE ", upper_sql)
                self.assertNotIn("INSERT ", upper_sql)
                self.assertNotIn("ALTER ", upper_sql)
                self.assertNotIn("DROP ", upper_sql)

        self.assertEqual(("price", "collection_method"), PROVIDERS["qld"].state_columns)
        self.assertEqual(("price", "collection_method"), PROVIDERS["sa"].state_columns)
        self.assertEqual(("price",), PROVIDERS["nsw"].state_columns)
        self.assertEqual(("price", "is_available"), PROVIDERS["vic"].state_columns)
        self.assertEqual(("price", "is_available"), PROVIDERS["nt"].state_columns)

    def test_audit_output_reports_candidates_and_estimated_savings(self) -> None:
        from history_cleanup import PROVIDERS
        from history_cleanup import parse_audit_output

        result = parse_audit_output(
            PROVIDERS["nt"],
            "1000\t750\t104857600\n",
            duration_seconds=1.25,
        )

        self.assertEqual(1000, result.total_rows)
        self.assertEqual(750, result.redundant_rows)
        self.assertEqual(250, result.retained_rows)
        self.assertEqual(75.0, result.redundant_percent)
        self.assertEqual(78643200, result.estimated_reclaimable_bytes)
        self.assertEqual(1.25, result.duration_seconds)

    def test_text_report_is_explicitly_non_destructive(self) -> None:
        from history_cleanup import PROVIDERS
        from history_cleanup import parse_audit_output
        from history_cleanup import print_text_report

        result = parse_audit_output(PROVIDERS["qld"], "10\t8\t1048576\n", 0.1)
        output = io.StringIO()
        with redirect_stdout(output):
            print_text_report([result])

        report = output.getvalue()
        self.assertIn("read-only", report)
        self.assertIn("WA is excluded", report)
        self.assertIn("No rows were changed", report)

    def test_cleanup_staging_and_deletion_are_scoped_and_bounded(self) -> None:
        from history_cleanup import CLEANUP_PROVIDERS
        from history_cleanup import PROVIDERS
        from history_cleanup import build_delete_batch_sql
        from history_cleanup import build_stage_candidates_sql

        self.assertEqual(("nt", "sa", "qld", "vic"), CLEANUP_PROVIDERS)
        for provider in CLEANUP_PROVIDERS:
            with self.subTest(provider=provider):
                stage_sql = build_stage_candidates_sql(PROVIDERS[provider]).upper()
                delete_sql = build_delete_batch_sql(PROVIDERS[provider], 50000).upper()
                self.assertIn("FUELAU_HISTORY_CLEANUP_CANDIDATES", stage_sql)
                self.assertIn("ROW_NUMBER() OVER", stage_sql)
                self.assertIn("LAG(", stage_sql)
                self.assertNotIn("_SITE_PRICES_CURRENT", stage_sql)
                self.assertIn("START TRANSACTION", delete_sql)
                self.assertIn("LIMIT 50000", delete_sql)
                self.assertIn("TMP_FUELAU_HISTORY_CLEANUP_BATCH", delete_sql)
                self.assertNotIn("_SITE_PRICES_CURRENT", delete_sql)
                self.assertNotIn("DROP TABLE", delete_sql)

        with self.assertRaises(ValueError):
            build_stage_candidates_sql(PROVIDERS["nsw"])
        with self.assertRaises(ValueError):
            build_delete_batch_sql(PROVIDERS["nsw"], 50000)
        with self.assertRaises(ValueError):
            build_delete_batch_sql(PROVIDERS["nt"], 100001)

    def test_cleanup_requires_a_complete_gzip_backup(self) -> None:
        from history_cleanup import verify_backup

        markers = [
            "CREATE TABLE `nt_site_prices_history`",
            "CREATE TABLE `sa_site_prices_history`",
            "CREATE TABLE `fpq_site_prices_history`",
            "CREATE TABLE `vic_site_prices_history`",
            "-- Dump completed on 2026-07-30 15:23:05",
        ]
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "backup.sql.gz"
            with gzip.open(path, "wb") as backup:
                backup.write(("\n".join(markers) + "\n").encode("utf-8"))

            digest, size = verify_backup(path)
            self.assertEqual(64, len(digest))
            self.assertGreater(size, 0)

            incomplete = Path(directory) / "incomplete.sql.gz"
            with gzip.open(incomplete, "wb") as backup:
                backup.write(b"-- incomplete\n")
            with self.assertRaises(ValueError):
                verify_backup(incomplete)


if __name__ == "__main__":
    unittest.main()
