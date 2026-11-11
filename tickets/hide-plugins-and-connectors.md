# No Plugins or Connectors screens for anyone; members may embed what they want

**Milestone:** M4 (member sites) · **Status:** built 2026-11-11, not deployed

## Asked for
By the maintainer on 2026-11-11, after the planning of [agent-knows-gutenberg-spectra-settings.md](agent-knows-gutenberg-spectra-settings.md) found that Settings → Connectors (core 7.1's API-key screen) is visible to members, and that the key option (`connectors_ai_infomaniak_api_key`) can be written through `/wp/v2/settings`:

> For now no connector settings nor plugins should be visible for any user.

And about embeds (core's oEmbed providers are active, most of them US companies):

> Allow users to embed what they want. Is us that don't use american services, our users are allowed to use what they want on their sites.

## Decisions (the maintainer, 2026-11-11)
1. **Nobody sees Plugins or Connectors**, super admins included: no menu entries, the screens refused, and their REST routes refused. Plugins are installed and switched on only through Composer, `./bin/deploy` and WP-CLI. This replaces M4-02's "core's Plugins screen for members" (no plugin is offered to members yet).
2. **The connector keys are not in the REST API for anyone**, and members cannot write the AI plugin's options there. (Inference: super admins keep that, because the AI plugin's own settings screen saves through it.)
3. **Embeds are the member's choice.** Rule 1 binds the platform (what Mody Cloud runs and loads by itself), not what a member chooses to put on their own site. Core's embeds stay as they are.

## Plan
- `Gating\HiddenScreens` (new): removes Plugins (site and network) and Settings → Connectors from every menu, refuses `plugins.php`, `plugin-install.php`, `plugin-editor.php`, the network's `plugins.php` and `options-connectors.php` with a 403, refuses `/wp/v2/plugins` and the connector routes, and refuses `connectors_*` and `wpai_*` in `/wp/v2/settings`. WP-CLI is not affected.
- `Gating\MemberPlugins` stays (it already hides the platform plugins), but has no screen to work on. M4-02, mvp-spec §10 and AGENTS.md change with it.
- The decision on embeds goes into constraints.md (rule 1) and AGENTS.md.
- Test: in `scripts/member-plugins-test.php`, as a member and as a super admin, over HTTP.

## Results (built 2026-11-11, not deployed)
- `Gating\HiddenScreens`, registered in `mody-platform.php`: the menus (site, network, toolbar), the screens refused by URL with a 403 (`plugins.php`, `plugin-install.php`, `plugin-editor.php`, `options-connectors.php`, the `ai` plugin's `ai-connector-approval`), the `/wp/v2/plugins` and `/wp/v2/block-directory` routes refused to everyone, the connector keys out of `/wp/v2/settings` (`show_in_rest` off) and the `ai` plugin's options refused there to members. The `ai` plugin's dashboard status widget, which links to Connectors, is removed for everyone (it was already for members).
- The capabilities are unchanged on purpose: Wordfence's menu needs `activate_plugins`.
- Found: the `ai` plugin's own settings screen saves through `/wp/v2/settings`, so its options stay writable there for super admins.
- Tests: `scripts/member-plugins-test.php` rewritten (the refusals for a member and a super admin, the settings route, no menu entries; hidden plugins still never switch through Spectra's onboarding API), `gating-test.php` and `updates-test.php` updated. These, and `ai-plugin-lockdown-test.php`, pass.
- Locally the member sites were gone (deleted on 2026-11-10), so the tests had nothing to run on: `alpha` and `beta` were created again for `member@mody.local`, as in M0-01.

## Review (2026-11-11)
An independent review (two reviewers, each finding checked by a skeptic) found, and these were fixed the same day:
- **Route casing.** The REST server matches routes regardless of case, so `/wp/v2/Plugins`, `/wp/v2/Block-Directory` and `/wp/v2/Settings` got past every check. Fixed by lowercasing the route in `HiddenScreens`, `MemberPlugins` (the hidden plugins), `AiPlugin` (the `ai` plugin's routes, also open to members this way), and `Design\Patterns` (the Pattern Directory proxy, rule 1; that one dates from 2026-11-08).
- **admin-ajax.php.** Core's plugin actions (`search-plugins`, `activate-plugin`, `install-plugin`, `search-install-plugins`, `update-plugin`, `delete-plugin`) are refused to everyone.
- **Spectra's onboarding routes.** Its routes that read, install or switch plugins are refused too.
- **Not changed:** Site Health → Info still lists the plugins to super admins (it is a report, not a plugin screen), and the `ai` plugin's connector approvals still answer over REST for super admins (it has no screen any more).

Tests added for each to `scripts/member-plugins-test.php` and `scripts/patterns-test.php`.
