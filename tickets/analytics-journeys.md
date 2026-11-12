# Analytics: every page view in one table, journeys as patterns, your sites side by side

**Builds on:** [mody-dashboard.md](mody-dashboard.md) (part B, `mody-analytics` and the Visitors section) · **Replaces:** that ticket's Decision 4, "daily totals only" · **Status:** built on 2026-11-12; not deployed.

## Asked for
By the maintainer on 2026-11-12:

> Every page per user without collecting their email address but only the uniqueness of their visit is count. A user who visits a pattern of URLs can be stored as a pattern but never as the user itself. […] This should be truth for all sites, as this is the only tracking plugin the users will have. […] The tracking system should all count on one main table of visits where everything is stored and show the administrators of each site filtered data for only their sites. The dashboard view of each site will show the filtered data of that specific site while the analytics view in the sites view will show a wider view of each site the user has in the platform.

## Decisions (the maintainer's, 2026-11-12)
1. **One table for every page view of every site:** `{base}mody_analytics_views`. One row per view: site, visit, `seq` (the view's place in the visit), the site's day and hour, path (no query string), source (the referring host), device. Every dashboard reads it, filtered by site.
2. **The visit, never the person.** `visit_id` is random (`random_bytes`), never computed from the visitor. The only link between a person and a visit is today's map, `mody_analytics_seen` (the daily salted hash of address, browser and site → the current visit). The map and its salt are deleted after the day. No address, user agent, user ID, email or query string is stored anywhere. Time is kept to the hour, not the second, so rows are harder to match against server logs.
3. **Visits are per site** (option "per site"): the hash includes the site, so the same person on two sites is two unrelated visits. Members' audiences are never linked.
4. **Logged-in users count too** (option "count them anonymously"): by the same anonymous visit, never by account. A site's owner reading their site adds to its numbers.
5. **A visit ends after 30 minutes without a page** (`Collector::VISIT_GAP`), and never crosses midnight (the hash changes every day).
6. **Retention: 13 months, then daily totals** (`Store::RETENTION`). Older views are reduced to per-site, per-day totals in `mody_analytics_daily` (total, page, source, device) and deleted. That runs when the day's first salt is made, at most 7 days per pass. There is no cron, because WP-Cron does not run on subsites ([architecture.md](../documentation/architecture.md#open-questions)).

## What the dashboards show
- **A site's Dashboard → Visitors**, that site only: visits, page views, pages per visit, single-page visits; visits per day; **Journeys** (the most common orders of pages, first 5 steps, then "more"); pages; entry pages; exit pages; sources; devices.
- **Dashboard → Your sites**, the member's sites together: visits, page views and pages per visit across all of them; visits per day for all of them; a table comparing the sites (visits, page views, the change on the period before, most viewed page; each name opens that site's Visitors); a visits line on each site's card. Same 7, 30 and 90 day periods.

## Results (2026-11-12)
- **Built:** `Store` (version 2: the views table, the map with visits, the archive pass), `Collector` (visits and the 30-minute gap), `Tracker` (logged-in users counted), `Stats::summary()` (patterns) and `Stats::sites()` (several sites), and `Dashboard\DashboardPage` (Journeys, entry and exit pages, Visitors across your sites).
- **Upgrade:** version 1's `seen` table is dropped and made again, so today's visitors start new visits. The daily totals counted since 2026-11-11 stay in `mody_analytics_daily`, and the charts use them for days with no views in the new table.
- **Tests:** `scripts/dashboard-test.php`, 54 checks, all pass. New ones cover visits, the 30-minute gap, journeys and "more", entry and exit pages, random visit IDs (never the hash, an address or a browser), several sites side by side, the archive after 13 months, and the beacon for logged-in users.
- **In headless Chromium** (a member with two sites and 30 days of sample journeys): Visitors and Your sites at 1440 and 390 px, with no horizontal scroll and no script errors.

## Open
- **Work in progress watermark:** Visitors still carries it (`DashboardPage::WORK_IN_PROGRESS`). Removing it is the maintainer's call.
- **The legal pages** should describe this: anonymous visits and their pages, kept 13 months, no cookies.
- **Inference:** Nginx's access logs hold addresses, times and paths. Rows are anonymous only as long as those logs are not kept or not joined with them. Logging retention belongs to M5-01.
- **A network-wide view for super admins** is cheap now (the same table, no filter), but nobody has asked for it.
