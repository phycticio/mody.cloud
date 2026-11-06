# M3-02 · Agent design tool: palette colors, and the brand question

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 · **Status:** todo

## Goal
"Make the primary color coral" or "use the Forest preset" changes the site's colors through the agent. Before it changes anything, the agent asks whether the new colors are part of the brand. If they are, the brand profile changes too, so later generations and the wizard use them.

Decided by the maintainer on 2026-11-06 (split from M3-01): the brand question, and support for the **six design presets** of mvp-spec §6.

## Deliverables
- **The six presets, as data in the MU plugin** (for example `src/Design/Presets.php`): slug, name and the three colors, from the candidate table in mvp-spec §6 (Mody Teal as the default, Forest, Ocean, Plum, Graphite, Sand). The brand wizard (M2-01) will reuse the same list, so it is the single source. The table is marked "to be curated before M2 ships": use it as it is and leave the curation to the maintainer.
- **Ability `mody/update-design`** (category `mody`, public like the others):
  - Input:
    - either `preset`: one of the six preset slugs (an enum in the schema);
    - or `primary?`, `secondary?`, `accent?`: hex colors, validated server-side with `^#[0-9a-fA-F]{6}$`;
    - `brand`: a **required** boolean, whether the colors are part of the brand.

    A preset, or at least one color, must be given, but not both. Color names ("coral") are converted to hex by the model; the server only accepts hex.
  - Effect: patches the site's Global Styles palette entries `primary`, `secondary` and `accent` (the user `wp_global_styles` post of the active theme), never `base` or `contrast`. Content keeps using palette slugs, so nothing in pages changes.
  - If `brand` is true, it also updates `color_primary`, `color_secondary` and `color_accent` of the site's row in `mody_brand_profiles`.
  - Permission: `edit_theme_options`. No brand profile: refused (rule 4).
  - Returns the old and new values, and whether the brand profile changed.
- **Agent:** offer the ability in `Agent::ABILITIES`. In `SystemPrompt::for_agent()`: before calling it, ask with `ask_user` whether the colors are part of the brand (choices such as "Yes, it is part of my brand" / "No, only this site's design"), and pass the answer as `brand`. The agent can list the presets by name when asked ("which designs are there?"); give it the names in the ability's description or schema, not hex values. The reply offers a preview: the styles button (`mody/open-editor`, `target` `styles`).
- **Audit:** the call is logged in `mody_agent_events` like any tool call, with the old and new colors in its result.
- **Tests:** extend `scripts/ai-agent-chat-test.php`:
  - fake answers for the ask-then-update flow, brand yes and no, with a preset and with a single color;
  - hex and preset validation, and the permission check;
  - that the palette and the profile row changed, or not;
  - real runs of "make the primary color coral" and "use the Forest preset".

  Restore the site's Global Styles and profile colors at the end.
- **Docs:** ai-engine.md ("The agent"), mvp-spec §8 tools table and §6 (the presets now live in the MU plugin), brand-profile.md if the profile's colors change meaning.

## Acceptance criteria (spec §8)
- "Make the primary color coral" → the agent asks whether it is a brand color → Global Styles updated, preview offered, event logged.
- "Use the Forest preset" → the same flow, with all three colors from the preset.
- Answer "part of the brand" → the brand profile's colors changed too. Answer "only this site" → the profile unchanged.
- A hex value in a request never ends up in page content (palette slugs only).
