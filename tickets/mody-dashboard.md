# The Mody dashboard: every site's Dashboard, with visitors, Mody's usage, sites and the plan

**Builds on:** [M1-01](M1-01-registration-trial-billing.md) part 4 (My sites, Plan & billing), [your-brand-page.md](your-brand-page.md) (the layout), [agent-performance.md](agent-performance.md) (the turn rows) · **Replaces:** mvp-spec §5's dashboard in Network Admin · **Status:** planned and built on 2026-11-11 (parts A to D, Results below); not deployed.

## Asked for
By the maintainer on 2026-11-11:

> Let's build the user's dashboard. This should be a page replacing the wordpress default Dashboard link. Using the same Icon and word and having the same layout as the mody-brand page and it should called only mody-dashboard. In there we are going to show metrics of the site (we need to build a tracking plugin), tokens used in the design of the page, tokens used in the configuration of the page (both need building), billing information, sites, current subscription plan, upgrade options. Etc […] If it aligns with what we have let's proceed with your recommendations I will get the Mollie information. […] Let's enforce the 3GB on site creation.

## Decisions (the maintainer's recommendations, 2026-11-11)
1. **Where:** on every site, wp-admin's **Dashboard** menu item (same word, same icon, same place) opens `admin.php?page=mody-dashboard`. Core's dashboard (`index.php`) redirects there, so logging in lands on it too. Main site included: the maintainer sees mody.cloud's own visitors.
2. **Layout:** the Brand page's: sections on the left, a card on top, the section's panel below. It reuses `brand-page.css`.
3. **Who sees what:**
   - **Overview** (everyone with the menu): the site's card, storage, shortcuts.
   - **Visitors** and **Mody's work** (the site's administrators, `manage_options`).
   - **Sites** and **Plan & billing** (whoever has a `mody_members` row; super admins see a note instead). They are the member's account, the same on every site of theirs.
4. **The tracking plugin is our own MU plugin, `mody-analytics`** (rule 1: no Matomo, no outside service). Like `mody-languages` and `mody-mail`, it stands alone, loaded on every site; the dashboard reads it.
   - **No cookies, nothing stored in the browser, no address kept:** a visitor is counted once a day by a hash of the address, the browser and a salt that changes every day; yesterday's hashes are deleted. No consent banner needed for it (**Inference**: the legal pages, when written, should still mention it).
   - **A tiny script** on the front end sends one beacon per page view to the site's own REST route. Logged-in users, bots and previews are not counted.
   - **Daily totals only:** views and visitors per site and day, views per page, sources (other sites linking in), devices. **Replaced on 2026-11-12** by one table of page views under anonymous visits, with journeys, logged-in users counted and a view across a member's sites: [analytics-journeys.md](analytics-journeys.md).
   - **The real address through the proxies:** read from Cloudflare's header only when the request came from a private address (our proxy). The same helper serves the signup's rate limit later.
5. **Mody's work** comes from the agent's `turn` rows (`Agent\TurnMetrics`, since 2026-11-11), counted per site in three groups:
   - **Design:** pages written or changed (`generate-page`, `edit_content`), designs and styles (`update-design`, `update-styles`);
   - **Configuration:** settings, menus, publishing, the site's name (`update-settings`, `update-menu`, `publish-page`, `update-site-settings`, `open-editor`);
   - **Questions and answers:** messages that ran no tool.

   Each shows messages and tokens. Turn rows also record the page that was open, so the dashboard lists the pages that took the most. Earlier messages have no rows: the counts start on 2026-11-11.
6. **The new plans in code** (from M1-01 part 3, needed to show prices): `sites_1`, `sites_3`, `sites_10`, monthly or yearly (`billing_period`), the table at version 3. Old rows: `collaborator` → `sites_3`, `member` and `patron` → `sites_10`.
7. **Plan & billing without Mollie yet:** the plan, the trial's days left, the three plans with monthly and yearly prices and the yearly saving. **Choose** records the plan for when the trial ends (`next_plan`, `billing_period`); the card and payments come with M1-01 part 3. A plan with fewer sites than the member uses is disabled, with the reason.
8. **3 GB per site, enforced** with WordPress's own upload quota: `blog_upload_space` = 3072 (MB) on every new site and as the network's default, the check switched on, the main site without a limit. WordPress's per-file cap (`fileupload_maxk`, 1.5 MB by default and enforced only with the check) is raised to PHP's 1 GB. The quota counts the uploads folder only.

## Parts
- **A. The page and the storage** (Decisions 1–3, 8): the menu, the redirect, the Overview with storage, the 3 GB quota.
- **B. `mody-analytics`** (Decision 4) and the Visitors section.
- **C. Mody's work** (Decision 5).
- **D. Sites and Plan & billing** (Decisions 6, 7).

## Not in this ticket
- Payments, the card and invoices: M1-01 part 3, with the Mollie keys.
- Deleting a site from the dashboard (spec §5's soft delete with a 30-day trash): it needs the WP-Cron decision.
- Tokens used by the `ai` plugin's writing features (titles, excerpts): they are not in the turn rows.

## Results (2026-11-11)
- **Built:**
  - `Dashboard\DashboardPage`: the menu item, the redirect, five sections. Plugins' items under core's Dashboard move under it (core's My Sites does).
  - `Dashboard\Usage`: Mody's work from the turn rows. `Agent::respond()` now also records what was open (`open`: type and ID).
  - `Site\Storage`: the 3 GB.
  - `Members`: the new plans, `billing_period`, `choose_plan()`, `yearly_saving()`; table version 3 with the old plans' migration.
  - The `mody-analytics` MU plugin.
  - `assets/dashboard-page.css`.
- **Tests:** `scripts/dashboard-test.php`, 43 checks, all pass. It covers the quota (an upload over it refused, a 5 MB file accepted), the beacon's rules (paths, sources, devices, the address behind the proxies, bots, the daily cap, only hashes kept), Mody's groups and the open page, the plans' migration on the real table, who sees which section, and over HTTP: wp-admin/ redirects to the dashboard, the menu, the beacon for visitors only, the route's 204.
  - Updated: `scripts/members-test.php` (the new plans) and `scripts/member-plugins-test.php` (the menus are checked on the new dashboard). Both pass, as do registration, provisioning, onboarding, gating, updates, brand page and settings, agent edit, agent chat, network profile, login page and antibot.
  - `scripts/wizard-test.php` fails one check ("the theme's header with the brand name") with or without this work.
- **In headless Chromium**, as a member on a site with 29 days of sample visits:
  - wp-admin/ lands on the dashboard; the Dashboard item has its icon and is current; core's item is gone;
  - every section renders with no horizontal scroll at 1440 px and at 390 px; a bar's tooltip shows its day;
  - choosing "3 sites, yearly" shows the notice and the choice, with 249.99 EUR on the trial's last day;
  - no script errors.
- **On deploy, in production:**
  - the network's quota switches on by itself (`mody_storage_version`): every site but the main one gets 3 GB, and single files up to 1 GB;
  - the members table moves to version 3;
  - the visitor counts start from zero;
  - Mody's work shows messages from 2026-11-11, when the turn rows started.
- **Open:**
  - the legal pages, when written, should describe the visitor counting;
  - deleting a site from the dashboard (needs the WP-Cron decision);
  - the card, payments and invoices (M1-01 part 3, the Mollie keys).

## Work in progress watermark (2026-11-11)
The maintainer asked for "a watermark to visitors and Mody's work that says Work in progress with an ellipsis animation". `DashboardPage::WORK_IN_PROGRESS` lists the two sections:
- a large, faint, tilted "Work in progress" over the panel, with three dots that fade in one after another (still under `prefers-reduced-motion`);
- it does not block the numbers: tooltips, the table and the links still work through it;
- screen readers read it once, as a note.

Remove a section from the list when it is finished. Visitors left the list on 2026-11-13 (the maintainer's request); Mody's work keeps it. Checked in `scripts/dashboard-test.php` and in headless Chromium at 1440 and 390 px. The same pass fixed Mody's work's table on phones, which stacked its cells and was wider than the screen.
