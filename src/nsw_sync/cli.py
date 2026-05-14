from __future__ import annotations

import argparse
import json
import os
import subprocess
import uuid
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

from sync_utils import is_unconfigured_value


DEFAULT_MYSQL_ENV_PATH = "/etc/fuelapi/mysql.env"
DEFAULT_APP_ENV_PATH = "/etc/fuelapi/app.env"
DEFAULT_TOKEN_CACHE_PATH = "/var/www/html/var/docker/app-state/nsw_fuel_api_token.json"
DEFAULT_API_BASE_URL = "https://api.onegov.nsw.gov.au"
DEFAULT_API_STATES = "NSW|TAS"
DEFAULT_TIMEZONE = "Australia/Sydney"
USER_AGENT = "fuelau-nsw-sync/0.1"
MYSQL_INSERT_BATCH_SIZE = 500
TOKEN_REFRESH_SKEW_SECONDS = 300


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


def request_timestamp() -> str:
    return datetime.now(UTC).astimezone(ZoneInfo(DEFAULT_TIMEZONE)).strftime("%d/%m/%Y %I:%M:%S %p")


def load_cached_token(cache_path: Path) -> dict[str, str] | None:
    if not cache_path.is_file():
        return None
    try:
        payload = json.loads(cache_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return None
    if not isinstance(payload, dict):
        return None
    access_token = str(payload.get("access_token", "")).strip()
    expires_at = str(payload.get("expires_at_utc", "")).strip()
    client_id = str(payload.get("client_id", "")).strip()
    if access_token == "" or expires_at == "" or client_id == "":
        return None
    try:
        expiry = datetime.fromisoformat(expires_at.replace("Z", "+00:00"))
    except ValueError:
        return None
    if expiry <= datetime.now(UTC) + timedelta(seconds=TOKEN_REFRESH_SKEW_SECONDS):
        return None
    return {
        "access_token": access_token,
        "client_id": client_id,
        "expires_at_utc": expiry.astimezone(UTC).isoformat().replace("+00:00", "Z"),
    }


def save_cached_token(cache_path: Path, payload: dict[str, str]) -> None:
    cache_path.parent.mkdir(parents=True, exist_ok=True)
    cache_path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    cache_path.chmod(0o600)


def fetch_access_token(app_config: dict[str, str], cache_path: Path, api_base_url: str) -> tuple[str, str]:
    cached = load_cached_token(cache_path)
    if cached is not None:
        return cached["access_token"], cached["client_id"]

    auth_header = required_app_config(app_config, "NSW_FUEL_API_AUTHORIZATION_HEADER")
    request = Request(
        api_base_url.rstrip("/") + "/oauth/client_credential/accesstoken?grant_type=client_credentials",
        headers={
            "Authorization": auth_header,
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        },
    )
    try:
        with urlopen(request, timeout=60) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code} when requesting NSW access token: {body[:500]}") from exc

    access_token = str(payload.get("access_token", "")).strip()
    client_id = str(payload.get("client_id", "")).strip() or required_app_config(app_config, "NSW_FUEL_API_KEY")
    expires_in_raw = str(payload.get("expires_in", "0")).strip()
    if access_token == "":
        raise RuntimeError("NSW access token response did not include access_token")
    try:
        expires_in = int(expires_in_raw)
    except ValueError as exc:
        raise RuntimeError(f"Invalid NSW expires_in value: {expires_in_raw}") from exc

    cache_payload = {
        "access_token": access_token,
        "client_id": client_id,
        "expires_at_utc": (
            datetime.now(UTC) + timedelta(seconds=max(expires_in - TOKEN_REFRESH_SKEW_SECONDS, 0))
        ).isoformat().replace("+00:00", "Z"),
    }
    save_cached_token(cache_path, cache_payload)
    return access_token, client_id


def fetch_json(api_base_url: str, access_token: str, client_id: str, path: str) -> dict[str, object]:
    request_url = api_base_url.rstrip("/") + path
    request = Request(
        request_url,
        headers={
            "Authorization": "Bearer " + access_token,
            "apikey": client_id,
            "transactionID": str(uuid.uuid4()),
            "requestTimeStamp": request_timestamp(),
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        },
    )
    try:
        with urlopen(request, timeout=180) as response:
            return json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code} for {request_url}: {body[:500]}") from exc


def build_api_path(path: str, states: str) -> str:
    states_value = states.strip()
    if states_value == "":
        return path
    delimiter = "&" if "?" in path else "?"
    return f"{path}{delimiter}{urlencode({'states': states_value})}"


def log_sync_run(mysql_env_path: str, job_name: str, status: str, rows_processed: int, message: str) -> None:
    now = datetime.now(UTC).replace(microsecond=0).isoformat().replace("+00:00", "")
    sql = f"""
INSERT INTO `nsw_sync_runs` (
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


def parse_nsw_datetime(value: object | None) -> str | None:
    text = str(value or "").strip()
    if text == "":
        return None
    parsed = datetime.strptime(text, "%d/%m/%Y %H:%M:%S")
    return parsed.isoformat(sep=" ")


def build_brands_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = [
        "(" + ", ".join([mysql_escape(item.get("state")), mysql_escape(item.get("name"))]) + ")"
        for item in items
    ]
    return f"""
INSERT INTO `nsw_brands` (`state`, `name`)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);
""".strip()


def build_fuel_types_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = [
        "("
        + ", ".join(
            [
                mysql_escape(item.get("state")),
                mysql_escape(item.get("code")),
                mysql_escape(item.get("name")),
            ]
        )
        + ")"
        for item in items
    ]
    return f"""
INSERT INTO `nsw_fuel_types` (`state`, `fuel_code`, `name`)
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
        location = item.get("location") if isinstance(item.get("location"), dict) else {}
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("state")),
                    mysql_escape(item.get("code")),
                    mysql_escape(item.get("stationid")),
                    mysql_escape(item.get("brand")),
                    mysql_escape(item.get("brandid")),
                    mysql_escape(item.get("name")),
                    mysql_escape(item.get("address")),
                    mysql_escape(location.get("latitude")),
                    mysql_escape(location.get("longitude")),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `nsw_stations` (
    `state`,
    `station_code`,
    `station_id`,
    `brand_name`,
    `brand_id`,
    `name`,
    `address`,
    `latitude`,
    `longitude`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `station_id` = VALUES(`station_id`),
    `brand_name` = VALUES(`brand_name`),
    `brand_id` = VALUES(`brand_id`),
    `name` = VALUES(`name`),
    `address` = VALUES(`address`),
    `latitude` = VALUES(`latitude`),
    `longitude` = VALUES(`longitude`);
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
                    mysql_escape(item.get("state")),
                    mysql_escape(item.get("stationcode")),
                    mysql_escape(item.get("fueltype")),
                    mysql_escape(parse_nsw_datetime(item.get("lastupdated"))),
                    mysql_escape(item.get("price")),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `nsw_site_prices_history` (
    `state`,
    `station_code`,
    `fuel_code`,
    `last_updated_at`,
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
    latest_by_key: dict[tuple[object, object, object], dict[str, object]] = {}
    for item in items:
        key = (item.get("state"), item.get("stationcode"), item.get("fueltype"))
        existing = latest_by_key.get(key)
        current_last_updated = parse_nsw_datetime(item.get("lastupdated")) or ""
        if existing is None:
            latest_by_key[key] = item
            continue
        existing_last_updated = parse_nsw_datetime(existing.get("lastupdated")) or ""
        if current_last_updated >= existing_last_updated:
            latest_by_key[key] = item

    values = []
    for item in latest_by_key.values():
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("state")),
                    mysql_escape(item.get("stationcode")),
                    mysql_escape(item.get("fueltype")),
                    mysql_escape(parse_nsw_datetime(item.get("lastupdated"))),
                    mysql_escape(item.get("price")),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `nsw_site_prices_current` (
    `state`,
    `station_code`,
    `fuel_code`,
    `last_updated_at`,
    `price`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `last_updated_at` = VALUES(`last_updated_at`),
    `price` = VALUES(`price`);
""".strip()


def sync_reference(
    mysql_env_path: str,
    api_base_url: str,
    access_token: str,
    client_id: str,
    api_states: str,
) -> SyncResult:
    payload = fetch_json(
        api_base_url,
        access_token,
        client_id,
        build_api_path("/FuelCheckRefData/v2/fuel/lovs", api_states),
    )
    brands = list(payload.get("brands", {}).get("items", [])) if isinstance(payload.get("brands"), dict) else []
    fuel_types = (
        list(payload.get("fueltypes", {}).get("items", [])) if isinstance(payload.get("fueltypes"), dict) else []
    )
    stations = list(payload.get("stations", {}).get("items", [])) if isinstance(payload.get("stations"), dict) else []
    sql_chunks = [build_brands_sql(brands), build_fuel_types_sql(fuel_types), build_stations_sql(stations)]
    sql = "\n\n".join(chunk for chunk in sql_chunks if chunk)
    if sql:
        run_mysql_sql(mysql_env_path, sql)
    total_rows = len(brands) + len(fuel_types) + len(stations)
    message = f"brands={len(brands)}, fuel_types={len(fuel_types)}, stations={len(stations)}"
    return SyncResult(job_name="nsw_reference", rows_processed=total_rows, message=message)


def sync_prices(
    mysql_env_path: str,
    api_base_url: str,
    access_token: str,
    client_id: str,
    path: str,
    job_name: str,
    api_states: str,
) -> SyncResult:
    payload = fetch_json(api_base_url, access_token, client_id, build_api_path(path, api_states))
    stations = list(payload.get("stations", [])) if isinstance(payload.get("stations"), list) else []
    prices = list(payload.get("prices", [])) if isinstance(payload.get("prices"), list) else []
    if stations:
        run_batched_sql(mysql_env_path, stations, build_stations_sql)
    if prices:
        run_batched_sql(mysql_env_path, prices, build_prices_history_sql)
        current_sql = build_prices_current_sql(prices)
        if current_sql:
            run_mysql_sql(mysql_env_path, current_sql)
    message = f"stations={len(stations)}, prices={len(prices)}"
    return SyncResult(job_name=job_name, rows_processed=len(prices), message=message)


def latest_success_value(mysql_env_path: str, job_name: str) -> str | None:
    sql = f"""
SELECT DATE_FORMAT(MAX(started_at_utc), '%Y-%m-%d %H:%i:%s')
FROM nsw_sync_runs
WHERE job_name = {mysql_escape(job_name)}
  AND status = 'success';
""".strip()
    values = query_mysql_values(mysql_env_path, sql)
    value = values[0].strip() if values else ""
    if value == "" or value.upper() == "NULL":
        return None
    return value


def current_price_count(mysql_env_path: str) -> int:
    sql = "SELECT COUNT(*) FROM nsw_site_prices_current;"
    values = query_mysql_values(mysql_env_path, sql)
    return int(values[0] or "0") if values else 0


def normalized_api_states(api_states: str) -> list[str]:
    states = [item.strip().upper() for item in api_states.split("|")]
    return [state for state in states if state != ""]


def missing_reference_states(mysql_env_path: str, api_states: str) -> list[str]:
    states = normalized_api_states(api_states)
    if not states:
        return []

    expected = ", ".join(mysql_escape(state) for state in states)
    sql = f"""
SELECT DISTINCT `state`
FROM `nsw_fuel_types`
WHERE `state` IN ({expected});
""".strip()
    existing = {value.strip().upper() for value in query_mysql_values(mysql_env_path, sql) if value.strip() != ""}
    return [state for state in states if state not in existing]


def should_refresh_reference(mysql_env_path: str, api_states: str) -> bool:
    if missing_reference_states(mysql_env_path, api_states):
        return True
    latest = latest_success_value(mysql_env_path, "nsw_reference")
    if latest is None:
        return True
    latest_dt = datetime.strptime(latest, "%Y-%m-%d %H:%M:%S").replace(tzinfo=UTC)
    return latest_dt <= datetime.now(UTC) - timedelta(hours=24)


def should_run_full_prices(mysql_env_path: str) -> bool:
    if current_price_count(mysql_env_path) == 0:
        return True
    latest = latest_success_value(mysql_env_path, "nsw_prices_full")
    if latest is None:
        return True
    latest_dt = datetime.strptime(latest, "%Y-%m-%d %H:%M:%S").replace(tzinfo=UTC)
    sydney = ZoneInfo(DEFAULT_TIMEZONE)
    now_sydney = datetime.now(UTC).astimezone(sydney)
    start_of_day_sydney = now_sydney.replace(hour=0, minute=0, second=0, microsecond=0)
    return latest_dt.astimezone(sydney) < start_of_day_sydney


def run_diagnostics(
    api_base_url: str,
    access_token: str,
    client_id: str,
    api_states: str,
) -> list[tuple[str, int, str]]:
    probes = [
        ("reference_brands", "/FuelCheckRefData/v2/fuel/lovs", ("brands", "items")),
        ("reference_fueltypes", "/FuelCheckRefData/v2/fuel/lovs", ("fueltypes", "items")),
        ("reference_stations", "/FuelCheckRefData/v2/fuel/lovs", ("stations", "items")),
        ("prices_full", "/FuelPriceCheck/v2/fuel/prices", ("prices",)),
        ("prices_new", "/FuelPriceCheck/v2/fuel/prices/new", ("prices",)),
    ]
    results: list[tuple[str, int, str]] = []
    for label, path, keys in probes:
        try:
            payload: object = fetch_json(
                api_base_url,
                access_token,
                client_id,
                build_api_path(path, api_states),
            )
            for key in keys:
                if isinstance(payload, dict):
                    payload = payload.get(key)
                else:
                    payload = None
                    break
            count = len(payload) if isinstance(payload, list) else -1
            results.append((label, count, "ok" if count >= 0 else "unexpected payload"))
        except Exception as exc:
            results.append((label, -1, str(exc)))
    return results


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Sync NSW Fuel API data into MySQL.")
    parser.add_argument(
        "job",
        choices=("reference", "prices-full", "prices-new", "all", "diagnose"),
        help="Which NSW sync job to run.",
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
        help="NSW Fuel API base URL.",
    )
    parser.add_argument(
        "--api-states",
        default="",
        help="Pipe-delimited NSW API states query, for example NSW|TAS.",
    )
    parser.add_argument(
        "--token-cache",
        default=DEFAULT_TOKEN_CACHE_PATH,
        help="Path to the cached NSW access token file.",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or os.sys.argv[1:])
    app_config = parse_env_file(Path(args.app_env))
    api_states = args.api_states.strip() or app_config.get("NSW_FUEL_API_STATES", "").strip() or DEFAULT_API_STATES

    auth_header = app_config.get("NSW_FUEL_API_AUTHORIZATION_HEADER")
    api_key = app_config.get("NSW_FUEL_API_KEY")
    if is_unconfigured_value(auth_header) or is_unconfigured_value(api_key):
        print(
            "info: NSW_FUEL_API_AUTHORIZATION_HEADER or NSW_FUEL_API_KEY is not configured; skipping NSW sync",
            file=os.sys.stderr,
        )
        return 0

    try:
        access_token, client_id = fetch_access_token(app_config, Path(args.token_cache), args.api_base_url)
    except Exception as exc:
        print(f"error: {exc}", file=os.sys.stderr)
        return 1

    try:
        results: list[SyncResult] = []
        if args.job == "diagnose":
            diagnostics = run_diagnostics(args.api_base_url, access_token, client_id, api_states)
            for label, count, message in diagnostics:
                if count >= 0:
                    print(f"{label}: count={count}")
                else:
                    print(f"{label}: error={message}")
            return 0
        if args.job in ("reference", "all") and should_refresh_reference(args.mysql_env, api_states):
            results.append(sync_reference(args.mysql_env, args.api_base_url, access_token, client_id, api_states))
        elif args.job == "reference":
            results.append(SyncResult("nsw_reference", 0, "skipped reference refresh; last success < 24h"))

        if args.job == "prices-full":
            results.append(
                sync_prices(
                    args.mysql_env,
                    args.api_base_url,
                    access_token,
                    client_id,
                    "/FuelPriceCheck/v2/fuel/prices",
                    "nsw_prices_full",
                    api_states,
                )
            )
        elif args.job == "prices-new":
            results.append(
                sync_prices(
                    args.mysql_env,
                    args.api_base_url,
                    access_token,
                    client_id,
                    "/FuelPriceCheck/v2/fuel/prices/new",
                    "nsw_prices_new",
                    api_states,
                )
            )
        elif args.job == "all":
            if should_run_full_prices(args.mysql_env):
                results.append(
                    sync_prices(
                        args.mysql_env,
                        args.api_base_url,
                        access_token,
                        client_id,
                        "/FuelPriceCheck/v2/fuel/prices",
                        "nsw_prices_full",
                        api_states,
                    )
                )
            else:
                results.append(
                    sync_prices(
                        args.mysql_env,
                        args.api_base_url,
                        access_token,
                        client_id,
                        "/FuelPriceCheck/v2/fuel/prices/new",
                        "nsw_prices_new",
                        api_states,
                    )
                )
    except Exception as exc:
        try:
            log_sync_run(args.mysql_env, f"nsw_{args.job.replace('-', '_')}", "error", 0, str(exc))
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
