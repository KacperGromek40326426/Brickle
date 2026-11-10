#!/bin/sh
set -eu

if [ -z "${BRICKLE_BACKUP_DIR:-}" ]; then
    printf '%s\n' 'Set BRICKLE_BACKUP_DIR to a private directory outside the web root.' >&2
    exit 1
fi

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
project_dir=$(dirname "$script_dir")
compose_file=${BRICKLE_COMPOSE_FILE:-"$project_dir/docker-compose.production.yml"}
timestamp=$(date -u +%Y%m%dT%H%M%SZ)

umask 077
mkdir -p "$BRICKLE_BACKUP_DIR"
chmod 700 "$BRICKLE_BACKUP_DIR"
temporary_file=$(mktemp "$BRICKLE_BACKUP_DIR/.brickle-$timestamp.XXXXXX")
backup_file="$BRICKLE_BACKUP_DIR/brickle-$timestamp.sqlite"

cleanup() {
    if [ -f "$temporary_file" ]; then
        rm -f "$temporary_file"
    fi
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

if [ -e "$backup_file" ]; then
    printf 'A backup already exists for %s.\n' "$timestamp" >&2
    exit 1
fi

docker compose -f "$compose_file" exec -T --user www-data web php /usr/local/bin/brickle-backup.php > "$temporary_file"
if [ ! -s "$temporary_file" ]; then
    printf '%s\n' 'The database backup was empty.' >&2
    exit 1
fi

chmod 600 "$temporary_file"
mv "$temporary_file" "$backup_file"
printf 'Created SQLite backup: %s\n' "$backup_file"
