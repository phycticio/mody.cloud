# Local development

## Prerequisites

- Docker with Compose v2 (v2.24 or newer if you use the stage or production override samples).
- `mkcert` for locally trusted TLS (`sudo apt install mkcert` or `brew install mkcert`).
- Composer on the host is optional. It is useful to regenerate `app/composer.lock`. Everything else runs in the containers.
- An `/etc/hosts` entry for the local domains:

  ```text
  127.0.0.1 mody.cloud.local mail.mody.cloud.local
  ```

## First run

These steps follow `bin/install`, minus the steps that depended on the removed projects (database dump import and the asset build). They have **not** been run end-to-end since the cleanup. `compose.yml` was validated with `docker compose config`.

```bash
cp sample.env .env                         # review the values
./bin/certs                                # mkcert certificates into config/ssl/
mkdir -p app/logs && touch app/logs/debug.log && chmod 666 app/logs/debug.log
docker compose up -d --build
./bin/version 7.0 --no-backup              # downloads WordPress core into app/wp (use WORDPRESS_VERSION)
./bin/composer install                     # installs Query Monitor (dev) and composer/installers
./bin/wp core install --url="https://mody.cloud.local" --title="Mody Cloud" \
  --admin_user=admin --admin_email=admin@mody.cloud --admin_password=password --skip-email
./bin/wp theme activate theme
```

Open `https://mody.cloud.local`. Captured mail is at `https://mail.mody.cloud.local` (Mailpit).

The private plugin repository (`plugins.mody.cloud`, SatisPress) is only needed for `mody/*` licensed plugins. None are required at the moment. Without credentials, Composer prints a harmless 401 warning for that repository. To authenticate, `bin/install` does it with `./bin/composer config http-basic.$PLUGIN_REPO_HOST "$SATISPRESS_KEY" satispress`.

## Commands

| Command | What it does |
| --- | --- |
| `docker compose up -d` | Start the stack. |
| `./bin/wp <args>` | WP-CLI inside the app container. Needs `SITE_SLUG` in `.env` and a matching alias in `wp-cli.yml` (already `mody`). |
| `./bin/composer <args>` | Composer inside the app container. |
| `./bin/certs` | Generate local certificates for `APP_DOMAINS`. |
| `./bin/version <wp-version>` | Replace WordPress core in `app/wp` without touching `app/web`. |
| `./bin/db import` | Import `app/dumps/<DB_NAME>.sql` and rewrite the domain. Needs a dump. |
| `cd app && composer update --no-install` | Regenerate `composer.lock` on the host. |
| `docker exec mody_cloud_server nginx -s reload` | Reload Nginx after editing its config. |

Container, volume and network names are prefixed with `CONTAINER_PREFIX` (`mody_cloud_` in `sample.env`).

## Key environment variables

| Variable | Purpose |
| --- | --- |
| `CONTAINER_PREFIX` | Prefix for containers, image, volumes and network. |
| `HOST_NAME`, `MAIL_HOST` | Local site and Mailpit hostnames. |
| `PROXY_NAME` | Production domain (`mody.cloud`). `./bin/db` rewrites it to `HOST_NAME` after an import. |
| `WORDPRESS_VERSION`, `WORDPRESS_IMAGE_VERSION` | Core version and base image tag. |
| `WORDPRESS_ENVIRONMENT` | `local`, stage or production. Mkcert runs only for `local`. |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Database credentials. The sample values are for local use only. |
| `SITE_TITLE`, `SITE_ADMIN_*` | Used by `wp core install`. |
| `WORDPRESS_ALLOW_MULTISITE`, `WORDPRESS_MULTISITE`, `WORDPRESS_SUBDOMAIN_INSTALL` | Multisite switches. Not in `sample.env`. `compose.yml` defaults them to `false`. |
| `NGINX_SSL_CERT_FILE`, `NGINX_SSL_KEY_FILE` | Override certificate paths (stage and production use `/dev/null`). |

## File permissions on Linux

The `Dockerfile` maps `www-data` to `USER_ID` and `GROUP_ID` (default 1000), so on a typical Ubuntu desktop the container and your user share an ID. If you see "Forbidden" or permission errors:

```bash
sudo chown -R $USER:$USER app/
```

## Known stale items

The stack scripts came from an earlier project (Tamarind Intelligence). The previous themes and the JS build toolchain were removed. These leftovers are still present and **do not work for this project as they are**:

| Item | Problem |
| --- | --- |
| `bin/build`, `config/bin/build.config`, `config/bin/install.config` | Build and install Tamarind and Omitsis plugins that do not exist here, and run `pnpm build`, which no longer exists. |
| `bin/install` | Calls `./bin/build` and `./bin/db import` (needs a database dump). Use the first-run steps above, or `--no-build` and expect the import to fail. |
| `bin/migrate-*`, `bin/subscription-migration-local-env`, `config/bin/db.config` | Tamarind data-migration tooling. `bin/wp` also forwards `TAMARIND_MIGRATION_*` variables. |
| `compose.yml` | Ofelia labels for `wp tamarind newsletters run-due`. No Ofelia service is defined, so they do nothing. |
| `compose.stage.yml.sample`, `compose.prod.yml.sample` | Server names are Tamarind domains. |
| `.github/workflows/*` | Deployment and CI for the previous project (`tamarind styles refresh`, `lint:js`, `lint:scss`, `./bin/build`). |
| `app/assets/` | About 295 MB of font files (`.ttf`, `.ufm`) and one image. Nothing in this repository references it. |
| `test.md` | QA notes for a Tamarind search refactor. |
| `.vscode/settings.json` | Enables ESLint and Stylelint auto-fix. Their configuration was removed. |

Clean these up as the real deployment and CI for Mody Cloud get defined.
