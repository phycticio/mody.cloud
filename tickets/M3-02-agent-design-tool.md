# M3-02 · Agent design tool: palette colors, and the brand question

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 · **Status:** todo

## Goal
"Make the primary color coral" changes the site's colors through the agent. Before it changes anything, the agent asks whether the new color is part of the brand. If it is, the brand profile changes too, so later generations and the wizard use it.

Decided by the maintainer on 2026-11-06 (split from M3-01).

## Deliverables
- **Ability `mody/update-design`** (category `mody`, public like the others):
  - Input:
    - `primary?`, `secondary?`, `accent?`: hex colors, validated server-side with `^#[0-9a-fA-F]{6}$`;
    - `brand`: a **required** boolean, whether the colors are part of the brand.

    At least one color must be given. Color names ("coral") are converted to hex by the model; the server only accepts hex.
  - Effect: patches the site's Global Styles palette entries `primary`, `secondary` and `accent` (the user `wp_global_styles` post of the active theme), never `base` or `contrast`. Content keeps using palette slugs, so nothing in pages changes.
  - If `brand` is true, it also updates `color_primary`, `color_secondary` and `color_accent` of the site's row in `mody_brand_profiles`.
  - Permission: `edit_theme_options`. No brand profile: refused (rule 4).
  - Returns the old and new values, and whether the brand profile changed.
- **Agent:** offer the ability in `Agent::ABILITIES`. In `SystemPrompt::for_agent()`: before calling it, ask with `ask_user` whether the color is part of the brand (choices such as "Yes, it is part of my brand" / "No, only this site's design"), and pass the answer as `brand`. The reply offers a preview: the styles button (`mody/open-editor`, `target` `styles`).
- **Audit:** the call is logged in `mody_agent_events` like any tool call, with the old and new colors in its result.
- **Tests:** extend `scripts/ai-agent-chat-test.php`: fake answers for the ask-then-update flow (brand yes and no), hex validation, the permission check, and that the palette and the profile row changed or not. One real run of "make the primary color coral".
- **Docs:** ai-engine.md ("The agent"), mvp-spec §8 tools table, brand-profile.md if the profile's colors change meaning.

## Acceptance criteria (spec §8)
- "Make the primary color coral" → the agent asks whether it is a brand color → Global Styles updated, preview offered, event logged.
- Answer "part of the brand" → the brand profile's color changed too. Answer "only this site" → the profile unchanged.
- A hex value in a request never ends up in page content (palette slugs only).

## Open
- Presets (spec §8 lists `preset` as an argument): wait for the six presets of the wizard (M2-01), or skip them here.
