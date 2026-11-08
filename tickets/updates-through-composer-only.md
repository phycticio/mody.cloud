# WordPress and plugin updates only through the repository

**Milestone:** M4 (follow-up of [M4-02](M4-02-plugin-market.md)) · **Spec:** documentation/architecture.md (Open questions: "WordPress.org update checks and rule 1"), documentation/mvp-spec.md §10 · **Status:** done (created and built 2026-11-08; Results below)

## Decision (maintainer, 2026-11-08)
> Users will not have the ability to update WordPress nor any plugin.

WordPress core and plugin versions change only in the repository: core with `./bin/version`, plugins in `app/composer.json`. They reach production through `./bin/deploy`. Nobody updates them from wp-admin.

## Already true before this ticket (checked 2026-11-08)
- **Members**, on every environment: `update_core`, `update_plugins`, `update_themes`, `update_languages` and `install_languages` are all refused. Core refuses them to everyone but super admins on Multisite, and `Gating\MemberSite` also refuses the plugin and theme ones. Checked with `user_can()` for `member` on `alpha`.
- **Everyone in production**, super admins included: `WORDPRESS_DISALLOW_FILE_MODS=true` makes core refuse every update capability, and it disables automatic updates.
- **Locally**, file changes are allowed (`WORDPRESS_DISALLOW_FILE_MODS=false`) so that the maintainer can develop. Automatic updates are off (`WORDPRESS_AUTOMATIC_UPDATER_DISABLED=true`, `WORDPRESS_AUTO_UPDATE_CORE=false`).

## Plan (built 2026-11-08; differences in Results)
1. **Switch off core's update checks to `api.wordpress.org`.** These are the checks for core, plugins, themes and translations, and they run on a WP-Cron timer, on admin page loads, and on every load of the Plugins screen. Nobody can act on the result, so they only send requests. Turning them off also settles the rule 1 question in architecture.md for page loads. This belongs in the MU plugin, not `wp-config.php`, so that it holds on every environment.
2. **Hide the update interface**: the update notices on the Plugins screen and the Dashboard → Updates screen. Core already shows them only to users who can update, so most of this should follow from item 1. Check it in the browser as a super admin in production.
3. **Tests:** add `update_core` and the translation capabilities to the "still refused" checks in `scripts/member-plugins-test.php`. Add one check that loading the Plugins screen makes no request to `api.wordpress.org` (`pre_http_request`).
4. **Docs:** turn the architecture.md open question into a decision, and add this rule to mvp-spec §10.

## Results (2026-11-08)
**Built** in `app/web/mu-plugins/mody-platform/src/Gating/Updates.php`, wired in `mody-platform.php`:
- **The capabilities:** `map_meta_cap` at priority 100 refuses `update_core`, `update_plugins`, `update_themes` and `update_languages` to every user on every site and environment. `do_not_allow` denies super admins too. Themes and translations are included because they are part of the same updates. For our theme, a WordPress.org theme named `theme` could otherwise be offered as its update. Nothing installed checks these capabilities except core.
  - **What this hides and refuses:** the Updates screen (core's menu check refuses it before its own check runs), update notices, rows and counts, and the auto-update controls. It also refuses the AJAX and upgrader update requests.
  - **The update check of its own that the Updates screen runs** (`wp_version_check()` in `update-core.php`) is never reached.
- **The automatic checks:**
  - `remove_triggers()` runs when the MU plugin loads. It removes core's 14 triggers from `wp-includes/update.php`, which `wp-settings.php` loads before MU plugins: `_maybe_update_*` on `admin_init`, the `load-*.php` screen hooks, the cron event callbacks, `wp_maybe_auto_update`, and `wp_schedule_update_checks`.
  - `unschedule()` on `init` clears the four update cron events on each site. A recurring event reschedules itself even without a callback, so this is needed.
- **Deliberately left alone: code that calls core's check functions directly.**
  - **Wordfence:** its scans and activity report call `wp_version_check()`, `wp_update_plugins()` and `wp_update_themes()`, and use the result to warn about outdated and vulnerable plugins. Its own auto-update routine calls `wp_update_plugins()` too.
  - **Site Health → Info** (`WP_Debug_Data`).
  - **WP-CLI** commands run by hand.
  - Blocking these at the HTTP level would also have cut off Wordfence's warnings. `wp_version_check()` would also log a warning and retry over plain HTTP on every refusal.
- **Checked in core** (WordPress 7.1): no installed plugin declares `Requires Plugins`, so the Plugins screen does not ask WordPress.org for dependency data either.

**Differences from the plan:**
- The checks are in a new suite, not in `member-plugins-test.php`, because they cover super admins and the main site too.
- "No request to WordPress.org" is shown through the update caches, not `pre_http_request`. The screens load in PHP-FPM, so a filter in the test's own process would not see their requests.

**Test:** `scripts/updates-test.php`, 16 checks, no model call, no request to WordPress.org:
- the four capabilities refused to a member and a super admin, on a member site and the main site; activating plugins and installing (by `DISALLOW_FILE_MODS`) unchanged;
- none of the 14 triggers hooked; no update event on the main site;
- over HTTP:
  - with the update caches emptied, the network Dashboard, Plugins and Themes screens and a member site's Plugins screen leave them empty. Core writes the cache before it asks WordPress.org, so an empty cache means no check ran;
  - update events scheduled on the member site are cleared by that request;
  - the network Updates screen refuses a super admin (403) and is not in the menu;
  - with a fake update for mcp-adapter in the cache, the network Plugins screen shows no notice and no count.

**Every suite passes offline** (18, with `MODY_TEST_OFFLINE=1`). Two of them, run once with real model requests, failed for reasons unrelated to this ticket:
- `ai-agent-chat-test`: three checks compare the agent's button URLs with editor URLs that lack `mody-chat=open`. `Agent.php` adds that parameter to every admin button, so the expectations are stale;
- `agent-template-test`: the model left the requested line out of the footer once.

The stale checks are listed in [next-steps.md](next-steps.md).

**Not checked by the tests:** the screens in a real browser, for a super admin. In production, file changes are disallowed already, so nothing visible changes there, except that the four update cron events go away.

## Open questions (for the maintainer)
- **The direct calls that remain** (Wordfence's scans, Site Health → Info, WP-CLI) still reach `api.wordpress.org`. Keep them, which keeps Wordfence's vulnerability warnings, or block them too?
- **How the maintainer hears about security releases.** Candidates: Wordfence's scan results and emails, `./bin/composer outdated` and `./bin/wp core check-update` run by hand, or WordPress's release announcements.
- **The Dashboard's other WordPress.org calls:** the Events and News widget, and the browser and PHP version checks (`browse-happy`, `serve-happy`). They are not update checks, and this ticket does not touch them.
