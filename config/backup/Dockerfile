# The backup container (tickets/backups.md): restic, the MariaDB client and
# curl, with the mody-backup script. It idles; the Ofelia scheduler on the
# host runs `mody-backup <command>` inside it (job-exec labels in
# compose.prod.yml.sample).
#
# Built on the server by `docker compose build backup`, and on the
# maintainer's Mac by bin/restore-drill.
FROM alpine:3.22

RUN apk add --no-cache \
    ca-certificates \
    curl \
    mariadb-client \
    restic \
    tzdata

COPY --chmod=0755 mody-backup.sh /usr/local/bin/mody-backup

# Idle until Ofelia (or a person) runs mody-backup with `docker exec`.
ENTRYPOINT ["sleep", "infinity"]
