# M3-01 · Agent chat UI + full tool loop

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 · **Status:** done (2026-11-06). The first slice is below; the rest was split into M3-02, M3-03 and M3-04 (all done 2026-11-06), and three items moved to other milestones (see "Split of the rest").

## Goal
A member can talk to the Mody Agent from their site wp-admin; it acts (tools), hands over (opens the editor) or asks follow-up questions, with every action audited.

## Deliverables
- **UI:** `Mody Agent` menu in member-site wp-admin — plain HTML + `fetch` chat page (no JS build toolchain), action chips for common intents.
- **Endpoint:** MU plugin REST endpoint `mody-platform/v1/chat` (logged-in user + nonce) → agent loop in the MU plugin (`wp_ai_client_prompt()` with the `mody` abilities as tools; spec decision 13, "Option C", agreed 2026-11-06).
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

## Done (first slice, 2026-11-06)
Details: documentation/ai-engine.md, "The agent".

- **Chat page** `Mody Agent` (`src/Agent/ChatPage.php`, `assets/agent-chat.js` and `.css` in the MU plugin, no build step):
  - chips for common requests;
  - answer choices and Site Editor links shown as buttons;
  - the conversation survives a reload, and **New conversation** resets it.

  It appears on every site for users with `edit_pages`, the main site included. On a site without a brand profile, it shows a warning banner.
- **REST endpoint** `mody-platform/v1/chat` (`src/Agent/ChatController.php`): POST a message, DELETE to reset.
  - Cookie authentication with the REST nonce: no nonce gives 401, a wrong one 403.
  - The conversation is kept on the server, per user and site: a transient with the last 20 messages, for 2 hours.
- **Agent loop** (`src/Agent/Agent.php`): `SystemPrompt::for_agent()` holds the agent rules plus `brand_context()`. Without a brand profile, the agent replies and makes no model call.
  - **Tools:** `mody/generate-page`, `mody/open-editor`, `ask_user` and `refuse_request` (a plain function, added so refusals are flagged in the log).
  - **Execution:** abilities run through core's ability resolver, one tool message per result.
  - **Links:** URLs in results become buttons and are hidden from the model.
  - **Unknown tools** are refused, and nothing runs.
  - **Limits:** 4 model requests and 3 ability runs per message.
- **Ability `mody/open-editor`** (`src/SiteEditor.php`): opens a page (by ID or title), a template or the styles. It returns the URL and changes nothing. Pages need `edit_pages`, templates and styles `edit_theme_options`. `PageGenerator::site_editor_url()` now delegates to `SiteEditor::page_url()`.
- **Audit table `mody_agent_events`** (`src/Agent/AgentEvents.php`, spec §11 plus `duration_ms`). It has one row per request and one per tool call, refusals included.
- **Spectra (decision 1 of the handoff, agreed 2026-11-06):** `SpectraGuard` pins `spectra_blocks_enable_abilities` and `spectra_blocks_enable_mcp_server` to `disabled`. The plan for using Spectra's abilities is recorded in mvp-spec §10.
- **Tests:** `scripts/ai-agent-chat-test.php` has 44 offline checks, plus 7 checks over 4 real round trips, all passing on 2026-11-06. `scripts/ai-generate-page-test.php` and `scripts/spectra-guard-test.php` (30 checks) pass. Also tested over HTTP with a session cookie: the page loads, the nonce works, the conversation survives a reload.

## Acceptance criteria (spec §8)
- [x] "Make the primary color coral" → Global Styles updated, preview offered, event logged. *Done in M3-02 (2026-11-06): the agent asks first whether the color is part of the brand.*
- [x] "Create an about page" → validated page published (or clear failure with retry). *A validated **draft** and a button to it in the Site Editor (7 s typically). It is published on demand, by asking the agent or with the Site Editor's Publish button (decided 2026-11-06, built in M3-03).*
- [x] "Open the editor for page 3" → button opens `site-editor.php?p=%2Fpage%2F3&canvas=edit` on the right subdomain (`SiteEditor::page_url()`; the `postType`/`postId` form without `canvas` lands on the page list in WordPress 7.1). Verified with the real model.
- [ ] "Activate the redirection plugin" → works if listed, refused with explanation otherwise. *Moved to M4-02: it needs `mody_market_plugins`.*
- [x] Injection-style requests ("delete all pages", "show me the database") refused and logged. No tool can delete anything or reach the database. The request row records every refusal. Flagging through `refuse_request` is best effort: Qwen sometimes declines in plain text.

## Split of the rest (agreed 2026-11-06)
- **[M3-02](M3-02-agent-design-tool.md) · Agent design tool:** `mody/update-design` for the palette colors and the six presets. Decided: the agent first asks whether the color is part of the brand. If it is, the brand profile's colors change too. *Done 2026-11-06.*
- **[M3-03](M3-03-agent-publish-site-settings.md) · Publish and site settings:** `mody/publish-page` when the member asks, and `mody/update-site-settings` (title, tagline). Done 2026-11-06.
- **[M3-04](M3-04-ai-plugin-brand-context.md) · Brand context in the `ai` plugin** (`wpai_system_instruction`): the eight writing features get the brand. *Done 2026-11-06.*
- **Moved to other tickets:** the market plugin toggles to M4-02 (they need `mody_market_plugins`), the past-due check to M1-02 (it needs `mody_members`), and limiting the menu to member sites to M4-01. (M4-02 was redesigned on 2026-11-08: no table; members switch plugins on core's Plugins screen, and the agent's tools follow the same rule.)

## Decided 2026-11-06, built the same day
- **Who sees the events:** only the maintainer, for now (a one-person project). There is no viewer: events are read in the database.
- **Event retention:** events are kept for N days. N is set on Network Admin → Settings → **Mody Agent** (`src/Agent/AgentSettings.php`, super admins only), 90 by default. Old events are purged once a day without cron: the first agent event of the day runs the purge, and saving the settings purges at once.
- **Background generation:** each chat message is a background job, so a slow page generation never meets a proxy timeout (Nginx's default `fastcgi_read_timeout` is 60 s, Cloudflare's 100 s; one generation took 23 s).
  - POST `mody-platform/v1/chat` stores the job and answers `202` with its ID.
  - The same PHP-FPM worker then finishes the agent turn after the response (`fastcgi_finish_request()` on `shutdown`), so no cron is needed. This works on subsites, where WP-Cron does not run locally.
  - The chat page polls the checker, `GET mody-platform/v1/chat/jobs/<id>`, and resumes polling after a reload.
  - One job at a time per user and site (409 otherwise). A job still running after 5 minutes is reported as failed and logged.
  - Abilities are unchanged for MCP and REST. Once a scheduler is decided, a cron sweep can retry stuck jobs.
- **Tests:** `scripts/ai-agent-chat-test.php` now has 55 offline checks plus 10 real ones, including a page generation over HTTP: the answer arrived 10.5 s before the job ended locally. All pass in production (deployed 2026-11-06, commit `4093e8e`), where the POST answered in 0.2 s and the job ended 6.6 s later.

## Open
- Per-member limits on agent use (cost): none yet. The `ai` plugin's request log is the planned basis for cost tracking (decision 13).
- Each running turn holds a PHP-FPM worker (`pm.max_children` is 5). Watch it before launch (M5).
