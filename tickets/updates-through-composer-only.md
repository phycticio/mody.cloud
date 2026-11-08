# WordPress and plugin updates only through the repository

**Milestone:** M4 (follow-up of [M4-02](M4-02-plugin-market.md)) · **Spec:** documentation/architecture.md (Open questions: "WordPress.org update checks and rule 1"), documentation/mvp-spec.md §10 · **Status:** todo (created 2026-11-08)

## Decision (maintainer, 2026-11-08)
> Users will not have the ability to update WordPress nor any plugin.

WordPress core and plugin versions change only in the repository: core with `./bin/version`, plugins in `app/composer.json`. They reach production through `./bin/deploy`. Nobody updates them from wp-admin.

## Already true (checked 2026-11-08)
- **Members**, on every environment: `update_core`, `update_plugins`, `update_themes`, `update_languages` and `install_languages` are all refused. Core refuses them to everyone but super admins on Multisite, and `Gating\MemberSite` also refuses the plugin and theme ones. Checked with `user_can()` for `member` on `alpha`.
- **Everyone in production**, super admins included: `WORDPRESS_DISALLOW_FILE_MODS=true` makes core refuse every update capability, and it disables automatic updates.
- **Locally**, file changes are allowed (`WORDPRESS_DISALLOW_FILE_MODS=false`) so that the maintainer can develop. Automatic updates are off (`WORDPRESS_AUTOMATIC_UPDATER_DISABLED=true`, `WORDPRESS_AUTO_UPDATE_CORE=false`).

## To do
1. **Switch off core's update checks to `api.wordpress.org`.** These are the checks for core, plugins, themes and translations, and they run on a WP-Cron timer, on admin page loads, and on every load of the Plugins screen. Nobody can act on the result, so they only send requests. Turning them off also settles the rule 1 question in architecture.md for page loads. This belongs in the MU plugin, not `wp-config.php`, so that it holds on every environment.
2. **Hide the update interface**: the update notices on the Plugins screen and the Dashboard → Updates screen. Core already shows them only to users who can update, so most of this should follow from item 1. Check it in the browser as a super admin in production.
3. **Tests:** add `update_core` and the translation capabilities to the "still refused" checks in `scripts/member-plugins-test.php`. Add one check that loading the Plugins screen makes no request to `api.wordpress.org` (`pre_http_request`).
4. **Docs:** turn the architecture.md open question into a decision, and add this rule to mvp-spec §10.

## Open question
- **How the maintainer hears about security releases** once the checks are off. Candidates: run `./bin/composer outdated` and `./bin/wp core check-update` by hand (WP-CLI asks WordPress.org only when it is run), or follow WordPress's release announcements. Decide when building this.
