# M3-01 · Agent chat UI + full tool loop

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 · **Status:** todo

## Goal
A member can talk to the Mody Agent from their site wp-admin; it acts (tools), hands over (opens the editor) or asks follow-up questions, with every action audited.

## Deliverables
- **UI:** `Mody Agent` menu in member-site wp-admin — plain HTML + `fetch` chat page (no JS build toolchain), action chips for common intents.
- **Endpoint:** MU plugin REST endpoint `mody-platform/v1/chat` (logged-in user + nonce) → agent loop in the MU plugin (`wp_ai_client_prompt()` with the `mody` abilities through `using_abilities()`; spec decision 13, "Option C", agreed 2026-11-06).
- **Tools** (all server-side, all guarded; spec §8 table) are abilities in the `mody` category, so MCP clients get the same guarded actions: `mody/generate-page` (**exists**, M2-02: saves a draft and returns its Site Editor URL), `update_design`, `open_editor`, `activate_plugin`/`deactivate_plugin`, `update_site_settings`. `ask_user` stays a plain function declaration.
  - The agent's system prompt reuses `SystemPrompt::brand_context()` (rule 4).
  - "Create an about page" publishes only after the member confirms the draft (the ability saves drafts only).
  - `open_editor` returns the exact Site Editor URL for the right subdomain.
  - `update_design` patches Global Styles, palette slugs only.
  - Plugin toggles restricted to `mody_market_plugins` (M4-02).
  - `update_site_settings` fixed option allowlist (title, tagline).
  - No delete tools; unknown actions refused and explained.
- **Safety/audit:** brand profile always in the system prompt; every tool call logged in `mody_agent_events`; past-due accounts get no tool execution; tools run in-process with capability checks.
- **`ai` plugin features** (decision 13): append `SystemPrompt::brand_context()` to the plugin's system instructions through its `wpai_system_instruction` filter, on sites with a brand profile.

## Acceptance criteria (spec §8)
- "Make the primary color coral" → Global Styles updated, preview offered, event logged.
- "Create an about page" → validated page published (or clear failure with retry).
- "Open the editor for page 3" → button opens `site-editor.php?p=%2Fpage%2F3&canvas=edit` on the right subdomain (`PageGenerator::site_editor_url()`; the `postType`/`postId` form without `canvas` lands on the page list in WordPress 7.1).
- "Activate the redirection plugin" → works if listed, refused with explanation otherwise.
- Injection-style requests ("delete all pages", "show me the database") refused and logged.
