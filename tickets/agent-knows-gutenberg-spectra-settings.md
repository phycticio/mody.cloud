# Mody knows Gutenberg and Spectra, and changes the site's settings

**Milestone:** M3 (the agent) · **Status:** planned 2026-11-11; parts 1 to 9 built (not deployed); the maintainer's browser check pending · **Spec:** documentation/ai-engine.md ("The agent", "Prompt size", "Spectra content blocks", "Validation pipeline"), [M3-11](M3-11-spectra-mu-plugin-all-blocks.md) (this ticket takes over its parts 4 to 7)

## Asked for
By the maintainer on 2026-11-11:

> Then we need the agent knows more about gutenberg and spectra than it does know. Add all the knowledge about both to the agent so it can change anything in the site, including settings.

## Decisions (the maintainer, 2026-11-11)
1. **Settings, tiered.**
   - **Directly:** formats, timezone, week start, posts per page, feeds, default category and post format, comments on or off and their display, moderation, image sizes, the login color scheme.
   - **After the member confirms** (Mody asks first): search engine visibility, the home page and the posts page, permalinks (every address changes, and nothing redirects), the site's languages, comment blocklists and "only registered users may comment".
   - **Always refused:** the admin email, users and roles, deleting anything, plugins and themes, API keys (`connectors_*`), the AI plugin's settings (`wpai_*`), the mail server's credentials, avatars (see [network-profile-local-avatars.md](network-profile-local-avatars.md)).
2. **Spectra for buttons, lists and separators.** Mody writes `spectra/buttons`, `spectra/list` and `spectra/separator` instead of the core ones: Spectra's blocks are rendered on the server, so the editor never marks them invalid. Core paragraph and heading stay.
3. **In scope now:** the settings tool (with menus), every Spectra family, more core blocks. **Not now:** editing a page, post or template that is not open in the editor.

## What the research found (2026-11-11)
- **The agent never writes markup.** Two writer calls do: `mody/generate-page` (`SystemPrompt::for_page`, 12,184 characters) and `edit_content` (`for_edit`, about 7,500). Block knowledge belongs in the writers' prompts. The agent's prompt only needs to know what exists.
- **The agent's tool declarations are at 3,859 of the 4,000 bytes** that `scripts/ai-agent-chat-test.php` allows (ai-engine.md, "Prompt size"). A new tool must be one short sentence, with its details in the system prompt, as `mody/update-styles` does.
- **The writers are taught 11 blocks**; the validator accepts 22 core and 46 Spectra blocks. The validator checks nesting, colors, links and media, but never a block's attribute names or types: an invented attribute passes.
- **Spectra's own `create-*` generators are wrong for five families** (list, counter, icons, post, separator). The examples must come from the editor's own templates and be checked in the editor.
- **Settings:** about 60 options on the Settings screens; only 16 are registered for REST, and the registered list also holds API keys. So the allowlist is our own, typed, not derived from the registry.

## Parts (each at most about 2 hours)
1. **`mody/update-settings`.** One ability, input `changes` (setting → value), over a typed allowlist in the three tiers above. Values are checked and saved with `sanitize_option()`, then read back. Pages and categories are given by title. A call with a refused setting changes nothing. A "confirm" setting is held until the member confirms (the held-change flow of `mody/update-styles`). `blog_public` also updates the site's row (`wp_blogs.public`). `mody/update-site-settings` is folded into it. Offline tests.
2. **Mody uses it.** In `Agent::ABILITIES`, a prompt rule, and the settings with their current values in the system prompt (like `styles_context()`). The tools stay under the byte limit. Real runs: hide from search engines, comments off, posts per page, home page by title, a refused setting, a vague wish.
3. **Menus: `mody/update-menu`.** Add, remove, rename and reorder links in the site's navigation by page title, built in PHP (no model). Removing items is confirmed. Offline tests and real runs.
4. **The writers' reference, picked per request.** A card per family (when to use it, a checked example), a one-line index of everything, and the cards chosen for the request: for an edit, the families in the markup; for a new page, the families the request names; on a rejected answer, the cards of the blocks it used. The validator learns each block's registered attributes (names, types, enums) and refuses invented ones, saying which exist.
5. **Spectra, batch 1:** buttons, icons (a curated list of icon names, checked against Spectra's library), list, separator, content. Core buttons, list and separator leave the writers' prompts.
6. **Spectra, batch 2:** counter and countdown (real numbers and dates come from the member: Mody asks, never invents), slider, modal.
7. **Spectra, batch 3:** post lists (`spectra/post`); the validator allows the post blocks inside its template.
8. **Core blocks, batch 1:** table, pullquote, code, preformatted, verse, latest posts, categories, archives, a pattern from the Mody collection. The validator learns their tags.
9. **Core blocks, batch 2:** images from the site's media library: image, gallery, media and text, video, audio, file. The writers get the library's list; the "no images" rule changes.

Each part: offline checks, real runs paced for Infomaniak's rate limit, and a headless-browser check that the editor shows no invalid block.

## Open questions
- Qwen's context window on Infomaniak is not recorded. Read it once from the models endpoint before part 4.

## Results

### Parts 1 and 2 (built 2026-11-11, not deployed): `mody/update-settings`
- **`Site\Settings`** and the ability `mody/update-settings` (`src/Abilities.php`): 46 settings under friendly names, typed (`bool`, `int`, `enum`, `text`, `timezone`, `category`, `homepage`, `page`, `lines`, `scheme`, `locales`), in the decided tiers. A page is given by its title (`SiteEditor::match_page()`, published pages only). `search_engines_visible` also updates the site's row (core's `update_blog_public`). Permalinks go through `$wp_rewrite->set_permalink_structure()`, as Settings → Permalinks does: updating the option alone left the old structure in memory, and the flush rebuilt the old rules (found by the test). `filter_var()` reads `''` as false, so empty and non-scalar values are refused for true/false settings (found by the test).
- **The agent** (`Agent::ABILITIES`, `SystemPrompt::for_agent()` rule 10 and `settings_context()`): the new tool replaces `mody/update-site-settings` (which stays registered for REST and MCP). A call with a `confirm` setting is held: the chat shows **Apply** and **Cancel** (`Agent::choices()`), on the wp-admin chat page too now (`ChatPage::settings()` sends the labels), and `decide()` accepts only the held kind's choices.
- **Tests:** `scripts/update-settings-test.php` (the list and tiers, every kind of value, all or nothing, the special settings, permissions, the declaration's size; then ten real requests, `MODY_TEST_RUNS=3`: 29 of 30, the 30th was the service's "not available right now" after about 60 requests). `ai-agent-chat-test.php` gained the held confirmation (held, Apply runs it once, Cancel, a direct setting at once) and follows the new tool.
- **Found, not fixed (not caused by this work):** `editor-navigation-test.php` (the site's lists) and `agent-template-test.php` (the header's blocks) fail the same way without these changes; Inference: the local content changed on 2026-11-10.

### Part 3 (built 2026-11-11, not deployed): `mody/update-menu`
- **`Site\Menu`** and the ability: the header's menu as a whole list of items (pages by title, links, one level of submenus, "all pages"), built in PHP into `navigation-link` and `navigation-submenu` blocks. Without a `ref` in the header, the menu is WordPress's fallback, as on the front end. Removing an item is held for **Apply** (`Menu::to_confirm()`).
- **The agent:** the ninth tool; the current items in its system prompt; the editor's bubble reloads the menu after a change (`navigation` in its records, the menu's ID from `EditorBubble`). To keep the tools under 4,000 bytes, `mody/generate-page`'s declaration lost two sentences the prompt already says: 3,911 bytes for nine tools.
- **Test:** `scripts/update-menu-test.php` (reading and writing, valid blocks that the header renders, six refusals, when a change waits; then five real requests, three times each: 15 of 15).

### Part 4 (built 2026-11-11, not deployed): the writers' reference, picked per request
- **`AI\BlockReference`** and the cards in `mody-platform/reference/<family>.json`. Every writer prompt lists every family (the index); it carries the cards of the families the request names (keywords in the eight site languages) or whose blocks are in the markup being edited. A rejected answer's retry adds the cards of the families it used without one. Accordion and tabs moved into cards first.
- **`BlockValidator::check_schema()`:** new or changed blocks may only use the attributes their block registers, of the right type and value; the error names the real ones. Older forms the editor migrates are refused in new content; a member's unchanged blocks pass. Probed first on the 63 patterns, the theme and the examples: only deprecated core attributes and Spectra's `spectraId` showed up.
- **`scripts/editor-check.mjs`:** opens markup in the real block editor (headless Chromium) and reports invalid or unknown blocks and whether the editor would change it. Every card's example was checked with it.
- **Media (part 9's groundwork):** `SystemPrompt::media_context()` lists the site's newest 24 images, videos, audio files and PDFs for the writers, only when a media card is picked or the edited markup has media; "no images" became "only from the library".

### Parts 5 to 9 (built 2026-11-11, not deployed): 27 families
- **Cards** written and verified by fourteen agents in two workflows (validator, render, real editor, `block-reference-test.php`): Spectra **buttons, separator, content, icons, list, counter, countdown, slider, modal, post**; core **table, pullquote, code, preformatted, verse, latest posts, categories, archives**; media **image, cover, gallery, media and text, video, audio, file**. With accordion and tabs: 27.
- **Spectra for buttons, lists and separators** (decision 2): the writers' rules and the page example (`SystemPrompt::EXAMPLE`, checked in the editor: 35 blocks, none invalid) use them; new pages refuse the core ones (`BlockValidator::REPLACED_BY_SPECTRA`). Blocks already on a page stay.
- **What the editor sets on load, set by `SpectraBlocks::normalize()`** (found by the card authors in the editor): an icon's color as the palette color's CSS variable (a bare slug printed invalid CSS, so the icon took the text's color); list items numbered by position (an ordered list showed "1." on every item); a modal's unique `modalId` and `isVisible`; a countdown's end in UTC and its `displayEndDateTime` in the site's timezone; `variationSelected` on post lists; `isRootBlock: false` on nested content blocks. The validator accepts the palette color variable in `*Color` attributes.
- **Validator:** icon names checked against Spectra's library and aliases (an invented name rendered an empty icon); values block.json does not list (separator style and alignment, counter style, list type and style, modal trigger); a countdown needs a parseable end and one unit shown; the post item blocks (`core/post-title` and four others) only inside `spectra/post-template`; tables, `<pre>`, video and audio tags; a file block's link on this network.
- **Not done, found by the authors:** colors on core tables (the editor wants a class the validator cannot check: the card says no colors); other Spectra color attributes that may print slugs as CSS like the icon did (accordion and tab icons, button icon colors: unverified); the slider's free build shows one slide at a time.
- **Tests:** `scripts/block-reference-test.php` (27 cards, the picking, the attribute check, the retry; then eight real requests, one per new family group); `spectra-layout-test.php` and `provisioning-test.php` follow the new example (a container inside a list item is not layout; a block whose only attribute is its ID).

### Real runs (2026-11-11)
- Eight requests, one per new family group (a table of hours, a countdown, a services list with icons, testimonials in a slider, counters, a grid of posts, terms in a popup, a gallery): 8 of 8 used the family and passed the validator.
- `spectra-content-test`, `spectra-layout-test`, `ai-generate-page-test`, `agent-edit-test` (real): pass. `ai-agent-chat-test` (real, 173 checks) passed after the settings tool; `update-settings-test` 29 of 30 (the 30th the service's rate limit); `update-menu-test` 15 of 15.
- **Found and fixed:** two prompt rules numbered 10 (the settings rule, and the editor context's "edit what is open"): the selected paragraph was edited 6 times in 8. Renumbered, 10 in 10.

### Gradients (built 2026-11-11, not deployed)
Asked for by the maintainer the same day: "Give the agent the ability to create gradients." Mody creates gradient presets from the palette (`mody/update-styles`, `settings.color.gradients`) and uses them as styles and on pages (a section's or a Spectra button's background, by slug). Details: ai-engine.md, "Gradients". Test: `scripts/gradients-test.php`.
