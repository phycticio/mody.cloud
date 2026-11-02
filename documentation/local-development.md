# Local development

## Prerequisites

- Docker with Compose v2 (v2.24 or newer if you use the stage or production override samples).
- `mkcert` for locally trusted TLS (`sudo apt install mkcert` or `brew install mkcert`).
- Composer on the host is optional. It is useful to regenerate `app/composer.lock`. Everything else runs in the containers.
- `/etc/hosts` entries for the local domains. The network is a **subdomain** Multisite and `/etc/hosts` has no wildcard, so every subsite needs its own line:

  ```text
  127.0.0.1 mody.cloud.local mail.mody.cloud.local
  127.0.0.1 alpha.mody.cloud.local beta.mody.cloud.local
  ```

  Add a line each time you create a site, or run a local resolver (dnsmasq with `address=/mody.cloud.local/127.0.0.1`) so the whole wildcard resolves and new sites work without `sudo`. The certificate already covers `*.mody.cloud.local`, so only name resolution is missing. To check a subsite without editing `/etc/hosts` at all, let curl resolve it: `curl --resolve alpha.mody.cloud.local:443:127.0.0.1 https://alpha.mody.cloud.local/`.
- Port 443 free for the stack, **or** a proxy in front of it that owns 443 and forwards with the original `Host` header. Site URLs never carry a port — see [Site URLs never carry a port](#site-urls-never-carry-a-port).

## First run

These steps follow `bin/install`, minus the steps that depended on the removed projects (database dump import and the asset build). They have **not** been run end-to-end since the cleanup. `compose.yml` was validated with `docker compose config`.

The order is **install single, then convert**. The three multisite switches must still be `false` while you install and convert: `wp core multisite-convert` starts with `if ( is_multisite() )` and aborts with *"This already is a multisite installation."*, so an install that already declares `MULTISITE` can never be converted.

```bash
cp sample.env .env                         # review the values
# Multisite comes later in this sequence: start with the three switches off.
sed -i '' 's/^WORDPRESS_ALLOW_MULTISITE=true/WORDPRESS_ALLOW_MULTISITE=false/;
          s/^WORDPRESS_MULTISITE=true/WORDPRESS_MULTISITE=false/;
          s/^WORDPRESS_SUBDOMAIN_INSTALL=true/WORDPRESS_SUBDOMAIN_INSTALL=false/' .env
./bin/certs                                # mkcert certificates into config/ssl/, incl. *.${HOST_NAME}
mkdir -p app/logs && touch app/logs/debug.log && chmod 666 app/logs/debug.log
docker compose up -d --build
./bin/version 7.0 --no-backup              # downloads WordPress core into app/wp (use WORDPRESS_VERSION)
./bin/composer install                     # installs Query Monitor (dev) and composer/installers
./bin/wp core install --url="https://mody.cloud.local" --title="Mody Cloud" \
  --admin_user=admin --admin_email=admin@mody.cloud --admin_password=password --skip-email
./bin/wp theme activate theme
```

Open `https://mody.cloud.local`. Captured mail is at `https://mail.mody.cloud.local` (Mailpit).

Then turn it into a network — the next section.

The private plugin repository (`plugins.mody.cloud`, SatisPress) is only needed for `mody/*` licensed plugins. None are required at the moment. Without credentials, Composer prints a harmless 401 warning for that repository. To authenticate, `bin/install` does it with `./bin/composer config http-basic.$PLUGIN_REPO_HOST "$SATISPRESS_KEY" satispress`.

## Multisite (subdomain network)

Run this once, after the single-site first run above.

```bash
# 1. Network activation. --skip-config is required: wp-config.php derives the
#    multisite constants from the environment, so WP-CLI must not write them.
#    It prints the constants it would have added - that is informational.
./bin/wp core multisite-convert --title="Mody Cloud" --base=/ --subdomains --skip-config

# 2. Flip the three switches on and recreate the app container so PHP sees them.
#    Nginx caches the PHP-FPM container's address at startup, so restart it too.
sed -i '' 's/^WORDPRESS_ALLOW_MULTISITE=false/WORDPRESS_ALLOW_MULTISITE=true/;
          s/^WORDPRESS_MULTISITE=false/WORDPRESS_MULTISITE=true/;
          s/^WORDPRESS_SUBDOMAIN_INSTALL=false/WORDPRESS_SUBDOMAIN_INSTALL=true/' .env
docker compose up -d --force-recreate wordpress
docker compose restart nginx

# 3. Confirm the constants resolve before going further.
./bin/wp eval 'printf("MULTISITE=%s SUBDOMAIN_INSTALL=%s DOMAIN_CURRENT_SITE=%s COOKIE_DOMAIN=%s\n",
  var_export(MULTISITE, true), var_export(SUBDOMAIN_INSTALL, true), DOMAIN_CURRENT_SITE, COOKIE_DOMAIN);'

# 4. A member account: network-wide user, administrator of its own sites,
#    never a super admin (mvp-spec.md section 3).
./bin/wp user create member member@mody.cloud --role=subscriber --display_name="Test Member"

# 5. Sites. --email makes that existing user the site administrator.
./bin/wp site create --slug=alpha --title="Alpha" --email=member@mody.cloud
./bin/wp site create --slug=beta  --title="Beta"  --email=member@mody.cloud

# 6. The theme is network-enabled but not active on a new site.
./bin/wp --site=alpha theme activate theme
./bin/wp --site=beta  theme activate theme

# 7. Check.
./bin/wp site list --fields=blog_id,url,domain
./bin/wp super-admin list                       # must list only the admin account
./bin/wp --site=alpha user list --fields=ID,user_login,roles
```

Add `alpha` and `beta` to `/etc/hosts` (see Prerequisites) and open `https://alpha.mody.cloud.local`.

### Addressing one site

WP-CLI runs against the network's main site unless it is told otherwise. `./bin/wp --site=<slug>` builds the right `--url` from `.env`, so the host name and the published port stay in one place:

```bash
./bin/wp --site=alpha plugin list        # -> wp --url=alpha.mody.cloud.local ...
./bin/wp --site=main option get home     # the network's main site
./bin/wp site list                       # network-wide commands need no --site
```

A literal `--url=...` still passes through. `wp-cli.yml` is **not** read inside the container — WP-CLI finds `wp-config.php` from its working directory — so do not put per-site defaults there. `bin/wp` only greps that file for the container name behind `@$SITE_SLUG`, and the `@mody` alias is what a host-side WP-CLI uses (`wp @mody core version`).

### Site URLs never carry a port

Stored domains are always bare host names — `mody.cloud.local`, `alpha.mody.cloud.local` — and `NGINX_HTTP_PORT` / `NGINX_HTTPS_PORT` are only where the container publishes itself on the host. This is not a style choice: WordPress strips only `:80` and `:443` from the request host before it looks a site up (`wp-includes/ms-settings.php`), so a host carrying any other port never matches `wp_blogs.domain` and every request ends on the `wp-signup.php?new=...` "site not installed" redirect.

So requests have to arrive on a standard port. Either the stack's own Nginx owns 443, or **a proxy in front of it does and forwards with the original `Host` header** — which is the setup on the development machine, where an unrelated Nginx Proxy Manager instance owns 80/443/81 and this stack publishes 8091/4435. Both work without any WordPress-side difference: the stack's Nginx terminates TLS itself, so `include fastcgi_params` sets `HTTPS=on` and `is_ssl()` is true either way.

Two consequences worth knowing when a proxy is in front:

- **Reaching `https://<host>:4435` directly no longer works.** The `Host` header then carries `:4435` and you get the signup redirect. Use the proxied host name. To check the stack itself without going through the proxy, override the header: `curl -k --resolve alpha.mody.cloud.local:4435:127.0.0.1 -H "Host: alpha.mody.cloud.local" https://alpha.mody.cloud.local:4435/`.
- **The proxy needs the wildcard too**, or only the main site answers over HTTPS. One proxy host for `mody.cloud.local` leaves `alpha.*` failing TLS with `tlsv1 unrecognized name`, because no configured server name matches the SNI. Add a proxy host covering `*.mody.cloud.local` with a certificate that covers it, pointing at the stack's HTTPS port.

Separately, `wp site create` writes each new site's `siteurl` and `home` with an `http://` scheme and without the Bedrock `/wp` suffix, so fix both after creating a site (`siteurl` carries `/wp` because core lives in `app/wp`, `home` does not — see [architecture.md](architecture.md)):

```bash
./bin/wp --site=alpha option update home    "https://alpha.mody.cloud.local"
./bin/wp --site=alpha option update siteurl "https://alpha.mody.cloud.local/wp"
```

## Commands

| Command | What it does |
| --- | --- |
| `docker compose up -d` | Start the stack. |
| `./bin/wp <args>` | WP-CLI inside the app container, against the network's main site. Needs `SITE_SLUG` in `.env` and a matching alias in `wp-cli.yml` (already `mody`). |
| `./bin/wp --site=<slug> <args>` | The same, against one subsite. `--site=main` is the network's main site. |
| `./bin/composer <args>` | Composer inside the app container. |
| `./bin/certs` | Generate local certificates for `APP_DOMAINS`, including the `*.${HOST_NAME}` wildcard the network needs. Re-run it after changing `APP_DOMAINS`. |
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
| `WORDPRESS_ALLOW_MULTISITE`, `WORDPRESS_MULTISITE`, `WORDPRESS_SUBDOMAIN_INSTALL` | Multisite switches. `sample.env` sets all three to `true`; `compose.yml` still defaults them to `false`. Install and convert with them off, then flip them on. |
| `APP_DOMAINS` | Domains `./bin/certs` issues the local certificate for. Must include `*.${HOST_NAME}` for the subdomain network. |
| `COMPOSE_FILE`, `COMPOSE_PATH_SEPARATOR` | Make every `docker compose` call load `compose.local.yml`, which is where Nginx gets the wildcard `server_name`. Without them the subsites do not resolve. |
| `NGINX_HTTP_PORT`, `NGINX_HTTPS_PORT` | Where the stack's Nginx publishes itself on the host. A local detail: these never appear in a site URL. If something else owns 443, put a proxy in front rather than exposing the port — see [Site URLs never carry a port](#site-urls-never-carry-a-port). |
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
