# M5-01 · Hardening, production and launch checklist

**Milestone:** M5 · **Spec:** documentation/mvp-spec.md §15 · **Status:** todo. Refreshed 2026-11-08: items that were already done or stale were updated, and the wildcard DNS and TLS, `home` and mail moved to [M5-02](M5-02-production-member-sites.md).

## Goal
The platform is launch-ready for 100 founding members: member sites reachable in production, backups proven, and a security pass over everything the MVP added.

## Already done
- **Production compose** (2026-11-04): the server runs `compose.yml` with `compose.prod.yml` from the sample. Only nginx's HTTP port is published; Xdebug and MariaDB publish nothing; Mailpit never starts (architecture.md, Host). There is no agent service any more: the AI runs in PHP since 2026-11-04.
- **Deploys** (2026-11-04): `./bin/deploy --env=prod`, with checks, from pushed commits on `main` only.
- **No updates from wp-admin** (2026-11-08): [updates-through-composer-only.md](updates-through-composer-only.md).

## Deliverables
- **[M5-02](M5-02-production-member-sites.md):** member sites in production (wildcard DNS and certificate, the proxy host), `home` without `/wp`, and real mail through an EU SMTP relay.
- **The production host: decided 2026-11-14, the laptop at launch, for budget** (architecture.md, Open questions). A European VPS (Hetzner, Infomaniak) later; replacing Cloudflare depends on it.
- **Backup and restore drill:** a full database and uploads backup, restored into a clean stack, with one member site checked end to end. Then a backup schedule. **Built 2026-11-14** ([backups.md](backups.md)): restic to Infomaniak Swiss Backup (database every 6 hours, the rest nightly) and `bin/restore-drill`; running on production since 2026-11-14, and the first drill passed (data: sites, members, every attachment, the server's files). The full-stack check is still to do.
- **WP-Cron on subsites** (architecture.md, Open questions), if M1 has not settled it already.
- **Security pass** over M1 to M4:
  - **Endpoints:** nonce and capability checks on the wizard REST routes (`mody-platform/v1/sites`), the agent chat endpoint and its jobs, and the `mody/*` abilities' permission callbacks (REST and MCP). Inputs sanitized: the wizard fields, the logo upload, chat messages.
  - **Mollie:** the webhook's verification and the idempotency replay test (M1-01 part 3).
  - **The registration chat** (M1-01 part 2, M1-03): since 2026-11-08 no card stands in the way, so each signup creates an account, a site and an email.
    - **Bot check, done:** since 2026-11-10, creating an account needs an ALTCHA solution ([antibot-altcha.md](antibot-altcha.md)); so do log in, lost password and visitors' comments.
    - **Rate limit, still open:** it needs the visitor's real address passed through the proxies first. Decide it before setting `mody_registration_open` in production, for the page and its two routes (`mody-platform/v1/register` and `register/check`).
    - **Email lookup:** the check route still tells whether an email has an account, as the old form did.
  - **Plugins on member sites:** the M4-02 rules (hidden plugins by every path) and the `ai` plugin's lock-down (M4-03).
  - **Secrets:** `INFOMANIAK_API_KEY`, the Mollie keys and the SMTP password only in the server `.env`, never in the database, logs or prompts (rule 6). `.env` untracked.
- **Monitoring:** Query Monitor is a dev requirement and never reaches production, so it is not the answer here. What is left:
  - the error log at `app/logs/debug.log`;
  - a simple system health page for super admins: site count, past-due count, failed provisioning jobs, agent errors from `mody_agent_events`.
- **Stale cleanup** (after launch is stable): the Tamarind leftovers listed in local-development.md, "Known stale items". `bin/build` is current and not on that list.

## Security pass (2026-11-12)
An audit of six surfaces (signup and login, the agent and REST, wp-admin handlers, member isolation, configuration, public features), each finding checked by a skeptic: about 30 confirmed, 10 rejected. Fixed in two rounds, each reviewed; the maintainer asked for "fix all security issues" and then "fix everything that needs fixing before deploy".
- **Nginx** (`config/nginx/app.conf.template`): every rule case-insensitive (production's disk is case-insensitive: `/AUTH.JSON`, `/Logs/debug.log` and `wp-config.PHP` could be served); PHP only from core and the plugin and theme folders; static files from an allow-list; core dumps, dotfiles, logs, SQL, archives next to code and plugin readmes refused; XML-RPC and trackbacks closed; the `/wp//host` open redirect fixed; 32 MB bodies except the media and font uploads (1 GB); `server_tokens` off, nosniff, Referrer-Policy, same-origin framing, HSTS when Nginx ends TLS; `expose_php` off (`config/php/security.ini`). Test: `scripts/nginx-hardening-test.sh`.
- **Compose and deploy:** no core dumps (`ulimits core: 0`), MariaDB and Xdebug published on localhost only, core files ignored by git and skipped by the deploy (which warns about one on the server).
- **Secrets:** ALTCHA has its own random secret; while the committed default salts are in use, a per-install secret replaces them (`Security\Secrets`): everyone is logged out once after the deploy, no `.env` change needed.
- **Rate limits** (`Security\RateLimit`, table `mody_rate_limits`, hashed keys): signup 3 per address and per mailbox a day and 30 an hour on the network; the chat's check 30 per 10 minutes; login 10 failures per address, 5 per account at one address, 100 per account (never refusing a browser the owner used before; a new password starts over); lost password 5 an hour; comments 10 an hour; ALTCHA challenges; the visitor beacon. The visitor's address (`Security\ClientAddress`): Cloudflare's header only from Cloudflare's ranges, else the rightmost public forwarded address.
- **Signup:** no answer reveals an existing account (same text, same timing: the site is built after the response); an existing address gets an email instead of a second account; names without links or markup; new logins are not the email's local part; passwords of at least 10 characters; ALTCHA solutions used once, atomically. Anonymous `/wp/v2/users` refused in any form; `?author=` does not reveal logins.
- **The agent:** one job per person and site, three on the network (`Agent\Slots`), deadlines, daily token budgets (`Agent\Budget`, see membership-tiers.md); page content, brand texts and titles fenced as data; a confirmation card when a lower-role user's text would change the whole site; editors do not see settings; `core/get-environment-info` for super admins only; the validator refuses event handlers and unsafe link schemes.
- **Member isolation:** Spectra's custom JavaScript stripped on every save path and off on member sites; every Spectra attribute printed as an element name checked against a safe list, at save and at render; legacy widgets gone for members; Tools → Delete Site refused until the real deletion flow; the plan's site limit counts owned sites (`mody_owner`); caps on admin-email changes (3 a day) and invitations (20 a day) with neutral messages; image dimension limits (logos and avatars 6,000 px; other uploads 20,000 px and 64 megapixels; ImageMagick resource limits); invitation keys out of the site download; 15 minutes between fresh download copies (not after a failure).
- **Accepted for now:** the PHP container receives every `.env` secret (narrowing `env_file` risks production; plan in architecture.md); anyone can send up to 1 GB to the three upload endpoints (Nginx cannot see a login).
- **Tests:** `scripts/security-signup-test.php`, `security-agent-test.php`, `security-isolation-test.php`, `nginx-hardening-test.sh`, plus the existing suites.
- **After the deploy, on the server:** recreate the containers and restart Nginx; delete any `app/core*`; check whether `/AUTH.JSON` or `/Logs/debug.log` answered before (if so, rotate the `plugins.mody.cloud` Composer credential and treat the logs as exposed).

## Small items before launch (2026-11-14)
- **The home page's promises** (rule 7): "Every plugin in our market" became "Everything built in" (Spectra's blocks, languages, cookieless visitor stats, antispam and image optimization; more plugins as we check them), because members have no plugin to switch on yet. Custom domains are no longer "soon" (built and live, 2026-11-13). Done in `scripts/home-page.php` and its translations, and published on production in all four languages.
- **The main site:** WordPress's "Sample Page" and its default "Privacy Policy" draft are in the trash. `wp_page_for_privacy_policy` points at the published privacy page (276), which core links from login and signup.
- **The theme's spacing sizes** are fixed without moving anything (theme-development.md, Design tokens).

## Acceptance (launch checklist)
- [ ] Fresh machine: `cp sample.env .env`, certs, `docker compose up`, core install, multisite activation — the site is usable (documented in local-development.md).
- [ ] Register → trial → create site → edit in Site Editor → agent tool → all work over HTTPS on the production front end.
- [ ] Restore drill completes with zero data loss on the test account.
- [ ] Security pass sign-off recorded in this ticket (the pass itself: above, 2026-11-12).
