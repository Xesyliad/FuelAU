from __future__ import annotations

import re
import time
from collections.abc import Callable
from typing import Any
from urllib.error import HTTPError
from urllib.error import URLError
from urllib.request import Request
from urllib.request import urlopen


_PLACEHOLDER_PREFIXES = (
    "replace_with_",
    "replace-me",
    "replace_me",
    "your_",
    "your-",
    "your ",
    "changeme",
    "change_me",
    "change-me",
)

_PLACEHOLDER_VALUES = {
    "<replace>",
    "<replace_me>",
    "<replace-with-value>",
    "<todo>",
    "todo",
    "placeholder",
}


def is_unconfigured_value(value: object | None) -> bool:
    text = str(value or "").strip()
    if text == "":
        return True

    normalized = text.lower()
    if normalized in _PLACEHOLDER_VALUES:
        return True

    return normalized.startswith(_PLACEHOLDER_PREFIXES)


class SnapshotValidationError(ValueError):
    """Raised before a bad or empty snapshot can alter live current data."""


def sync_mysql_credentials(config: dict[str, str]) -> tuple[str, str]:
    username = config.get("MYSQL_SYNC_USERNAME") or config.get("MYSQL_USERNAME") or ""
    password = config.get("MYSQL_SYNC_PASSWORD") or config.get("MYSQL_PASSWORD") or ""
    if username == "" or password == "":
        raise ValueError("MySQL sync credentials are not configured")
    return username, password


def retry_urlopen(
    request: str | Request,
    *,
    timeout: int,
    attempts: int = 4,
    base_delay_seconds: float = 0.5,
    opener: Callable[..., Any] = urlopen,
    sleeper: Callable[[float], None] = time.sleep,
) -> Any:
    """Open an HTTP request with bounded backoff for transient failures."""

    if attempts < 1:
        raise ValueError("attempts must be at least 1")
    retryable_statuses = {408, 425, 429, 500, 502, 503, 504}

    for attempt in range(attempts):
        try:
            return opener(request, timeout=timeout)
        except HTTPError as exc:
            if exc.code not in retryable_statuses or attempt + 1 >= attempts:
                raise
            retry_after = str(exc.headers.get("Retry-After", "") if exc.headers else "").strip()
            delay = float(retry_after) if retry_after.isdigit() else base_delay_seconds * (2**attempt)
        except (URLError, TimeoutError):
            if attempt + 1 >= attempts:
                raise
            delay = base_delay_seconds * (2**attempt)

        sleeper(min(8.0, max(0.0, delay)))

    raise RuntimeError("HTTP retry loop ended unexpectedly")


def sync_monotonic() -> float:
    return time.monotonic()


def sync_duration_message(message: str, started_at: float) -> str:
    duration_seconds = max(0.0, time.monotonic() - started_at)
    return f"{message}; duration_seconds={duration_seconds:.3f}"


def _quoted_identifier(identifier: str) -> str:
    if re.fullmatch(r"[a-z0-9_]+", identifier, flags=re.IGNORECASE) is None:
        raise ValueError(f"Unsafe SQL identifier: {identifier}")
    return f"`{identifier}`"


def build_atomic_snapshot_sql(
    *,
    table: str,
    columns: list[str],
    key_columns: list[str],
    freshness_column: str,
    values: list[str],
    expire_missing: bool = True,
) -> str:
    """Build one-connection SQL that validates, merges, expires, and commits a snapshot."""

    if not values:
        raise SnapshotValidationError(f"Refusing to publish an empty snapshot for {table}")
    if freshness_column not in columns:
        raise ValueError("freshness_column must be present in columns")
    if not key_columns or any(column not in columns for column in key_columns):
        raise ValueError("key_columns must be a non-empty subset of columns")

    quoted_table = _quoted_identifier(table)
    stage_table_name = f"tmp_{table}_snapshot"
    quoted_stage = _quoted_identifier(stage_table_name)
    quoted_columns = [_quoted_identifier(column) for column in columns]
    quoted_keys = [_quoted_identifier(column) for column in key_columns]
    quoted_freshness = _quoted_identifier(freshness_column)
    column_list = ", ".join(quoted_columns)

    updates = []
    for column in quoted_columns:
        updates.append(
            f"{column} = IF("
            f"VALUES({quoted_freshness}) >= {quoted_table}.{quoted_freshness}, "
            f"VALUES({column}), {quoted_table}.{column})"
        )
    join = " AND ".join(
        f"incoming.{column} = live.{column}"
        for column in quoted_keys
    )
    missing_key = quoted_keys[0]

    expire_sql = ""
    if expire_missing:
        expire_sql = f"""
DELETE live
FROM {quoted_table} AS live
LEFT JOIN {quoted_stage} AS incoming ON {join}
WHERE incoming.{missing_key} IS NULL;
""".strip()

    return f"""
DROP TEMPORARY TABLE IF EXISTS {quoted_stage};
CREATE TEMPORARY TABLE {quoted_stage} LIKE {quoted_table};
INSERT INTO {quoted_stage} ({column_list})
VALUES
{",\n".join(values)};

START TRANSACTION;
INSERT INTO {quoted_table} ({column_list})
SELECT {column_list}
FROM {quoted_stage}
WHERE 1
ON DUPLICATE KEY UPDATE
    {",\n    ".join(updates)};
{expire_sql}
COMMIT;

DROP TEMPORARY TABLE {quoted_stage};
""".strip()
