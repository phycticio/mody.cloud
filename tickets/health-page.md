# The platform's health on one page

**Builds on:** [M5-01](M5-01-hardening-launch.md) (Monitoring), [free-mvp.md](free-mvp.md), [launch-production-check.md](launch-production-check.md) (Q1 and Q2) · **Status:** planned and built on 2026-11-20 on `feature/#29-health-page`; merged (#30) and deployed 2026-11-21 in `3a39a7b` · **Issue:** [#29](https://github.com/phycticio/mody.cloud/issues/29)

## Goal
M5-01 asks for "a simple system health page for super admins: site count, past-due count, failed provisioning jobs, agent errors from `mody_agent_events`". Since 2026-11-20 every account is free with no cap on accounts ([free-mvp.md](free-mvp.md)), so the cost that grows with sign-ups, Mody's tokens paid to Infomaniak, needs to be in sight. The production check left two open questions about it (Q1: monitoring before or after launch; Q2: an alert at some number of accounts).

**Decided by the agent on 2026-11-20** (the maintainer asked for the night's pending tasks to be done with decisions recorded in the night report): build the page now, before launch (Q1); answer Q2 with the page's numbers rather than an alert (no email or threshold invented); the maintainer reviews.

## What it shows (Network Admin → Mody → Health)
- **Mody's tokens this month:** every `turn` and `usage` row of Mody Agent's log since the first of the month, super admins' included (Infomaniak bills them all); the estimated cost at 0.64 EUR a million (`Report::EUR_PER_MILLION`: about CHF 0.60 a million with our mix, 1 CHF as 1.07 EUR, [pricing-per-site.md](pricing-per-site.md)); the month's projection at this pace; the ten sites that used the most, each against its monthly allowance (`Agent\Budget::MONTH`), flagged from 80%.
- **Accounts:** total, by plan and status, new in the last day, week and 30 days, joined through an invite, whether sign-ups are open (and to whom).
- **Sites and space:** member sites, the space used in all, the sites at 80% of their space or more.
- **What failed:** site creations (last 30 days), Mody's answers that ended in an error (last 7 days, of all answers), custom domains by status, and whether payments are offered (the switch and the key's mode, never the key).

## How
A new plugin, `mody-health` (one thing: the platform's health page; the rule of independent plugins). Read-only. `Report` reads each part from its owner behind `class_exists()`: Mody Members, Mody Agent's `mody_agent_events`, Mody Sites' jobs, Mody Domains, Mody Billing; sites and space are WordPress's own (`get_space_used()` per site, at most 1,000 sites). `HealthPage` hangs under the network's Mody menu (Mody Sites), else under Settings; `manage_network` only. Not carried by Take your site with you.

## Results (2026-11-20)
- Built: `app/web/mu-plugins/mody-health.php`, `mody-health/mody-health.php`, `src/Report.php`, `src/HealthPage.php`.
- Test: `scripts/health-test.php` (tokens per site this month, only `turn` and `usage`, last month's left out; the cost and the projection; errors among turns; a site at 80% of its space listed; accounts; payments without the key; the page for super admins only): passes.
- Checked in a real browser as a super admin: under Mody, after New site and Offers; no errors, no outside request. Local numbers: 11.2 million tokens this month (about 7.17 EUR), projected about 10.82 EUR.
- **Not done:** an alert (email) when the projection or the accounts pass a threshold; history beyond this month.
