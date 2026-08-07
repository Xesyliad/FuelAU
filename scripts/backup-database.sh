#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="${FUELAU_PROJECT_ROOT:-/opt/FuelAU}"
BACKUP_DIR="${FUELAU_BACKUP_DIR:-${PROJECT_ROOT}/var/backups}"
BACKUP_BUCKET="${FUELAU_BACKUP_BUCKET:-fuelau-production-backups}"
AWS_SHARED_CREDENTIALS_FILE="${AWS_SHARED_CREDENTIALS_FILE:-/root/.aws/credentials}"
AWS_PROFILE="${AWS_PROFILE:-fuelau-mcp}"
AWS_REGION="${AWS_REGION:-ap-southeast-2}"
BACKUP_TIMEZONE="${FUELAU_BACKUP_TIMEZONE:-Australia/Brisbane}"
LOCAL_BACKUPS_TO_KEEP="${FUELAU_LOCAL_BACKUPS_TO_KEEP:-7}"
DAILY_BACKUPS_TO_KEEP="${FUELAU_DAILY_BACKUPS_TO_KEEP:-7}"
WEEKLY_BACKUPS_TO_KEEP="${FUELAU_WEEKLY_BACKUPS_TO_KEEP:-4}"
MONTHLY_BACKUPS_TO_KEEP="${FUELAU_MONTHLY_BACKUPS_TO_KEEP:-6}"
LOCK_FILE="${FUELAU_BACKUP_LOCK_FILE:-/run/lock/fuelau-database-backup.lock}"
STATUS_FILE="${FUELAU_BACKUP_STATUS_FILE:-${PROJECT_ROOT}/var/docker/backup-status.json}"

export AWS_SHARED_CREDENTIALS_FILE AWS_PROFILE AWS_REGION

partial_backup=""
partial_status=""

log() {
    printf '%s %s\n' "$(date --iso-8601=seconds)" "$*"
}

cleanup() {
    if [[ -n "${partial_backup}" && -f "${partial_backup}" ]]; then
        rm -f -- "${partial_backup}"
    fi
    if [[ -n "${partial_status}" && -f "${partial_status}" ]]; then
        rm -f -- "${partial_status}"
    fi
}

trap cleanup EXIT
trap 'log "Backup failed at line ${LINENO}."' ERR

for command_name in aws docker flock gzip python3; do
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        log "Required command is unavailable: ${command_name}"
        exit 1
    fi
done

if [[ ! -r "${AWS_SHARED_CREDENTIALS_FILE}" ]]; then
    log "AWS credentials are not readable: ${AWS_SHARED_CREDENTIALS_FILE}"
    exit 1
fi

if [[ ! -d "${PROJECT_ROOT}" ]]; then
    log "Project root does not exist: ${PROJECT_ROOT}"
    exit 1
fi

mkdir -p "${BACKUP_DIR}" "$(dirname "${LOCK_FILE}")"
exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
    log "Another database backup is already running; skipping this invocation."
    exit 0
fi

utc_timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
local_path="$(TZ="${BACKUP_TIMEZONE}" date +%Y/%m/%d)"
local_week="$(TZ="${BACKUP_TIMEZONE}" date +%G-W%V)"
local_month="$(TZ="${BACKUP_TIMEZONE}" date +%Y-%m)"
local_weekday="$(TZ="${BACKUP_TIMEZONE}" date +%u)"
local_month_day="$(TZ="${BACKUP_TIMEZONE}" date +%d)"
backup_name="fuelau-production-${utc_timestamp}.sql.gz"
final_backup="${BACKUP_DIR}/${backup_name}"
partial_backup="${BACKUP_DIR}/.${backup_name}.partial"

log "Creating transaction-consistent MariaDB backup ${backup_name}."
(
    cd "${PROJECT_ROOT}"
    docker compose exec -T db sh -c \
        'exec mariadb-dump --user=root --password="$MYSQL_ROOT_PASSWORD" --single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 "$MYSQL_DATABASE"'
) | gzip -9 >"${partial_backup}"

gzip -t "${partial_backup}"
verification="$({
    PYTHONPATH="${PROJECT_ROOT}/src" python3 -c \
        'from pathlib import Path; import sys; from history_cleanup import verify_backup; digest, size = verify_backup(Path(sys.argv[1])); print(digest, size)' \
        "${partial_backup}"
})"
read -r backup_sha256 backup_bytes <<<"${verification}"

mv -- "${partial_backup}" "${final_backup}"
partial_backup=""
log "Verified local backup: bytes=${backup_bytes} sha256=${backup_sha256}."

daily_key="database/daily/${local_path}/fuelau-production.sql.gz"
log "Uploading s3://${BACKUP_BUCKET}/${daily_key}."
aws s3api put-object \
    --bucket "${BACKUP_BUCKET}" \
    --key "${daily_key}" \
    --body "${final_backup}" \
    --content-type application/gzip \
    --server-side-encryption AES256 \
    --checksum-algorithm SHA256 \
    --metadata "sha256=${backup_sha256},verified=true,source=fuelau-production,source_filename=${backup_name}" \
    --output json >/dev/null

copy_backup() {
    local destination_key="$1"
    log "Copying verified backup to s3://${BACKUP_BUCKET}/${destination_key}."
    aws s3api copy-object \
        --bucket "${BACKUP_BUCKET}" \
        --copy-source "${BACKUP_BUCKET}/${daily_key}" \
        --key "${destination_key}" \
        --metadata-directive COPY \
        --server-side-encryption AES256 \
        --checksum-algorithm SHA256 \
        --output json >/dev/null
}

prune_s3_prefix() {
    local prefix="$1"
    local keep="$2"
    local stale_keys
    local stale_key

    stale_keys="$(aws s3api list-objects-v2 \
        --bucket "${BACKUP_BUCKET}" \
        --prefix "${prefix}" \
        --query "reverse(sort_by(Contents,&LastModified))[${keep}:].Key" \
        --output text)"

    if [[ -z "${stale_keys}" || "${stale_keys}" == "None" ]]; then
        return
    fi

    for stale_key in ${stale_keys}; do
        log "Pruning s3://${BACKUP_BUCKET}/${stale_key}."
        aws s3api delete-object \
            --bucket "${BACKUP_BUCKET}" \
            --key "${stale_key}" \
            --output json >/dev/null
    done
}

prune_s3_prefix "database/daily/" "${DAILY_BACKUPS_TO_KEEP}"

if [[ "${local_weekday}" == "7" ]]; then
    copy_backup "database/weekly/${local_week}/fuelau-production.sql.gz"
    prune_s3_prefix "database/weekly/" "${WEEKLY_BACKUPS_TO_KEEP}"
fi

if [[ "${local_month_day}" == "01" ]]; then
    copy_backup "database/monthly/${local_month}/fuelau-production.sql.gz"
    prune_s3_prefix "database/monthly/" "${MONTHLY_BACKUPS_TO_KEEP}"
fi

local_removed="$({
    python3 -c \
        'from pathlib import Path; import sys; root = Path(sys.argv[1]); keep = int(sys.argv[2]); files = sorted(root.glob("fuelau-production-*.sql.gz"), key=lambda path: path.stat().st_mtime, reverse=True); stale = files[keep:]; [path.unlink() for path in stale]; print(len(stale))' \
        "${BACKUP_DIR}" "${LOCAL_BACKUPS_TO_KEEP}"
})"

mkdir -p "$(dirname "${STATUS_FILE}")"
partial_status="${STATUS_FILE}.tmp.$$"
completed_at_utc="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
python3 -c \
    'import json, sys; payload = {"version": 1, "verified": True, "completed_at_utc": sys.argv[1], "bucket": sys.argv[2], "key": sys.argv[3], "source_filename": sys.argv[4], "bytes": int(sys.argv[5]), "sha256": sys.argv[6]}; open(sys.argv[7], "w", encoding="utf-8").write(json.dumps(payload, indent=2, sort_keys=True) + "\n")' \
    "${completed_at_utc}" \
    "${BACKUP_BUCKET}" \
    "${daily_key}" \
    "${backup_name}" \
    "${backup_bytes}" \
    "${backup_sha256}" \
    "${partial_status}"
chmod 644 "${partial_status}"
mv -- "${partial_status}" "${STATUS_FILE}"
partial_status=""

log "Backup completed: s3://${BACKUP_BUCKET}/${daily_key}; pruned_local=${local_removed}."
