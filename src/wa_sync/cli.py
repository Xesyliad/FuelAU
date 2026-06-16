from __future__ import annotations

import argparse
import hashlib
import os
import re
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
from zoneinfo import ZoneInfo
from xml.etree import ElementTree

from sync_utils import is_unconfigured_value


DEFAULT_MYSQL_ENV_PATH = "/etc/fuelapi/mysql.env"
DEFAULT_APP_ENV_PATH = "/etc/fuelapi/app.env"
DEFAULT_FEED_BASE_URL = "https://www.fuelwatch.wa.gov.au/fuelwatch/fuelWatchRSS"
DEFAULT_TIMEZONE = "Australia/Perth"
USER_AGENT = "fuelau-wa-sync/0.1"
MYSQL_INSERT_BATCH_SIZE = 500
DAY_SWITCH_HOUR = 14
DAY_SWITCH_MINUTE = 30

PRODUCTS: dict[int, str] = {
    1: "Unleaded Petrol",
    2: "Premium Unleaded",
    4: "Diesel",
    5: "LPG",
    6: "98 RON",
    10: "E85",
    11: "Brand diesel",
}

STATE_REGIONS: list[tuple[int, str]] = [
    (1, "Gascoyne"),
    (2, "Goldfields-Esperance"),
    (3, "Great Southern"),
    (4, "Kimberley"),
    (5, "Mid-West"),
    (6, "Peel"),
    (7, "Pilbara"),
    (8, "South-West"),
    (9, "Wheatbelt"),
    (98, "Metro"),
]


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


def request_json(url: str, timeout: int = 180) -> str:
    request = Request(
        url,
        headers={
            "Accept": "application/rss+xml, application/xml, text/xml;q=0.9, */*;q=0.1",
            "User-Agent": USER_AGENT,
        },
    )
    try:
        with urlopen(request, timeout=timeout) as response:
            return response.read().decode("utf-8-sig")
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code} for {url}: {body[:500]}") from exc


def wa_current_day() -> str:
    now = datetime.now(ZoneInfo(DEFAULT_TIMEZONE))
    if (now.hour, now.minute) >= (DAY_SWITCH_HOUR, DAY_SWITCH_MINUTE):
        return "tomorrow"
    return "today"


def wa_build_feed_url(
    base_url: str,
    product: int,
    state_region: int,
    day: str,
) -> str:
    query = urlencode(
        {
            "Product": product,
            "StateRegion": state_region,
            "Day": day,
        }
    )
    return f"{base_url.rstrip('/')}?{query}"


def parse_feed_items(xml_text: str) -> list[dict[str, object]]:
    root = ElementTree.fromstring(xml_text.encode("utf-8"))
    items: list[dict[str, object]] = []
    for node in root.findall(".//item"):
        item: dict[str, object] = {}
        for child in list(node):
            key = re.sub(r"[^a-z0-9]+", "_", child.tag.strip().lower()).strip("_")
            item[key] = (child.text or "").strip()
        items.append(item)
    return items


def normalize_text(value: object | None) -> str:
    return str(value or "").strip()


def normalize_float(value: object | None) -> float | None:
    text = normalize_text(value)
    if text == "":
        return None
    try:
        return float(text)
    except ValueError:
        return None


def normalize_price(value: object | None) -> float | None:
    price = normalize_float(value)
    if price is None or price <= 0:
        return None
    return round(price, 3)


def parse_price_date(value: object | None) -> str | None:
    text = normalize_text(value)
    if text == "":
        return None
    try:
        return datetime.strptime(text, "%Y-%m-%d").date().isoformat()
    except ValueError:
        return None


def normalize_brand_name(value: object | None) -> str:
    text = normalize_text(value)
    if text == "":
        return "Unknown"
    return text


def brand_id_from_name(value: object | None) -> str:
    text = normalize_brand_name(value).lower()
    slug = re.sub(r"[^a-z0-9]+", "-", text).strip("-")
    return slug or "unknown"


def station_id_for(item: dict[str, object]) -> str:
    parts = [
        brand_id_from_name(item.get("brand")),
        normalize_text(item.get("trading_name")).lower(),
        normalize_text(item.get("address")).lower(),
        normalize_text(item.get("location")).lower(),
        normalize_text(item.get("latitude")),
        normalize_text(item.get("longitude")),
    ]
    digest = hashlib.sha1("|".join(parts).encode("utf-8")).hexdigest()
    return digest


def log_sync_run(mysql_env_path: str, job_name: str, status: str, rows_processed: int, message: str) -> None:
    now = datetime.now(UTC).replace(microsecond=0).isoformat().replace("+00:00", "")
    sql = f"""
INSERT INTO `wa_sync_runs` (
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


def build_brands_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = [
        "(" + ", ".join([mysql_escape(item.get("brand_id")), mysql_escape(item.get("name"))]) + ")"
        for item in items
    ]
    return f"""
INSERT INTO `wa_brands` (`brand_id`, `name`)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);
""".strip()


def build_fuel_types_sql() -> str:
    values = [
        "(" + ", ".join([mysql_escape(code), mysql_escape(name)]) + ")"
        for code, name in PRODUCTS.items()
    ]
    return f"""
INSERT INTO `wa_fuel_types` (`fuel_code`, `name`)
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
                    mysql_escape(item["station_id"]),
                    mysql_escape(item.get("brand_id")),
                    mysql_escape(item.get("name")),
                    mysql_escape(item.get("address")),
                    mysql_escape(item.get("suburb")),
                    mysql_escape(item.get("phone")),
                    mysql_escape(item.get("latitude")),
                    mysql_escape(item.get("longitude")),
                    mysql_escape(item.get("site_features")),
                    mysql_escape(item.get("restrictions")),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `wa_stations` (
    `station_id`,
    `brand_id`,
    `name`,
    `address`,
    `suburb`,
    `phone`,
    `latitude`,
    `longitude`,
    `site_features`,
    `restrictions`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `brand_id` = VALUES(`brand_id`),
    `name` = VALUES(`name`),
    `address` = VALUES(`address`),
    `suburb` = VALUES(`suburb`),
    `phone` = VALUES(`phone`),
    `latitude` = VALUES(`latitude`),
    `longitude` = VALUES(`longitude`),
    `site_features` = VALUES(`site_features`),
    `restrictions` = VALUES(`restrictions`);
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
                    mysql_escape(item.get("station_id")),
                    mysql_escape(item.get("fuel_code")),
                    mysql_escape(item.get("price_date")),
                    mysql_escape(item.get("price")),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `wa_site_prices_history` (
    `station_id`,
    `fuel_code`,
    `price_date`,
    `price`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `price` = VALUES(`price`);
""".strip()


def build_prices_current_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("station_id")),
                    mysql_escape(item.get("fuel_code")),
                    mysql_escape(item.get("price_date")),
                    mysql_escape(item.get("price")),
                ]
            )
            + ")"
        )
    return f"""
START TRANSACTION;
DELETE FROM `wa_site_prices_current`;
INSERT INTO `wa_site_prices_current` (
    `station_id`,
    `fuel_code`,
    `price_date`,
    `price`
)
VALUES
{",\n".join(values)};
COMMIT;
""".strip()


def extract_records_for_day(day: str, product: int, state_region: int, feed_base_url: str) -> list[dict[str, object]]:
    feed_url = wa_build_feed_url(feed_base_url, product, state_region, day)
    xml_text = request_json(feed_url)
    return parse_feed_items(xml_text)


def fetch_all_rows(feed_base_url: str) -> tuple[list[dict[str, object]], list[dict[str, object]], list[dict[str, object]], list[dict[str, object]]]:
    day = wa_current_day()
    brands_by_id: dict[str, dict[str, object]] = {}
    stations_by_id: dict[str, dict[str, object]] = {}
    prices_by_key: dict[tuple[str, int, str], dict[str, object]] = {}

    for state_region, _state_region_name in STATE_REGIONS:
        for product_code, product_name in PRODUCTS.items():
            try:
                items = extract_records_for_day(day, product_code, state_region, feed_base_url)
            except Exception as exc:
                raise RuntimeError(
                    f"Unable to fetch FuelWatch feed for product={product_code} state_region={state_region} day={day}: {exc}"
                ) from exc

            for raw_item in items:
                price_date = parse_price_date(raw_item.get("date"))
                price = normalize_price(raw_item.get("price"))
                station_name = normalize_text(raw_item.get("trading_name")) or normalize_text(raw_item.get("title"))
                address = normalize_text(raw_item.get("address"))
                if price_date is None or price is None or station_name == "" or address == "":
                    continue

                brand_name = normalize_brand_name(raw_item.get("brand"))
                brand_id = brand_id_from_name(brand_name)
                station = {
                    "station_id": station_id_for(raw_item),
                    "brand_id": brand_id,
                    "name": station_name,
                    "address": address,
                    "suburb": normalize_text(raw_item.get("location")),
                    "phone": normalize_text(raw_item.get("phone")) or None,
                    "latitude": normalize_float(raw_item.get("latitude")),
                    "longitude": normalize_float(raw_item.get("longitude")),
                    "site_features": normalize_text(raw_item.get("site_features")) or None,
                    "restrictions": normalize_text(raw_item.get("restrictions")) or None,
                }
                if brand_id not in brands_by_id:
                    brands_by_id[brand_id] = {"brand_id": brand_id, "name": brand_name}
                stations_by_id[station["station_id"]] = station

                price_key = (station["station_id"], product_code, price_date)
                prices_by_key[price_key] = {
                    "station_id": station["station_id"],
                    "fuel_code": product_code,
                    "price_date": price_date,
                    "price": price,
                }

    brands = sorted(brands_by_id.values(), key=lambda item: str(item["name"]).lower())
    fuel_types = [{"fuel_code": code, "name": name} for code, name in PRODUCTS.items()]
    stations = sorted(stations_by_id.values(), key=lambda item: (str(item["name"]).lower(), str(item["address"]).lower()))
    prices = sorted(prices_by_key.values(), key=lambda item: (str(item["price_date"]), int(item["fuel_code"]), str(item["station_id"])))
    return brands, fuel_types, stations, prices


def sync_all(mysql_env_path: str, feed_base_url: str) -> SyncResult:
    brands, fuel_types, stations, prices = fetch_all_rows(feed_base_url)
    run_mysql_sql(mysql_env_path, build_brands_sql(brands))
    run_mysql_sql(mysql_env_path, build_fuel_types_sql())
    run_batched_sql(mysql_env_path, stations, build_stations_sql)
    run_batched_sql(mysql_env_path, prices, build_prices_history_sql)
    current_sql = build_prices_current_sql(prices)
    if current_sql:
        run_mysql_sql(mysql_env_path, current_sql)
    message = f"brands={len(brands)} fuels={len(fuel_types)} stations={len(stations)} prices={len(prices)} day={wa_current_day()}"
    return SyncResult(job_name="wa_all", rows_processed=len(prices), message=message)


def sync_prices(mysql_env_path: str, feed_base_url: str) -> SyncResult:
    result = sync_all(mysql_env_path, feed_base_url)
    return SyncResult(job_name="wa_prices", rows_processed=result.rows_processed, message=result.message)


def run_diagnostics(feed_base_url: str) -> list[tuple[str, int, str]]:
    probes = [
        ("metro_ulp", wa_build_feed_url(feed_base_url, 1, 98, "today")),
        ("metro_diesel", wa_build_feed_url(feed_base_url, 4, 98, "today")),
        ("metro_tomorrow", wa_build_feed_url(feed_base_url, 1, 98, "tomorrow")),
    ]
    results: list[tuple[str, int, str]] = []
    for label, url in probes:
        try:
            payload = parse_feed_items(request_json(url))
            results.append((label, len(payload), "ok"))
        except Exception as exc:
            results.append((label, -1, str(exc)))
    return results


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Sync Western Australia FuelWatch RSS data into MySQL.")
    parser.add_argument(
        "job",
        choices=("all", "prices", "diagnose", "reference"),
        help="Which WA sync job to run.",
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
        "--feed-base-url",
        default=DEFAULT_FEED_BASE_URL,
        help="FuelWatch RSS base URL.",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or os.sys.argv[1:])
    app_config = parse_env_file(Path(args.app_env))
    feed_base_url = args.feed_base_url.strip() or app_config.get("WA_FUELWATCH_FEED_BASE_URL", DEFAULT_FEED_BASE_URL)
    if is_unconfigured_value(feed_base_url):
        feed_base_url = DEFAULT_FEED_BASE_URL

    try:
        results: list[SyncResult] = []
        if args.job == "diagnose":
            diagnostics = run_diagnostics(feed_base_url)
            for label, count, message in diagnostics:
                if count >= 0:
                    print(f"{label}: count={count}")
                else:
                    print(f"{label}: error={message}")
            return 0
        if args.job in {"all", "reference"}:
            results.append(sync_all(args.mysql_env, feed_base_url))
        else:
            results.append(sync_prices(args.mysql_env, feed_base_url))
        for result in results:
            log_sync_run(args.mysql_env, result.job_name, "success", result.rows_processed, result.message)
            print(f"{result.job_name}: rows={result.rows_processed} {result.message}")
        return 0
    except Exception as exc:
        job_name = f"wa_{args.job}"
        try:
            log_sync_run(args.mysql_env, job_name, "error", 0, str(exc))
        except Exception:
            pass
        print(f"error: {exc}", file=os.sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
