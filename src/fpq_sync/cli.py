from __future__ import annotations

import argparse
import json
import os
import subprocess
import uuid
from dataclasses import dataclass
from datetime import UTC
from datetime import datetime
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request
from urllib.request import urlopen


DEFAULT_MYSQL_ENV_PATH = "/etc/fuelapi/mysql.env"
DEFAULT_APP_ENV_PATH = "/etc/fuelapi/app.env"
DEFAULT_API_HOST = "https://fppdirectapi-prod.fuelpricesqld.com.au"
USER_AGENT = "fuel-fpq-sync/0.1"
MYSQL_INSERT_BATCH_SIZE = 500


@dataclass(frozen=True)
class SyncResult:
    job_name: str
    rows_processed: int
    message: str


@dataclass(frozen=True)
class DiagnosticProbe:
    label: str
    path: str
    wrapper_key: str


@dataclass(frozen=True)
class AnalysisResult:
    batch_id: str
    staged_sites: int
    staged_prices: int
    distinct_price_sites: int
    matched_price_sites: int
    missing_price_sites: int
    distinct_price_fuels: int
    matched_price_fuels: int
    duplicate_price_keys: int


def parse_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    content = path.read_text(encoding="utf-8")
    for raw_line in content.splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip()
    return values


def mysql_escape(value: object | None) -> str:
    if value is None:
        return "NULL"
    return "'" + str(value).replace("\\", "\\\\").replace("'", "''") + "'"


def build_mysql_command(mysql_env_path: str) -> tuple[list[str], dict[str, str]]:
    config = parse_env_file(Path(mysql_env_path))
    required = ("MYSQL_HOST", "MYSQL_PORT", "MYSQL_DATABASE", "MYSQL_USERNAME", "MYSQL_PASSWORD")
    missing = [key for key in required if not config.get(key)]
    if missing:
        raise ValueError(f"MySQL env file is missing required keys: {', '.join(missing)}")

    command = [
        "mysql",
        f"--host={config['MYSQL_HOST']}",
        f"--port={config['MYSQL_PORT']}",
        f"--user={config['MYSQL_USERNAME']}",
        f"--default-character-set={config.get('MYSQL_CHARSET', 'utf8mb4')}",
        "--protocol=TCP",
        config["MYSQL_DATABASE"],
    ]
    env = os.environ.copy()
    env["MYSQL_PWD"] = config["MYSQL_PASSWORD"]
    return command, env


def run_mysql_sql(mysql_env_path: str, sql: str) -> None:
    command, env = build_mysql_command(mysql_env_path)
    result = subprocess.run(command, input=sql, text=True, env=env, capture_output=True, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "mysql exited with a non-zero status")


def chunk_items(items: list[dict[str, object]], batch_size: int) -> list[list[dict[str, object]]]:
    if batch_size <= 0:
        raise ValueError("batch_size must be positive")
    return [items[index:index + batch_size] for index in range(0, len(items), batch_size)]


def run_batched_sql(
    mysql_env_path: str,
    items: list[dict[str, object]],
    sql_builder,
    batch_size: int = MYSQL_INSERT_BATCH_SIZE,
) -> None:
    for batch in chunk_items(items, batch_size):
        sql = sql_builder(batch)
        if sql:
            run_mysql_sql(mysql_env_path, sql)


def fetch_json(api_host: str, token: str, path: str) -> dict[str, object]:
    request = Request(
        f"{api_host}{path}",
        headers={
            "Authorization": f"FPDAPI SubscriberToken={token}",
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        },
    )
    try:
        with urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code} for {path}: {body[:500]}") from exc


def log_sync_run(mysql_env_path: str, job_name: str, status: str, rows_processed: int, message: str) -> None:
    now = datetime.now(UTC).replace(microsecond=0).isoformat().replace("+00:00", "")
    sql = f"""
INSERT INTO `fpq_sync_runs` (
    `job_name`,
    `started_at_utc`,
    `finished_at_utc`,
    `status`,
    `rows_processed`,
    `message`
)
VALUES (
    {mysql_escape(job_name)},
    {mysql_escape(now)},
    {mysql_escape(now)},
    {mysql_escape(status)},
    {rows_processed},
    {mysql_escape(message[:65535])}
);
""".strip()
    run_mysql_sql(mysql_env_path, sql)


def build_brands_sql(brands: list[dict[str, object]]) -> str:
    if not brands:
        return ""
    values = [
        "(" + ", ".join([mysql_escape(item.get("BrandId")), mysql_escape(item.get("Name"))]) + ")"
        for item in brands
    ]
    return f"""
INSERT INTO `fpq_brands` (`brand_id`, `name`)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);
""".strip()


def build_regions_sql(regions: list[dict[str, object]]) -> str:
    if not regions:
        return ""
    values = [
        "("
        + ", ".join(
            [
                mysql_escape(item.get("GeoRegionLevel")),
                mysql_escape(item.get("GeoRegionId")),
                mysql_escape(item.get("Name")),
                mysql_escape(item.get("Abbrev")),
                mysql_escape(item.get("GeoRegionParentId")),
            ]
        )
        + ")"
        for item in regions
    ]
    return f"""
INSERT INTO `fpq_geographic_regions` (
    `geo_region_level`,
    `geo_region_id`,
    `name`,
    `abbrev`,
    `geo_region_parent_id`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `abbrev` = VALUES(`abbrev`),
    `geo_region_parent_id` = VALUES(`geo_region_parent_id`);
""".strip()


def build_fuels_sql(fuels: list[dict[str, object]]) -> str:
    if not fuels:
        return ""
    values = [
        "(" + ", ".join([mysql_escape(item.get("FuelId")), mysql_escape(item.get("Name"))]) + ")"
        for item in fuels
    ]
    return f"""
INSERT INTO `fpq_fuel_types` (`fuel_id`, `name`)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);
""".strip()


def build_sites_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("S")),
                    mysql_escape(item.get("N")),
                    mysql_escape(item.get("A")),
                    mysql_escape(item.get("B")),
                    mysql_escape(item.get("P")),
                    mysql_escape(item.get("G1")),
                    mysql_escape(item.get("G2")),
                    mysql_escape(item.get("G3")),
                    mysql_escape(item.get("G4")),
                    mysql_escape(item.get("G5")),
                    mysql_escape(item.get("Lat")),
                    mysql_escape(item.get("Lng")),
                    mysql_escape(item.get("M")),
                    mysql_escape(item.get("GPI")),
                ]
            )
            + ")"
        )

    return f"""
INSERT INTO `fpq_sites` (
    `site_id`,
    `name`,
    `address`,
    `brand_id`,
    `postcode`,
    `geo_region_level_1_id`,
    `geo_region_level_2_id`,
    `geo_region_level_3_id`,
    `geo_region_level_4_id`,
    `geo_region_level_5_id`,
    `latitude`,
    `longitude`,
    `last_modified_at`,
    `google_place_id`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `address` = VALUES(`address`),
    `brand_id` = VALUES(`brand_id`),
    `postcode` = VALUES(`postcode`),
    `geo_region_level_1_id` = VALUES(`geo_region_level_1_id`),
    `geo_region_level_2_id` = VALUES(`geo_region_level_2_id`),
    `geo_region_level_3_id` = VALUES(`geo_region_level_3_id`),
    `geo_region_level_4_id` = VALUES(`geo_region_level_4_id`),
    `geo_region_level_5_id` = VALUES(`geo_region_level_5_id`),
    `latitude` = VALUES(`latitude`),
    `longitude` = VALUES(`longitude`),
    `last_modified_at` = VALUES(`last_modified_at`),
    `google_place_id` = VALUES(`google_place_id`);
""".strip()


def build_stage_sites_sql(sync_batch_id: str, items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(sync_batch_id),
                    mysql_escape(item.get("S")),
                    mysql_escape(item.get("N")),
                    mysql_escape(item.get("A")),
                    mysql_escape(item.get("B")),
                    mysql_escape(item.get("P")),
                    mysql_escape(item.get("G1")),
                    mysql_escape(item.get("G2")),
                    mysql_escape(item.get("G3")),
                    mysql_escape(item.get("G4")),
                    mysql_escape(item.get("G5")),
                    mysql_escape(item.get("Lat")),
                    mysql_escape(item.get("Lng")),
                    mysql_escape(item.get("M")),
                    mysql_escape(item.get("GPI")),
                    mysql_escape(json.dumps(item, ensure_ascii=True, sort_keys=True)),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `fpq_stage_sites` (
    `sync_batch_id`,
    `site_id`,
    `name`,
    `address`,
    `brand_id`,
    `postcode`,
    `geo_region_level_1_id`,
    `geo_region_level_2_id`,
    `geo_region_level_3_id`,
    `geo_region_level_4_id`,
    `geo_region_level_5_id`,
    `latitude`,
    `longitude`,
    `last_modified_at`,
    `google_place_id`,
    `raw_payload`
)
VALUES
{",\n".join(values)};
""".strip()


def build_prices_history_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("SiteId")),
                    mysql_escape(item.get("FuelId")),
                    mysql_escape(item.get("CollectionMethod")),
                    mysql_escape(item.get("TransactionDateUtc")),
                    mysql_escape(item.get("Price")),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `fpq_site_prices_history` (
    `site_id`,
    `fuel_id`,
    `collection_method`,
    `transaction_date_utc`,
    `price`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `collection_method` = VALUES(`collection_method`),
    `price` = VALUES(`price`);
""".strip()


def build_stage_prices_sql(sync_batch_id: str, items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(sync_batch_id),
                    mysql_escape(item.get("SiteId")),
                    mysql_escape(item.get("FuelId")),
                    mysql_escape(item.get("CollectionMethod")),
                    mysql_escape(item.get("TransactionDateUtc")),
                    mysql_escape(item.get("Price")),
                    mysql_escape(json.dumps(item, ensure_ascii=True, sort_keys=True)),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `fpq_stage_prices` (
    `sync_batch_id`,
    `site_id`,
    `fuel_id`,
    `collection_method`,
    `transaction_date_utc`,
    `price`,
    `raw_payload`
)
VALUES
{",\n".join(values)};
""".strip()


def build_prices_current_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    latest_by_key: dict[tuple[object, object], dict[str, object]] = {}
    for item in items:
        key = (item.get("SiteId"), item.get("FuelId"))
        existing = latest_by_key.get(key)
        if existing is None or str(item.get("TransactionDateUtc", "")) >= str(existing.get("TransactionDateUtc", "")):
            latest_by_key[key] = item

    values = []
    for item in latest_by_key.values():
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("SiteId")),
                    mysql_escape(item.get("FuelId")),
                    mysql_escape(item.get("CollectionMethod")),
                    mysql_escape(item.get("TransactionDateUtc")),
                    mysql_escape(item.get("Price")),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `fpq_site_prices_current` (
    `site_id`,
    `fuel_id`,
    `collection_method`,
    `transaction_date_utc`,
    `price`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `collection_method` = VALUES(`collection_method`),
    `transaction_date_utc` = VALUES(`transaction_date_utc`),
    `price` = VALUES(`price`);
""".strip()


def sync_daily_reference(mysql_env_path: str, api_host: str, token: str) -> SyncResult:
    brands_payload = fetch_json(api_host, token, "/Subscriber/GetCountryBrands?countryId=21")
    regions_payload = fetch_json(api_host, token, "/Subscriber/GetCountryGeographicRegions?countryId=21")
    fuels_payload = fetch_json(api_host, token, "/Subscriber/GetCountryFuelTypes?countryId=21")
    sites_payload = fetch_json(
        api_host,
        token,
        "/Subscriber/GetFullSiteDetails?countryId=21&geoRegionLevel=3&geoRegionId=1",
    )

    brands = list(brands_payload.get("Brands", []))
    regions = list(regions_payload.get("GeographicRegions", []))
    fuels = list(fuels_payload.get("Fuels", []))
    sites = list(sites_payload.get("S", []))

    sql_chunks = [build_brands_sql(brands), build_regions_sql(regions), build_fuels_sql(fuels), build_sites_sql(sites)]
    sql = "\n\n".join(chunk for chunk in sql_chunks if chunk)
    if sql:
        run_mysql_sql(mysql_env_path, sql)

    total_rows = len(brands) + len(regions) + len(fuels) + len(sites)
    message = (
        f"brands={len(brands)}, regions={len(regions)}, fuels={len(fuels)}, sites={len(sites)}"
    )
    return SyncResult(job_name="fpq_daily_reference", rows_processed=total_rows, message=message)


def sync_prices(mysql_env_path: str, api_host: str, token: str) -> SyncResult:
    prices_payload = fetch_json(
        api_host,
        token,
        "/Price/GetSitesPrices?countryId=21&geoRegionLevel=3&geoRegionId=1",
    )
    prices = list(prices_payload.get("SitePrices", []))
    run_batched_sql(mysql_env_path, prices, build_prices_history_sql)
    current_sql = build_prices_current_sql(prices)
    if current_sql:
        run_mysql_sql(mysql_env_path, current_sql)

    message = f"prices={len(prices)}"
    return SyncResult(job_name="fpq_prices", rows_processed=len(prices), message=message)


def stage_live_data(mysql_env_path: str, api_host: str, token: str) -> tuple[str, int, int]:
    sync_batch_id = str(uuid.uuid4())
    sites_payload = fetch_json(
        api_host,
        token,
        "/Subscriber/GetFullSiteDetails?countryId=21&geoRegionLevel=3&geoRegionId=1",
    )
    prices_payload = fetch_json(
        api_host,
        token,
        "/Price/GetSitesPrices?countryId=21&geoRegionLevel=3&geoRegionId=1",
    )

    sites = list(sites_payload.get("S", []))
    prices = list(prices_payload.get("SitePrices", []))
    sql_chunks = [build_stage_sites_sql(sync_batch_id, sites), build_stage_prices_sql(sync_batch_id, prices)]
    sql = "\n\n".join(chunk for chunk in sql_chunks if chunk)
    if sql:
        run_mysql_sql(mysql_env_path, sql)
    return sync_batch_id, len(sites), len(prices)


def query_mysql_rows(mysql_env_path: str, sql: str) -> list[dict[str, str]]:
    command, env = build_mysql_command(mysql_env_path)
    command.append("--batch")
    command.append("--raw")
    command.append("--skip-column-names")
    result = subprocess.run(command, input=sql, text=True, env=env, capture_output=True, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "mysql exited with a non-zero status")
    rows: list[dict[str, str]] = []
    for line in result.stdout.splitlines():
        parts = line.split("\t")
        rows.append({"value": parts[0] if parts else ""})
    return rows


def analyze_staged_batch(mysql_env_path: str, batch_id: str) -> AnalysisResult:
    metrics_sql = f"""
SELECT COUNT(*) FROM fpq_stage_sites WHERE sync_batch_id = {mysql_escape(batch_id)};
SELECT COUNT(*) FROM fpq_stage_prices WHERE sync_batch_id = {mysql_escape(batch_id)};
SELECT COUNT(DISTINCT site_id) FROM fpq_stage_prices WHERE sync_batch_id = {mysql_escape(batch_id)};
SELECT COUNT(DISTINCT p.site_id)
FROM fpq_stage_prices p
INNER JOIN fpq_stage_sites s
    ON s.sync_batch_id = {mysql_escape(batch_id)}
   AND s.site_id = p.site_id
WHERE p.sync_batch_id = {mysql_escape(batch_id)};
SELECT COUNT(*) FROM (
    SELECT p.site_id
    FROM fpq_stage_prices p
    LEFT JOIN fpq_stage_sites s
        ON s.sync_batch_id = {mysql_escape(batch_id)}
       AND s.site_id = p.site_id
    WHERE p.sync_batch_id = {mysql_escape(batch_id)}
      AND s.site_id IS NULL
    GROUP BY p.site_id
) AS missing_sites;
SELECT COUNT(DISTINCT fuel_id) FROM fpq_stage_prices WHERE sync_batch_id = {mysql_escape(batch_id)};
SELECT COUNT(DISTINCT p.fuel_id)
FROM fpq_stage_prices p
INNER JOIN fpq_fuel_types f ON f.fuel_id = p.fuel_id
WHERE p.sync_batch_id = {mysql_escape(batch_id)};
SELECT COUNT(*) FROM (
    SELECT site_id, fuel_id, transaction_date_utc, COUNT(*) AS c
    FROM fpq_stage_prices
    WHERE sync_batch_id = {mysql_escape(batch_id)}
    GROUP BY site_id, fuel_id, transaction_date_utc
    HAVING COUNT(*) > 1
) AS duplicate_keys;
""".strip()

    rows = query_mysql_rows(mysql_env_path, metrics_sql)
    values = [int(row["value"] or "0") for row in rows]
    return AnalysisResult(
        batch_id=batch_id,
        staged_sites=values[0],
        staged_prices=values[1],
        distinct_price_sites=values[2],
        matched_price_sites=values[3],
        missing_price_sites=values[4],
        distinct_price_fuels=values[5],
        matched_price_fuels=values[6],
        duplicate_price_keys=values[7],
    )


def sample_missing_site_ids(mysql_env_path: str, batch_id: str, limit: int = 20) -> list[str]:
    sql = f"""
SELECT DISTINCT p.site_id
FROM fpq_stage_prices p
LEFT JOIN fpq_stage_sites s
    ON s.sync_batch_id = {mysql_escape(batch_id)}
   AND s.site_id = p.site_id
WHERE p.sync_batch_id = {mysql_escape(batch_id)}
  AND s.site_id IS NULL
ORDER BY p.site_id
LIMIT {limit};
""".strip()
    return [row["value"] for row in query_mysql_rows(mysql_env_path, sql)]


def run_diagnostics(api_host: str, token: str) -> list[tuple[str, int, str]]:
    probes = [
        DiagnosticProbe(
            label="sites_qld_state",
            path="/Subscriber/GetFullSiteDetails?countryId=21&geoRegionLevel=3&geoRegionId=1",
            wrapper_key="S",
        ),
        DiagnosticProbe(
            label="prices_qld_state",
            path="/Price/GetSitesPrices?countryId=21&geoRegionLevel=3&geoRegionId=1",
            wrapper_key="SitePrices",
        ),
        DiagnosticProbe(
            label="sites_brisbane_city",
            path="/Subscriber/GetFullSiteDetails?countryId=21&geoRegionLevel=2&geoRegionId=1",
            wrapper_key="S",
        ),
        DiagnosticProbe(
            label="prices_brisbane_city",
            path="/Price/GetSitesPrices?countryId=21&geoRegionLevel=2&geoRegionId=1",
            wrapper_key="SitePrices",
        ),
        DiagnosticProbe(
            label="sites_brisbane_suburb",
            path="/Subscriber/GetFullSiteDetails?countryId=21&geoRegionLevel=1&geoRegionId=147",
            wrapper_key="S",
        ),
        DiagnosticProbe(
            label="prices_brisbane_suburb",
            path="/Price/GetSitesPrices?countryId=21&geoRegionLevel=1&geoRegionId=147",
            wrapper_key="SitePrices",
        ),
    ]

    results: list[tuple[str, int, str]] = []
    for probe in probes:
        try:
            payload = fetch_json(api_host, token, probe.path)
            count = len(list(payload.get(probe.wrapper_key, [])))
            results.append((probe.label, count, "ok"))
        except Exception as exc:
            results.append((probe.label, -1, str(exc)))
    return results


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Sync Fuel Prices QLD Direct API data into MySQL.")
    parser.add_argument(
        "job",
        choices=("daily-reference", "prices", "all", "diagnose", "stage", "analyze"),
        help="Which sync job to run.",
    )
    parser.add_argument(
        "--mysql-env",
        default=DEFAULT_MYSQL_ENV_PATH,
        help="Path to the MySQL env file.",
    )
    parser.add_argument(
        "--app-env",
        default=DEFAULT_APP_ENV_PATH,
        help="Path to the app env file.",
    )
    parser.add_argument(
        "--api-host",
        default=DEFAULT_API_HOST,
        help="Fuel Prices QLD API host.",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or os.sys.argv[1:])
    config = parse_env_file(Path(args.app_env))
    token = config.get("FUEL_PRICES_QLD_SUBSCRIBER_TOKEN")
    if not token:
        print("error: missing FUEL_PRICES_QLD_SUBSCRIBER_TOKEN in app env file", file=os.sys.stderr)
        return 1

    try:
        results: list[SyncResult] = []
        if args.job == "diagnose":
            diagnostics = run_diagnostics(args.api_host, token)
            for label, count, message in diagnostics:
                if count >= 0:
                    print(f"{label}: count={count}")
                else:
                    print(f"{label}: error={message}")
            return 0
        if args.job in ("stage", "analyze"):
            batch_id, staged_sites, staged_prices = stage_live_data(args.mysql_env, args.api_host, token)
            analysis = analyze_staged_batch(args.mysql_env, batch_id)
            missing_site_ids = sample_missing_site_ids(args.mysql_env, batch_id)
            summary = {
                "batch_id": analysis.batch_id,
                "staged_sites": analysis.staged_sites,
                "staged_prices": analysis.staged_prices,
                "distinct_price_sites": analysis.distinct_price_sites,
                "matched_price_sites": analysis.matched_price_sites,
                "missing_price_sites": analysis.missing_price_sites,
                "distinct_price_fuels": analysis.distinct_price_fuels,
                "matched_price_fuels": analysis.matched_price_fuels,
                "duplicate_price_keys": analysis.duplicate_price_keys,
                "sample_missing_site_ids": missing_site_ids,
            }
            print(json.dumps(summary, indent=2))
            return 0
        if args.job in ("daily-reference", "all"):
            results.append(sync_daily_reference(args.mysql_env, args.api_host, token))
        if args.job in ("prices", "all"):
            results.append(sync_prices(args.mysql_env, args.api_host, token))
    except Exception as exc:
        try:
            log_sync_run(args.mysql_env, f"fpq_{args.job.replace('-', '_')}", "error", 0, str(exc))
        except Exception:
            pass
        print(f"error: {exc}", file=os.sys.stderr)
        return 1

    for result in results:
        try:
            log_sync_run(args.mysql_env, result.job_name, "success", result.rows_processed, result.message)
        except Exception:
            pass
        print(f"{result.job_name}: {result.message}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
