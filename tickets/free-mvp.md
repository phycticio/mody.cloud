# A free service: one free site for everyone

**Builds on:** [pricing-per-site.md](pricing-per-site.md), [invites-and-offers.md](invites-and-offers.md), [M1-01](M1-01-registration-trial-billing.md), [M1-02](M1-02-past-due-readonly-deletion.md), [legal-pages.md](legal-pages.md) · **Feeds:** paid tiers later (freemium), [M5-01](M5-01-hardening-launch.md) · **Status:** planned and built on 2026-11-20 on `feature/#13-free-mvp`, not deployed · **Issue:** [#13](https://github.com/phycticio/mody.cloud/issues/13)

**Replaces:** the price per site and the launch offer of 2026-11-13 ([pricing-per-site.md](pricing-per-site.md)). The billing code stays, switched off, for the paid tiers.

## Goal (maintainer, 2026-11-20)

> Let's start as a free service and in the future we will add more tiers. This is the MVP. Free that will turn into Freemium.

> The service will be free for everyone on the basic tier at the beginning […] no registrations outside Spain. […] first free for all with instead of 1gb 500Mb and the use of everything.

## Decisions (maintainer, 2026-11-20)
1. **Every account starts free.** No places, no three-month window: anyone may sign up while registration is open.
2. **One site per account.**
3. **Mody keeps 5,000,000 tokens a month per site**, at most 1,000,000 in one day (`Agent\Budget`, unchanged).
4. **Free is the only tier for now.** More tiers come later; the free one stays (freemium).
5. **No end date and no notice:** a free account does not expire. No reminders, no card, no read-only, no deletion for free accounts.
6. **No donations and no payments.** Mollie's live key is not set; the billing code stays in the repository, off.
7. **500 MB per site** instead of 1 GB, existing sites too.
8. **Sign-ups from Spain only.**

Every site still gets everything built: Mody, the Site Editor, Spectra, languages, visitor stats, SEO, images, custom domains, Download your site.

## What exists today (checked 2026-11-20)
- **Accounts** (`mody-members`): `Members::start_free()` gives a place of the launch offer under a lock, counts it in `mody_promo_given`, and sets `free_until` and `billing_due_at` six months on. `promo_open()` closes sign-ups after 50 places or three months; `Registration` then offers paid sign-ups when a Mollie key is set, or refuses with `mody_promo_closed`. `SITE_LIMITS` is 1 free, 10 paid. The Offers page (`Offers`) sets the offer: on or off, everyone or invited, places, free months, months, start.
- **Billing** (`mody-billing`): the daily run emails free accounts a month and a week before `free_until`, and charges or marks past due every active account whose `billing_due_at` has passed (`Billing.php` around line 998). An account with no `billing_due_at` is never charged or made past due. `Mollie::offered()` is false on production with a test key, so members see no card today.
- **Invites** (`Invites`): a personal link, with a point on the friend's first payment, which never comes without payments.
- **Space** (`mody-storage`): `Storage::SITE_MB` = 1024, settings version 2.
- **Where the price and the offer are shown:** the Dashboard's Plan & billing (`mody-dashboard/src/DashboardPage.php`), the editor tour's last step (`mody-onboarding/src/EditorTour.php`, "free until"), the signup chat's intro and the welcome email (`Registration`), the home page in four languages (`scripts/home-page.php`, `scripts/home-page-translations.json`), the terms and the legal notice (`scripts/legal-pages.php`: prices, VAT, Mollie, the 14-day withdrawal, "a self-employed professional in Spain").
- **Location:** `mody-analytics` reads a country from the visitor's address on our own server (`Geo\Locator`, DB-IP City Lite).

## Plan
1. **Free accounts with no end** (`mody-members`): `start_free()` without places, months or the lock's count; `free_until` and `billing_due_at` left empty. Table version 8 empties both on existing free accounts without a card. `promo_open()` reads only "registration open" and "invited only"; `mody_promo_closed` and paid sign-ups are unreachable while payments are off. Tests: `members-test.php`, `registration-test.php`.
2. **Payments off by a switch** (`mody-billing`): a network option (proposed `mody_payments_open`, off) that `Mollie::offered()` also needs, so no key turns payments on by itself. The daily run then finds nothing to do. Test: `billing-test.php`.
3. **500 MB** (`mody-storage`): `SITE_MB` 500, settings version 3 moves sites on 1024 to 500. Before the deploy, list the sites over 500 MB. Test: `dashboard-test.php`.
4. **Spain only** (`mody-members`, `Registration`): the chat asks the visitor to confirm they live in Spain; see Q1 for the country check. Test: `registration-test.php`.
5. **What members read:** Plan & billing becomes Your plan (Free: one site, 500 MB, Mody's month, what is included; no prices or card); the editor tour's last step, the chat's intro and the welcome email drop "free until"; Invites drops the points. Offers page: only who may sign up (Q3). Tests: `dashboard-test.php`, `onboarding-test.php`, `invites-test.php`.
6. **The home page** in English, Spanish, German and Italian: free, what is included, the real limits (one site, 500 MB, Mody's tokens: rule 7), Spain only, paid tiers with more later. Never "free forever" or "unlimited".
7. **The legal pages:** the legal notice names the maintainer as a private person in Spain; the terms describe a free service (no prices, no Mollie, no withdrawal section); the privacy policy drops Mollie as a processor. Reviewed by the maintainer before publishing.
8. **The documents:** `AGENTS.md` (the summary and "The business and its price"), `membership-tiers.md`, `mvp-spec.md` §4, `project-overview.md` (Economics), and pricing-per-site.md, invites-and-offers.md, M1-01 and M1-02 marked as paused by this ticket.
9. **Production, after the merge:** the deploy, the sites over 500 MB, `scripts/home-page.php` and its translations, `scripts/legal-pages.php`.

Parts 1 to 3 are one sitting; 4 and 5 another; 6 to 8 are mostly text.

## Costs
Mody's tokens are the cost that grows with accounts (pricing-per-site.md, The numbers behind it): about 0.85 EUR a month for a regular site, at most about 3.25 EUR at the allowance.

| Accounts | Regular use a month | At the allowance a month |
| --- | --- | --- |
| 50 | ~43 EUR | ~163 EUR |
| 200 | ~170 EUR | ~650 EUR |

**Inference:** with no cap on accounts, the safety valve is closing registration (`wp site option update mody_registration_open 0`) and the token filter `mody_agent_token_budget`.

## Open questions (answered by the maintainer on 2026-11-20)
- ~~**Q1. How Spain only is checked.**~~ A confirmation in the chat, plus a refusal when our own location data puts the address outside Spain (through `mody-analytics`'s `Locator` when it is present, the confirmation alone otherwise). A VPN gets around it, which is acceptable while nothing is charged.
- ~~**Q2. Accounts from outside Spain that already exist:**~~ kept (**Inference**, not asked).
- ~~**Q3. Invites and the Offers page:**~~ keep the invite link, drop the points while payments are off; the Offers page keeps only who may sign up and invites on or off.
- ~~**Q4. The legal notice:**~~ name, address, email and tax ID, as before. To be read by the maintainer's adviser before publishing.
- ~~**Q5. Mollie's test key on production:**~~ left; the switch keeps it from offering anything.
- **Still open:** which paid tiers come, and what they add (membership-tiers.md, Open questions).

## Results (2026-11-20)
- **Free accounts with no end** (`mody-members`): `Members::start_free()` makes a free, active row with no `free_until` and no `billing_due_at`, for anyone, or for invited people only (`free_open()`, `free_closed_message()`, error `mody_signups_invited_only`). The launch offer's places, months, lock and options are gone. Table version 8 empties the end, the billing date and a cancellation on older free accounts, makes free accounts past due or cancelled active again, and deletes `mody_promo_starts_at` and `mody_promo_given`; paid rows stay. The plan's label is Free.
- **Payments off by a switch** (`mody-billing`): `Mollie::offered()` also needs the network option `mody_payments_open` (`Mollie::OPEN_OPTION`). The daily run finds nothing to do for free accounts.
- **500 MB** (`mody-storage`): `Storage::SITE_MB` 500, settings version 3 moves sites on 3072 or 1024 MB to 500.
- **Spain only** (`Registration`): the intro and the summary say so; the chat's last button is "I live in Spain: create my site" and sends `spain`; the form has an "I live in Spain." box; without it, 400 `mody_spain_unconfirmed` (or the form's error). Both routes and the form refuse an address that DB-IP places outside Spain (403 `mody_outside_spain`, `Registration::outside_spain()`, filter `mody_registration_country` for tests).
- **What members read:** the Dashboard's section is Your plan (free, one site, 500 MB, Mody's tokens, everything built in, paid plans later; no price or card) while payments are off; a paid account still sees Plan & billing. The welcome email says the site is free with no card; the editor tour has no last free day. Invite friends: the link and how many joined, no points; the Offers page has who can sign up and invites. "The free plan includes one site" in Your sites and the wizard.
- **The home page** in English, Spanish, German and Italian: free, one site, no card, no end date, 500 MB, Spain only, optional paid plans later; the plan cards Free, Who can join, Later; the questions (a card, who runs it, more than one site, Mody per site); the SEO description. Applied locally with both scripts.
- **The legal pages** (`scripts/legal-pages.php`): the legal notice names a private person in Spain offering the service free of charge; the terms: the service, free with no card, who can sign up, ending (30 days' notice), content, use, visitors' data, availability, liability, changes, law; the privacy policy without Mollie or payment records. Applied locally. **To be read by the maintainer's adviser before publishing on production.**
- **Documents:** `AGENTS.md`, `membership-tiers.md` (the free plan, the switch, the Offers page, open questions), `mvp-spec.md` §4 (superseded note), `project-overview.md` (Economics), `roadmap.md`; pricing-per-site.md, invites-and-offers.md, legal-pages.md, M1-01 and M1-02 marked.
- **Tests:** `members-test.php`, `registration-test.php`, `invites-test.php`, `onboarding-test.php`, `dashboard-test.php`, `billing-test.php` (payments switched on for its run), `security-signup-test.php`, `antibot-test.php`, `provisioning-test.php`, `gating-test.php`, `export-panel-test.php`, `domains-test.php`, `security-isolation-test.php`, `security-agent-test.php`, `mail-test.php`: all pass. `wizard-test.php`: one known local failure, unrelated (the header's site title, independent-mody-plugins.md).
- **In a real browser** (headless Chromium, 1440 and 390 px, 2026-11-20): the chat's intro, the four answers, the summary with "I live in Spain: create my site", and "Done!" at both widths, no sideways scroll, no console errors; both accounts free with no end and a 500 MB site; the welcome emails in Mailpit; the set-password link to the Identity screen and the editor tour; Your plan (free, 500 MB, 5,000,000 tokens, no EUR) and Invite friends without points. The test accounts were removed.
- **Not done:** the Dashboard's paid-path texts still speak of free months (unreachable while payments are off); the signup chat is still English on `/es/`, `/de/` and `/it/` (as before; translated on 2026-11-21, [signup-chat-languages.md](signup-chat-languages.md)).

## Before and after the deploy (production)
1. List the sites over 500 MB: `wp site list --field=blog_id` and each site's `get_space_used()`; they keep their files but cannot upload until under 500 MB.
2. Deploy. The first request runs table version 8 and storage version 3.
3. Rerun `scripts/home-page.php`, then copy the JSON and run `scripts/home-page-translations.php`.
4. Rerun `scripts/legal-pages.php` **only after the adviser's read**; until then production keeps the paid version of 2026-11-13, which no longer matches the service.
5. Run `./bin/geoip` before the deploy if the location file is older than a month (Spain only depends on it).
