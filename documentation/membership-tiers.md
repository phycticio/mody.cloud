# Plans, limits and gating

Decided by the maintainer on 2026-11-10. It replaces the briefing's four tiers (Collaborator, Member, Patron and Dedicated VPS at 5, 15 and 50 EUR, "fees equal real cost"): Mody Cloud is run by the maintainer as a self-employed professional in Spain, not by a non-profit association. See [project-overview.md](project-overview.md#economics).

## Every site gets the same

Whatever the plan, each site has:

- **3 GB of space.** **Not enforced yet:** the network has `upload_space_check_disabled` = 1, so no quota applies. WordPress's own per-site upload quota (`blog_upload_space` = 3072, in MB, with the check switched on) counts the site's uploads folder only (**Inference**: a starting point, to confirm).
- **Every plugin in the market**, switched on per site by the member ([mvp-spec.md §10](mvp-spec.md#10-plugin-market-and-capability-gating)). The market offers no plugin yet.
- **Its own Mody.** The agent of a site reads only that site's brand and content, and nothing passes between the sites of one account. This is how the agent already works: every call runs on the current site (`get_current_blog_id()`).
- **A custom domain:** the member points a domain to our servers and the site answers at it. **Not built yet** (mvp-spec §14, the Caddy sidecar); the home page says "soon".
- **A way out** (**not verified yet**: nobody has exported a member site and restored it elsewhere; check whether the zip carries the Mody fonts, which the platform serves): the theme downloads as a zip from the Site Editor (core's Export, `export` capability, which members have as administrators), and the content with Tools → Export. The site then runs on another WordPress host with Spectra installed.
- The full Site Editor, the six designs and the Mody fonts, Spectra blocks, and updates and security run by the platform.

## Plans

| Plan | Sites | Monthly | Yearly | Yearly saving |
| --- | --- | --- | --- | --- |
| Trial (30 days, no card) | 3 | 0 | – | – |
| 1 site | 1 | 9.99 EUR | 89.99 EUR | 29.89 EUR |
| 3 sites | 3 | 25.99 EUR | 249.99 EUR | 61.89 EUR |
| 10 sites | 10 | 49.99 EUR | 499.99 EUR | 99.89 EUR |

- Plans differ **only** in the number of sites and the billing period.
- The trial is unchanged (M1-03): 30 days, no card, 3 sites.
- **The code still has the old plans:** `Members\Members::PLANS` (trial 3, `collaborator` 3 at 5 EUR, `member` 10 at 15 EUR, `patron` 50 at 50 EUR), the `plan` and `next_plan` columns' enums, and `scripts/members-test.php`. They change with the billing work ([M1-01](../tickets/M1-01-registration-trial-billing.md) part 3), which also adds the billing period. The new slugs are chosen then.
- The home page shows these plans since 2026-11-10 (`scripts/home-page.php`).

## Gating

- **Sites:** `Provisioning\Quota::can_create_site()` (M2-01) counts a user's sites: the network sites they administer (not the main site), plus site creations still on the way. A user with a `mody_members` row gets their plan's limit through the `mody_site_limit` filter (`Members\Members::site_limit()`, M1-01 part 1). Users without a row keep 3. The refusal never says "unlimited" (rule 7).
- **Space:** 3 GB per site, to enforce (see above).
- **Newsletter sends:** out of the MVP ([newsletter.md](newsletter.md)); no per-plan cap is decided.

## Open questions

- **Prices and VAT:** are the prices shown VAT included? A self-employed seller in Spain charges VAT to consumers, and EU consumer rules expect the final price to be shown. The home page shows the prices with no VAT mention.
- **The trial's 3 sites and the 1-site plan:** what happens to the extra sites when a trial member chooses the 1-site plan.
- **Monthly and yearly:** switching between them, and what a yearly member gets back on cancelling. mvp-spec §4 applies plan changes "at the next billing date", which for a yearly plan can be a year away.
- **Invoices and the right of withdrawal:** the invoices a self-employed seller must issue (who issues them, Mollie or the platform), and the EU 14-day right of withdrawal for consumers on a paid plan.
- **More than 10 sites:** not offered. The briefing's dedicated VPS tier is not part of the new plans.
- **Enforcing the 3 GB:** WordPress's upload quota, or a measure of the whole site (database included).
- **Custom domains:** they are on the home page as "soon"; building them (mvp-spec §14) moves before the launch.
