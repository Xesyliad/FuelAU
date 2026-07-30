from __future__ import annotations

import argparse
import gzip
import hashlib
import json
import os
import re
import subprocess
import sys
import time
from dataclasses import asdict
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

from sync_utils import sync_mysql_credentials


DEFAULT_MYSQL_ENV_PATH = "/etc/fuelapi/mysql.env"
CANDIDATE_TABLE = "fuelau_history_cleanup_candidates"
DELETE_CONFIRMATION = "DELETE REDUNDANT HISTORY"


@dataclass(frozen=True)
class ProviderSpec:
    name: str
    label: str
    table: str
    key_columns: tuple[str, ...]
    event_column: str
    state_columns: tuple[str, ...]


@dataclass(frozen=True)
class AuditResult:
    provider: str
    table: str
    total_rows: int
    redundant_rows: int
    retained_rows: int
    redundant_percent: float
    table_bytes: int
    estimated_reclaimable_bytes: int
    duration_seconds: float


PROVIDERS = {
    spec.name: spec
    for spec in (
        ProviderSpec(
            name="qld",
            label="QLD",
            table="fpq_site_prices_history",
            key_columns=("site_id", "fuel_id"),
            event_column="transaction_date_utc",
            state_columns=("price", "collection_method"),
        ),
        ProviderSpec(
            name="sa",
            label="SA",
            table="sa_site_prices_history",
            key_columns=("station_id", "fuel_id"),
            event_column="transaction_date_utc",
            state_columns=("price", "collection_method"),
        ),
        ProviderSpec(
            name="nsw",
            label="NSW/TAS",
            table="nsw_site_prices_history",
            key_columns=("state", "station_code", "fuel_code"),
            event_column="last_updated_at",
            state_columns=("price",),
        ),
        ProviderSpec(
            name="vic",
            label="VIC",
            table="vic_site_prices_history",
            key_columns=("station_id", "fuel_code"),
            event_column="updated_at_utc",
            state_columns=("price", "is_available"),
        ),
        ProviderSpec(
            name="nt",
            label="NT",
            table="nt_site_prices_history",
            key_columns=("station_id", "fuel_code"),
            event_column="observed_at_utc",
            state_columns=("price", "is_available"),
        ),
    )
}

CLEANUP_PROVIDERS = ("nt", "sa", "qld", "vic")


def parse_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip()
    return values


def quoted_identifier(identifier: str) -> str:
    if re.fullmatch(r"[a-z0-9_]+", identifier, flags=re.IGNORECASE) is None:
        raise ValueError(f"Unsafe SQL identifier: {identifier}")
    return f"`{identifier}`"


def ordered_history_projection(spec: ProviderSpec, *, include_id: bool = False) -> tuple[str, str]:
    partition = ", ".join(quoted_identifier(column) for column in spec.key_columns)
    event = quoted_identifier(spec.event_column)
    order = f"{event}, `id`"
    projection = []
    if include_id:
        projection.append("`id`")
    projection.append(f"ROW_NUMBER() OVER (PARTITION BY {partition} ORDER BY {order}) AS `sequence_number`")
    state_comparisons = []

    for column in spec.state_columns:
        quoted = quoted_identifier(column)
        previous_alias = quoted_identifier(f"previous_{column}")
        projection.extend(
            [
                quoted,
                f"LAG({quoted}) OVER (PARTITION BY {partition} ORDER BY {order}) AS {previous_alias}",
            ]
        )
        state_comparisons.append(f"{quoted} <=> {previous_alias}")

    redundant_condition = " AND ".join(["`sequence_number` > 1", *state_comparisons])
    return ",\n            ".join(projection), redundant_condition


def build_audit_sql(spec: ProviderSpec) -> str:
    table = quoted_identifier(spec.table)
    projection, redundant_condition = ordered_history_projection(spec)

    return f"""
START TRANSACTION READ ONLY;
SELECT
    COUNT(*) AS `total_rows`,
    COALESCE(SUM({redundant_condition}), 0) AS `redundant_rows`,
    COALESCE(MAX(`table_stats`.`total_bytes`), 0) AS `table_bytes`
FROM (
    SELECT
            {projection}
    FROM {table}
) AS `ordered_history`
CROSS JOIN (
    SELECT (`DATA_LENGTH` + `INDEX_LENGTH`) AS `total_bytes`
    FROM `information_schema`.`TABLES`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = '{spec.table}'
) AS `table_stats`;
COMMIT;
""".strip()


def build_stage_candidates_sql(spec: ProviderSpec) -> str:
    if spec.name not in CLEANUP_PROVIDERS:
        raise ValueError(f"Cleanup is not approved for provider: {spec.name}")

    table = quoted_identifier(spec.table)
    candidate_table = quoted_identifier(CANDIDATE_TABLE)
    projection, redundant_condition = ordered_history_projection(spec, include_id=True)
    return f"""
CREATE TABLE IF NOT EXISTS {candidate_table} (
    `provider` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `row_id` BIGINT UNSIGNED NOT NULL,
    `staged_at_utc` DATETIME NOT NULL,
    PRIMARY KEY (`provider`, `row_id`)
) ENGINE=InnoDB;

DELETE FROM {candidate_table}
WHERE `provider` = '{spec.name}';

INSERT INTO {candidate_table} (`provider`, `row_id`, `staged_at_utc`)
SELECT '{spec.name}', `id`, UTC_TIMESTAMP()
FROM (
    SELECT
            {projection}
    FROM {table}
) AS `ordered_history`
WHERE {redundant_condition};

SELECT COUNT(*)
FROM {candidate_table}
WHERE `provider` = '{spec.name}';
""".strip()


def build_delete_batch_sql(spec: ProviderSpec, batch_size: int) -> str:
    if spec.name not in CLEANUP_PROVIDERS:
        raise ValueError(f"Cleanup is not approved for provider: {spec.name}")
    if batch_size < 1 or batch_size > 100_000:
        raise ValueError("batch_size must be between 1 and 100000")

    table = quoted_identifier(spec.table)
    candidate_table = quoted_identifier(CANDIDATE_TABLE)
    return f"""
SET SESSION `innodb_lock_wait_timeout` = 10;
START TRANSACTION;
CREATE TEMPORARY TABLE `tmp_fuelau_history_cleanup_batch` (
    `row_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

INSERT INTO `tmp_fuelau_history_cleanup_batch` (`row_id`)
SELECT `row_id`
FROM {candidate_table}
WHERE `provider` = '{spec.name}'
ORDER BY `row_id`
LIMIT {batch_size};
SET @fuelau_cleanup_batch_rows = ROW_COUNT();

DELETE `history`
FROM {table} AS `history`
INNER JOIN `tmp_fuelau_history_cleanup_batch` AS `batch`
    ON `batch`.`row_id` = `history`.`id`;
SET @fuelau_cleanup_deleted_rows = ROW_COUNT();

DELETE `candidate`
FROM {candidate_table} AS `candidate`
INNER JOIN `tmp_fuelau_history_cleanup_batch` AS `batch`
    ON `batch`.`row_id` = `candidate`.`row_id`
WHERE `candidate`.`provider` = '{spec.name}';

COMMIT;
SELECT @fuelau_cleanup_batch_rows, @fuelau_cleanup_deleted_rows;
""".strip()


def build_candidate_status_sql(provider_names: list[str]) -> str:
    invalid = [name for name in provider_names if name not in CLEANUP_PROVIDERS]
    if invalid:
        raise ValueError(f"Cleanup is not approved for provider: {', '.join(invalid)}")
    names = ", ".join(f"'{name}'" for name in provider_names)
    candidate_table = quoted_identifier(CANDIDATE_TABLE)
    return f"""
SELECT `provider`, COUNT(*)
FROM {candidate_table}
WHERE `provider` IN ({names})
GROUP BY `provider`
ORDER BY `provider`;
""".strip()


def verify_backup(path: Path) -> tuple[str, int]:
    if not path.is_file():
        raise ValueError(f"Backup file does not exist: {path}")
    if path.stat().st_size <= 0:
        raise ValueError(f"Backup file is empty: {path}")

    digest = hashlib.sha256()
    with path.open("rb") as compressed:
        for chunk in iter(lambda: compressed.read(1024 * 1024), b""):
            digest.update(chunk)

    required_markers = {
        b"CREATE TABLE `nt_site_prices_history`": False,
        b"CREATE TABLE `sa_site_prices_history`": False,
        b"CREATE TABLE `fpq_site_prices_history`": False,
        b"CREATE TABLE `vic_site_prices_history`": False,
        b"-- Dump completed on": False,
    }
    try:
        with gzip.open(path, "rb") as dump:
            carry = b""
            while chunk := dump.read(1024 * 1024):
                searchable = carry + chunk
                for marker in required_markers:
                    if not required_markers[marker] and marker in searchable:
                        required_markers[marker] = True
                carry = searchable[-128:]
    except (OSError, EOFError) as exc:
        raise ValueError(f"Backup gzip validation failed: {path}") from exc

    missing = [marker.decode("utf-8") for marker, found in required_markers.items() if not found]
    if missing:
        raise ValueError(f"Backup is missing required dump markers: {', '.join(missing)}")
    return digest.hexdigest(), path.stat().st_size


def parse_single_integer(output: str, label: str) -> int:
    rows = [line.strip() for line in output.splitlines() if line.strip()]
    if len(rows) != 1 or not rows[0].isdigit():
        raise ValueError(f"Expected one integer for {label}, received: {rows}")
    return int(rows[0])


def parse_delete_batch_output(output: str) -> tuple[int, int]:
    rows = [line.strip() for line in output.splitlines() if line.strip()]
    if len(rows) != 1:
        raise ValueError(f"Expected one delete result row, received {len(rows)}")
    fields = rows[0].split("\t")
    if len(fields) != 2 or any(not field.isdigit() for field in fields):
        raise ValueError(f"Invalid delete result: {rows[0]}")
    return int(fields[0]), int(fields[1])


def stage_candidates(specs: list[ProviderSpec], mysql_env_path: str) -> dict[str, int]:
    counts = {}
    for spec in specs:
        print(f"Staging {spec.label} cleanup candidates...", file=sys.stderr, flush=True)
        output = run_mysql_sql(mysql_env_path, build_stage_candidates_sql(spec), writable=True)
        counts[spec.name] = parse_single_integer(output, f"{spec.name} staged candidates")
        print(f"Staged {counts[spec.name]:,} {spec.label} candidates.", file=sys.stderr, flush=True)
    return counts


def delete_staged_candidates(
    specs: list[ProviderSpec],
    mysql_env_path: str,
    batch_size: int,
    max_batches: int | None,
) -> dict[str, int]:
    totals = {}
    batches_completed = 0
    for spec in specs:
        deleted_total = 0
        provider_batches = 0
        started_at = time.monotonic()
        while max_batches is None or batches_completed < max_batches:
            output = run_mysql_sql(
                mysql_env_path,
                build_delete_batch_sql(spec, batch_size),
                writable=True,
            )
            batch_rows, deleted_rows = parse_delete_batch_output(output)
            if batch_rows == 0:
                break
            if deleted_rows != batch_rows:
                raise RuntimeError(
                    f"{spec.label} batch selected {batch_rows} candidates but deleted {deleted_rows} rows"
                )
            deleted_total += deleted_rows
            provider_batches += 1
            batches_completed += 1
            if provider_batches == 1 or provider_batches % 10 == 0:
                elapsed = max(time.monotonic() - started_at, 0.001)
                print(
                    f"{spec.label}: deleted {deleted_total:,} rows in {provider_batches} batches "
                    f"({deleted_total / elapsed:,.0f} rows/second).",
                    file=sys.stderr,
                    flush=True,
                )
        totals[spec.name] = deleted_total
        if max_batches is not None and batches_completed >= max_batches:
            break
        print(f"{spec.label}: deletion complete ({deleted_total:,} rows).", file=sys.stderr, flush=True)
    return totals


def build_mysql_command(mysql_env_path: str, *, writable: bool = False) -> tuple[list[str], dict[str, str]]:
    config = parse_env_file(Path(mysql_env_path))
    required = ("MYSQL_HOST", "MYSQL_PORT", "MYSQL_DATABASE")
    missing = [key for key in required if not config.get(key)]
    if missing:
        raise ValueError(f"MySQL env file is missing required keys: {', '.join(missing)}")

    if writable:
        username = config.get("MYSQL_USERNAME") or ""
        password = config.get("MYSQL_PASSWORD") or ""
        if username == "" or password == "":
            raise ValueError("MySQL migration credentials are not configured")
    else:
        username, password = sync_mysql_credentials(config)
    command = [
        "mysql",
        f"--host={config['MYSQL_HOST']}",
        f"--port={config['MYSQL_PORT']}",
        f"--user={username}",
        f"--default-character-set={config.get('MYSQL_CHARSET', 'utf8mb4')}",
        "--protocol=TCP",
        "--batch",
        "--skip-column-names",
        "--raw",
        config["MYSQL_DATABASE"],
    ]
    env = os.environ.copy()
    env["MYSQL_PWD"] = password
    return command, env


def run_mysql_sql(mysql_env_path: str, sql: str, *, writable: bool = False) -> str:
    command, env = build_mysql_command(mysql_env_path, writable=writable)
    result = subprocess.run(command, input=sql, text=True, env=env, capture_output=True, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "mysql exited with a non-zero status")
    return result.stdout


def parse_audit_output(spec: ProviderSpec, output: str, duration_seconds: float) -> AuditResult:
    rows = [line for line in output.splitlines() if line.strip()]
    if len(rows) != 1:
        raise ValueError(f"Expected one audit row for {spec.name}, received {len(rows)}")

    fields = rows[0].split("\t")
    if len(fields) != 3:
        raise ValueError(f"Expected three audit fields for {spec.name}, received {len(fields)}")

    total_rows, redundant_rows, table_bytes = (int(field) for field in fields)
    if total_rows < 0 or redundant_rows < 0 or redundant_rows > total_rows or table_bytes < 0:
        raise ValueError(f"Invalid audit values for {spec.name}: {fields}")

    redundant_percent = (redundant_rows / total_rows * 100.0) if total_rows else 0.0
    estimated_reclaimable_bytes = round(table_bytes * redundant_rows / total_rows) if total_rows else 0
    return AuditResult(
        provider=spec.label,
        table=spec.table,
        total_rows=total_rows,
        redundant_rows=redundant_rows,
        retained_rows=total_rows - redundant_rows,
        redundant_percent=redundant_percent,
        table_bytes=table_bytes,
        estimated_reclaimable_bytes=estimated_reclaimable_bytes,
        duration_seconds=duration_seconds,
    )


def audit_provider(
    spec: ProviderSpec,
    mysql_env_path: str,
    sql_runner: Callable[[str, str], str] = run_mysql_sql,
) -> AuditResult:
    started_at = time.monotonic()
    output = sql_runner(mysql_env_path, build_audit_sql(spec))
    return parse_audit_output(spec, output, time.monotonic() - started_at)


def format_mib(value: int) -> str:
    return f"{value / 1024 / 1024:,.1f}"


def print_text_report(results: list[AuditResult]) -> None:
    headings = ("Provider", "Total rows", "Candidates", "Keep", "Redundant", "Table MiB", "Est. MiB")
    rows = [
        (
            result.provider,
            f"{result.total_rows:,}",
            f"{result.redundant_rows:,}",
            f"{result.retained_rows:,}",
            f"{result.redundant_percent:.1f}%",
            format_mib(result.table_bytes),
            format_mib(result.estimated_reclaimable_bytes),
        )
        for result in results
    ]
    widths = [
        max(len(headings[index]), *(len(row[index]) for row in rows))
        for index in range(len(headings))
    ]

    print("FuelAU historical cleanup audit (read-only)")
    print("  ".join(heading.ljust(widths[index]) for index, heading in enumerate(headings)))
    print("  ".join("-" * width for width in widths))
    for row in rows:
        print("  ".join(value.ljust(widths[index]) for index, value in enumerate(row)))

    total_rows = sum(result.total_rows for result in results)
    redundant_rows = sum(result.redundant_rows for result in results)
    retained_rows = total_rows - redundant_rows
    table_bytes = sum(result.table_bytes for result in results)
    reclaimable_bytes = sum(result.estimated_reclaimable_bytes for result in results)
    percent = redundant_rows / total_rows * 100.0 if total_rows else 0.0
    print()
    print(
        f"Total: {redundant_rows:,} candidate rows out of {total_rows:,} "
        f"({percent:.1f}%); retain {retained_rows:,}."
    )
    print(
        f"Estimated logical reduction: {format_mib(reclaimable_bytes)} MiB "
        f"across {format_mib(table_bytes)} MiB of table and index data."
    )
    print("WA is excluded because FuelAU intentionally preserves one observation per station/fuel/day.")
    print("No rows were changed. Filesystem space is not returned unless tables are rebuilt separately.")


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Audit or safely remove redundant consecutive FuelAU history observations.",
    )
    parser.add_argument(
        "action",
        nargs="?",
        choices=("audit", "stage-cleanup", "delete-cleanup", "cleanup-status"),
        default="audit",
        help="Operation to perform. The default audit action is read-only.",
    )
    parser.add_argument(
        "--mysql-env",
        default=DEFAULT_MYSQL_ENV_PATH,
        help="Path to the MySQL env file.",
    )
    parser.add_argument(
        "--provider",
        action="append",
        choices=tuple(PROVIDERS),
        help="Provider to audit; repeat for several. Defaults to all change-aware providers.",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Emit machine-readable JSON after the audit completes.",
    )
    parser.add_argument(
        "--backup",
        help="Verified pre-cleanup .sql.gz backup required for staging or deletion.",
    )
    parser.add_argument(
        "--batch-size",
        type=int,
        default=50_000,
        help="Rows per delete transaction (1-100000; default 50000).",
    )
    parser.add_argument(
        "--max-batches",
        type=int,
        help="Stop after this many batches so deletion can be deliberately paced.",
    )
    parser.add_argument(
        "--confirm-delete",
        help=f"Required deletion confirmation phrase: {DELETE_CONFIRMATION}",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    provider_names = list(dict.fromkeys(args.provider or list(PROVIDERS)))
    specs = [PROVIDERS[name] for name in provider_names]

    if args.action == "audit":
        results = []
        for spec in specs:
            if not args.json:
                print(f"Auditing {spec.label} ({spec.table})...", file=sys.stderr, flush=True)
            results.append(audit_provider(spec, args.mysql_env))

        if args.json:
            print(json.dumps([asdict(result) for result in results], indent=2))
        else:
            print_text_report(results)
        return 0

    invalid = [name for name in provider_names if name not in CLEANUP_PROVIDERS]
    if invalid:
        raise SystemExit(f"Cleanup is not approved for provider(s): {', '.join(invalid)}")
    if not args.provider:
        raise SystemExit("Cleanup actions require one or more explicit --provider options")

    if args.action == "cleanup-status":
        output = run_mysql_sql(args.mysql_env, build_candidate_status_sql(provider_names), writable=True)
        print(output, end="")
        return 0

    if not args.backup:
        raise SystemExit(f"{args.action} requires --backup")
    backup_path = Path(args.backup)
    backup_sha256, backup_bytes = verify_backup(backup_path)
    print(
        f"Verified backup {backup_path} ({format_mib(backup_bytes)} MiB, sha256={backup_sha256}).",
        file=sys.stderr,
        flush=True,
    )

    if args.action == "stage-cleanup":
        counts = stage_candidates(specs, args.mysql_env)
        for provider_name, count in counts.items():
            print(f"{provider_name}\t{count}")
        return 0

    if args.confirm_delete != DELETE_CONFIRMATION:
        raise SystemExit(f"delete-cleanup requires --confirm-delete '{DELETE_CONFIRMATION}'")
    if args.max_batches is not None and args.max_batches < 1:
        raise SystemExit("--max-batches must be positive")

    totals = delete_staged_candidates(
        specs,
        args.mysql_env,
        args.batch_size,
        args.max_batches,
    )
    for provider_name, count in totals.items():
        print(f"{provider_name}\t{count}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
