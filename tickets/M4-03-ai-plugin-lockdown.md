# M4-03 · The `ai` plugin on member sites

**Milestone:** M4 · **Spec:** documentation/mvp-spec.md §8 ("The `ai` plugin's features on member sites"), decision 13 · **Status:** done (2026-11-07; in production, with the plugin network-activated)

Split from M4-01 on 2026-11-07 (rule 5).

## Goal
On member sites, the `ai` plugin offers a curated set of features that work out of the box, and members cannot change its configuration or reach MCP.

## Decisions (maintainer, 2026-11-07)
- **Curated features: every text feature.**
  - On-demand writing: titles, excerpts, meta descriptions, resizing, editorial updates, summaries, translation, slugs, alt text.
  - Type-ahead.
  - Comments: moderation and suggested replies.
  - Organizing: classification, block labels, editorial notes, revision summaries.
- **Off:** image generation and text to speech (the Infomaniak connection is text only), the developer tools (MCP, WebMCP, abilities explorer, custom abilities), Markdown feeds, the C2PA monitor.
- **Sites without a brand profile:** the features keep working there. Member sites always get a profile from the wizard.

## How it is built (`src/Gating/AiPlugin.php`)
- **Pinned values:** on every site but the main one, WordPress's generic `pre_option` filter answers three things, whatever is saved:
  - the plugin's feature switches (`wpai_feature_<id>_enabled`): `1` for the 19 in `AiPlugin::FEATURES` (the curated list plus connector approval, request logging and key encryption), `0` for anything else, including features a plugin update adds;
  - its connector approvals (`ai/ai.php` may use `infomaniak`, nothing else);
  - its pending requests (none, so members get no notice to review them).
  - A new site works at once, members cannot change anything, and the plugin's own screens show the pinned values. To change the list, change `FEATURES`.
- **For members (not super admins) on member sites:**
  - the plugin's screens (Settings → AI, Tools → Connector Approvals, MCP access, abilities explorer, request logs) are removed from the menus and refused by URL (403);
  - its "AI Status" dashboard widget is removed;
  - REST: mcp-adapter's `/mcp` routes and the plugin's configuration routes (`/ai/v1/mcp`, `connector-approvals`, `settings`, `abilities`, `providers`, `logs`) answer 403 `mody_locked`.
- **The request log table** is created for every new site on `wp_initialize_site`, using the plugin's own schema class. Requests made in a new site's context during provisioning no longer hit a missing table: zero "doesn't exist" errors across all suites, from hundreds before.
- The plugin's source is not edited.

## Acceptance criteria (spec §8, §10)
- [x] On a member site, the member cannot change the `ai` plugin's settings or connector approvals, sees only the curated features, and cannot reach MCP.
- [x] On a newly provisioned site, the curated features work at once (no approval step, no database errors).

## Results (2026-11-07, local)
- `scripts/ai-plugin-lockdown-test.php`: all checks pass.
  - **Features:** all 27 of the plugin's features are classified (19 on, 8 off); an unknown one fails the test.
  - **Pinned on `alpha`:** the switches, an unknown feature off, the approvals (through the plugin's own `Approvals_Store`), a saved change that does not take; the main site untouched.
  - **Over HTTP on `alpha` as a member:**
    - three screens answer 403;
    - none are linked from the dashboard;
    - the configuration and MCP routes answer 403;
    - `ai/title-generation` exists (200) and `ai/image-generation` does not (404);
    - a super admin is not refused.
  - **A new site** gets the log table.
- `ai-plugin-brand-context-test.php`: its end-to-end title generation on `alpha`, skipped until now because nothing was on or approved there, now runs and passes.
- All other suites pass.

## Production
Deployed 2026-11-07 (commit `4d139f2`, after a backup in `backups/2026-11-07-pre-m4-03/`). The `ai` plugin was then **network-activated** (decided by the maintainer the same day). Before, it was active on the main site only.

Checked on `phycticio.mody.cloud` in its own context:
- the curated switches on and the others off;
- `ai/title-generation` registered and `ai/image-generation` not;
- `ai/ai.php` approved for Infomaniak;
- its request log table there.

The main site keeps its own settings and approvals. The agent and Spectra tests pass, with no PHP warnings. `mcp-adapter` stays active on the main site only; its routes are locked on member sites either way.

## Open
- **Cost:** type-ahead calls the model as members type, and comment moderation on every new comment. The request log records them per site; per-member limits are still open (mvp-spec §8).
