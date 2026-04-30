from __future__ import annotations

import argparse
import json
import os
import subprocess
from dataclasses import dataclass
from datetime import UTC
from datetime import datetime
from datetime import timedelta
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request
from urllib.request import urlopen


DEFAULT_MYSQL_ENV_PATH = "/etc/fuelapi/mysql.env"
DEFAULT_APP_ENV_PATH = "/etc/fuelapi/app.env"
DEFAULT_API_BASE_URL = "https://fppdirectapi-prod.safuelpricinginformation.com.au"
DEFAULT_COUNTRY_ID = 21
DEFAULT_STATE_GEO_REGION_LEVEL = 3
DEFAULT_STATE_GEO_REGION_ID = 4
USER_AGENT = "fuelau-sa-sync/0.1"
MYSQL_INSERT_BATCH_SIZE = 500


@dataclass(frozen=True)
class SyncResult:
    job_name: str
    rows_processed: int
    message: str


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


def query_mysql_values(mysql_env_path: str, sql: str) -> list[str]:
    command, env = build_mysql_command(mysql_env_path)
    command.extend(["--batch", "--raw", "--skip-column-names"])
    result = subprocess.run(command, input=sql, text=True, env=env, capture_output=True, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "mysql exited with a non-zero status")
    return [line.split("\t")[0] if line else "" for line in result.stdout.splitlines()]


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


def required_app_config(config: dict[str, str], key: str) -> str:
    value = config.get(key, "").strip()
    if value == "":
        raise ValueError(f"app env file is missing required key: {key}")
    return value


def parse_sa_datetime(value: object | None) -> str | None:
    text = str(value or "").strip()
    if text == "":
        return None
    parsed = datetime.fromisoformat(text.replace("Z", "+00:00"))
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=UTC)
    return parsed.astimezone(UTC).replace(tzinfo=None).isoformat(sep=" ")


def fetch_json(api_base_url: str, token: str, path: str) -> object:
    request = Request(
        api_base_url.rstrip("/") + path,
        headers={
            "Authorization": f"FPDAPI SubscriberToken={token}",
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        },
    )
    try:
        with urlopen(request, timeout=180) as response:
            return json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code} for {path}: {body[:500]}") from exc


def api_path(path: str, params: dict[str, object]) -> str:
    query = urlencode({key: value for key, value in params.items() if value is not None})
    return f"{path}?{query}" if query else path


def extract_records(payload: object) -> list[dict[str, object]]:
    if isinstance(payload, list):
        return [item for item in payload if isinstance(item, dict)]
    if not isinstance(payload, dict):
        return []

    wrapper_keys = (
        "Brands",
        "Brand",
        "GeographicRegions",
        "GeographicRegion",
        "Fuels",
        "FuelTypes",
        "FuelType",
        "SitePrices",
        "Prices",
        "S",
    )
    for key in wrapper_keys:
        value = payload.get(key)
        if isinstance(value, list):
            return [item for item in value if isinstance(item, dict)]
        if isinstance(value, dict):
            return [value]

    if any(not isinstance(value, (list, dict)) for value in payload.values()):
        return [payload]
    return []


def log_sync_run(mysql_env_path: str, job_name: str, status: str, rows_processed: int, message: str) -> None:
    now = datetime.now(UTC).replace(microsecond=0).isoformat().replace("+00:00", "")
    sql = f"""
INSERT INTO `sa_sync_runs` (
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


def latest_success_value(mysql_env_path: str, job_name: str) -> str | None:
    sql = f"""
SELECT DATE_FORMAT(MAX(finished_at_utc), '%Y-%m-%d %H:%i:%s')
FROM sa_sync_runs
WHERE job_name = {mysql_escape(job_name)}
  AND status = 'success';
""".strip()
    values = query_mysql_values(mysql_env_path, sql)
    if not values:
        return None
    value = values[0].strip()
    if value.upper() == "NULL":
        return None
    return value or None


def should_refresh_reference(mysql_env_path: str) -> bool:
    reference_counts = [
        int(query_mysql_values(mysql_env_path, "SELECT COUNT(*) FROM sa_brands;")[0] or 0),
        int(query_mysql_values(mysql_env_path, "SELECT COUNT(*) FROM sa_geographic_regions;")[0] or 0),
        int(query_mysql_values(mysql_env_path, "SELECT COUNT(*) FROM sa_fuel_types;")[0] or 0),
        int(query_mysql_values(mysql_env_path, "SELECT COUNT(*) FROM sa_stations;")[0] or 0),
    ]
    if any(count <= 0 for count in reference_counts):
        return True

    latest = latest_success_value(mysql_env_path, "sa_reference")
    if latest is None:
        return True
    try:
        latest_dt = datetime.strptime(latest, "%Y-%m-%d %H:%M:%S").replace(tzinfo=UTC)
    except ValueError:
        return True
    return latest_dt <= datetime.now(UTC) - timedelta(hours=24)


def build_brands_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = [
        "(" + ", ".join([mysql_escape(item.get("BrandId")), mysql_escape(item.get("Name"))]) + ")"
        for item in items
    ]
    return f"""
INSERT INTO `sa_brands` (`brand_id`, `name`)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);
""".strip()


def build_regions_sql(items: list[dict[str, object]]) -> str:
    if not items:
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
        for item in items
    ]
    return f"""
INSERT INTO `sa_geographic_regions` (
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


def build_fuel_types_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = [
        "(" + ", ".join([mysql_escape(item.get("FuelId")), mysql_escape(item.get("Name"))]) + ")"
        for item in items
    ]
    return f"""
INSERT INTO `sa_fuel_types` (`fuel_id`, `name`)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);
""".strip()


def build_stations_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("S")),
                    mysql_escape(item.get("B")),
                    mysql_escape(item.get("N")),
                    mysql_escape(item.get("A")),
                    mysql_escape(item.get("P")),
                    mysql_escape(item.get("G1")),
                    mysql_escape(item.get("G2")),
                    mysql_escape(item.get("G3")),
                    mysql_escape(item.get("G4")),
                    mysql_escape(item.get("G5")),
                    mysql_escape(item.get("Lat")),
                    mysql_escape(item.get("Lng")),
                    mysql_escape(parse_sa_datetime(item.get("M"))),
                    mysql_escape(item.get("GPI")),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `sa_stations` (
    `station_id`,
    `brand_id`,
    `name`,
    `address`,
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
    `brand_id` = VALUES(`brand_id`),
    `name` = VALUES(`name`),
    `address` = VALUES(`address`),
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


def build_prices_history_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        transaction_date = parse_sa_datetime(item.get("TransactionDateUtc"))
        if transaction_date is None:
            continue
        try:
            price_value = float(item.get("Price"))
        except (TypeError, ValueError):
            continue
        if price_value >= 9999:
            continue
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("SiteId")),
                    mysql_escape(item.get("FuelId")),
                    mysql_escape(item.get("CollectionMethod") or "T"),
                    mysql_escape(transaction_date),
                    mysql_escape(price_value),
                ]
            )
            + ")"
        )
    if not values:
        return ""
    return f"""
INSERT INTO `sa_site_prices_history` (
    `station_id`,
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


def build_prices_current_sql(items: list[dict[str, object]]) -> str:
    values = []
    for item in items:
        transaction_date = parse_sa_datetime(item.get("TransactionDateUtc"))
        if transaction_date is None:
            continue
        try:
            price_value = float(item.get("Price"))
        except (TypeError, ValueError):
            continue
        if price_value >= 9999:
            continue
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("SiteId")),
                    mysql_escape(item.get("FuelId")),
                    mysql_escape(item.get("CollectionMethod") or "T"),
                    mysql_escape(transaction_date),
                    mysql_escape(price_value),
                ]
            )
            + ")"
        )
    if not values:
        return "START TRANSACTION; DELETE FROM `sa_site_prices_current`; COMMIT;"
    return f"""
START TRANSACTION;
DELETE FROM `sa_site_prices_current`;
INSERT INTO `sa_site_prices_current` (
    `station_id`,
    `fuel_id`,
    `collection_method`,
    `transaction_date_utc`,
    `price`
)
VALUES
{",\n".join(values)};
COMMIT;
""".strip()


def fetch_reference_payloads(api_base_url: str, token: str) -> tuple[list[dict[str, object]], list[dict[str, object]], list[dict[str, object]], list[dict[str, object]]]:
    brands_payload = fetch_json(
        api_base_url,
        token,
        api_path("/Subscriber/GetCountryBrands", {"countryId": DEFAULT_COUNTRY_ID}),
    )
    regions_payload = fetch_json(
        api_base_url,
        token,
        api_path("/Subscriber/GetCountryGeographicRegions", {"countryId": DEFAULT_COUNTRY_ID}),
    )
    fuel_types_payload = fetch_json(
        api_base_url,
        token,
        api_path("/Subscriber/GetCountryFuelTypes", {"countryId": DEFAULT_COUNTRY_ID}),
    )
    brands = extract_records(brands_payload)
    regions = extract_records(regions_payload)
    fuel_types = extract_records(fuel_types_payload)

    state_region_ids = [
        int(region["GeoRegionId"])
        for region in regions
        if str(region.get("GeoRegionLevel", "")).strip() == str(DEFAULT_STATE_GEO_REGION_LEVEL)
        and str(region.get("GeoRegionId", "")).strip() != ""
    ]

    site_details: list[dict[str, object]] = []
    for geo_region_id in state_region_ids:
        payload = fetch_json(
            api_base_url,
            token,
            api_path(
                "/Subscriber/GetFullSiteDetails",
                {
                    "countryId": DEFAULT_COUNTRY_ID,
                    "geoRegionLevel": DEFAULT_STATE_GEO_REGION_LEVEL,
                    "geoRegionId": geo_region_id,
                },
            ),
        )
        site_details.extend(extract_records(payload))

    return brands, regions, fuel_types, site_details


def sync_reference(mysql_env_path: str, api_base_url: str, token: str) -> SyncResult:
    brands, regions, fuel_types, site_details = fetch_reference_payloads(api_base_url, token)
    run_batched_sql(mysql_env_path, brands, build_brands_sql)
    run_batched_sql(mysql_env_path, regions, build_regions_sql)
    run_batched_sql(mysql_env_path, fuel_types, build_fuel_types_sql)
    run_batched_sql(mysql_env_path, site_details, build_stations_sql)
    message = f"brands={len(brands)} regions={len(regions)} fuels={len(fuel_types)} stations={len(site_details)}"
    return SyncResult(job_name="sa_reference", rows_processed=len(site_details), message=message)


def load_state_region_ids(mysql_env_path: str, api_base_url: str, token: str) -> list[int]:
    values = query_mysql_values(
        mysql_env_path,
        """
SELECT CAST(`geo_region_id` AS CHAR)
FROM `sa_geographic_regions`
WHERE `geo_region_level` = 3
ORDER BY `geo_region_id`;
""".strip(),
    )
    region_ids = [int(value) for value in values if value.strip() != ""]
    if region_ids:
        return region_ids

    payload = fetch_json(
        api_base_url,
        token,
        api_path("/Subscriber/GetCountryGeographicRegions", {"countryId": DEFAULT_COUNTRY_ID}),
    )
    regions = extract_records(payload)
    return [
        int(region["GeoRegionId"])
        for region in regions
        if str(region.get("GeoRegionLevel", "")).strip() == str(DEFAULT_STATE_GEO_REGION_LEVEL)
        and str(region.get("GeoRegionId", "")).strip() != ""
    ]


def build_price_rows(items: list[dict[str, object]]) -> list[dict[str, object]]:
    rows: list[dict[str, object]] = []
    for item in items:
        transaction_date = parse_sa_datetime(item.get("TransactionDateUtc"))
        if transaction_date is None:
            continue
        try:
            price_value = float(item.get("Price"))
        except (TypeError, ValueError):
            continue
        if price_value >= 9999:
            continue
        rows.append(
            {
                "station_id": item.get("SiteId"),
                "fuel_id": item.get("FuelId"),
                "collection_method": item.get("CollectionMethod") or "T",
                "transaction_date_utc": transaction_date,
                "price": price_value,
            }
        )
    return rows


def sync_prices(mysql_env_path: str, api_base_url: str, token: str) -> SyncResult:
    region_ids = load_state_region_ids(mysql_env_path, api_base_url, token)
    if not region_ids:
        raise RuntimeError("No state geographic regions are available for South Australia")

    payload_items: list[dict[str, object]] = []
    for region_id in region_ids:
        payload = fetch_json(
            api_base_url,
            token,
            api_path(
                "/Price/GetSitesPrices",
                {
                    "countryId": DEFAULT_COUNTRY_ID,
                    "geoRegionLevel": DEFAULT_STATE_GEO_REGION_LEVEL,
                    "geoRegionId": region_id,
                },
            ),
        )
        payload_items.extend(extract_records(payload))

    price_rows = build_price_rows(payload_items)
    if not price_rows:
        raise RuntimeError("South Australia price feed returned no usable prices")

    run_mysql_sql(mysql_env_path, build_prices_current_sql(payload_items))
    run_mysql_sql(mysql_env_path, build_prices_history_sql(payload_items))
    message = f"regions={len(region_ids)} prices={len(price_rows)}"
    return SyncResult(job_name="sa_prices", rows_processed=len(price_rows), message=message)


def run_diagnostics(api_base_url: str, token: str) -> list[tuple[str, int, str]]:
    probes = [
        ("brands", "/Subscriber/GetCountryBrands?countryId=21"),
        ("regions", "/Subscriber/GetCountryGeographicRegions?countryId=21"),
        ("fuel_types", "/Subscriber/GetCountryFuelTypes?countryId=21"),
        (
            "state_sites",
            api_path(
                "/Subscriber/GetFullSiteDetails",
                {
                    "countryId": DEFAULT_COUNTRY_ID,
                    "geoRegionLevel": DEFAULT_STATE_GEO_REGION_LEVEL,
                    "geoRegionId": DEFAULT_STATE_GEO_REGION_ID,
                },
            ),
        ),
        (
            "state_prices",
            api_path(
                "/Price/GetSitesPrices",
                {
                    "countryId": DEFAULT_COUNTRY_ID,
                    "geoRegionLevel": DEFAULT_STATE_GEO_REGION_LEVEL,
                    "geoRegionId": DEFAULT_STATE_GEO_REGION_ID,
                },
            ),
        ),
    ]
    results: list[tuple[str, int, str]] = []
    for label, path in probes:
        try:
            payload = fetch_json(api_base_url, token, path)
            count = len(extract_records(payload))
            results.append((label, count, "ok"))
        except Exception as exc:
            results.append((label, -1, str(exc)))
    return results


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Sync South Australia fuel pricing data into MySQL.")
    parser.add_argument(
        "job",
        choices=("reference", "prices", "all", "diagnose"),
        help="Which SA sync job to run.",
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
        "--api-base-url",
        default=DEFAULT_API_BASE_URL,
        help="SA Fuel Pricing Information API base URL.",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or os.sys.argv[1:])
    app_config = parse_env_file(Path(args.app_env))
    try:
        token = required_app_config(app_config, "SA_FUEL_SUBSCRIBER_TOKEN")
    except Exception as exc:
        print(f"error: {exc}", file=os.sys.stderr)
        return 1

    api_base_url = args.api_base_url.strip() or required_app_config(app_config, "SA_FUEL_API_BASE_URL")

    try:
        results: list[SyncResult] = []
        if args.job == "diagnose":
            diagnostics = run_diagnostics(api_base_url, token)
            for label, count, message in diagnostics:
                if count >= 0:
                    print(f"{label}: count={count}")
                else:
                    print(f"{label}: error={message}")
            return 0

        if args.job == "prices" and should_refresh_reference(args.mysql_env):
            results.append(sync_reference(args.mysql_env, api_base_url, token))

        if args.job in ("reference", "all") and should_refresh_reference(args.mysql_env):
            results.append(sync_reference(args.mysql_env, api_base_url, token))
        elif args.job == "reference":
            results.append(SyncResult("sa_reference", 0, "skipped reference refresh; last success < 24h"))

        if args.job in ("prices", "all"):
            results.append(sync_prices(args.mysql_env, api_base_url, token))
    except Exception as exc:
        try:
            log_sync_run(args.mysql_env, f"sa_{args.job.replace('-', '_')}", "error", 0, str(exc))
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
