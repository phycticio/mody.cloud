#!/bin/sh
# mody-backup: production's backups (tickets/backups.md).
#
#   mody-backup db       Dump the database, then send the dump off site.
#                        Every 6 hours.
#   mody-backup full     Dump the database, then send the dump, the uploads,
#                        the server's .env and compose.prod.yml, and Nginx
#                        Proxy Manager's data off site. Every night.
#   mody-backup prune    Forget old snapshots (the retention below), prune,
#                        and check part of the data. Every week.
#   mody-backup init     Create the restic repository (once).
#   mody-backup status   List the snapshots.
#
# Off site is a restic repository: encrypted here with RESTIC_PASSWORD before
# anything leaves, deduplicated and compressed. RESTIC_REPOSITORY and the
# storage credentials come from the environment (OS_* for Swift, as
# Infomaniak Swiss Backup gives them, or AWS_* for S3); restic reads them
# itself.
#
# The dump is also kept on the laptop's internal disk (/backups/db), a
# different disk from the sites: the latest as mody_cloud.sql (always the
# same name, so restic deduplicates it between snapshots), and the last
# BACKUP_KEEP_LOCAL_DUMPS gzipped copies in /backups/db/history.
#
# Each success pings BACKUP_PING_URL (an Uptime Kuma push monitor) with
# status=up; a failure pings status=down and exits non-zero, which Ofelia
# logs as failed.
set -eu
set -o pipefail
# The dumps hold every secret in the database: readable by their owner only.
umask 077

LOCAL=/backups
SOURCES=/sources
HOST_TAG="${BACKUP_HOST:-mody.cloud}"
DB_HOST="${BACKUP_DB_HOST:-database}"
DB_NAME="${BACKUP_DB_NAME:?BACKUP_DB_NAME is not set}"
DB_USER="${BACKUP_DB_USER:?BACKUP_DB_USER is not set}"
KEEP_LOCAL="${BACKUP_KEEP_LOCAL_DUMPS:-12}"
export RESTIC_CACHE_DIR="${RESTIC_CACHE_DIR:-$LOCAL/restic-cache}"

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

ping_monitor() { # status message
	[ -n "${BACKUP_PING_URL:-}" ] || return 0
	curl -fsS -m 15 -o /dev/null -G "$BACKUP_PING_URL" \
		--data-urlencode "status=$1" --data-urlencode "msg=$2" --data-urlencode "ping=" \
		|| log "warning: could not reach the monitor"
}

COMMAND="${1:-}"
STEP="starting"
on_exit() {
	code=$?
	if [ "$code" -ne 0 ]; then
		rm -f "$LOCAL/db/$DB_NAME.sql.part"
		log "FAILED: $COMMAND ($STEP), exit $code"
		ping_monitor down "mody-backup $COMMAND failed: $STEP"
	fi
}
trap on_exit EXIT

need_repository() {
	: "${RESTIC_REPOSITORY:?RESTIC_REPOSITORY is not set}"
	: "${RESTIC_PASSWORD:?RESTIC_PASSWORD is not set}"
}

# The database, consistent (one transaction), into /backups/db.
dump() {
	STEP="database dump"
	mkdir -p "$LOCAL/db/history"
	part="$LOCAL/db/$DB_NAME.sql.part"
	MYSQL_PWD="${BACKUP_DB_PASSWORD:?BACKUP_DB_PASSWORD is not set}" mariadb-dump --no-defaults \
		-h "$DB_HOST" -u "$DB_USER" \
		--single-transaction --quick --routines --triggers --events --hex-blob \
		"$DB_NAME" > "$part"
	# A dump that stopped half way has no "Dump completed" line.
	tail -n 1 "$part" | grep -q 'Dump completed' || { log "the dump is incomplete"; return 1; }
	mv "$part" "$LOCAL/db/$DB_NAME.sql"
	gzip -6 -c "$LOCAL/db/$DB_NAME.sql" > "$LOCAL/db/history/$DB_NAME-$(date -u +%Y%m%d-%H%M%S).sql.gz"
	# Only the newest KEEP_LOCAL copies stay on the laptop.
	ls -1t "$LOCAL/db/history/"*.sql.gz 2>/dev/null | tail -n +"$((KEEP_LOCAL + 1))" | while read -r old; do rm -f "$old"; done
	log "dumped $DB_NAME: $(du -h "$LOCAL/db/$DB_NAME.sql" | cut -f1)"
}

# The paths of a full backup that exist here (a missing source is logged, not fatal).
full_paths() {
	echo "$LOCAL/db/$DB_NAME.sql"
	for path in "$SOURCES/uploads" "$SOURCES/server" "$SOURCES/npm/data" "$SOURCES/npm/letsencrypt"; do
		if [ -e "$path" ]; then echo "$path"; else log "warning: $path is missing, not backed up" >&2; fi
	done
}

case "$COMMAND" in
	db)
		need_repository
		dump
		STEP="restic backup (db)"
		restic backup --host "$HOST_TAG" --tag db "$LOCAL/db/$DB_NAME.sql"
		ping_monitor up "db"
		;;
	full)
		need_repository
		dump
		STEP="restic backup (full)"
		# shellcheck disable=SC2046 # Paths without spaces, one per line.
		restic backup --host "$HOST_TAG" --tag full $(full_paths)
		ping_monitor up "full"
		;;
	prune)
		need_repository
		STEP="restic forget (db)"
		restic forget --host "$HOST_TAG" --tag db --keep-last 8 --keep-daily 7 --keep-weekly 4 --keep-monthly 12
		STEP="restic forget (full)"
		restic forget --host "$HOST_TAG" --tag full --keep-daily 7 --keep-weekly 4 --keep-monthly 12
		STEP="restic prune"
		restic prune
		STEP="restic check"
		restic check --read-data-subset=5%
		ping_monitor up "prune"
		;;
	init)
		need_repository
		STEP="restic init"
		if restic cat config >/dev/null 2>&1; then
			log "the repository already exists"
		else
			restic init
		fi
		;;
	status)
		need_repository
		STEP="restic snapshots"
		restic snapshots --host "$HOST_TAG"
		;;
	*)
		echo "usage: mody-backup db|full|prune|init|status" >&2
		exit 2
		;;
esac
log "done: $COMMAND"
