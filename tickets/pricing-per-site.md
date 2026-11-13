# One price per site, 1 GB, and the launch offer

**Status:** built 2026-11-13 on `feature/pricing-per-site`, not deployed. **Docs:** [membership-tiers.md](../documentation/membership-tiers.md), [mvp-spec.md §4](../documentation/mvp-spec.md#4-price-launch-offer-and-billing). **Replaces:** the plans of 2026-11-10 (1, 3 and 10 sites) and the 30-day trial with 3 sites.

## Asked for
By the maintainer on 2026-11-13:

> I want to make a change in the business model. Every site costs 5.99 and every site will have 1Gb space to start. Every service we offer will be available to everyone at the same price, no more sites more discounts. Just 5.99 + IVA/monthly per site. During the first three months, we are going to offer 50 accounts for free and the marketing strategy will be that they will be free for six months to the first 50 people registered, then the price starts at 5.99 per site.

Then, after the token calculation:

> D1: Let's make 7.99 per site IVA included. D2: 1GB for all. Reduce the quota of the currents. D3: The promo replaces it. D4: For free accounts only one site. No card required at sign up. Yes. Just the sign up for 7.99. D5: Monthly 7.99/month Yearly 69.99/year

> Set the year price to 79.99 so it is the equivalent to ten months i think that's a standard. Go ahead with your recommendations for all questions.

## Decisions
1. **Price:** 7.99 EUR a month or 79.99 EUR a year per site, **VAT included**; the same for everyone, no discount for more sites.
2. **Space:** 1 GB per site, existing sites too.
3. **The launch offer replaces the trial:** during the first three months, the first 50 accounts, **one site**, **free for six months**, **no card** at signup.
4. **After the offer**, signing up means paying 7.99 a month. Until Mollie works (M1-01 part 3), the signup says the places are taken and paid signups open soon (the recommendation: no paid signup without payments; no waiting list kept, so no emails are stored).
5. **Mody's allowance** (the recommendation): **5,000,000 tokens a month per site, at most 1,000,000 in one day**, the same free or paid. It replaces the daily allowance per site and per account (800,000 / 1,500,000 on the trial, 2,000,000 / 4,000,000 paid).
6. **The end of the free months** (the recommendation): an email a month before and another a week before; without a card at the end, the site is read-only for 60 days, then deleted (the rule for unpaid sites, mvp-spec §4).
7. **Up to 10 sites per account** when paying, the most offered before (a real limit, rule 7).

## The numbers behind it
Measured locally, 717 Mody messages on 82 sites over the 30 days to 2026-11-13: 10,607 prompt and 904 answer tokens per message (61% of the prompt from Infomaniak's cache). Infomaniak's price for `Qwen3.8-27B-FP8`: CHF 0.40 per million prompt tokens, CHF 3.00 per million answer tokens; about CHF 0.60 a million with our mix (cached tokens counted at full price; 1 CHF taken as 1.07 EUR).

| | Tokens | Cost |
| --- | --- | --- |
| A message | ~11,500 | ~0.007 EUR |
| A generated page | ~25,000 | ~0.03 EUR |
| A first day of building (8 pages, 40 messages) | ~700,000 | ~0.52 EUR |
| A regular site's month (100 messages, 4 pages) | ~1.25 M | ~0.85 EUR |
| A site's month at the allowance | 5 M | ~3.25 EUR |

A monthly site leaves 6.60 EUR after 21% VAT, a yearly one 4.82 EUR a month. The launch offer's 50 sites cost about 280 EUR in tokens over six months at regular use, and at most about 1,000 EUR at the allowance. Hosting is not counted: production runs on the maintainer's own machine (architecture.md, Open questions).

## What was built
- **`Members\Members`:** `PLANS` is `free` and `paid`; `PRICE` (7.99, 79.99); `SITE_LIMITS` (1, 10); the offer's `FREE_PLACES`, `FREE_MONTHS`, `PROMO_MONTHS`; `start_free()` (a place, under a database lock), `promo_open()`, `promo_left()`, `promo_ends()`, `promo_closed_message()`, `free_until()`, `choose_period()`, `total()`, `price()`. Table version 4: old trials become `free` (free until six months after signup), the paid plans `paid`, `trial_ends_at` became `free_until`, `next_plan` dropped. The network options `mody_promo_starts_at` and `mody_promo_given`.
- **`Members\Registration`:** a place of the offer instead of the trial; when the offer has ended, the block says why and the price, and both routes refuse (`mody_promo_closed`, 403). The intro, the welcome email and the page's title name the free months and the price after them.
- **`Site\Storage`:** 1024 MB; version 2 moves every site still on 3072 MB to 1024 MB.
- **`Agent\Budget`:** `MONTH` and `DAY` per site; the filter is now `mody_agent_token_budget` (`day`, `month`); the refusal says when Mody is back (tomorrow, or the first of next month).
- **The Dashboard:** Plan & billing shows the free months or the sites times the price, the next payment, the card, and How you pay (monthly or yearly); Mody's work shows the month's tokens against the allowance; Your sites and New site say why no site can be added.
- **The editor tour:** its last step says until when the site is free.
- **The home page** (`scripts/home-page.php`) and its Spanish, German and Italian texts: the offer, the price with VAT, 1 GB, three cards (the offer, monthly, yearly), the questions. Applied locally.

## Built after (M1-01 part 3a, 2026-11-13)
The card, the charges, the reminders and the 14-day withdrawal (a full refund): see M1-01. **Still not built:** the paid signup after the offer (part 3b), and read-only then deletion without a card (M1-02).

## Before the deploy
- **Registration is open in production:** the offer's three months start with the first signup after the deploy, unless `mody_promo_starts_at` is set to the launch day first.
- **Sites over 1 GB:** list them (`wp site list` and each site's `get_space_used()`); they keep their files but cannot upload.
- Production's trial accounts become free accounts with six months from their signup; they do not take places of the offer. One with more than one site keeps them all and, after the free months, pays for each.
- Run `scripts/home-page.php`, then copy the JSON and run `scripts/home-page-translations.php`, on production.

## Tests
- `scripts/members-test.php`: the plans, the limits, and the launch offer (a place, six months, the three months started, a deleted account keeps its place taken, the 51st refused with the price, refused after three months, the prices).
- `scripts/registration-test.php`: the page's offer and price, a place given at signup, the welcome email, the free member's New site limit, and the places taken (the page and the 403).
- `scripts/dashboard-test.php`: 1 GB and the move from 3 GB, the migration to version 4, `choose_period()`, Plan & billing for a paying and a free account, the month's tokens.
- `scripts/security-agent-test.php`: the allowance per site, a day's and a month's, and the filter.
- The tests that create accounts put the offer's two options back as they were.
