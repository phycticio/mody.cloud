# Plans, limits and gating

Decided by the maintainer on 2026-11-20: **Mody Cloud is a free service**, freemium later ([tickets/free-mvp.md](../tickets/free-mvp.md)). It replaces the price per site and the launch offer of 2026-11-13 ([pricing-per-site.md](../tickets/pricing-per-site.md)), which had replaced the plans of 2026-11-10 (1, 3 and 10 sites after a 30-day trial) and the briefing's four tiers ("fees equal real cost"). Mody Cloud is run by its maintainer as a private person in Spain and takes no payments and no donations. See [project-overview.md](project-overview.md#economics).

> "Let's start as a free service and in the future we will add more tiers. This is the MVP. Free that will turn into Freemium." (the maintainer, 2026-11-20)

## The free plan

- **Every account starts free:** one site, no card, **no end date**. `Members::start_free()` (`mody-members`) makes the row `free` and `active` and leaves `free_until` and `billing_due_at` empty, so Mody Billing's daily run never reminds, charges, locks or deletes a free account. Accounts from before 2026-11-20 got the same with table version 8 (their launch-offer end emptied; a free account past due or cancelled for want of a card became active again).
- **No places and no window:** anyone may sign up while registration is open (`mody_registration_open`), or only invited people when the Offers page says so (below). The launch offer's 50 places, six free months and three months are gone, with their network options.
- **Sign-ups from Spain only** (decided 2026-11-20): the visitor confirms they live in Spain (the chat's last button, "I live in Spain: create my site"; the form's box), and an address our own location data (Mody Analytics' DB-IP file, on our server) places outside Spain is refused (`Registration::outside_spain()`). An address it cannot place is let through; a VPN gets around it, which is accepted while nothing is charged. Accounts made before stay.
- **The Dashboard** shows **Your plan**: free, one site, what is included, and that paid plans with more will come later. No price, card or payment (`DashboardPage::render_free_plan()`).

## Every site gets the same

Each site has:

- **500 MB of space** (3 GB until 2026-11-13, 1 GB until 2026-11-20). Enforced with WordPress's own upload quota (`Storage` (the `mody-storage` plugin), since 2026-11-11, [mody-dashboard.md](../tickets/mody-dashboard.md)): every new site gets `blog_upload_space` = 500 (MB), the network has the same default and the check switched on, and the per-file cap is PHP's 1 GB (the quota stops it first). Sites made before got 500 MB too (version 3 of the settings): every site still on 3072 or 1024 MB; a size a super admin set by hand stays. A site already over 500 MB keeps its files and uploads again once under it. The main site has no limit. It counts the site's uploads folder only, not its database rows. The site's Dashboard shows the space used.
- **Everything built in:** Spectra's blocks, languages, cookieless visitor stats, antispam, image optimization, SEO. The plugin market offers no plugin yet ([mvp-spec.md §10](mvp-spec.md#10-plugin-market-and-capability-gating)).
- **Its own Mody**, with 5,000,000 tokens a month (see Gating). The agent of a site reads only that site's brand and content. Every call runs on the current site (`get_current_blog_id()`).
- **A custom domain:** Your domain on the site's Dashboard ([custom-domains.md](../tickets/custom-domains.md)).
- **A way out:** Download your site on the Dashboard, three files, at any time ([take-your-site-with-you.md](../tickets/take-your-site-with-you.md)).
- The full Site Editor, the six designs and the Mody fonts, and updates and security run by the platform.

## Paid tiers, later (built, switched off)

Optional paid tiers come later (freemium); which tiers, and what they add, is an open question. Until then everything built for paying stays in the repository and **off**:

- **The switch:** `Billing\Mollie::offered()` needs the network option `mody_payments_open` as well as a key (`Mollie::OPEN_OPTION`, 2026-11-20). Without it members see no card, no paid sign-up and no points, whatever `MOLLIE_API_KEY` holds. Mollie's test key on production stays (the maintainer, 2026-11-20); the webhook check passed there on 2026-11-18 (`scripts/mollie-sandbox.md`).
- **What is kept:** the price per site (`Members::PRICE`: 7.99 EUR a month or 79.99 a year, VAT included, decided 2026-11-13), up to 10 paid sites per account (`SITE_LIMITS`), monthly or yearly, Add your card (a 0.01 EUR check), charges per site, the webhook, the 14-day withdrawal in full, Cancel, refunds and chargebacks, read-only then deletion for unpaid accounts (M1-02), the expiring-card warning, paid sign-ups, and Plan & billing on the Dashboard. How it all works: [pricing-per-site.md](../tickets/pricing-per-site.md) and [M1-01](../tickets/M1-01-registration-trial-billing.md).
- **Before switching it on:** the legal pages' paid version (in git history before 2026-11-20), invoices, how free accounts and the new tiers live together, and the texts on the Dashboard's paid path, which still speak of the launch offer's free months.

## Invite friends

Since 2026-11-14 ([invites-and-offers.md](../tickets/invites-and-offers.md), `Invites` (`mody-members`)), every member's Dashboard, and a super admin's, has an **Invite friends** section with a personal link (`/register/?ref=<code>`, a random code). A signup through it records the inviter in `mody_members.invited_by`. The friend gets what every signup gets: a free account (and, when sign-ups are for invited people only, the link is what lets them in). **A point** (`invite_point_at`) counts once, when the friend's first payment is paid; a withdrawal takes it back. **Points give nothing yet**, and the section says so (rule 7). While payments are off nobody can earn one, so the section shows only the link and how many joined (2026-11-20), never who. Invites can be switched off on the Offers page.

## The Offers page

Network Admin → Mody → Offers (`Offers` (`mody-members`), the network option `mody_offers`, super admins only) sets **who may sign up**, everyone or invited people only (`launch_who`, the key the launch offer saved it under), and **invites** on or off. Cut down to these two on 2026-11-20: the launch offer's places, months and start are gone.

## Gating

- **Sites:** `Quota::can_create_site()` (`mody-sites`) (M2-01) counts a user's sites: since the security pass of 2026-11-12, the sites they own (blog meta `mody_owner`, set when the platform creates a site; older sites were backfilled from the provisioning jobs or their first administrator), not every site they administer, plus site creations still on the way. A user with a `mody_members` row gets their plan's limit through the `mody_site_limit` filter (`Members::site_limit()` (`mody-members`)): 1 free, 10 when paying (kept for the paid tiers). Users without a row keep 3. The refusal never says "unlimited" (rule 7).
- **Space:** 500 MB per site, WordPress's upload quota (see above).
- **Mody's allowance** (`Agent\Budget`; daily per site and per account from the security pass of 2026-11-12, **monthly per site** since 2026-11-13): each site gets **5,000,000 tokens a calendar month**, at most **1,000,000 of them in one day**, the same free or paid; prompt and answer together, counted from the agent's `turn` and `usage` rows; super admins are exempt and their work on a site is not counted. A message uses about 11,500 tokens and a generated page about 25,000 (717 turns over 30 days to 2026-11-13), so a month is about 430 messages, and a first day of building a site (8 pages and 40 messages, about 700,000) fits in a day. Past it, Mody says when it is back (the next UTC day, or the first of next month). The filter is `mody_agent_token_budget` (`day`, `month`). The Dashboard's Mody's work section shows the month's tokens against the allowance. The home page names it among the real limits (rule 7).
  - **Why 5 million:** at Infomaniak's price for the model (CHF 0.40 per million prompt tokens, CHF 3.00 per million answer tokens; with our mix about CHF 0.60 a million, cached prompt tokens counted at full price), a site's month costs at most about 3.25 EUR, and a regular site (100 messages and 4 pages a month) about 0.85 EUR. With free accounts and no cap on them, this is the cost that grows: about 43 EUR a month for 50 regular sites, at most about 163 at the allowance. The safety valves are closing registration and the `mody_agent_token_budget` filter ([free-mvp.md](../tickets/free-mvp.md), Costs).
- **Mody at once:** one job per person and site, three on the network, the editor's small AI features one of them (`Agent\Slots`).
- **Newsletter sends:** out of the MVP ([newsletter.md](newsletter.md)); no cap is decided.

## Open questions

- **The paid tiers** (freemium): which tiers, what they add over the free plan (more space, more sites, more of Mody's work?), their prices, and how free accounts and paid ones live together. Until decided, everything below about paying applies only when payments are switched on.
- **Who may sign up beyond Spain**, and when.
- ~~**Who issues the invoices**~~ Decided by the maintainer on 2026-11-13: Mollie at first, then our own invoicing plugin. To check before payments are switched on: that Mollie's invoices meet Spain's rules (numbering, NIF, VAT; Verifactu).
- ~~**Payment methods**~~ Decided 2026-11-13: cards only.
- ~~**The EU 14-day right of withdrawal**~~ Decided 2026-11-13: a full refund of the first charge, from a Withdraw button on Plan & billing (EU withdrawal function, Directive 2023/2673). To confirm with a lawyer or adviser: the wording, and the seller's legal name and address in the emails.
- **Monthly and yearly:** switching between them, and what a yearly member gets back on cancelling or removing a site mid-year.
- **More than one site:** one per free account; refused ("The free plan includes one site"). More comes with the paid tiers.
- **More space:** 500 MB for every site; buying more is not offered.
- ~~**Prices and VAT**~~ Answered on 2026-11-13: VAT included.
- ~~**Enforcing the space**~~ WordPress's upload quota, from 2026-11-11. Still open: whether the database should count too.
