# M3-01 · Agent chat UI + full tool loop

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 · **Status:** todo

## Goal
A member can talk to the Mody Agent from their site wp-admin; it acts (tools), hands over (opens the editor) or asks follow-up questions, with every action audited.

## Deliverables
- **UI:** `Mody Agent` menu in member-site wp-admin — plain HTML + `fetch` chat page (no JS build toolchain), action chips for common intents.
- **Relay:** MU plugin REST endpoint `mody-platform/v1/chat` (logged-in user + nonce) → Python agent service over the internal network.
- **Tools** (all server-side, all guarded; spec §8 table): `generate_page`, `update_design`, `open_editor`, `activate_plugin`/`deactivate_plugin`, `update_site_settings`, `ask_user`.
  - `open_editor` returns the exact Site Editor URL for the right subdomain.
  - `update_design` patches Global Styles, palette slugs only.
  - Plugin toggles restricted to `mody_market_plugins` (M4-02).
  - `update_site_settings` fixed option allowlist (title, tagline).
  - No delete tools; unknown actions refused and explained.
- **Safety/audit:** brand profile always in the system prompt; every tool call logged in `mody_agent_events`; past-due accounts get no tool execution; internal endpoint auth via `MODY_INTERNAL_SECRET`.

## Acceptance criteria (spec §8)
- "Make the primary color coral" → Global Styles updated, preview offered, event logged.
- "Create an about page" → validated page published (or clear failure with retry).
- "Open the editor for page 3" → button opens `site-editor.php?postType=page&postId=3` on the right subdomain.
- "Activate the redirection plugin" → works if listed, refused with explanation otherwise.
- Injection-style requests ("delete all pages", "show me the database") refused and logged.
