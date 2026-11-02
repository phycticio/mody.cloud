# M4-01 · Editor-first redirects + capability gating

**Milestone:** M4 · **Spec:** documentation/mvp-spec.md §9, §10 · **Status:** todo

## Goal
On member sites, every editing entry point lands in the Site Editor, and a member cannot install/upload plugins or themes or switch themes — while keeping full control of everything else.

## Deliverables
- **Editor redirects** (MU plugin, member sites only — never the main site; spec §9):
  - `post.php?post=N&action=edit` (page **and** post) → `site-editor.php?postType=<type>&postId=N`.
  - `post-new.php?post_type=page|post` → `site-editor.php?postType=<type>`.
- **Capability gating** (capability filter scoped to the member's own sites; spec §10): remove `install_plugins`, `activate_plugins` (replaced by the market page), `switch_themes`, `install_themes`, `delete_themes`, `edit_themes`. `DISALLOW_FILE_EDIT` already network-wide.
  - Add `mody_manage_market` capability for the market page (M4-02).
- Keep intact: content, media, site users, settings, Spectra, Global Styles.

## Acceptance criteria (spec §10)
- A member-site admin cannot install, upload or switch themes/plugins by any UI or REST path.
- A member cannot reach a classic block editor for any content type on their site; every editing entry point lands in the Site Editor.
- Front end and Site Editor are unaffected; media upload still works.
