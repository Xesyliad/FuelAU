from __future__ import annotations

import re
import time
from collections.abc import Callable
from dataclasses import dataclass
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


@dataclass(frozen=True)
class PublicationMetrics:
    api_rows_fetched: int
    current_rows_published: int
    history_changes: int
    unchanged_observations: int
    missing_rows_expired: int


def parse_publication_metrics(output: str) -> PublicationMetrics:
    match = re.search(
        r"FUELAU_METRICS:"
        r"api_rows=(\d+),"
        r"current_rows=(\d+),"
        r"history_changes=(\d+),"
        r"unchanged=(\d+),"
        r"expired=(\d+)",
        output,
    )
    if match is None:
        raise ValueError("Importer publication did not return metrics")
    values = [int(value) for value in match.groups()]
    return PublicationMetrics(
        api_rows_fetched=values[0],
        current_rows_published=values[1],
        history_changes=values[2],
        unchanged_observations=values[3],
        missing_rows_expired=values[4],
    )


def publication_metrics_message(metrics: PublicationMetrics) -> str:
    return (
        f"api_rows_fetched={metrics.api_rows_fetched} "
        f"current_rows_published={metrics.current_rows_published} "
        f"history_changes_inserted={metrics.history_changes} "
        f"unchanged_observations_skipped={metrics.unchanged_observations} "
        f"missing_current_rows_expired={metrics.missing_rows_expired}"
    )


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


def build_change_aware_snapshot_sql(
    *,
    current_table: str,
    history_table: str,
    columns: list[str],
    key_columns: list[str],
    freshness_column: str,
    state_columns: list[str],
    values: list[str],
    expire_missing: bool = True,
    missing_means_unavailable: bool = False,
    availability_column: str | None = None,
    price_column: str | None = None,
) -> str:
    """Publish current state and meaningful history transitions in one transaction."""

    if not values:
        raise SnapshotValidationError(f"Refusing to publish an empty snapshot for {current_table}")
    if freshness_column not in columns:
        raise ValueError("freshness_column must be present in columns")
    if not key_columns or any(column not in columns for column in key_columns):
        raise ValueError("key_columns must be a non-empty subset of columns")
    if not state_columns or any(column not in columns for column in state_columns):
        raise ValueError("state_columns must be a non-empty subset of columns")
    if missing_means_unavailable:
        if not expire_missing:
            raise ValueError("missing availability transitions require a full snapshot")
        if availability_column not in state_columns or price_column not in state_columns:
            raise ValueError("availability and price columns must be meaningful state columns")

    quoted_current = _quoted_identifier(current_table)
    quoted_history = _quoted_identifier(history_table)
    stage_name = f"tmp_{current_table}_incoming"
    events_name = f"tmp_{current_table}_events"
    quoted_stage = _quoted_identifier(stage_name)
    quoted_events = _quoted_identifier(events_name)
    quoted_columns = [_quoted_identifier(column) for column in columns]
    quoted_keys = [_quoted_identifier(column) for column in key_columns]
    quoted_state = [_quoted_identifier(column) for column in state_columns]
    quoted_freshness = _quoted_identifier(freshness_column)
    column_list = ", ".join(quoted_columns)

    def key_match(left: str, right: str) -> str:
        return " AND ".join(
            f"{left}.{column} = {right}.{column}"
            for column in quoted_keys
        )

    def state_signature(alias: str) -> str:
        parts = ", ".join(
            f"COALESCE(CAST({alias}.{column} AS CHAR), '<NULL>')"
            for column in quoted_state
        )
        return f"CONCAT_WS(CHAR(31), {parts})"

    exact_match = (
        f"{key_match('existing_exact', 'incoming')} "
        f"AND existing_exact.{quoted_freshness} = incoming.{quoted_freshness}"
    )
    previous_event_max = (
        f"(SELECT MAX(previous_event.{quoted_freshness}) FROM {quoted_events} AS previous_event "
        f"WHERE {key_match('previous_event', 'incoming')} "
        f"AND previous_event.{quoted_freshness} < incoming.{quoted_freshness})"
    )
    previous_history_max = (
        f"(SELECT MAX(previous_history.{quoted_freshness}) FROM {quoted_history} AS previous_history "
        f"WHERE {key_match('previous_history', 'incoming')} "
        f"AND previous_history.{quoted_freshness} < incoming.{quoted_freshness})"
    )
    previous_event_signature = (
        f"(SELECT {state_signature('previous_event')} FROM {quoted_events} AS previous_event "
        f"WHERE {key_match('previous_event', 'incoming')} "
        f"AND previous_event.{quoted_freshness} < incoming.{quoted_freshness} "
        f"ORDER BY previous_event.{quoted_freshness} DESC LIMIT 1)"
    )
    previous_history_signature = (
        f"(SELECT {state_signature('previous_history')} FROM {quoted_history} AS previous_history "
        f"WHERE {key_match('previous_history', 'incoming')} "
        f"AND previous_history.{quoted_freshness} < incoming.{quoted_freshness} "
        f"ORDER BY previous_history.{quoted_freshness} DESC LIMIT 1)"
    )
    previous_signature = (
        "CASE "
        f"WHEN {previous_event_max} IS NULL THEN {previous_history_signature} "
        f"WHEN {previous_history_max} IS NULL THEN {previous_event_signature} "
        f"WHEN {previous_event_max} >= {previous_history_max} THEN {previous_event_signature} "
        f"ELSE {previous_history_signature} END"
    )
    incoming_signature = state_signature("incoming")
    exact_signature = state_signature("existing_exact")
    meaningful_change = f"""
(
    EXISTS (
        SELECT 1
        FROM {quoted_history} AS existing_exact
        WHERE {exact_match}
          AND {exact_signature} <> {incoming_signature}
    )
    OR (
        NOT EXISTS (
            SELECT 1
            FROM {quoted_history} AS existing_exact
            WHERE {exact_match}
        )
        AND (
            ({previous_event_max} IS NULL AND {previous_history_max} IS NULL)
            OR ({previous_signature}) <> {incoming_signature}
        )
    )
)
""".strip()

    event_updates = ",\n    ".join(
        f"{column} = VALUES({column})"
        for column in quoted_state
    )
    current_updates = []
    for column in quoted_columns:
        current_updates.append(
            f"{column} = IF("
            f"VALUES({quoted_freshness}) >= {quoted_current}.{quoted_freshness}, "
            f"VALUES({column}), {quoted_current}.{column})"
        )
    current_updates.append(
        "`last_seen_at` = GREATEST(COALESCE(`last_seen_at`, VALUES(`last_seen_at`)), "
        "VALUES(`last_seen_at`))"
    )
    newest_event = (
        f"NOT EXISTS (SELECT 1 FROM {quoted_events} AS newer "
        f"WHERE {key_match('newer', 'incoming')} "
        f"AND newer.{quoted_freshness} > incoming.{quoted_freshness})"
    )

    missing_sql = "SET @fuelau_expired = 0;"
    if expire_missing and not missing_means_unavailable:
        missing_sql = f"""
DELETE live
FROM {quoted_current} AS live
LEFT JOIN {quoted_events} AS incoming ON {key_match('incoming', 'live')}
WHERE incoming.{quoted_keys[0]} IS NULL;
SET @fuelau_expired = ROW_COUNT();
""".strip()
    elif missing_means_unavailable:
        quoted_availability = _quoted_identifier(str(availability_column))
        quoted_price = _quoted_identifier(str(price_column))
        missing_select = []
        for column in quoted_columns:
            if column in quoted_keys:
                missing_select.append(f"live.{column}")
            elif column == quoted_freshness:
                missing_select.append("@fuelau_seen_at")
            elif column == quoted_availability:
                missing_select.append("0")
            elif column == quoted_price:
                missing_select.append("NULL")
            else:
                missing_select.append(f"live.{column}")
        missing_updates = ", ".join(
            f"{column} = VALUES({column})"
            for column in [quoted_freshness, quoted_availability, quoted_price]
        )
        missing_sql = f"""
INSERT INTO {quoted_history} ({column_list})
SELECT {", ".join(missing_select)}
FROM {quoted_current} AS live
LEFT JOIN {quoted_events} AS incoming ON {key_match('incoming', 'live')}
WHERE incoming.{quoted_keys[0]} IS NULL
  AND (live.{quoted_availability} <> 0 OR live.{quoted_price} IS NOT NULL)
ON DUPLICATE KEY UPDATE {missing_updates};

UPDATE {quoted_current} AS live
LEFT JOIN {quoted_events} AS incoming ON {key_match('incoming', 'live')}
SET live.{quoted_freshness} = @fuelau_seen_at,
    live.{quoted_availability} = 0,
    live.{quoted_price} = NULL
WHERE incoming.{quoted_keys[0]} IS NULL
  AND (live.{quoted_availability} <> 0 OR live.{quoted_price} IS NOT NULL);
SET @fuelau_expired = ROW_COUNT();
SET @fuelau_missing_history_changes = @fuelau_expired;
""".strip()

    return f"""
SET @fuelau_seen_at = UTC_TIMESTAMP();

DROP TEMPORARY TABLE IF EXISTS {quoted_stage};
CREATE TEMPORARY TABLE {quoted_stage} LIKE {quoted_current};
ALTER TABLE {quoted_stage}
    DROP PRIMARY KEY,
    MODIFY `last_seen_at` DATETIME NULL,
    ADD COLUMN `_fuelau_stage_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;
INSERT INTO {quoted_stage} ({column_list})
VALUES
{",\n".join(values)};

DROP TEMPORARY TABLE IF EXISTS {quoted_events};
CREATE TEMPORARY TABLE {quoted_events} LIKE {quoted_history};
INSERT INTO {quoted_events} ({column_list})
SELECT {column_list}
FROM {quoted_stage}
ORDER BY `_fuelau_stage_id`
ON DUPLICATE KEY UPDATE
    {event_updates};

START TRANSACTION;
SET @fuelau_missing_history_changes = 0;
SET @fuelau_api_rows = (SELECT COUNT(*) FROM {quoted_stage});
SET @fuelau_current_rows = (
    SELECT COUNT(*) FROM {quoted_events} AS incoming WHERE {newest_event}
);
SET @fuelau_history_changes = (
    SELECT COUNT(*) FROM {quoted_events} AS incoming WHERE {meaningful_change}
);

INSERT INTO {quoted_history} ({column_list})
SELECT {column_list}
FROM {quoted_events} AS incoming
WHERE {meaningful_change}
ON DUPLICATE KEY UPDATE
    {event_updates};

INSERT INTO {quoted_current} ({column_list}, `last_seen_at`)
SELECT {column_list}, @fuelau_seen_at
FROM {quoted_events} AS incoming
WHERE {newest_event}
ON DUPLICATE KEY UPDATE
    {",\n    ".join(current_updates)};

{missing_sql}
COMMIT;

SELECT CONCAT(
    'FUELAU_METRICS:',
    'api_rows=', @fuelau_api_rows,
    ',current_rows=', @fuelau_current_rows,
    ',history_changes=', @fuelau_history_changes + @fuelau_missing_history_changes,
    ',unchanged=', GREATEST(@fuelau_api_rows - @fuelau_history_changes, 0),
    ',expired=', @fuelau_expired
);

DROP TEMPORARY TABLE {quoted_events};
DROP TEMPORARY TABLE {quoted_stage};
""".strip()
