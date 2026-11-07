# M4-01 · Editor-first redirects + capability gating

**Milestone:** M4 · **Spec:** documentation/mvp-spec.md §9, §10 · **Status:** todo

## Goal
On member sites, every editing entry point lands in the Site Editor, and a member cannot install/upload plugins or themes or switch themes — while keeping full control of everything else.

## Deliverables
- **Editor redirects** (MU plugin, member sites only — never the main site; spec §9):
  - `post.php?post=N&action=edit` (page **and** post) → the Site Editor for that item. Pages: `site-editor.php?p=%2Fpage%2FN&canvas=edit`. The `?postType=<type>&postId=N` form without `canvas=edit` lands on the list in WordPress 7.1. Confirm the form for posts.
  - `post-new.php?post_type=page|post` → `site-editor.php?postType=<type>`.
- **Capability gating** (capability filter scoped to the member's own sites; spec §10): remove `install_plugins`, `activate_plugins` (replaced by the market page), `switch_themes`, `install_themes`, `delete_themes`, `edit_themes`. `DISALLOW_FILE_EDIT` already network-wide.
  - Add `mody_manage_market` capability for the market page (M4-02).
- Keep intact: content, media, site users, settings, Spectra, Global Styles.
- **`ai` plugin lock-down** (spec decision 13, "Option C", agreed 2026-11-06), on member sites:
  - members cannot change the plugin's settings (Settings → AI) or its connector approvals;
  - members get only the curated set of features (which ones is an open question in mvp-spec §8). Which features get the brand context is decided (M3-04, 2026-11-06: the eight writing features), but that is not the curated list;
  - the plugin's feature toggles and connector approvals are options of each site (`wpai_connector_approvals`), so a new member site has every feature off and nothing approved. Set the curated features on and approve `ai/ai.php` for `infomaniak` on member sites (here or in M2's provisioning), and keep members from changing either;
  - decide whether the features should refuse on a member site without a brand profile, as the agent does (left open in M3-04: today they work there with no brand context);
  - the plugin's request log table (`wp_<id>_wpai_request_logs`) does not exist on sites created after the plugin was activated. Every AI request made in such a site's context logs a database error "Table … doesn't exist" (seen 2026-11-07 with provisioned sites). Create the plugin's tables for new sites (here or in M2's provisioning), or switch the logging off on member sites;
  - MCP access (mcp-adapter) is locked. Spectra's own abilities and MCP server are already off on every site: `SpectraGuard` pins their options to `disabled` (mvp-spec §10, 2026-11-06).
- `mody` abilities stay reachable for members through the agent. They keep their own permission callbacks.
- **Agent menu** (moved here from M3-01 on 2026-11-06): the `Mody Agent` page (`src/Agent/ChatPage.php`) and its endpoint appear on every site today, for users with `edit_pages`. Limit them to member sites, and decide whether the main site keeps them for platform admins.

## Acceptance criteria (spec §10)
- A member-site admin cannot install, upload or switch themes/plugins by any UI or REST path.
- A member cannot reach a classic block editor for any content type on their site; every editing entry point lands in the Site Editor.
- Front end and Site Editor are unaffected; media upload still works.
- On a member site, the member cannot change the `ai` plugin's settings or connector approvals, sees only the curated features, and cannot reach MCP.
