# Production checked end to end before launch

**Builds on:** [free-mvp.md](free-mvp.md), [M5-01](M5-01-hardening-launch.md), [M5-02](M5-02-production-member-sites.md), [backups.md](backups.md) · **Feeds:** the launch · **Status:** planned on 2026-11-20 · **Issue:** [#16](https://github.com/phycticio/mody.cloud/issues/16)

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
Not started.
