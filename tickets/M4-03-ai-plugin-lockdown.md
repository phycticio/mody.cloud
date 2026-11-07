# M4-03 · The `ai` plugin on member sites

**Milestone:** M4 · **Spec:** documentation/mvp-spec.md §8 ("The `ai` plugin's features on member sites"), decision 13 · **Status:** todo

Split from M4-01 on 2026-11-07 (rule 5).

## Goal
On member sites, the `ai` plugin offers a curated set of features that work out of the box, and members cannot change its configuration or reach MCP.

## Deliverables
- **Locked configuration:** members cannot change the plugin's settings (Settings → AI) or its connector approvals.
- **Curated features:** members get only the curated set. **Open question:** which ones (mvp-spec §8). Which features get the brand context is already decided (M3-04: the eight writing features), but that is not the curated list. Ask the maintainer before building.
- **Working out of the box:** the plugin's feature toggles and connector approvals are options of each site (`wpai_connector_approvals`), so a new member site has every feature off and nothing approved. Turn the curated features on, and approve `ai/ai.php` for `infomaniak`, on member sites. Do it in provisioning (a new step, or the `theme` step), and for existing sites once.
- **The request log table:** `wp_<id>_wpai_request_logs` does not exist on sites created after the plugin was activated. Every AI request in such a site's context logs a database error "Table … doesn't exist" (seen 2026-11-07 with provisioned sites). Create the plugin's tables for new sites, or switch the logging off on member sites. The log is the planned basis for per-member cost tracking (decision 13), so creating the tables is likely the right call.
- **MCP:** mcp-adapter is locked on member sites. Spectra's abilities and MCP server are already off everywhere (`SpectraGuard`).
- **Open question:** whether the features should refuse on a member site without a brand profile, as the agent does. Left open in M3-04: today they work there with no brand context.
- `mody` abilities stay reachable for members through the agent, with their own permission callbacks.

## Acceptance criteria (spec §8, §10)
- On a member site, the member cannot change the `ai` plugin's settings or connector approvals, sees only the curated features, and cannot reach MCP.
- On a newly provisioned site, the curated features work at once (no approval step, no database errors).
