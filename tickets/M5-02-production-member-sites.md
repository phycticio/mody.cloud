# M5-02 · Member sites in production: wildcard DNS and TLS, `home`, mail

**Milestone:** M5 (split from [M5-01](M5-01-hardening-launch.md) on 2026-11-08) · **Spec:** documentation/mvp-spec.md §12 (Wildcard TLS), §16 questions 2 and 3; documentation/architecture.md (Production: Request path, Host, Deviations to resolve 1 to 3) · **Status:** todo (step 5 done 2026-11-10). Instructions prepared 2026-11-08. Steps 1 to 4 and 6 are the maintainer's: Cloudflare, Nginx Proxy Manager, the server's `.env`. The certificate option is decided (step 2, 2026-11-08); the SMTP relay is not (step 6).

## Goal
A site created with the wizard in production opens at `https://<slug>.mody.cloud`, with correct links, and WordPress mail (password resets, the welcome email) is delivered. Until then everything built in M2 to M4 works only locally. One production member site already exists (`phycticio.mody.cloud`, seen 2026-11-07), and it cannot be reached.

## Where production stands (architecture.md, checked 2026-11-04 and 2026-11-07)
- **DNS and TLS:**
  - Cloudflare proxies only `mody.cloud`, with no wildcard record. `www.mody.cloud` answers 525.
  - NPM proxy host 18 serves `mody.cloud` with a Let's Encrypt certificate. It forwards to `http://host.docker.internal:8195`.
- **The stack:** `compose.prod.yml.sample` sets `NGINX_APP_SERVER_NAME` to `mody.cloud *.mody.cloud`, so nginx already answers every subsite, if the server's `compose.prod.yml` matches the sample (step 4 checks it).
- **The site URLs:** `home` and `siteurl` were both `https://mody.cloud/wp` (deviation 3) until step 5, 2026-11-10.
- **Mail:** PHP sends with msmtp to `mailpit:1025` (`config/php/zzz-mail.ini`), and Mailpit never runs in production, so `wp_mail()` fails (deviation 2).

## Steps

### 1. DNS (maintainer, Cloudflare)
Add a `*` record for `mody.cloud`, pointing where the apex points and proxied like it. Cloudflare's Universal SSL already covers first-level subdomains (`*.mody.cloud`) for visitors. This is the existing use of Cloudflare, one more record in the same zone. It is not a new service.

### 2. The origin certificate for `*.mody.cloud` (decided 2026-11-08: option a)
**The maintainer's decision:** Cloudflare stays on SSL mode "Full (strict)". Nginx Proxy Manager gets a Let's Encrypt wildcard certificate through the DNS challenge, with a Cloudflare API token so that it keeps renewing. The token is limited to DNS edits for the `mody.cloud` zone. It is recorded as the one new use of Cloudflare in architecture.md, Temporary exceptions to rule 1. The options weighed:
Let's Encrypt's HTTP challenge, which the apex certificate uses, cannot issue a wildcard. The options:

| Option | What it takes | Trade-off |
| --- | --- | --- |
| **a. Let's Encrypt, DNS challenge through Cloudflare's API** (NPM supports it) | A Cloudflare API token, limited to DNS edits for the zone, stored in NPM | Uses Cloudflare for something new, against the rule for the temporary exceptions (architecture.md). It would need your explicit exception. |
| **b. Move DNS to Infomaniak first, then the DNS challenge through Infomaniak's API** (NPM supports it too) | The DNS move, which is the documented replacement direction | Ends Cloudflare's proxying, which exposes the home connection's IP address. It belongs with the production host decision (architecture.md, Open questions). |
| **c. Cloudflare SSL mode "Full" with a self-signed wildcard certificate at NPM** | One certificate made on the server, no new service | Cloudflare encrypts to the origin but does not validate its certificate. If the zone is on "Full (strict)" today, this lowers it for every host. |
| **d. One Let's Encrypt certificate per member site** (HTTP challenge, a proxy host per site) | NPM work for every new site | Does not scale. New sites would wait for a manual step. |

**My recommendation:** check the zone's SSL mode first. If it is already "Full", choose **c**: it adds nothing new. If it is "Full (strict)", choose **a**, recorded as an explicit, temporary exception like Cloudflare itself. Go to **b** together with the production host move.

### 3. The proxy host (maintainer, NPM)
Add `*.mody.cloud` to proxy host 18, or create a second host with the same forward (`http://host.docker.internal:8195`, `X-Forwarded-Proto` sent), using the certificate from step 2.

### 4. Check the stack (maintainer, on `home.local`)
- The server's `compose.prod.yml` sets `NGINX_APP_SERVER_NAME: "${HOST_NAME} *.${HOST_NAME}"` as the sample does. If it was changed, run `docker compose up -d && docker compose restart nginx`.
- Then `curl -sI -H 'Host: phycticio.mody.cloud' http://127.0.0.1:8195/` on the server should answer from WordPress, not with nginx's default page.

### 5. No `/wp` in any address (decided by the maintainer on 2026-11-10: "I prefer mody.cloud over mody.cloud/wp")
Both `home` and `siteurl` lose `/wp`, not only `home`: wp-admin, wp-login.php and core's files are served from the root too. Core stays in `app/wp`.
1. **Deploy first** (`fix/no-wp-in-urls`): `config/nginx/app.conf.template` sends any old `/wp/…` address to the same address without `/wp` (a 308; it reads `$request_uri`, so the internal rewrites into `/wp/` never loop), and `wp-config.php`'s `WP_SITEURL` loses `/wp`. Then `docker compose restart nginx` on the server: nginx renders its template only at start.
2. A database dump ([architecture.md](../documentation/architecture.md#production)).
3. One regex search-replace over the network, `--dry-run` first, `guid` skipped. It drops `/wp` right after any `mody.cloud` host, plain or JSON-escaped, only when `/wp` is followed by `/`, a quote, `?`, `#`, `<`, a space, a backslash or the end, so `/wp-admin` and `/wp-json` are never touched. It covers `home`, `siteurl` and every stored link (menus, the header's "Log in" link):

   ```bash
   wp search-replace '(https?:(?:\\?/){2}(?:[a-z0-9-]+\.)?mody\.cloud)\\?/wp(?=\\?/|["'"'"'?#<\s\\]|$)' '$1' \
     --regex --regex-delimiter='~' --network --skip-columns=guid --report-changed-only --dry-run
   ```
4. The network's own `siteurl` option, if it carries `/wp`: `wp site option update siteurl https://mody.cloud/`. It changes WordPress's cookie names, so everyone logs in once more.
5. `wp cache flush`.
6. Check: `/`, `/es/`, wp-login.php, wp-admin, Network Admin, the Site Editor, `phycticio.mody.cloud`, and an old `/wp/es/` link.

Rehearsed locally on 2026-11-10: 7 replacements (the main site's `siteurl`, the five language menus' "Log in" links, one user URL), then wp-admin, Network Admin, the Site Editor and the Pages screen answered 200 at the root with no `/wp/` link, and `/wp/wp-admin/edit.php` answered 308 to `/wp-admin/edit.php`. New sites need no change: `Pipeline::urls()` copies the main site's form, now without `/wp`.

**Done in production on 2026-11-10** (deployed `dc1e0e6`, then nginx restarted):
- the dump `backups/mody_cloud-prod-before-no-wp-20261110-234714.sql`;
- 10 replacements: the main site's `home` and `siteurl`, the network's `siteurl`, the header menus' links in English, Spanish, German and Italian, one dashboard link and one user URL. `aliris.mody.cloud` already had no `/wp`;
- left alone: 33 `guid` values, the agent's audit log (`wp_mody_agent_events`) and Wordfence's traffic log;
- checked: `/`, `/es/`, `/de/`, `/it/`, `/register/`, wp-login.php, `/wp-json/` and `aliris.mody.cloud` answer 200 with no `/wp/` link; `/wp/`, `/wp/es/`, `/wp/register/` and `/wp/wp-admin/` answer 308 to the same address without `/wp`; no 500 in nginx's log. Between the nginx restart and the replacement (about a minute) the home page looped between `/` and `/wp/`.
- **Not checked:** wp-admin while logged in, in production (it was checked locally). The network's `siteurl` changed, so WordPress's cookie names did too: everyone logs in once more.

### 6. Mail (decided on 2026-11-09: Brevo's API; since 2026-11-10 through the `mody-mail` MU plugin)
- **The relay:** the maintainer chose Brevo on 2026-11-09, for now, as a temporary exception to rule 1 ([architecture.md](../documentation/architecture.md#temporary-exceptions-to-rule-1)). Spec §16 question 2 had named Infomaniak Mail or self-hosted.
- **Why it blocks signups** (2026-11-08, [M1-03](M1-03-chat-signup-and-editor-tour.md)): a new member's only way in is the welcome email's set-password link.
- **Built (2026-11-10):** the `mody-mail` MU plugin (`app/web/mu-plugins/mody-mail/`). It replaced WP Mail SMTP 4.10.3 (built 2026-11-09), which the maintainer dropped on 2026-11-10 after a WP Mail SMTP Pro package offered for it turned out to be incomplete and license-patched. It reads `MAIL_SENDER_EMAIL`, `MAIL_SENDER_NAME` and `BREVO_API_KEY` from the environment; with the key, every `wp_mail()` goes to Brevo's API (`BrevoMailer`), and production without the key fails every message with a clear error. No settings screen, nothing in the database, no library. Test: `scripts/mail-test.php` (Mailpit locally, and Brevo with a test key and faked answers).
- **The steps on the server** (after the deploy, which never touches `.env` or `compose.prod.yml`):
  1. In Brevo: authenticate `mody.cloud` as a sending domain (its DNS records, at Cloudflare), add the sender `no-reply@mody.cloud`, and create an API key (SMTP & API → API keys). **The maintainer's.**
  2. In the server's `.env`: the key in `BREVO_API_KEY` (the line is there since 2026-11-10, empty). Optionally `MAIL_SENDER_EMAIL` and `MAIL_SENDER_NAME` from `sample.env`; without them the sender is `no-reply@mody.cloud`, "Mody Cloud". `BREVO_SENDING_DOMAIN`, `BREVO_FROM_EMAIL` and `BREVO_FROM_NAME` are no longer read and can go.
  3. In the server's `compose.prod.yml`: remove the `wordpress` service's `environment` block with the `WORDPRESS_SMTP_*` lines, added on 2026-11-10 for WP Mail SMTP (`compose.prod.yml.sample` no longer has it). Nothing reads those values now, and until the key is in `.env` its `${BREVO_API_KEY:?…}` stops every `docker compose` command with "required variable BREVO_API_KEY is missing a value". Backups from 2026-11-10: `.env.before-brevo-20261110-093915` and `compose.prod.yml.before-brevo-20261110-093915`.
  4. `docker compose up -d && docker compose restart nginx`, so the app container gets the new `.env`.
  5. Test it: request a password reset, or `docker exec mody_cloud_app wp --allow-root --path=/var/www/html/wp --url=mody.cloud eval 'var_dump( wp_mail( "you@example.org", "Mody mail test", "Sent through Brevo." ) );'`. A failure prints `bool(false)`, and its reason is in PHP's error log (`app/logs/debug.log` when `WP_DEBUG_LOG` is on, otherwise `docker logs mody_cloud_app`), as `[mody-mail] Email not sent: …`.
  6. Optional: WP Mail SMTP left data behind (its `wp_mail_smtp*` options and `wpmailsmtp_*` tables). Its network activation is dropped by itself the next time a super admin opens Network Admin → Plugins.

### 7. Verify
- In production, as a super admin, create a site with the wizard, then open `https://<slug>.mody.cloud`, its Site Editor and the agent bubble.
- Request a password reset and receive it.
- Then mark deviations 1 to 3 resolved in architecture.md, and close spec §16 questions 2 and 3.

## Still open after this ticket
- **WP-Cron on subsites** (architecture.md, Open questions): production's network alias covers only `mody.cloud`, so subsites' loopback requests fail there too.
- **The production host** (the laptop, or a European VPS) and the backup drill: [M5-01](M5-01-hardening-launch.md).
