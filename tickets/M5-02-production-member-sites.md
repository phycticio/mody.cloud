# M5-02 · Member sites in production: wildcard DNS and TLS, `home`, mail

**Milestone:** M5 (split from [M5-01](M5-01-hardening-launch.md) on 2026-11-08) · **Spec:** documentation/mvp-spec.md §12 (Wildcard TLS), §16 questions 2 and 3; documentation/architecture.md (Production: Request path, Host, Deviations to resolve 1 to 3) · **Status:** todo. Instructions prepared 2026-11-08. Steps 1 to 4 and 6 are the maintainer's: Cloudflare, Nginx Proxy Manager, the server's `.env`. The certificate option is decided (step 2, 2026-11-08); the SMTP relay is not (step 6).

## Goal
A site created with the wizard in production opens at `https://<slug>.mody.cloud`, with correct links, and WordPress mail (password resets, the welcome email) is delivered. Until then everything built in M2 to M4 works only locally. One production member site already exists (`phycticio.mody.cloud`, seen 2026-11-07), and it cannot be reached.

## Where production stands (architecture.md, checked 2026-11-04 and 2026-11-07)
- **DNS and TLS:**
  - Cloudflare proxies only `mody.cloud`, with no wildcard record. `www.mody.cloud` answers 525.
  - NPM proxy host 18 serves `mody.cloud` with a Let's Encrypt certificate. It forwards to `http://host.docker.internal:8195`.
- **The stack:** `compose.prod.yml.sample` sets `NGINX_APP_SERVER_NAME` to `mody.cloud *.mody.cloud`, so nginx already answers every subsite, if the server's `compose.prod.yml` matches the sample (step 4 checks it).
- **The site URLs:** `home` and `siteurl` are both `https://mody.cloud/wp` (deviation 3).
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

### 5. `home` without `/wp` (deviation 3; maintainer runs it, or approves it)
1. Make a database dump first (`mariadb-dump` in `mody_cloud_db`, as the local Colima migration did on 2026-11-03).
2. Run `wp option update home https://mody.cloud` on the main site: `docker exec -u www-data mody_cloud_app wp --path=/var/www/html/wp --url=https://mody.cloud …`.
3. List the stored links that still carry the old form: `wp db search 'mody.cloud/wp/' --all-tables`. Change only page and navigation links (`wp search-replace` with `--dry-run` first). Leave `/wp/wp-admin`, `/wp/wp-includes` and the `siteurl` option alone.
4. New sites then get `siteurl` with `/wp`, like the local ones (`Pipeline::urls()` copies the main site's form). `phycticio` keeps its current URLs; nginx serves both forms.

### 6. Mail (decided on 2026-11-09: Brevo's API, through WP Mail SMTP)
- **The relay:** the maintainer chose Brevo on 2026-11-09, for now, as a temporary exception to rule 1 ([architecture.md](../documentation/architecture.md#temporary-exceptions-to-rule-1)). Spec §16 question 2 had named Infomaniak Mail or self-hosted.
- **Why it blocks signups** (2026-11-08, [M1-03](M1-03-chat-signup-and-editor-tour.md)): a new member's only way in is the welcome email's set-password link.
- **Built (2026-11-09):** WP Mail SMTP 4.10.3 in `app/composer.json`, network-active, hidden from members and limited to super admins (`Mail\WpMailSmtp`, `Gating\MemberPlugins`). Its settings are `WPMS_*` constants that `app/wp-config.php` sets from the environment: SMTP to Mailpit locally, and Brevo's API in production through the `wordpress` service's `environment` block in `compose.prod.yml.sample`. Its own calls to its maker are off. Test: `scripts/mail-test.php`, locally and with production's settings (the Brevo request is caught).
- **The maintainer's steps on the server** (after the deploy, which never touches `.env` or `compose.prod.yml`):
  1. In Brevo: authenticate `mody.cloud` as a sending domain (its DNS records, at Cloudflare), add the sender `no-reply@mody.cloud`, and create an API key (SMTP & API → API keys).
  2. In the server's `.env`: the four `BREVO_*` lines from `sample.env`, with the key.
  3. In the server's `compose.prod.yml`: the `wordpress` service's `environment` block from `compose.prod.yml.sample`. Then `docker compose up -d`. It refuses to start without the key and the sender.
  4. Network-activate the plugin: `docker exec mody_cloud_app wp plugin activate wp-mail-smtp --network --allow-root --path=/var/www/html/wp --url=mody.cloud`.
  5. Test it: request a password reset, or send one from WP Mail SMTP → Tools → Email Test as a super admin (that tool's domain check calls `connect.wpmailsmtp.com`).

### 7. Verify
- In production, as a super admin, create a site with the wizard, then open `https://<slug>.mody.cloud`, its Site Editor and the agent bubble.
- Request a password reset and receive it.
- Then mark deviations 1 to 3 resolved in architecture.md, and close spec §16 questions 2 and 3.

## Still open after this ticket
- **WP-Cron on subsites** (architecture.md, Open questions): production's network alias covers only `mody.cloud`, so subsites' loopback requests fail there too.
- **The production host** (the laptop, or a European VPS) and the backup drill: [M5-01](M5-01-hardening-launch.md).
