# M0-01 · Enable subdomain Multisite locally

**Milestone:** M0 · **Spec:** documentation/mvp-spec.md §3, §12 · **Status:** done (2026-11-01)

## Goal
Two subdomain sites are reachable over HTTPS locally, both running theme `theme`, owned by one non-super-admin user.

## Tasks
- [x] `.env` (and `sample.env`): set `WORDPRESS_ALLOW_MULTISITE=true`, `WORDPRESS_MULTISITE=true`, `WORDPRESS_SUBDOMAIN_INSTALL=true`; add `*.${HOST_NAME}` to `APP_DOMAINS` so `./bin/certs` issues a wildcard cert. `sample.env` also gained `COMPOSE_FILE`/`COMPOSE_PATH_SEPARATOR` (without them a fresh clone never loads `compose.local.yml`, so Nginx has no wildcard `server_name`) and the Nginx host ports.
- [x] Rebuild local certs (`./bin/certs`) after the `APP_DOMAINS` change. The certificate covers `mody.local`, `*.mody.local`, `mail.mody.local`. `bin/certs` now disables pathname expansion around the `mkcert` call: `$APP_DOMAINS` has to stay unquoted to split into one argument per domain, which also let the shell glob away the `*.${HOST_NAME}` wildcard.
- [x] Network activation. `wp core multisite-install` is not usable here (it wants an empty install), so the sequence is single-site install, then `./bin/wp core multisite-convert --title="Mody Cloud" --base=/ --subdomains --skip-config`, then flip the three switches and recreate the app container. `--skip-config` is required because `wp-config.php` derives the constants from the environment. Documented in [local-development.md](../documentation/local-development.md#multisite-subdomain-network).
- [x] Verify `wp-config.php` multisite constants resolve. They do: `DOMAIN_CURRENT_SITE=mody.local`, `COOKIE_DOMAIN=mody.local`, `MULTISITE`/`SUBDOMAIN_INSTALL` true. Both domain constants are plain host names, never a port — see **Port 443 and the local proxy** below.
- [x] Two test sites via `./bin/wp site create --slug=alpha|beta --email=member@mody.local`, both answering over HTTPS and serving theme `theme`. The theme is network-enabled but not active on a new site, so `theme activate` runs per site. `wp site create` also writes each site's `siteurl`/`home` with an `http://` scheme and without the Bedrock `/wp` suffix; both were corrected.
- [x] Per-site WP-CLI usage. `./bin/wp --site=<slug>` now builds `--url` from `.env` (`--site=main` for the network's main site); a literal `--url=` still passes through. `wp-cli.yml` was left alone on purpose: the container's WP-CLI never reads it, `bin/wp` only greps it for the container name, and per-site defaults there would have to carry a machine-specific host. Documented in [local-development.md](../documentation/local-development.md#addressing-one-site).

## Acceptance
Met. Stored domains are port-free; the subsites were verified against the stack with the port-free `Host` header the proxy forwards, and `mody.local` end to end through NPM on 443. Two machine-local steps are still outstanding before the subsites open in a browser — see **Outstanding on this machine**.

| Check | Result |
| --- | --- |
| `https://mody.local` loads | 200, `<title>Mody Cloud</title>`. End to end through NPM on 443. |
| `https://alpha.mody.local` loads | 200, `<title>Alpha</title>`, serves `/web/themes/theme/style.css`. Verified against the stack with the port-free `Host` the proxy forwards; NPM still needs the wildcard proxy host. |
| `https://beta.mody.local` loads | 200, `<title>Beta</title>`, serves `/web/themes/theme/style.css`. Same. |
| TLS | The stack's own mkcert wildcard validates without `-k` (checked directly on 4435 before the domains were made port-free). The certificate NPM serves for `*.mody.local` is item 1 under **Outstanding**. |
| Managed by one non-super-admin user | `member` (ID 2) is `administrator` on both, `is_super_admin()` false, only `admin@mody.local` in `wp super-admin list` |
| One shared login | Login issues `domain=mody.local` cookies; the same session opens both subsite dashboards and gets 403 on `/wp-admin/network/` |
| `./bin/wp site list` shows both | blog 1 `mody.local`, blog 2 `alpha.mody.local`, blog 3 `beta.mody.local` |

Test accounts are local only: `admin` (super admin) and `member` / `member` (the non-super-admin owner).

## Port 443 and the local proxy

`npm-app-1` (an unrelated Nginx Proxy Manager instance) owns 80, 443 and 81 on the development machine, so this stack publishes 8091/4435. **NPM fronts it and forwards with the original `Host` header, so site URLs stay port-free.** Those two variables are only where the container publishes itself on the host.

That is the only workable arrangement: `wp-includes/ms-settings.php` strips only `:80` and `:443` from the request host before looking the site up, so a host carrying `:4435` never matches `wp_blogs.domain` and every request ends on the `wp-signup.php?new=...` "site not installed" redirect. Nothing WordPress-side differs between "the stack owns 443" and "a proxy owns 443" — the stack's Nginx terminates TLS itself either way, so `include fastcgi_params` sets `HTTPS=on` and `is_ssl()` is true.

Consequence to remember: `https://mody.local:4435` **no longer works directly** — it returns the signup redirect, because the `Host` header carries the port. Go through the proxied host name. To test the stack without the proxy, override the header:

```bash
curl -k --resolve alpha.mody.local:4435:127.0.0.1 -H "Host: alpha.mody.local" \
  https://alpha.mody.local:4435/
```

## Outstanding on this machine

Both are outside this repository, so they were not changed:

1. **NPM needs the wildcard.** It has a proxy host for `mody.local` only, so `alpha.*` and `beta.*` fail TLS with `tlsv1 unrecognized name` — no configured server name matches the SNI. Add a proxy host covering `*.mody.local` with a certificate that covers it, forwarding to the stack's HTTPS port.
2. **`/etc/hosts` needs the subdomains**, since it has no wildcard: `127.0.0.1 alpha.mody.local beta.mody.local`.

## Follow-ups raised, not decided here

- **WP-Cron on subsites is broken** and needs an answer before M1, which puts the hourly billing and sweep jobs on WP-Cron. Docker's embedded DNS has no wildcard, so PHP cannot resolve `<slug>.${HOST_NAME}` and every loopback request from a subsite fails. Browser traffic is fine (it ends at Nginx, which has the wildcard). The commented `extra_hosts` template in `compose.local.yml` does not work — Docker's `--add-host` takes an IP, not a service name — and has been replaced with an accurate comment. Recorded as an open question in [architecture.md](../documentation/architecture.md#open-questions) with three candidate fixes. Workaround: `./bin/wp --site=<slug> cron event run --due-now`.
- **Per-site local DNS and per-site proxy entries do not scale to M2.** Every member-created site would need an `/etc/hosts` line and a matching proxy server name. dnsmasq (`address=/mody.local/127.0.0.1`) plus a wildcard proxy host solves it locally; in production the wildcard DNS record and the `*.mody.cloud` certificate cover it. Noted in the prerequisites.

## Notes
- `compose.local.yml`'s wildcard `server_name` was confirmed live against the new wildcard cert: `server_name mody.local *.mody.local;`.
- The stale Tamarind scripts were not touched. `bin/wp` and `bin/certs` were changed, both of which this ticket's tasks call for.
