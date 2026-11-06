# M3-02 · Agent design tool: palette colors, and the brand question

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 · **Status:** done (2026-11-06, local; not deployed yet)

## Goal
"Make the primary color coral" or "use the Forest preset" changes the site's colors through the agent. Before it changes anything, the agent asks whether the new colors are part of the brand. If they are, the brand profile changes too, so later generations and the wizard use them.

Decided by the maintainer on 2026-11-06 (split from M3-01): the brand question, and support for the **six design presets** of mvp-spec §6.

## Deliverables
- [x] **The six presets, as data in the MU plugin** (`src/Design/Presets.php`): slug, name and the three colors, from the candidate table in mvp-spec §6 (Mody Teal as the default, Forest, Ocean, Plum, Graphite, Sand). The brand wizard (M2-01) will reuse the same list, so it is the single source. The table is marked "to be curated before M2 ships": used as it is, curation left to the maintainer.
- [x] **Ability `mody/update-design`** (category `mody`, public like the others; `src/Design/SiteDesign.php`):
  - Input:
    - either `preset`: one of the six preset slugs (an enum in the schema);
    - or `primary?`, `secondary?`, `accent?`: hex colors, validated server-side with `^#[0-9a-fA-F]{6}$`;
    - `brand`: a **required** boolean, whether the colors are part of the brand.

    A preset, or at least one color, must be given, but not both. Color names ("coral") are converted to hex by the model; the server only accepts hex.
  - Effect: patches the site's Global Styles palette entries `primary`, `secondary` and `accent` (the user `wp_global_styles` post of the active theme), never `base` or `contrast`. Content keeps using palette slugs, so nothing in pages changes.
  - If `brand` is true, it also updates `color_primary`, `color_secondary` and `color_accent` of the site's row in `mody_brand_profiles`.
  - Permission: `edit_theme_options`. No brand profile: refused (rule 4).
  - Returns the old and new values, and whether the brand profile changed.
- [x] **Agent:** the ability is in `Agent::ABILITIES`. `SystemPrompt::for_agent()` (rule 7): before calling it, ask with `ask_user` whether the colors are part of the brand ("Yes, they are part of my brand" / "No, only this site's design"), and pass the answer as `brand`. The preset names are in the ability's description, without hex values. The preview is a button.
- [x] **Audit:** the call is logged in `mody_agent_events` like any tool call, with the old and new colors in its result.
- [x] **Tests** in `scripts/ai-agent-chat-test.php` (section 4, offline, and section 7, real runs). They restore the site's Global Styles, its new revisions and the profile colors at the end.
- [x] **Docs:** ai-engine.md ("The agent", "Changing the colors", "Prompt size"), mvp-spec §8 tools table and §6, brand-profile.md, AGENTS.md.

## Built 2026-11-06
- **Storage, verified first.** WordPress 7.1's Site Editor (`ColorPalettePanel` in `global-styles-ui`) saves an edited theme color as the **whole theme palette** under `settings.color.palette.theme` of the user `wp_global_styles` post. `SiteDesign::apply()` writes the same shape: the site's own palette if it has one, else the theme's, with only the three brand slugs changed. A probe on `beta` confirmed that the merged settings and the `--wp--preset--color--*` CSS variables follow, and that restoring the post content brings back the theme colors.
- Saved like core's REST controller (`wp_update_post()` with slashed JSON), with the after-insert hooks, so **each change is a Global Styles revision** the member can restore in the Site Editor. For members (no `unfiltered_html` on Multisite), kses re-encodes the JSON; the test checks that the colors survive it. After saving, the ability reads the colors back and fails if they do not show.
- **The preview button comes from `mody/update-design` itself** (`url` and `label`, "See the new colors", the Site Editor's styles), like the draft button of `mody/generate-page`. This replaces a second `mody/open-editor` call, which would have cost a model request.
- `BrandProfile` now loads its row `id` and the three colors (invalid values fall back to Mody Teal), and `BrandProfile::update_colors()` writes them. The colors still never reach a prompt: prompts name palette slugs only.
- `brand` is passed through `rest_sanitize_boolean()`, because the schema accepts the string `"false"` as a boolean.
- **The declaration is deliberately short.** With a longer description and one per color, Qwen on Infomaniak often answered with bracketed transcript text instead of a tool call. Details: ai-engine.md, "Prompt size".
- The chat's greeting and chips mention colors ("Change the colors" replaced "Open the styles").

Verified with Qwen (2026-11-06, local): "Make the primary color coral" → the agent asked, with two choices → "No, only this site's design" → `{"brand":false,"primary":"#ff7f50"}`, profile unchanged. "Use the Forest preset" → asked → "Yes, they are part of my brand" → `{"preset":"forest","brand":true}`, profile changed. Each step took 0.6 to 1.9 s. "Which design presets are there?" listed all six by name.

## Acceptance criteria (spec §8)
- [x] "Make the primary color coral" → the agent asks whether it is a brand color → Global Styles updated, preview offered, event logged.
- [x] "Use the Forest preset" → the same flow, with all three colors from the preset.
- [x] Answer "part of the brand" → the brand profile's colors changed too. Answer "only this site" → the profile unchanged.
- [x] A hex value in a request never ends up in page content (palette slugs only): the ability writes Global Styles only, and `BlockValidator` rejects color literals in generated pages.

## Open
- Curating the six presets before M2 ships (maintainer).
- Fonts are out of scope (Inter is fixed for the MVP).
