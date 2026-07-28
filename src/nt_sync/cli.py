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
from urllib.error import URLError
from urllib.parse import urlencode
from urllib.request import Request

from sync_utils import build_atomic_snapshot_sql
from sync_utils import is_unconfigured_value
from sync_utils import retry_urlopen
from sync_utils import sync_duration_message
from sync_utils import sync_mysql_credentials
from sync_utils import sync_monotonic


DEFAULT_MYSQL_ENV_PATH = "/etc/fuelapi/mysql.env"
DEFAULT_APP_ENV_PATH = "/etc/fuelapi/app.env"
DEFAULT_AUTH_URL = "https://myfuelnt.nt.gov.au/api/token"
DEFAULT_API_BASE_URL = "https://myfuelnt.nt.gov.au/api/v1"
DEFAULT_TOKEN_CACHE_PATH = "/var/www/html/var/docker/app-state/nt_fuel_api_token.json"
USER_AGENT = "fuelau-nt-sync/0.1"
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
    required = ("MYSQL_HOST", "MYSQL_PORT", "MYSQL_DATABASE")
    missing = [key for key in required if not config.get(key)]
    if missing:
        raise ValueError(f"MySQL env file is missing required keys: {', '.join(missing)}")

    username, password = sync_mysql_credentials(config)
    command = [
        "mysql",
        f"--host={config['MYSQL_HOST']}",
        f"--port={config['MYSQL_PORT']}",
        f"--user={username}",
        f"--default-character-set={config.get('MYSQL_CHARSET', 'utf8mb4')}",
        "--protocol=TCP",
        config["MYSQL_DATABASE"],
    ]
    env = os.environ.copy()
    env["MYSQL_PWD"] = password
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


def normalize_text(value: object | None) -> str:
    return str(value or "").strip()


def parse_nt_price_datetime(value: object | None) -> str | None:
    text = normalize_text(value)
    if text == "":
        return None
    parsed = datetime.fromisoformat(text.replace("Z", "+00:00"))
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=UTC)
    return parsed.astimezone(UTC).replace(tzinfo=None).isoformat(sep=" ")


def load_cached_token(cache_path: Path) -> dict[str, str] | None:
    if not cache_path.is_file():
        return None
    try:
        payload = json.loads(cache_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return None
    if not isinstance(payload, dict):
        return None

    access_token = normalize_text(payload.get("access_token"))
    expires_at = normalize_text(payload.get("expires_at_utc"))
    username = normalize_text(payload.get("username"))
    if access_token == "" or expires_at == "" or username == "":
        return None

    try:
        expiry = datetime.fromisoformat(expires_at.replace("Z", "+00:00"))
    except ValueError:
        return None
    if expiry <= datetime.now(UTC) + timedelta(seconds=TOKEN_REFRESH_SKEW_SECONDS):
        return None

    return {
        "access_token": access_token,
        "expires_at_utc": expiry.astimezone(UTC).isoformat().replace("+00:00", "Z"),
        "username": username,
    }


def save_cached_token(cache_path: Path, payload: dict[str, str]) -> None:
    cache_path.parent.mkdir(parents=True, exist_ok=True)
    cache_path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    cache_path.chmod(0o600)


def request_json(
    url: str,
    headers: dict[str, str] | None = None,
    method: str = "GET",
    body: bytes | None = None,
    timeout: int = 180,
) -> object:
    request = Request(
        url,
        data=body,
        method=method,
        headers={
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
            **(headers or {}),
        },
    )
    try:
        with retry_urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8")
    except HTTPError as exc:
        body_text = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code} for {url}: {body_text[:500]}") from exc
    except URLError as exc:
        raise RuntimeError(f"Network error for {url}: {exc.reason}") from exc

    try:
        return json.loads(raw)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"Invalid JSON response from {url}") from exc


def fetch_json(
    api_base_url: str,
    token: str,
    path: str,
    method: str = "GET",
    body: dict[str, object] | None = None,
) -> object:
    headers = {
        "Authorization": f"Bearer {token}",
    }
    request_body = None
    if body is not None:
        request_body = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    return request_json(api_base_url.rstrip("/") + path, headers=headers, method=method, body=request_body)


def fetch_access_token(
    app_config: dict[str, str],
    cache_path: Path,
    auth_url: str,
) -> str:
    username = required_app_config(app_config, "NT_MYFUEL_USERNAME")
    password = required_app_config(app_config, "NT_MYFUEL_PASSWORD")

    cached = load_cached_token(cache_path)
    if cached is not None and cached["username"] == username:
        return cached["access_token"]

    form_body = urlencode(
        {
            "grant_type": "password",
            "username": username,
            "password": password,
        }
    ).encode("utf-8")
    payload = request_json(
        auth_url,
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
        },
        method="POST",
        body=form_body,
        timeout=60,
    )
    if not isinstance(payload, dict):
        raise RuntimeError("NT token response was not a JSON object")

    access_token = normalize_text(payload.get("access_token"))
    if access_token == "":
        raise RuntimeError("NT token response did not include access_token")

    expires_in_raw = normalize_text(payload.get("expires_in"))
    try:
        expires_in = int(expires_in_raw)
    except ValueError as exc:
        raise RuntimeError(f"Invalid NT expires_in value: {expires_in_raw}") from exc

    cache_payload = {
        "access_token": access_token,
        "expires_at_utc": (
            datetime.now(UTC) + timedelta(seconds=max(expires_in - TOKEN_REFRESH_SKEW_SECONDS, 0))
        ).isoformat().replace("+00:00", "Z"),
        "username": username,
    }
    save_cached_token(cache_path, cache_payload)
    return access_token


def extract_list(payload: object, keys: tuple[str, ...]) -> list[dict[str, object]]:
    if isinstance(payload, list):
        return [item for item in payload if isinstance(item, dict)]
    if not isinstance(payload, dict):
        return []
    for key in keys:
        value = payload.get(key)
        if isinstance(value, list):
            return [item for item in value if isinstance(item, dict)]
        if isinstance(value, dict):
            return [value]
    if any(not isinstance(value, (list, dict)) for value in payload.values()):
        return [payload]
    return []


def nt_lookup(item: dict[str, object], keys: tuple[str, ...]) -> object | None:
    for key in keys:
        if key in item and item.get(key) not in (None, ""):
            return item.get(key)
    return None


def nt_bool(value: object | None) -> int:
    if isinstance(value, bool):
        return 1 if value else 0
    text = normalize_text(value).lower()
    if text in {"1", "true", "yes", "y"}:
        return 1
    if text in {"0", "false", "no", "n"}:
        return 0
    return 0


def nt_float(value: object | None) -> float | None:
    text = normalize_text(value)
    if text == "":
        return None
    try:
        return float(text)
    except ValueError:
        return None


def log_sync_run(mysql_env_path: str, job_name: str, status: str, rows_processed: int, message: str) -> None:
    now = datetime.now(UTC).replace(microsecond=0).isoformat().replace("+00:00", "")
    finished_at_sql = "NULL" if status == "started" else mysql_escape(now)
    sql = f"""
INSERT INTO `nt_sync_runs` (
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
    {finished_at_sql},
    {mysql_escape(status)},
    {rows_processed},
    {mysql_escape(message[:65535])}
);
DELETE FROM `nt_sync_runs`
WHERE `started_at_utc` < UTC_TIMESTAMP() - INTERVAL 90 DAY;
""".strip()
    run_mysql_sql(mysql_env_path, sql)


def latest_success_value(mysql_env_path: str, job_name: str) -> str | None:
    sql = f"""
SELECT DATE_FORMAT(MAX(started_at_utc), '%Y-%m-%d %H:%i:%s')
FROM nt_sync_runs
WHERE job_name = {mysql_escape(job_name)}
  AND status = 'success';
""".strip()
    values = query_mysql_values(mysql_env_path, sql)
    value = values[0].strip() if values else ""
    if value == "" or value.upper() == "NULL":
        return None
    return value


def current_price_count(mysql_env_path: str) -> int:
    values = query_mysql_values(mysql_env_path, "SELECT COUNT(*) FROM nt_site_prices_current;")
    return int(values[0] or "0") if values else 0


def should_refresh_reference(mysql_env_path: str) -> bool:
    if current_price_count(mysql_env_path) == 0:
        return True
    latest = latest_success_value(mysql_env_path, "nt_reference")
    if latest is None:
        return True
    latest_dt = datetime.strptime(latest, "%Y-%m-%d %H:%M:%S").replace(tzinfo=UTC)
    return latest_dt <= datetime.now(UTC) - timedelta(hours=24)


def normalize_records(payload: object, keys: tuple[str, ...]) -> list[dict[str, object]]:
    return extract_list(payload, keys)


def build_brands_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        brand_id = nt_lookup(item, ("BrandIdentifier", "BrandId", "brandId", "brand_id", "id"))
        name = nt_lookup(item, ("Name", "BrandName", "name"))
        if normalize_text(brand_id) == "" or normalize_text(name) == "":
            continue
        values.append(
            "(" + ", ".join([
                mysql_escape(brand_id),
                mysql_escape(name),
            ]) + ")"
        )
    return f"""
INSERT INTO `nt_brands` (`brand_id`, `name`)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);
""".strip()


def build_fuels_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        fuel_code = nt_lookup(item, ("FuelCode", "FuelIdentifier", "FuelId", "code", "id"))
        name = nt_lookup(item, ("Description", "Name", "FuelName", "description", "name"))
        if normalize_text(fuel_code) == "" or normalize_text(name) == "":
            continue
        values.append(
            "(" + ", ".join([
                mysql_escape(fuel_code),
                mysql_escape(name),
            ]) + ")"
        )
    return f"""
INSERT INTO `nt_fuel_types` (`fuel_code`, `name`)
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
        station_id = nt_lookup(item, ("FuelOutletIdentifier", "OutletIdentifier", "OutletId", "id", "identifier"))
        name = nt_lookup(item, ("Name", "OutletName", "name"))
        address = nt_lookup(item, ("Address", "address"))
        if normalize_text(station_id) == "" or normalize_text(name) == "" or normalize_text(address) == "":
            continue
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(station_id),
                    mysql_escape(
                        nt_lookup(
                            item,
                            ("FuelBrandIdentifier", "BrandIdentifier", "BrandId", "brandId", "brand_id"),
                        )
                    ),
                    mysql_escape(name),
                    mysql_escape(address),
                    mysql_escape(nt_lookup(item, ("PostCode", "Postcode", "postcode"))),
                    mysql_escape(nt_lookup(item, ("Suburb", "suburb", "Locality", "locality"))),
                    mysql_escape(nt_float(nt_lookup(item, ("Latitude", "Lat", "latitude")))),
                    mysql_escape(nt_float(nt_lookup(item, ("Longitude", "Lng", "longitude")))),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `nt_stations` (
    `station_id`,
    `brand_id`,
    `name`,
    `address`,
    `postcode`,
    `suburb`,
    `latitude`,
    `longitude`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `brand_id` = VALUES(`brand_id`),
    `name` = VALUES(`name`),
    `address` = VALUES(`address`),
    `postcode` = VALUES(`postcode`),
    `suburb` = VALUES(`suburb`),
    `latitude` = VALUES(`latitude`),
    `longitude` = VALUES(`longitude`);
""".strip()


def build_price_rows(
    items: list[dict[str, object]],
    observed_at_utc: str,
) -> list[dict[str, object]]:
    rows: list[dict[str, object]] = []
    for item in items:
        outlet_id = normalize_text(
            nt_lookup(item, ("FuelOutletIdentifier", "OutletIdentifier", "OutletId", "identifier", "id"))
        )
        if outlet_id == "":
            continue
        fuels = item.get("AvailableFuel")
        if not isinstance(fuels, list):
            if isinstance(item.get("availableFuel"), list):
                fuels = item.get("availableFuel")
            elif isinstance(item.get("AvailableFuel"), dict):
                fuels = [item.get("AvailableFuel")]
            else:
                fuels = []
        if not isinstance(fuels, list):
            fuels = []
        item_observed_at = parse_nt_price_datetime(
            nt_lookup(item, ("UpdatedAt", "updatedAt", "LastUpdated", "lastUpdated", "Timestamp", "timestamp"))
        ) or observed_at_utc
        for fuel in fuels:
            if not isinstance(fuel, dict):
                continue
            fuel_code = normalize_text(nt_lookup(fuel, ("FuelCode", "FuelIdentifier", "FuelId", "code", "id")))
            if fuel_code == "":
                continue
            rows.append(
                {
                    "station_id": outlet_id,
                    "fuel_code": fuel_code,
                    "price": nt_float(nt_lookup(fuel, ("Price", "price", "FuelPrice"))),
                    "is_available": nt_bool(nt_lookup(fuel, ("Available", "IsAvailable", "available", "isAvailable"))),
                    "observed_at_utc": item_observed_at,
                }
            )
    return rows


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
                    mysql_escape(parse_nt_price_datetime(item.get("observed_at_utc"))),
                    mysql_escape(item.get("is_available")),
                    mysql_escape(item.get("price")),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `nt_site_prices_history` (
    `station_id`,
    `fuel_code`,
    `observed_at_utc`,
    `is_available`,
    `price`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `is_available` = VALUES(`is_available`),
    `price` = VALUES(`price`);
""".strip()


def build_prices_current_sql(items: list[dict[str, object]]) -> str:
    latest_by_key: dict[tuple[object, object], dict[str, object]] = {}
    for item in items:
        key = (item.get("station_id"), item.get("fuel_code"))
        latest_by_key[key] = item

    values = []
    for item in latest_by_key.values():
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("station_id")),
                    mysql_escape(item.get("fuel_code")),
                    mysql_escape(parse_nt_price_datetime(item.get("observed_at_utc"))),
                    mysql_escape(item.get("is_available")),
                    mysql_escape(item.get("price")),
                ]
            )
            + ")"
        )
    return build_atomic_snapshot_sql(
        table="nt_site_prices_current",
        columns=["station_id", "fuel_code", "observed_at_utc", "is_available", "price"],
        key_columns=["station_id", "fuel_code"],
        freshness_column="observed_at_utc",
        values=values,
    )


def sync_reference(mysql_env_path: str, api_base_url: str, token: str) -> SyncResult:
    payload = fetch_json(api_base_url, token, "/getReferenceData")
    brands = normalize_records(payload, ("Brands", "Brand", "brands"))
    fuels = normalize_records(payload, ("Fuels", "FuelTypes", "FuelType", "fuelTypes"))
    outlets = normalize_records(payload, ("Outlets", "Outlets[]", "FuelOutlets", "fuelOutlets"))

    sql_chunks = [
        build_brands_sql(brands),
        build_fuels_sql(fuels),
        build_stations_sql(outlets),
    ]
    sql = "\n\n".join(chunk for chunk in sql_chunks if chunk)
    if sql:
        run_mysql_sql(mysql_env_path, sql)

    total_rows = len(brands) + len(fuels) + len(outlets)
    message = f"brands={len(brands)}, fuels={len(fuels)}, outlets={len(outlets)}"
    return SyncResult(job_name="nt_reference", rows_processed=total_rows, message=message)


def load_outlet_ids(mysql_env_path: str) -> list[str]:
    values = query_mysql_values(
        mysql_env_path,
        """
SELECT CAST(`station_id` AS CHAR)
FROM `nt_stations`
ORDER BY `station_id`;
""".strip(),
    )
    return [value.strip() for value in values if value.strip() != ""]


def sync_prices(mysql_env_path: str, api_base_url: str, token: str) -> SyncResult:
    outlet_ids = load_outlet_ids(mysql_env_path)
    if outlet_ids == []:
        return SyncResult(job_name="nt_prices", rows_processed=0, message="no NT outlets available to price")

    observed_at_utc = datetime.now(UTC).replace(microsecond=0).isoformat().replace("+00:00", "")
    price_rows: list[dict[str, object]] = []
    batch_count = 0

    for batch in chunk_items([{"station_id": outlet_id} for outlet_id in outlet_ids], 10):
        batch_count += 1
        request_ids = [str(item["station_id"]) for item in batch]
        payload = fetch_json(
            api_base_url,
            token,
            "/getFuelPrice/fuelOutletIdentifier",
            method="POST",
            body={"FuelOutletIdentifier": request_ids},
        )
        outlets = normalize_records(payload, ("Outlets", "FuelOutlets", "FuelOutletPrices", "FuelPriceDetails"))
        if not outlets and isinstance(payload, dict):
            outlets = [payload]
        price_rows.extend(build_price_rows(outlets, observed_at_utc))
    if price_rows:
        run_batched_sql(mysql_env_path, price_rows, build_prices_history_sql)
        current_sql = build_prices_current_sql(price_rows)
        if current_sql:
            run_mysql_sql(mysql_env_path, current_sql)
    else:
        build_prices_current_sql([])

    message = f"outlets={len(outlet_ids)}, batches={batch_count}, price_rows={len(price_rows)}"
    return SyncResult(job_name="nt_prices", rows_processed=len(price_rows), message=message)


def run_diagnostics(api_base_url: str, token: str, outlet_ids: list[str]) -> list[tuple[str, int, str]]:
    probes: list[tuple[str, str, str | None]] = [
        ("reference", "/getReferenceData", None),
    ]
    if outlet_ids:
        sample_ids = outlet_ids[: min(3, len(outlet_ids))]
        probes.append(
            (
                "prices",
                "/getFuelPrice/fuelOutletIdentifier",
                json.dumps({"FuelOutletIdentifier": sample_ids}),
            )
        )

    results: list[tuple[str, int, str]] = []
    for label, path, body_text in probes:
        try:
            if body_text is None:
                payload = fetch_json(api_base_url, token, path)
            else:
                payload = request_json(
                    api_base_url.rstrip("/") + path,
                    headers={
                        "Authorization": f"Bearer {token}",
                        "Content-Type": "application/json",
                    },
                    method="POST",
                    body=body_text.encode("utf-8"),
                )
            if label == "reference" and isinstance(payload, dict):
                count = sum(
                    len(payload.get(key, [])) if isinstance(payload.get(key), list) else 0
                    for key in ("Brands", "Fuels", "Outlets")
                )
            elif isinstance(payload, list):
                count = len(payload)
            elif isinstance(payload, dict):
                count = 1
            else:
                count = -1
            results.append((label, count, "ok" if count >= 0 else "unexpected payload"))
        except Exception as exc:
            results.append((label, -1, str(exc)))
    return results


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Sync Northern Territory fuel data into MySQL.")
    parser.add_argument(
        "job",
        choices=("reference", "prices", "all", "diagnose"),
        help="Which NT sync job to run.",
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
        "--auth-url",
        default=DEFAULT_AUTH_URL,
        help="NT OAuth token URL.",
    )
    parser.add_argument(
        "--api-base-url",
        default=DEFAULT_API_BASE_URL,
        help="NT fuel API base URL.",
    )
    parser.add_argument(
        "--token-cache",
        default=DEFAULT_TOKEN_CACHE_PATH,
        help="Path to the cached NT access token file.",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or os.sys.argv[1:])
    started_at = sync_monotonic()
    run_job_name = f"nt_{args.job.replace('-', '_')}"
    app_config = parse_env_file(Path(args.app_env))
    try:
        username = required_app_config(app_config, "NT_MYFUEL_USERNAME")
        password = required_app_config(app_config, "NT_MYFUEL_PASSWORD")
    except Exception as exc:
        print(f"error: {exc}", file=os.sys.stderr)
        return 1

    if is_unconfigured_value(username) or is_unconfigured_value(password):
        print("info: NT_MYFUEL_USERNAME or NT_MYFUEL_PASSWORD is not configured; skipping NT sync", file=os.sys.stderr)
        return 0

    if args.job != "diagnose":
        try:
            log_sync_run(args.mysql_env, run_job_name, "started", 0, "sync started")
        except Exception:
            pass

    try:
        token = fetch_access_token(app_config, Path(args.token_cache), args.auth_url)
        results: list[SyncResult] = []
        if args.job == "diagnose":
            diagnostics = run_diagnostics(args.api_base_url, token, load_outlet_ids(args.mysql_env))
            for label, count, message in diagnostics:
                if count >= 0:
                    print(f"{label}: count={count}")
                else:
                    print(f"{label}: error={message}")
            return 0

        if args.job in ("reference", "all") and should_refresh_reference(args.mysql_env):
            results.append(sync_reference(args.mysql_env, args.api_base_url, token))
        elif args.job == "reference":
            results.append(SyncResult("nt_reference", 0, "skipped reference refresh; last success < 24h"))

        if args.job in ("prices", "all"):
            results.append(sync_prices(args.mysql_env, args.api_base_url, token))
    except Exception as exc:
        error_message = sync_duration_message(str(exc), started_at)
        try:
            log_sync_run(args.mysql_env, run_job_name, "error", 0, error_message)
        except Exception:
            pass
        print(f"error: {exc}", file=os.sys.stderr)
        return 1

    for result in results:
        result_message = sync_duration_message(result.message, started_at)
        try:
            log_sync_run(args.mysql_env, result.job_name, "success", result.rows_processed, result_message)
        except Exception:
            pass
        print(f"{result.job_name}: {result.message}")
    try:
        log_sync_run(
            args.mysql_env,
            run_job_name,
            "success",
            sum(result.rows_processed for result in results),
            sync_duration_message("sync completed", started_at),
        )
    except Exception:
        pass

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
