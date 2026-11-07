# M4-01 · Editor-first redirects + capability gating

**Milestone:** M4 · **Spec:** documentation/mvp-spec.md §9, §10 · **Status:** done (2026-11-07)

## Goal
On member sites, every editing entry point lands in the Site Editor, and a member cannot install/upload plugins or themes or switch themes — while keeping full control of everything else.

## Decisions (maintainer, 2026-11-07)
- **Split:** this ticket covers the redirects and the capability gating. The `ai` plugin lock-down moved to [M4-03](M4-03-ai-plugin-lockdown.md).
- **Member sites:** every site of the network but the main one. The rules apply to everyone on them **except super admins**, who keep full rights to fix things.
- **Posts keep the block editor.** WordPress 7.1's Site Editor has routes for pages (`/page/:id`), patterns (`/wp_block/:id`) and navigation menus (`/wp_navigation/:id`), but none for posts. So posts open in `post.php`, the block editor, until the Site Editor supports them.
- **The agent menu:** instead of limiting the `Mody Agent` page, the maintainer wants the agent as a **chat bubble in the editor**. It should know the page, template or part being edited, and be able to change it. This is a new feature, to be scoped in its own tickets; the page stays as it is until then.

## Deliverables
- [x] **`Gating\MemberSite`** (`src/Gating/MemberSite.php`), hooked in `mody-platform.php`.
  - **Capabilities** (`map_meta_cap`): on member sites, users who are not super admins never get the plugin and theme capabilities. Install, upload, activate and deactivate (`activate_plugin` and `deactivate_plugin` included), resume, update, delete, edit; switch, install, upload, update, delete and edit themes. That covers every screen and REST route that checks them. `DISALLOW_FILE_EDIT` was already on network-wide.
  - **Kept:** content, media, users of their own site, settings, Spectra, Global Styles (`edit_theme_options`).
  - **`mody_manage_market`**, for the market page (M4-02): a member site's administrators (`manage_options`); nobody on the main site.
  - **Redirects** (`load-post.php`, `load-post-new.php`, GET only, block theme only):
    - `post.php?post=N&action=edit` for a page, pattern or navigation menu → the Site Editor with its canvas (`?p=/page/N&canvas=edit`, `/wp_block/N`, `/wp_navigation/N`);
    - `post-new.php?post_type=page` → the Site Editor's pages (`?p=/page`, which has **Add page**). Patterns go to `/pattern`, menus to `/navigation`;
    - posts, other post types and other actions (trash, save) stay.
- [x] `scripts/gating-test.php`.
- [x] Docs: mvp-spec §9, §10, §13; local-development.md (reaching a subsite from inside the container); AGENTS.md; roadmap.md.

## Acceptance criteria (spec §9, §10)
- [x] A member-site admin cannot install, upload or switch themes or plugins by any UI or REST path. The capabilities are refused, and the test checks the REST plugins routes (list, install, activate: 403), the plugins screen (403) and a theme switch (no effect).
- [x] A member cannot reach the block editor for pages, patterns or menus; those entry points land in the Site Editor. **Posts** are the decided exception.
- [x] Front end and Site Editor are unaffected; media upload still works (`upload_files` kept; every other suite passes).

## Results (2026-11-07, local)
- `scripts/gating-test.php`, with a test user as administrator of `alpha` (a member site) and of the main site:
  - **On `alpha`:** the 17 plugin and theme capabilities are refused, 10 others kept, the market capability granted.
  - **Elsewhere:** on the main site nothing changes; super admins keep everything.
  - **REST:** the plugins routes refuse with 403.
  - **Redirect targets:** 8 cases.
  - **Over HTTP on `alpha`:** editing a page gets 302 to the Site Editor, as does adding a page. A post opens the post editor (200). The plugins screen refuses (403). A theme switch leaves the theme unchanged. A super admin is not redirected.
- The other suites pass: agent (offline), Spectra, wizard, provisioning, `ai` plugin brand context, page generation.
- **Testing subsites from the container:** a request to the main host with the subsite's `Host` header reaches the subsite (local-development.md).
