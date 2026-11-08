# M3-10 · The agent edits every part of the site's styles (its theme.json, stored per site)

**Milestone:** M3 · **Spec:** documentation/ai-engine.md ("Changing the colors", "Opening Site Editor screens", "Prompt size"), documentation/theme-development.md (design tokens) · **Status:** planned and decided 2026-11-08; **parts A and B built 2026-11-08** (Results below), part C to do; builds on [M2-08](M2-08-brand-profile-wordpress-settings.md) (the brand is the site's settings and styles).

## The goal (maintainer, 2026-11-08)
> The goal of the agent in the sites is that the users are able to edit every part of their theme.json database stored per site, and all changes are managed there.

Each site's design is its **Global Styles**: the user `wp_global_styles` post of that site, the same theme.json data that Editor → Styles reads and writes. The agent changes that post, and nothing else: no theme files, no copies, no Mody tables. Every change is a Global Styles revision the member can see and restore in Styles → Revisions.

## Where it stands
- **`mody/update-design`** (`Design\SiteDesign`, M3-02, M2-06) changes only the palette's `primary`, `secondary` and `accent`, or applies a whole preset (a style variation). It already saves the way Styles does: a revision, kses for members, read back.
- **M3-09** opens any Styles screen in place: colors, palette, typography, layout, blocks, one block type, revisions.
- **Everything else in the styles** cannot be changed by the agent today: font sizes, heading and link styles, button shapes, spacing, widths, element and block styles.

## What the member can ask (examples to measure)
- **Typography:** "Make the headings bigger"; "Use Lora for headings" (a family installed in the Font Library, M2-07).
- **Colors on elements:** "Links in the accent color".
- **Blocks:** "Round the buttons" (`styles.blocks.core/button.border.radius` or `styles.elements.button`).
- **Spacing and width:** "More space between sections"; "A wider content area" (`settings.layout.contentSize`, `wideSize`).
- **The palette:** "Make the background a warm white" (the `base` color).
- **Undo:** "Undo the last style change" (the revisions).

## Plan (rule 5: three parts of 1 to 2 hours)

### Part A · `mody/update-styles`: a guarded patch of the site's Global Styles
- **Input:** `changes`, a partial theme.json object (`styles` and/or `settings`), merged deeply into the site's current user Global Styles.
- **The guard,** before saving:
  - Only `styles` (`color`, `typography`, `spacing`, `border`, `shadow`, `dimensions`, `elements`, `blocks`) and `settings` (`color.palette`, `typography.fontSizes`, `typography.fontFamilies`, `layout`, `spacing.spacingSizes`) may change.
  - **The theme palette keeps its five slugs** (`primary`, `secondary`, `accent`, `base`, `contrast`), because templates, patterns and generated pages use them: only their colors change. **Members' own colors** go in `settings.color.palette.custom` (decision 3): added, renamed or removed freely, each with a slug and a hex color.
  - **Styles name colors by palette slug** (`var:preset|color|accent`, or a custom color's slug); hex is accepted only inside the palette.
  - **Font families** only from the site's Font Library (installed `wp_font_family`), never a URL.
  - **No `styles.css`:** members have no `unfiltered_html` or `edit_css` on Multisite.
  - Unknown keys go through `WP_Theme_JSON` sanitization (as core's REST controller), and the result must still parse. There is a size limit.
- **Saving:** as `SiteDesign` does today, a revision, then read back.
- **Result:** the paths that changed, old and new values (logged in `mody_agent_events`), and the matching Styles screen opened in place (M3-09): Typography for fonts, Blocks → Button for buttons, and so on.
- **`mody/update-design` stays** for presets and the three brand colors, which are well measured. A later step may fold it in.
- **Tests (offline):** merge, each guard, the palette's slugs kept, the revision written, the read-back, the screen chosen.

### Part B · The prompt, and measuring it
- **The tool declaration:** one sentence and a schema that is just `changes` (an object). The tools JSON is 3,648 of the 4,000 bytes the test allows, so measure (ai-engine.md, "Prompt size").
- **The system prompt:** a compact map of the theme.json paths members ask about, with the palette slugs, the site's installed font slugs and its font size and spacing slugs, plus two or three examples. When the request is vague ("make it look more modern"), ask, or open Styles (M3-09).
- **What is set now:** a short summary of the site's current styles (the values the member is likely to change), so the agent changes from what is there.
- **Real runs, paced and repeated,** for the example phrases above, checked on the saved Global Styles.

### Part C · The editor, undo, and docs
- **Undo** (decision 2): `mody/undo-styles` restores the previous Global Styles revision as a new revision, then opens Styles → Revisions. "Undo the last style change" calls it.
- **In the Site Editor:** after any change, the matching Styles screen opens in place and shows it.
- **Docs:** ai-engine.md ("Changing the styles"), mvp-spec §8 (the tool row), theme-development.md (what members may change through the agent), AGENTS.md (the row).

## Decisions (maintainer, 2026-11-08)
1. **Two tools:** `mody/update-design` stays for presets and the three brand colors, and `mody/update-styles` does the rest.
2. **Undo is a tool:** an ability (`mody/undo-styles`) restores the site's previous Global Styles revision. The restore is itself a new revision, so it can be undone too. The Styles → Revisions screen opens to show it.
3. **The palette's five slugs stay fixed, and members may add their own colors.** `primary`, `secondary`, `accent`, `base` and `contrast` are never added or removed, only recolored. Members' own colors go in the palette's custom origin (`settings.color.palette.custom`, where Styles → Palette puts them), may be added, renamed and removed, and may be used in styles by their slug.

## Results

### Part A (built 2026-11-08)
- **`Design\SiteStyles`** and the ability **`mody/update-styles`** (`changes`, an object). It is registered but **not offered to the agent yet**: part B adds it to `Agent::ABILITIES` and measures the tools JSON.
- **The merge:** a JSON merge patch on the site's user Global Styles: objects key by key, `null` removes a key, so the theme's value shows again. Preset lists merge by slug and come as a plain list (theme.json's form) or keyed by origin. An entry with a theme slug changes that preset's value. Any other slug is one of the member's own: it is added, renamed (`name`) or removed (`"remove": true`).
- **The guard,** with nothing saved when any part is refused, and every reason in one error:
  - Only the style groups `color`, `typography`, `spacing`, `border`, `shadow` and `dimensions` may change: in `styles`, in its elements (with the link and button states such as `:hover`) and in registered blocks (with core's block states). Their keys must be WordPress's own (`WP_Theme_JSON::VALID_STYLES`). `css`, `background`, `filter`, `outline` and block style variations are refused anywhere.
  - Only the palette, the font sizes, the font families, the spacing sizes and `layout.contentSize`/`wideSize` may change in `settings`.
  - The theme's slugs (five colors, five font sizes, seven spacing sizes) stay: a theme entry must carry a value, and cannot be removed. The theme's font cannot change.
  - Colors in styles (`color.text`, `color.background`, border colors) must name a palette preset, or be `transparent`, `inherit` or `currentcolor`. A hex code or color function anywhere in a style is refused, including shadows and text shadows. A shadow names a preset (core's default shadows) or is `none`. Hex is accepted only in palette entries.
  - Every preset a changed value names must exist after the change. A member's preset still in use cannot be removed. Remove the style in the same change.
  - Font families come only from the site's Font Library: a family installed but not active is activated, with its settings and faces read from `wp_font_family` and `wp_font_face`, never from the input. A family that is not installed is refused, with the installed ones listed.
  - Values may not contain URLs, `;`, braces, quotes, backslashes or tags. Preset names must survive kses (no `&`, quotes or tags). `changes` is at most 8,000 bytes. Whatever WordPress's own `WP_Theme_JSON` sanitizing would drop is refused.
- **Saved** through `SiteDesign::save()`, the save `mody/update-design` uses, now shared: a Global Styles revision, kses for members. Then the saved data is read back, and every change must be there.
- **The result:** `changed` (path, old, new; the theme's value when the site had none of its own), and the Styles screen that shows it, opened in place (M3-09). Palette → Colors → Palette; other colors → Colors; fonts and sizes → Typography; spacing, dimensions and widths → Layout; one block (or the button element) → Blocks → that block. Several sections → the Styles overview. A change that changes nothing is `mody_no_change`, with no revision.
- **Two findings:**
  - **kses rewrites preset references.** WordPress 7.1 saves a member's `var:preset|color|accent` as `var(--wp--preset--color--accent)`; a super admin's stays as given. The ability stores the CSS form for everyone, so its read-back and no-change check are exact.
  - **Spectra activates installed fonts.** Its StyleGuide bridge (`Global_Styles_Bridge::sync_font_library_families`, on `save_post_wp_font_family` and `save_post_wp_font_face`) adds every installed family to the site's Global Styles. So installing a font in the Font Library already activates it. The ability's own activation matters only for a family the member deactivated. Inference: when Mody's blocks replace Spectra ([mody-blocks-from-spectra.md](mody-blocks-from-spectra.md)), installing will stop activating, and the ability will still activate what it uses.
- **Tests:** `scripts/site-styles-test.php` (new, offline, 68 checks). It runs as a member on a member site, so kses runs, and covers:
  - the ticket's examples, each a revision with its screen;
  - the member's own colors;
  - a font from the Font Library;
  - 24 refusals that leave the styles and revisions unchanged;
  - removing with `null`, and the no-change case.
  All 23 suites pass offline.
- **Fixed on the way:** `scripts/ai-plugin-brand-context-test.php` still read `$profile->brand_name` from the profile removed in M2-08. Two of its checks compared with an empty string, so they always passed. They now use the site's title.

### Part B (built 2026-11-08)
- **The agent offers `mody/update-styles`** (`Agent::ABILITIES`, placeholder `%8$s`).
  - Its declaration is now one shorter sentence and one property: 286 bytes.
  - The tools JSON went from 3,572 to 3,859 bytes, under the test's 4,000.
- **The prompt** (`SystemPrompt::styles_context()`, from `SiteStyles::overview()`, about 2,600 characters):
  - **The site's styles now, by path:** the theme's raw styles with the owner's changes merged over them, grouped by node (`styles`, `styles.elements.button`…). The values are shown the way the agent writes them (`var:preset|color|accent`). This list is also the map of what can change, so no separate map is needed. The widths come from `settings.layout`.
  - **The presets:** the palette and the owner's own colors, font sizes, spacing sizes, and the fonts it can use. Those are the theme's, the active ones and the installed ones; any other font must be installed first.
  - **How to write a change:** merged, `null` removes the owner's change, colors as palette slugs, sizes as presets or CSS sizes, `clamp()` above the presets, the palette's five slugs only recolored, the owner's own colors added and removed. Two examples.
- **Rule 7:** when the owner says how a style should change, the agent calls the tool without asking whether it is part of the brand. Presets and the three brand colors go to `mody/update-design`, any other style to `mody/update-styles`. It changes only the styles asked for. A vague style wish gets `ask_user` with up to four concrete changes.
- **Real runs** (section 7 of `scripts/site-styles-test.php`; `-e MODY_TEST_RUNS=3` repeats them):
  - **The cases:** the ticket's phrases, plus a font that is not installed ("Use Fraunces for the headings"), a vague wish ("Make my site look more modern") and a brand color ("Make the primary color coral", still `mody/update-design`).
  - **How they run:** on a member site, as a member, each from the site's own styles, checked on what was saved.
  - **The first prompt:** 40 of 40 in four runs.
  - **The two changes below:** 20 of 20 after each.
  - **What the agent did:**
    - **Headings:** h1 to h3 bigger, as `clamp()` since the hint; fixed rem sizes before it.
    - **Lora:** the heading element, with Lora activated from the Font Library.
    - **Fraunces (not installed):** the tool refused it, the agent opened Styles → Typography and said to install it there.
    - **Links:** `elements.link.color.text` in accent.
    - **Buttons:** `elements.button.border.radius` 999px.
    - **Space:** `spacing.blockGap` raised to the 60 preset.
    - **Width:** content 840px, and also wide 1320px, every time. Not asked for, but it keeps wide content wider than normal content. Accepted.
    - **Warm white:** `base` recolored to `#fdf8f0`.
    - **More modern:** a question with choices, nothing saved.
- **Two prompt changes after measuring:**
  - **`clamp()` for font sizes above the presets.** "Make the headings bigger" had set h1 to a fixed 3.5rem (56px on phones too). The theme's presets are fluid.
  - **The tagline in the editor context.** A real run of `scripts/agent-template-test.php` found that "add the tagline under the site title", in a header on a site with no tagline, added an empty tagline block 2 to 3 times in 4 instead of asking.
    - **Why:** the longer prompt diluted a cue the agent had to infer. Without the styles block it asked 3 times in 4; on `main` every time (1 of 3 failed only on the reply's format).
    - **Narrowing rule 7** to styles did not help.
    - **The fix:** the editor context now says to ask what the tagline should say before adding one. After it, the agent asked 7 times in 7.
- **Checked:**
  - all 23 suites offline;
  - for real: `editor-navigation-test` (twice), `ai-agent-chat-test`, `agent-edit-test` and `agent-template-test` (3 of 3 after the fix; 1 run in 7 failed another check of it).

## Acceptance criteria
- Each example phrase changes the site's Global Styles post as asked, as a revision, with the matching Styles screen opened.
- Nothing outside the guard is ever saved: no CSS, no new or removed palette slugs, no fonts that are not installed, no hex in styles.
- The tools JSON stays under the limit, and the real runs pass repeatedly.
