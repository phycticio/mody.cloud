# Custom domains for member sites

**Status:** built 2026-11-13 on `feature/custom-domains`, not deployed. Needs three things on the production host before it works there (below). **Issue (the admin on the domain, 2026-11-20):** [#19](https://github.com/phycticio/mody.cloud/issues/19) · **Spec:** mvp-spec §14 (needed by the launch since 2026-11-10: every plan includes it; the home page says "soon").

## Decided by the maintainer on 2026-11-13
1. **Certificates: automate Nginx Proxy Manager** (the host's one front door) through its API, not a Caddy sidecar.
2. **The home connection's address can change,** so members point a **CNAME** at a name we keep updated: `sites.mody.cloud` (`MODY_DOMAINS_TARGET`). A bare domain (`shop.com`) works only where the registrar allows a CNAME-like record there (ALIAS, ANAME, flattening); the page suggests `www.shop.com` and the registrar's forwarding for the bare domain.
3. ~~**The admin stays on `*.mody.cloud`**~~ (2026-11-13). **Changed by the maintainer on 2026-11-20** ([#19](https://github.com/phycticio/mody.cloud/issues/19)):

   > when a user adds their own domain, I will like them to see their own domain every time. So they are not in the just mody.cloud. Example, phycticio.com shows perfect, but if the user wants to admin the page it takes them to phycticio.mody.cloud/wp/wp-admin I would like them to see phycticio.com/wp/wp-admin

   The admin moves to the domain with the public site; super admins stay on `*.mody.cloud` (below).

Custom domains cannot go through our Cloudflare (that is Cloudflare for SaaS, a paid US service, and rule 1 forbids new Cloudflare uses), so their traffic reaches the router directly.

## How it works
- **Your domain** on the site's Dashboard (its administrators, member sites only): type the domain → the CNAME to add (name and value) → `pending`, checked every five minutes (WP-Cron on the main site, `mody_domains_run`; production runs WP-Cron every minute through Ofelia) or with **Check now** → once the domain points here (a CNAME to the target, or the target's own addresses), `issuing`: NPM gets a Let's Encrypt certificate (HTTP challenge) and a proxy host that forwards like `mody.cloud`'s own (same scheme, host and port) → `active`: the site's `home` becomes `https://<domain>`. After a day of failed tries, `failed`; **Try again** restarts. **Take this domain off** puts the old address back and deletes NPM's host and certificate. A deleted site's domain goes with it.
- **The mapping** runs before WordPress picks a site: `app/web/sunrise.php` (`SUNRISE` in `wp-config.php`) loads `src/Domains/Mapping.php` (since 2026-11-17 `mody-domains/src/Mapping.php`, [independent-mody-plugins.md](independent-mody-plugins.md)), which looks the host up in `mody_domains` (network table; one domain per site; object-cached) and answers `pre_get_site_by_path`.
- **The admin on the domain** (since 2026-11-20, #19): when the domain goes live, `home` and `siteurl` both become `https://<domain>` (the old values in the row: `old_home`, `old_siteurl`, table version 2, which also moved domains already live). wp-login.php, wp-admin, the editors, previews and REST calls all use the domain. The login cookie there is the domain's own: `COOKIE_DOMAIN` in `wp-config.php` is the network's (`mody.cloud`) only on the network's own hosts, and false (no Domain attribute) on any other, because a browser refuses a `mody.cloud` cookie on `phycticio.com`. So a member logs in once on their domain; coming from `mody.cloud`'s login, they log in again there.
- **At the old address:** visitors of the public site are sent to the domain (301; WordPress's own canonical redirect only changes www against no www), and wp-admin to the domain's (302, path and query kept); its wp-login.php stays, for super admins. Previews, REST and admin-ajax are not redirected.
- **Super admins stay on `*.mody.cloud`:** they cannot log in on a member's domain (`wp_authenticate_user`; the message gives the site's mody.cloud login) and never count as logged in there (`determine_current_user`), because the member controls where their domain's DNS points and could capture a session made there. For them, the site's admin, REST and preview addresses are rewritten to its `*.mody.cloud` address (`Domains::for_super_admin()`), Network Admin's links to the site included, and the old address does not redirect them.
- **CLI:** `wp mody-domains list | add <site> <domain> | check <site> | remove <site> | run`.

## Before it works on production (the maintainer)
1. **DNS:** in Cloudflare, a record `sites.mody.cloud` → the home's public address, **DNS only** (grey cloud), kept up to date when the address changes (whatever updates `mody.cloud`'s origin today, or a DDNS job).
2. **The server's `.env`:** `NPM_API_URL` (NPM's API as the app container reaches it, such as `http://host.docker.internal:81/api`), `NPM_API_EMAIL`, `NPM_API_PASSWORD` (an NPM user allowed to manage proxy hosts and certificates) (Let's Encrypt uses that NPM user's email as its contact). Then `docker compose up -d wordpress` and `docker compose restart nginx`.
   - **The NPM user can stay at "Created items only"** (fixed 2026-11-14, found on the first real try: such a user cannot see `mody.cloud`'s proxy host to copy, so the platform said "NPM has no proxy host for mody.cloud to copy"). Its domains' proxy hosts then forward to the stack's Nginx container, `<CONTAINER_PREFIX>server:80`, over `proxy_default` (as `mody.cloud`'s own host does; override with `NPM_FORWARD_HOST`, `NPM_FORWARD_PORT`). That keeps the platform from touching the other stacks' hosts on the shared NPM. A first version forwarded to `host.docker.internal:8195`, which NPM cannot reach: the first real domain (`www.phycticio.com`) got its certificate and a 502; an existing proxy host now gets the right forwarding again (an update), and `wp mody-domains check <site>` repairs a live domain.
3. **Check** that the app container reaches that API (`wget -qO- $NPM_API_URL/` from it answers `{"status":"OK"…}`), and that the router's ports 80 and 443 go to NPM (they do for mody.cloud).
4. Try it with a real domain; then the home page can drop "soon".

- **Safe redirects to the admin host** (fixed 2026-11-14, the first real domain): WordPress allows `wp_safe_redirect()` only to the home host, so once `home` was `www.phycticio.com` every safe redirect inside the admin (to `phycticio.mody.cloud`) fell back to `/wp-admin/`, which the Dashboard redirects again: ERR_TOO_MANY_REDIRECTS for logged-in users. The admin host is now allowed (`allowed_redirect_hosts`).

## Tests
`scripts/domains-test.php`: a domain as typed, the record's name, adding (one per site, one site per domain, not the main site), the DNS check, the certificate through a fake NPM (calls, forwarding, the address switched and kept), failing then `failed`, real requests through the local Nginx with the domain's Host header (the site, its links, the login and wp-admin redirects, the old address's 301, an unknown domain), admin links, taking it off, a deleted site, the Dashboard section.

## Results: the admin on the domain (2026-11-20, #19)
- `Domains`: `siteurl` moves with `home` (activate, remove, version 2 for live domains); `admin_on_domain()` sends wp-admin at the old address to the domain; `send_to_domain()` no longer spares logged-in members, only super admins; `refuse_super_admin_login()`, `no_super_admin_on_domain()`, `for_super_admin()`; the earlier `admin_on_network()`, `on_admin_host()` and `rest_url()` are gone. `wp-config.php`: the cookie domain. Your domain's text says the editor moves to the domain.
- Tests: `scripts/domains-test.php` (the admin switched and restored, version 2, the domain's login page and its own cookie, wp-admin on the domain, the old address's redirects, super admins refused and their links, a member's real login on the domain to wp-admin) passes; `antibot`, `gating`, `network-profile`, `login-page`, `security-isolation`, `security-signup`, `export-panel`, `dashboard`, `registration` and `onboarding` pass.
- **Production, after the deploy:** the first request runs table version 2, so `www.phycticio.com`'s admin moves to the domain at once. Members logged in at the old address log in once more on their domain.
- **Open question:** a member's own users (an editor they invited) log in on the member's domain; that member, controlling its DNS, could capture such a session and use it elsewhere in the network where that user has a role. Binding each session to the host it was made on would close it (WordPress's session tokens, through `session_token_manager`). **Open question**, not built.

## Not done
- Media inserted in the editor keep the `*.mody.cloud` address in their URLs (they still load).
- Renewals are NPM's (it renews its Let's Encrypt certificates by itself).
- The bare domain and www together (one domain per site; the registrar forwards the other).
