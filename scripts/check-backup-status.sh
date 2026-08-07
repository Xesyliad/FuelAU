#!/usr/bin/env bash

set -Eeuo pipefail

STATUS_FILE="${FUELAU_BACKUP_STATUS_FILE:-/opt/FuelAU/var/docker/backup-status.json}"
MAX_AGE_SECONDS="${FUELAU_BACKUP_MAX_AGE_SECONDS:-86400}"

if message="$(python3 -c '
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

path = Path(sys.argv[1])
max_age = int(sys.argv[2])
if max_age <= 0:
    raise ValueError("maximum backup age must be positive")
if not path.is_file():
    print(f"backup status file is missing: {path}")
    raise SystemExit(1)
try:
    payload = json.loads(path.read_text(encoding="utf-8"))
    completed = datetime.fromisoformat(str(payload["completed_at_utc"]).replace("Z", "+00:00"))
except (KeyError, TypeError, ValueError, json.JSONDecodeError) as error:
    print(f"backup status file is invalid: {error}")
    raise SystemExit(1)
if payload.get("verified") is not True or completed.tzinfo is None:
    print("backup status is not a verified timezone-aware completion")
    raise SystemExit(1)
now = datetime.now(timezone.utc)
if completed.astimezone(timezone.utc).timestamp() > now.timestamp() + 300:
    print("backup completion time is unexpectedly in the future")
    raise SystemExit(1)
age = max(0, int((now - completed.astimezone(timezone.utc)).total_seconds()))
if age > max_age:
    print(f"latest verified backup is stale: age_seconds={age} max_age_seconds={max_age}")
    raise SystemExit(1)
' "${STATUS_FILE}" "${MAX_AGE_SECONDS}")"; then
    exit 0
fi

alert="ALERT: FuelAU ${message}"
printf '%s\n' "${alert}" >&2
logger --priority daemon.err --tag fuelau-backup "${alert}"
exit 1
