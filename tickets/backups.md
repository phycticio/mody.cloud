# Backups: production off the laptop, every night, and a restore drill

**Builds on:** [M5-01](M5-01-hardening-launch.md) (backup and restore drill) · **Status:** planned and built 2026-11-14; running on production since 2026-11-14 (`7ac84f1`); Uptime Kuma alerts on; the first drill passed the same day.

## Asked for
By the maintainer on 2026-11-14, after deciding that production stays on the laptop at launch:

> What's your backup solution proposal?

Then:

> I purchased the infomaniak backup service so let's use that. 200Gb space.

## Decisions (the maintainer's, 2026-11-14)
1. **Off site at Infomaniak Swiss Backup** (Switzerland, rule 1), 200 GB.
2. **restic** encrypts everything on the laptop before it leaves, deduplicates and compresses. Infomaniak never sees the data.
3. **The database every 6 hours, everything else every night**, so a failure loses at most 6 hours of members' work. Payments are also recorded at Mollie.
4. **Retention:**
   - database snapshots: the last 8, then 7 daily, 4 weekly and 12 monthly;
   - full snapshots: 7 daily, 4 weekly and 12 monthly.
5. **A second copy on the laptop's internal disk**, apart from the sites' external disk (Extreme): the latest dump, and 12 gzipped earlier ones (3 days).

## What is backed up
| What | Where on the server | When |
| --- | --- | --- |
| The database (`mariadb-dump --single-transaction`, as the WordPress user) | the `mody_cloud_data` volume, read through the `database` service | every 6 hours and nightly |
| The uploads | `app/web/uploads/` | nightly |
| The server's own files, which are not in git | `.env`, `compose.prod.yml` | nightly |
| Nginx Proxy Manager: proxy hosts and certificates, which custom domains need | the volumes `proxy_data` and `proxy_letsencrypt` | nightly |

The code is not backed up: it is in git and reaches the server through `./bin/deploy`. The GeoIP file is rebuilt with `bin/geoip`. Exports (`app/dumps/`) live for 3 days only.

## How it works
- **The image** (`config/backup/Dockerfile`): Alpine 3.22 with restic 0.18, the MariaDB 11.4 client and curl, plus the `mody-backup` script (`config/backup/mody-backup.sh`). It idles.
- **The service** (`backup` in `compose.prod.yml.sample`):
  - gets only the database credentials, the repository's settings and the monitor's address, never the whole `.env`;
  - mounts the sources read-only;
  - joins the proxy's network to reach Uptime Kuma.
- **The schedule:** the host's Ofelia runs the jobs with `job-exec`, in UTC: `mody-backup db` at 00:00, 06:00, 12:00 and 18:00; `mody-backup full` at 02:30; `mody-backup prune` on Sundays at 04:30 (forget, prune, then check 5% of the data). There is no overlap, and restic's own locks apply.
- **Safety:**
  - a dump without its "Dump completed" line is refused, and the previous dump stays;
  - every file is readable by its owner only (`umask 077`): the dump holds every secret in the database.
- **Alerts:** each success pings `BACKUP_PING_URL` (an Uptime Kuma push monitor) with `status=up`, and each failure with `status=down`. A failure also exits non-zero, which Ofelia logs as failed. With a 7-hour heartbeat, a missed run alerts too.
- **The restore drill** (`bin/restore-drill`, on the maintainer's Mac):
  1. builds the same image;
  2. checks the newest database snapshot is at most 7 hours old and the newest full one at most 26;
  3. restores the newest full snapshot;
  4. imports the dump into a scratch MariaDB 11.4 (never the local stack's database);
  5. checks the sites, members and users, every site's published pages, every attachment's file in the restored uploads, and the server's files and Proxy Manager's data;
  6. removes everything it made.

  The repository's settings come from `.env.backup` at the repository root (ignored by git), with the same values as on the server.

## Results (2026-11-14, locally)
- **The tests** ran against the local stack, with a restic repository in a Docker volume:
  - `init` (twice: the second says the repository exists);
  - `db`: 68 MB of SQL stored as 2 MB;
  - the next `db` added 43 KB, because the deduplication works;
  - `full` and `prune`, where retention kept what it should and the check found no errors.
- **A wrong database password:** the job exits 2, pings `status=down`, keeps the previous dump and leaves no partial file.
- **The local copies:** retention kept the configured number, and every file is mode 600.
- **`bin/restore-drill --repo-volume=…`:**
  - the snapshots' ages pass;
  - the restore and the import into MariaDB 11.4.7 pass;
  - 6 sites, 5 members and 7 users;
  - every attachment's file is there;
  - `.env`, `compose.prod.yml` and Proxy Manager's data came back.

  The drill passed and left nothing behind.

## On production (2026-11-14)
- **The service:** the server's `compose.prod.yml` got the `backup` service and the two Proxy Manager volumes, added by the maintainer from the prepared file; the old file is `compose.prod.yml.before-backups-20261114`. It reuses the `proxy` network the file already declared.
- **The build:** the server's `~/.docker/config.json` still names Docker Desktop's credential helper (`"credsStore": "desktop"`), which is not installed under Colima, so `docker compose build` failed. It was built with a temporary `DOCKER_CONFIG` holding `{}` and a link to `~/.docker/cli-plugins`, and started alone (`up -d --no-deps backup`). Do the same after a change to `config/backup/`, or remove `credsStore` from that file.
- **The repository:** `mody-backup init` created it at `swift:mody-cloud-backups:/` (Swiss Backup, `swiss-backup02`).
- **The first full backup** (12:00 UTC): 626 files, 115 MB, stored as 19 MB, in 5 seconds. The local dump is in `/Users/javo/backups/mody.cloud/db`, owned by `javo`, mode 600.
- **Ofelia,** restarted: it registered `mody-backup-db`, `mody-backup-full` and `mody-backup-prune`, and the other 9 jobs of the host came back.
- **Uptime Kuma:** after the maintainer put the push monitor's real token in `BACKUP_PING_URL`, the push answers `{"ok":true}`.
- **The first database snapshot** (12:13 UTC): 12.2 MB of SQL added 0.5 MB to the repository.
- **The first drill** (`./bin/restore-drill` on the Mac, 28 s), run with `.env.backup` holding the server's `RESTIC_*` and `OS_*` lines (mode 600):
  - both snapshots were minutes old;
  - the restore and the import into MariaDB 11.4.7 passed;
  - 3 sites, 2 members and 3 users, which matches production;
  - every site's pages, and all 15 attachment files, are there;
  - `.env`, `compose.prod.yml` and Proxy Manager's database and certificates came back.

  It passed and left nothing behind.
- **A stray copy of the secrets:** the server also has `/Volumes/Extreme/mody.cloud/.env.backup`, a full copy of its `.env` made on 2026-11-14. The deploy never touches it, and the backup does not include it. Delete it once it is no longer needed.

## Setting it up on the server
1. **Deploy:** `./bin/deploy` ships `config/backup/`, `bin/restore-drill` and the sample.
2. **Swiss Backup:** in Infomaniak's Manager, create a device of the "Cloud backup" kind (OpenStack Swift) and open its connection details.
3. **The server's `.env`:** add `RESTIC_REPOSITORY=swift:mody-cloud-backups:/`, a long random `RESTIC_PASSWORD`, and the `OS_*` values (see `sample.env`). Keep `RESTIC_PASSWORD` in a password manager too.
4. **The server's `compose.prod.yml`:** add the `backup` service and the `volumes:` and `networks:` blocks from the sample.
5. **Uptime Kuma:** add a Push monitor with a 7-hour heartbeat. Put its URL in `BACKUP_PING_URL` as `http://uptime_kuma:3001/api/push/<token>`.
6. **Start it:** `mkdir -p ~/backups/mody.cloud`, `docker compose build backup`, `docker compose up -d backup`, `docker exec mody_cloud_backup mody-backup init`, `docker exec mody_cloud_backup mody-backup full`, then `docker restart ofelia`, so it reads the new labels (every site's WP-Cron pauses for about a minute).
7. **The first drill, on the Mac:** create `.env.backup` with the same `RESTIC_*` and `OS_*` values, then run `./bin/restore-drill`.

## Restoring production (the runbook)
On the laptop, or on a new machine with Docker and this repository:
1. `./bin/restore-drill --keep`, or `restic restore latest --tag full --target <dir>` with the image, gives back the dump, `uploads/`, `.env`, `compose.prod.yml` and Proxy Manager's data.
2. Deploy the code with `./bin/deploy` (or copy the repository). Put back `.env` and `compose.prod.yml`, and `app/web/uploads/`.
3. `docker compose up -d database`, then import the dump: `docker exec -i mody_cloud_db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb --no-defaults -u"$MARIADB_USER" "$MARIADB_DATABASE"' < mody_cloud.sql`. Then `docker compose up -d`.
4. Proxy Manager: restore `proxy_data` and `proxy_letsencrypt` into its volumes before starting it.
5. Check `https://mody.cloud/` and one member site, and log in. On a new machine, point DNS (Cloudflare) at the new address.

## Open
- **The full-stack check of the drill**: WordPress started on the restored data, with a member site opened in a browser. The drill checks the data (it passed on 2026-11-14). This check is the rest of M5-01's acceptance line, and it is done once by hand with `--keep`.
- **The other projects on the laptop** (the other WordPress stacks, Immich, Akaunting) are not covered. The same image could cover them.
