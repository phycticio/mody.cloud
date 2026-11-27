# Production checked end to end before launch

**Builds on:** [free-mvp.md](free-mvp.md), [M5-01](M5-01-hardening-launch.md), [M5-02](M5-02-production-member-sites.md), [backups.md](backups.md) · **Feeds:** the launch · **Status:** planned on 2026-11-20; steps 1, 2, 3 and 7 done the same day · **Issue:** [#16](https://github.com/phycticio/mody.cloud/issues/16)

## Goal (maintainer, 2026-11-20)

> Create the necessary tasks to achieve launch, start work on each one by one.

Before Mody Cloud is announced as a free service ([free-mvp.md](free-mvp.md)), prove on production that it works end to end: the server has what each feature needs, a real signup from Spain reaches a working site, and a restore brings WordPress back.

## What exists today (checked 2026-11-20, from the repository; production not read)
- Everything merged up to `6db8aaf` was deployed on 2026-11-17. The free service (#13) is in review.
- Registration has been open on production since 2026-11-10. No first real signup is recorded in the tickets.
- Backups run since 2026-11-14 and the first `./bin/restore-drill` passed (data only); the full-stack check is not done ([backups.md](backups.md)).
- Mollie's webhook was checked on production with the test key on 2026-11-18; the test key stays, and payments are off with `mody_payments_open` unset (after #13).
- Several features need server steps that no ticket records as done: custom domains (`NPM_API_*`, the `sites.mody.cloud` record), the location file (`./bin/geoip`), the billing run's Ofelia labels (harmless while payments are off).

## Plan
Who does each step: **agent** (with the maintainer's go for anything on the server), **maintainer**.
1. **Deploy the free service** (agent, after the maintainer merges #13): `./bin/deploy --env=prod` with the steps in [free-mvp.md](free-mvp.md) (sites over 500 MB listed first, `./bin/geoip` if the file is old, the home page scripts after). Check that table version 8 and storage version 3 ran.
2. **Server configuration, read-only** (agent): report, without printing secrets, whether `.env` sets `BREVO_API_KEY`, `INFOMANIAK_API_KEY`, `NPM_API_*` and `MOLLIE_API_KEY` (test); that `mody_payments_open` is unset and `mody_registration_open` is 1; the location file's date; that `compose.prod.yml` has the sample's labels; that the visitor's real address reaches PHP (a fresh `mody_rate_limits` or analytics row is not a proxy address).
3. **Spain only on production** (agent): `Registration::outside_spain()` with a known Spanish and a known foreign public address through `wp eval` (the location file answers ES and another country).
4. **A real signup** (maintainer, from Spain): `/register/`, the welcome email (through Brevo), the password, the editor tour, two requests to Mody, Your plan on the Dashboard, Download your site. Then a custom domain if one is at hand.
5. **The restore drill's full-stack check** (maintainer, the agent helping): WordPress running on restored data, once, by hand ([backups.md](backups.md)).
6. **Rotate the `plugins.mody.cloud` Composer credential** (maintainer; [M5-01](M5-01-hardening-launch.md#security-pass-2026-11-12)).
7. **The legal pages** (maintainer's adviser reads the free version; then the agent reruns `scripts/legal-pages.php` on production).

## Open questions
- ~~**Q1. Monitoring at launch:**~~ Decided by the agent on 2026-11-20 (night report): before; built in [health-page.md](health-page.md) (#29).
- **Q2. A cap on accounts** while the service is free: none was decided (free-mvp.md, D1); closing registration is the safety valve. The health page shows the accounts, the month's tokens, their cost and the projection; whether an alert (an email at some threshold) should come too is still the maintainer's **open question**.

## Results
**2026-11-20 (read-only on production, by the agent; nothing changed on the server but the deploy and the legal pages the maintainer asked for):**
- **1. Deployed** in `990aa62` after a database backup; table versions 8 (members), 2 (domains) and 3 (storage) ran; no site over 500 MB; no 500s ([free-mvp.md](free-mvp.md), Deployed).
- **2. Server configuration:** `.env` sets `BREVO_API_KEY`, `MAIL_SENDER_EMAIL`, `INFOMANIAK_API_KEY`, `INFOMANIAK_AI_BASE_URL`, `NPM_API_URL`, `NPM_API_EMAIL`, `NPM_API_PASSWORD` and a `test_` `MOLLIE_API_KEY` (values not read); `MODY_DOMAINS_TARGET` is unset, so the default `sites.mody.cloud` applies. `mody_registration_open` is 1, `mody_payments_open` unset. `compose.prod.yml` has the Ofelia labels (21) and the backup service. The location file is from 2026-11-13. Running: the app, Nginx, MariaDB, Redis, backup, Ofelia and Uptime Kuma containers. Ofelia's last 30 hours: `mody-backup-db` every 6 hours (11.4 MB), `mody-backup-full` nightly, `mody-billing` daily, none failed.
- **The visitor's real address reaches PHP:** the last 7 days of page views have a country for 86 of 98 (ES 71, US 14, NL 1, GB 1); 12 without one (**Inference:** IPv6 or addresses DB-IP Lite does not place).
- **3. Spain only:** on production, `Locator::locate()` places a Telefónica address in ES and Google's 8.8.8.8 in the US, so the signup refuses the second and accepts the first.
- **7. The legal pages** for the free service published on 2026-11-20 at the maintainer's request ("publish the free version now"); the adviser's read comes after.
- **Still the maintainer's:** 4 (a real signup from Spain: the email through Brevo, the tour, two requests to Mody), 5 (the restore's full-stack check), 6 (rotate the Composer credential). Q1 and Q2 below are answered by the health page, if its pull request is merged.

**2026-11-26 (the maintainer):**
- **4. A real signup, done:** a new member signed up on production, and their site `aliris.mody.cloud` was created and built with Mody. The member set their password from the welcome email, so mail through Brevo works.
- **Two-step login:** the maintainer set up a security key and an authenticator app on production, and both work ([security-plugin-replaces-wordfence.md](security-plugin-replaces-wordfence.md), Results).
- **5. The restore's full-stack check** may be done on the Mac: the drill's restore (`./bin/restore-drill --keep`) imported into the local stack, with the local `.env` (never the server's: it holds the Brevo and Proxy Manager keys) and the addresses moved to `mody.local`, then a member site opened in a browser ([backups.md](backups.md), Open).
- **Still the maintainer's:** 5 and 6.
