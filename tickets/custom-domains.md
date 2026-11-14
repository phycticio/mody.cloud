# Custom domains for member sites

**Status:** built 2026-11-13 on `feature/custom-domains`, not deployed. Needs three things on the production host before it works there (below). **Spec:** mvp-spec §14 (needed by the launch since 2026-11-10: every plan includes it; the home page says "soon").

## Decided by the maintainer on 2026-11-13
1. **Certificates: automate Nginx Proxy Manager** (the host's one front door) through its API, not a Caddy sidecar.
2. **The home connection's address can change,** so members point a **CNAME** at a name we keep updated: `sites.mody.cloud` (`MODY_DOMAINS_TARGET`). A bare domain (`shop.com`) works only where the registrar allows a CNAME-like record there (ALIAS, ANAME, flattening); the page suggests `www.shop.com` and the registrar's forwarding for the bare domain.
3. **The admin stays on `*.mody.cloud`:** only the public site moves to the domain; logins, the editors and wp-admin keep the network's login cookie.

Custom domains cannot go through our Cloudflare (that is Cloudflare for SaaS, a paid US service, and rule 1 forbids new Cloudflare uses), so their traffic reaches the router directly.

## How it works
- **Your domain** on the site's Dashboard (its administrators, member sites only): type the domain → the CNAME to add (name and value) → `pending`, checked every five minutes (WP-Cron on the main site, `mody_domains_run`; production runs WP-Cron every minute through Ofelia) or with **Check now** → once the domain points here (a CNAME to the target, or the target's own addresses), `issuing`: NPM gets a Let's Encrypt certificate (HTTP challenge) and a proxy host that forwards like `mody.cloud`'s own (same scheme, host and port) → `active`: the site's `home` becomes `https://<domain>`. After a day of failed tries, `failed`; **Try again** restarts. **Take this domain off** puts the old address back and deletes NPM's host and certificate. A deleted site's domain goes with it.
- **The mapping** runs before WordPress picks a site: `app/web/sunrise.php` (`SUNRISE` in `wp-config.php`) loads `src/Domains/Mapping.php`, which looks the host up in `mody_domains` (network table; one domain per site; object-cached) and answers `pre_get_site_by_path`.
- **On the domain:** `wp-login.php`, `wp-admin`, `wp-signup.php` and `wp-activate.php` redirect to the `*.mody.cloud` address. **In the admin:** REST addresses and previews use the admin host. **At the old address:** visitors of the public site are sent to the domain (301); logged-in users and previews stay. WordPress's own canonical redirect does not do this (it only changes www against no www).
- **CLI:** `wp mody-domains list | add <site> <domain> | check <site> | remove <site> | run`.

## Before it works on production (the maintainer)
1. **DNS:** in Cloudflare, a record `sites.mody.cloud` → the home's public address, **DNS only** (grey cloud), kept up to date when the address changes (whatever updates `mody.cloud`'s origin today, or a DDNS job).
2. **The server's `.env`:** `NPM_API_URL` (NPM's API as the app container reaches it, such as `http://host.docker.internal:81/api`), `NPM_API_EMAIL`, `NPM_API_PASSWORD` (an NPM user allowed to manage proxy hosts and certificates) (Let's Encrypt uses that NPM user's email as its contact). Then `docker compose up -d wordpress` and `docker compose restart nginx`.
   - **The NPM user can stay at "Created items only"** (fixed 2026-11-14, found on the first real try: such a user cannot see `mody.cloud`'s proxy host to copy, so the platform said "NPM has no proxy host for mody.cloud to copy"). Its domains' proxy hosts then forward to `host.docker.internal` and `NGINX_HTTP_PORT` (override with `NPM_FORWARD_HOST`, `NPM_FORWARD_PORT`). That keeps the platform from touching the other stacks' hosts on the shared NPM.
3. **Check** that the app container reaches that API (`wget -qO- $NPM_API_URL/` from it answers `{"status":"OK"…}`), and that the router's ports 80 and 443 go to NPM (they do for mody.cloud).
4. Try it with a real domain; then the home page can drop "soon".

## Tests
`scripts/domains-test.php`: a domain as typed, the record's name, adding (one per site, one site per domain, not the main site), the DNS check, the certificate through a fake NPM (calls, forwarding, the address switched and kept), failing then `failed`, real requests through the local Nginx with the domain's Host header (the site, its links, the login and wp-admin redirects, the old address's 301, an unknown domain), admin links, taking it off, a deleted site, the Dashboard section.

## Not done
- Media inserted in the editor keep the `*.mody.cloud` address in their URLs (they still load).
- Renewals are NPM's (it renews its Let's Encrypt certificates by itself).
- The bare domain and www together (one domain per site; the registrar forwards the other).
