# Move local Docker from Docker Desktop to Colima

**Milestone:** none (developer environment) · **Spec:** [architecture.md](../documentation/architecture.md), [local-development.md](../documentation/local-development.md) · **Status:** in progress (started 2026-11-03)

## Goal

Run every local container on this Mac on Colima, then uninstall Docker Desktop. No data is lost: the `mody_cloud` stack, the `tims` stack (Tamarind) and Nginx Proxy Manager keep their databases, mail and certificates.

## Starting point (2026-11-03)

- Docker Desktop VM: 4 CPUs, 12 GB. Eleven containers in three projects:
  - `mody_cloud_*` (this repo): `mody_cloud_app`, `_db`, `_server`, `_redis`, `_mail`.
  - `tims_*` (`~/web/tamarind/wp-private-content`): `tims_app`, `_db`, `_server`, `_redis`, `_mail`.
  - `npm-app-1` (`~/web/npm`): Nginx Proxy Manager, owns host ports 80, 81 and 443.
- Named volumes to migrate: `mody_cloud_data` (182 MB), `mody_cloud_mail_data`, `tims_data` (7.3 GB), `tims_mail_data`.
- NPM uses bind mounts (`data/`, `letsencrypt/`, 3.4 MB), not volumes. Its container is renamed to `proxy_app`.
- Every NPM proxy host forwards to `host.docker.internal:<port>`: 4435 (mody), 4434 (tims), 4433 (a stack that is not running), 81 (NPM admin).
- Colima is not installed. Homebrew has colima 0.10.3, docker 29.9.2, docker-compose 5.6.1 and docker-buildx 0.38.1.
- Docker Desktop leftovers that break the CLI after removal: `/usr/local/bin/docker*` and `~/.docker/cli-plugins/*` symlinks into `Docker.app`, and `"credsStore": "desktop"` in `~/.docker/config.json`.

## Decisions

- Migrate the data of all containers: all four volumes, and NPM's bind-mounted directories.
- Rename the NPM container and its Compose project to `proxy` (`proxy_app`), matching `mody_cloud_*` and `tims_*`.
- Xdebug is out of scope. Debugging is done with Query Monitor, so the `host-gateway` behaviour on Colima is documented, not fixed.
- Colima starts at login through `brew services`, replacing the LaunchAgent the original guide suggested.
- Docker Desktop is uninstalled once the checks below pass. Until then it stays installed and can be restarted as the rollback.
- Backups live in `~/docker-migration-backup/` (outside the repository, mode 700). They are never committed.

## Tasks

- [x] **A. Backups.** Container inventory, `mariadb-dump` of both databases, tar of all four volumes (each one verified with `gzip -t`), copies of `~/web/npm`, `.env` files and `~/.docker/config.json`.
- [x] **B. Tooling.** `brew install colima docker docker-compose docker-buildx docker-credential-helper`. Add `cliPluginsExtraDirs` to `~/.docker/config.json`. Point `~/.docker/cli-plugins/docker-compose` and `docker-buildx` at the Homebrew binaries.
- [x] **C. Colima and spike.** Docker Desktop quit. `colima start` with 6 CPUs, 10 GiB, 100 GiB, `vz`, `aarch64`, and `mountType: sshfs` (see Outcomes for why not virtiofs). Checks:
  - ports 80 and 443 publish and are reachable on `localhost`: **pass**;
  - `host.docker.internal` resolves to `192.168.5.2` and containers reach a Mac service on `127.0.0.1`: **pass**;
  - ownership of the bind-mounted repo: **fail with virtiofs, pass with sshfs plus `USER_ID=501`**.
- [x] **D. Restore.** Four volumes restored from the verified tars. `USER_ID=501` set in `.env` (mody) and tims `.env`. `mody` and `tims` built and started, both databases healthy. NPM **not yet running cleanly**: see Outcomes.
- [x] **E. Verify.** Acceptance checks below, with the exceptions noted in Outcomes.
- [ ] **F. Uninstall Docker Desktop.** Run its uninstaller (sudo and GUI steps are handed to the user). Remove the `/usr/local/bin` and `~/.docker` leftovers. Change `credsStore` to `osxkeychain`. Point PhpStorm's Docker connection at the Colima socket.
- [x] **G. Documentation.** Update `documentation/local-development.md` (Colima on macOS, `USER_ID`, the proxy name) and the `USER_ID` note in `sample.env`.

## Known risks

- **Ports 80, 81 and 443 under Colima** are not documented by the Colima project. Task C settles this. If they do not bind on `localhost`, the work stops and the options are reviewed with the user.
- **`extra_hosts: host.docker.internal:host-gateway`** (`compose.yml`) resolved to the Colima VM in older Colima releases ([colima #277](https://github.com/abiosoft/colima/issues/277), [#949](https://github.com/abiosoft/colima/issues/949)). On Colima 0.10.3 here it resolves to `192.168.5.2` (the Mac), as does the default. Not changed. Xdebug is out of scope.
- **File ownership.** `app/` is owned by uid 501. Under Colima `virtiofs`, containers see every repo file as `0:0`, so `www-data` cannot write. `sshfs` preserves `501:20`, so `USER_ID=501` fixes it. Trade-off: sshfs is slower and less reliable under heavy concurrent I/O, and file watching does not propagate.
- **NPM bind mounts on sshfs.** NPM's init script runs `chown` on `/data` and `/etc/letsencrypt`. sshfs denies `chown` for root, so the init fails and nginx does not start.
- **Consistent database copies.** The volume tars are taken after the database containers stop. The `mariadb-dump` files are an extra, logical copy.
- **Port conflicts during cutover.** NPM on Docker Desktop must stop before NPM on Colima starts, because both need 80 and 443.
- **`pull_policy: never`** in `compose.yml`: the locally built `mody_cloud_wordpress` image does not exist on Colima yet, so the first start needs `--build`.

## Acceptance

- `docker context` shows `colima` as the active context, and `docker info` reports the Colima VM.
- All eleven containers run, and `restart: unless-stopped` brings them back after `brew services restart colima`.
- `bash scripts/validate-m0-01.sh` output matches the baseline in `tickets/log.txt` (sites 1, 2 and 3, super admins, `home` and `siteurl`, theme, certificate SANs, live HTTPS through the proxy).
- `https://mody.local`, `https://projects.local` (NPM admin) and the tims hosts load through the proxy: **pass** (200, 200, 302).
- Exceptions, both **pre-existing and not caused by this migration**: `mail.mody.local` (Mailpit) has no NPM route and is served by the mody stack on port 4435 (200). `alpha.mody.local` and `beta.mody.local` need the wildcard NPM host that M0-01 lists as outstanding.
- WordPress in `mody_cloud_app` can write to `app/` (for example `logs/`).
- Docker Desktop is removed. `docker version`, `docker compose version` and `docker buildx version` use the Homebrew binaries.
- `documentation/local-development.md` and `sample.env` describe the Colima setup.

## Outcomes

**A. Backups (done, verified).** In `~/docker-migration-backup/` (mode 700, not in the repo):
- `mody_cloud_data.tar.gz` (2.7 MB), `mody_cloud_mail_data.tar.gz`, `tims_data.tar.gz` (982 MB), `tims_mail_data.tar.gz`. All pass `gzip -t`. The mody and tims archives list the MariaDB files.
- `mody_cloud_db.sql` (`mariadb-dump`, 4 MB, "Dump completed").
- A logical tims dump was stopped at 3.5 GB and deleted: it was larger than the volume's logical content and redundant with the raw archive.
- `npm/` (identical tree to `~/web/npm`), `mody.cloud.env`, `tims.env`, `docker-config.json.bak`, and the container inventory.

**B. Tooling (done).** colima 0.10.3, docker 29.9.2, docker-compose 5.7.0, docker-buildx 0.38.1 and docker-credential-helper 0.9.12 from Homebrew. `cliPluginsExtraDirs` is set in `~/.docker/config.json`. `~/.docker/cli-plugins/docker-compose` and `docker-buildx` point at Homebrew.

**C. Colima (done, with two corrections to the original plan).**
- `--mount-type virtiofs` on the command line was ignored, and the VM came up on `sshfs`. The template at `~/.colima/_templates/default.yaml` now holds the settings, and the instance config also needs `mounts:` set to `~` writable. Without it, no home mount exists in the VM and Docker creates the bind source as root.
- **virtiofs does not work for this repo.** Containers see every repo file as `0:0`, so uid 1000 and uid 501 both lose write access to `app/`. **sshfs works**: containers see `501:20`, uid 501 can write, and `USER_ID=501` makes `www-data` match the owner. The VM is `sshfs`, 6 CPUs, 10 GiB, 100 GiB, `vz`, `aarch64`, engine 29.5.2.
- Ports 80 and 443 publish and answer on `localhost`. `host.docker.internal` resolves to `192.168.5.2` by default, and containers reach a Mac service on `127.0.0.1` through it.
- Docker Desktop is quit. Its privileged helper `com.docker.vmnetd` is still installed and will be removed with the uninstaller.

**D. Restore.**
- `mody` (`proxy` not involved): restored and healthy. `wp_options` has 153 rows and `wp_blogs` 3. `validate-m0-01.sh` matches the baseline in `tickets/log.txt` line for line, including live HTTPS for alpha and beta.
- `tims`: restored, `tims_db` healthy, `local` database present. Its web routes through NPM are not yet verified.
- `USER_ID=501` is set in `.env` (mody) and in the tims `.env`. Both `.env` files are git-ignored.
- **NPM: not running cleanly.** `~/web/npm/compose.yml` is unchanged. It starts with the old name `npm-app-1` on ports 80, 81 and 443. Its init fails on `chown` under sshfs, so nginx does not start and the proxy does not answer. The planned `proxy_app` rename was declined as an edit to `compose.yml`.

**D (continued). NPM fixed.** You chose named volumes with the rename. `~/web/npm/compose.yml` now sets `name: proxy`, `container_name: proxy_app`, and the named volumes `proxy_data` and `proxy_letsencrypt`. The old `npm-app-1` container was removed (its data stays in `~/docker-migration-backup/npm/`), and the data was copied into the volumes. `proxy_app` starts with zero init errors and routes as described above.

**E. Verification.**
- Container count: 11 running, all healthy. Restart check: `colima stop`, then `brew services start colima` (sh.brew.colima). All 11 came back on their own, and `mody.local` answered 200 after the restart.
- WordPress writes from the app container as `www-data` (uid 501): `logs/` and `web/` both writable.
- WP-CLI and Composer run in the mody container.
- The M0-01 validation matches the baseline in `tickets/log.txt`, before and after the restart.

**F. Pending (needs your admin password).** The remaining steps are with you:
1. Quit Docker Desktop if it is open, then run `/Applications/Docker.app/Contents/MacOS/uninstall` (or use the whale menu → Troubleshoot → Uninstall).
2. Remove the root-owned symlinks: `sudo rm -f /usr/local/bin/docker /usr/local/bin/docker-compose /usr/local/bin/docker-credential-desktop /usr/local/bin/docker-credential-osxkeychain /usr/local/bin/compose-bridge /usr/local/bin/hub-tool /usr/local/bin/kubectl /usr/local/bin/kubectl.docker`.
3. Remove the `~/.docker/cli-plugins` links that point into `Docker.app`, leaving only `docker-compose` and `docker-buildx` (both point at Homebrew).
4. Ask before deleting any leftovers in `~/Library` (`Containers/com.docker.docker`, `Group Containers/group.com.docker`, `Application Support/Docker Desktop`, the preferences plist, `Logs/Docker Desktop`).
5. PhpStorm: point the Docker connection at `unix:///Users/javiertroya/.colima/default/docker.sock`.

**G. Docs.** Updated `documentation/local-development.md` (new "Colima on macOS" section, `USER_ID` in the variables table, and the Docker prerequisite) and the `USER_ID` note in `sample.env`.

**Done in this session but not in the original plan:** `~/.docker/config.json` now has `credsStore: osxkeychain` and `cliPluginsExtraDirs`. The `desktop-linux` Docker context is removed. `brew services` manages Colima at login.
