# Plans, limits and gating

Decided by the maintainer on 2026-11-13: **every site costs the same**, with the same features, and a launch offer replaces the trial. It replaces the plans of 2026-11-10 (1, 3 and 10 sites at 9.99, 25.99 and 49.99 EUR a month, after a 30-day trial with 3 sites), which had replaced the briefing's four tiers (Collaborator, Member, Patron and Dedicated VPS, "fees equal real cost"). Mody Cloud is run by the maintainer as a self-employed professional in Spain. The ticket: [pricing-per-site.md](../tickets/pricing-per-site.md). See [project-overview.md](project-overview.md#economics).

## Every site gets the same

Each site has:

- **1 GB of space** (3 GB until 2026-11-13). Enforced with WordPress's own upload quota (`Site\Storage`, since 2026-11-11, [mody-dashboard.md](../tickets/mody-dashboard.md)): every new site gets `blog_upload_space` = 1024 (MB), the network has the same default and the check switched on, and the per-file cap is PHP's 1 GB. Sites made before got 1 GB too (version 2 of the settings): every site still on the old 3072 MB; a size a super admin set by hand stays. A site already over 1 GB keeps its files and uploads again once under it. The main site has no limit. It counts the site's uploads folder only, not its database rows. The site's Dashboard shows the space used.
- **Every plugin in the market**, switched on per site by the member ([mvp-spec.md §10](mvp-spec.md#10-plugin-market-and-capability-gating)). The market offers no plugin yet.
- **Its own Mody**, with 5,000,000 tokens a month (see Gating). The agent of a site reads only that site's brand and content, and nothing passes between the sites of one account. Every call runs on the current site (`get_current_blog_id()`).
- **A custom domain:** the member points a domain to our servers and the site answers at it. **Not built yet** (mvp-spec §14, the Caddy sidecar); the home page says "soon".
- **A way out:** Download your site on the Dashboard, three files, whatever the plan or billing status ([take-your-site-with-you.md](../tickets/take-your-site-with-you.md)).
- The full Site Editor, the six designs and the Mody fonts, Spectra blocks, and updates and security run by the platform.

## The price

| | Per site | VAT |
| --- | --- | --- |
| Monthly | **7.99 EUR** a month | included |
| Yearly | **79.99 EUR** a year: ten months' price for twelve, saving 15.89 EUR | included |

- **The same for everyone.** No packs and no discount for more sites: an account pays the price times its sites (`Members::total()`). An account can have **up to 10 sites** (`Members::SITE_LIMITS`), the most offered before; more is not offered yet.
- **VAT included** (decided 2026-11-13): the price shown is the price paid, as EU consumer rules expect. With Spain's 21%, a monthly site leaves 6.60 EUR before costs; customers in other EU countries pay their country's VAT once cross-border sales to consumers pass 10,000 EUR a year (OSS), which changes what is left, not the price.
- **In code:** `Members\Members::PLANS` is `free` and `paid`, `PRICE` holds 7.99 and 79.99, and the member chooses monthly or yearly on their Dashboard (Plan & billing, `choose_period()`); `billing_period` keeps it. Nothing is charged until Mollie ([M1-01](../tickets/M1-01-registration-trial-billing.md) part 3).

## The launch offer (instead of the trial)

- **What:** during the **first three months**, the **first 50 accounts** get their site **free for six months**, with **one site** and **no card**. Signing up is the chat on `/register/`, unchanged (M1-03).
- **The count:** the network option `mody_promo_given` counts the places given (`Members::start_free()`, one at a time under a database lock, so two signups at once never make 51); a deleted account does not give its place back. The three months count from `mody_promo_starts_at` (a Unix time), set by the first free account unless set before, for example to the launch day: `wp site option update mody_promo_starts_at <time>`.
- **The account:** plan `free`, status `active`, `free_until` six calendar months after signup, also the first billing date (`billing_due_at`).
- **When the 50 are taken or the three months have passed:** the signup page says so, with the price, and both of its routes refuse (`mody_promo_closed`). Paid signups at 7.99 open with Mollie (M1-01 part 3); there is no waiting list.
- **When the free months end** (decided 2026-11-13; built with Mollie on 2026-11-13, M1-01 part 3a, `Billing\Billing`): an email a month before and another a week before, asking for a card (Add your card on Plan & billing: the terms, a 0.01 EUR card check); with no card by the end, the account is past due: the site is read-only for 60 days, then deleted (mvp-spec §4; read-only and deletion built 2026-11-13, `Billing\Lifecycle`, M1-02). The member can download the site at any time.
- **Charges:** the sites times the price, on the card, when `billing_due_at` comes; a failed charge makes the account past due and is tried again every day.
- **The 14-day withdrawal** (decided 2026-11-13): within 14 days of the first charge, a Withdraw button on Plan & billing refunds it in full and cancels the account.
- **Cancel** (built 2026-11-13): at the end of the period already paid (or of the free months), with Keep my plan until then; nothing more is charged.
- **Read-only, then deleted** (M1-02, built 2026-11-13): while an account is past due or canceled, nobody but a super admin can change its sites (Mody neither); a banner says why and until when. A last warning on day 53; on day 60 the sites go to the network's trash and the account is `expired`. A payment lifts it at once.
- **The daily run** (`wp mody-billing run`): on production, the host's Ofelia scheduler, from the labels in `compose.prod.yml.sample`.
- **Accounts from before 2026-11-13:** the migration (table version 4) made every trial account `free`, free until six months after it signed up, and every paid plan `paid`. They do not count as places of the offer. A trial account with more than one site keeps its sites (Your sites says "2 sites used"), cannot add another, and after the free months pays for each.

## Gating

- **Sites:** `Provisioning\Quota::can_create_site()` (M2-01) counts a user's sites: since the security pass of 2026-11-12, the sites they own (blog meta `mody_owner`, set when the platform creates a site; older sites were backfilled from the provisioning jobs or their first administrator), not every site they administer, plus site creations still on the way. A user with a `mody_members` row gets their plan's limit through the `mody_site_limit` filter (`Members\Members::site_limit()`): 1 on the launch offer, 10 when paying. Users without a row keep 3. The refusal never says "unlimited" (rule 7).
- **Space:** 1 GB per site, WordPress's upload quota (see above).
- **Mody's allowance** (`Agent\Budget`; daily per site and per account from the security pass of 2026-11-12, **monthly per site** since 2026-11-13): each site gets **5,000,000 tokens a calendar month**, at most **1,000,000 of them in one day**, the same free or paid; prompt and answer together, counted from the agent's `turn` and `usage` rows; super admins are exempt and their work on a site is not counted. A message uses about 11,500 tokens and a generated page about 25,000 (717 turns over 30 days to 2026-11-13), so a month is about 430 messages, and a first day of building a site (8 pages and 40 messages, about 700,000) fits in a day. Past it, Mody says when it is back (the next UTC day, or the first of next month). The filter is `mody_agent_token_budget` (`day`, `month`). The Dashboard's Mody's work section shows the month's tokens against the allowance. The home page names it among the real limits (rule 7).
  - **Why 5 million:** at Infomaniak's price for the model (CHF 0.40 per million prompt tokens, CHF 3.00 per million answer tokens; with our mix about CHF 0.60 a million, cached prompt tokens counted at full price), a site's month costs at most about 3.25 EUR, so every site pays for its tokens even on the yearly price (4.82 EUR a month without VAT). A regular site (100 messages and 4 pages a month) costs about 0.85 EUR.
- **Mody at once:** one job per person and site, three on the network, the editor's small AI features one of them (`Agent\Slots`).
- **Newsletter sends:** out of the MVP ([newsletter.md](newsletter.md)); no cap is decided.

## Open questions

- ~~**Who issues the invoices**~~ Decided by the maintainer on 2026-11-13: Mollie at first, then our own invoicing plugin. To check when building M1-01 part 3: that Mollie's invoices meet Spain's rules for a self-employed seller (numbering, NIF, VAT; Verifactu).
- ~~**Payment methods**~~ Decided 2026-11-13: cards only.
- ~~**The EU 14-day right of withdrawal**~~ Decided 2026-11-13: a full refund of the first charge, from a Withdraw button on Plan & billing (EU withdrawal function, Directive 2023/2673). To confirm with a lawyer or gestor: the wording, and the seller's legal name and address in the emails.
- **Monthly and yearly:** switching between them, and what a yearly member gets back on cancelling or removing a site mid-year.
- **More than 10 sites:** not offered. The briefing's dedicated VPS tier is not part of the plans.
- **More space:** 1 GB for every site; buying more is not offered.
- **A free account that wants a second site** before card payments open: refused today ("The launch offer includes one site").
- ~~**Prices and VAT**~~ Answered on 2026-11-13: VAT included.
- ~~**Enforcing the space**~~ WordPress's upload quota, from 2026-11-11. Still open: whether the database should count too.
- **Custom domains:** they are on the home page as "soon"; building them (mvp-spec §14) moves before the launch.
