# M2-04 · Wizard live preview

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §6 · **Status:** todo

Split from M2-01 on 2026-11-06. Comes after [M2-03](M2-03-brand-wizard.md).

## Goal
In step 2 of the wizard, choosing a preset updates a live preview of the site's header and hero, rendered with the real theme.

## Deliverables
- A preview of the header part and a hero (the brand name, the "what do you do?" sentence, a button), rendered by the theme `theme` with the chosen preset's colors, and the logo if one was uploaded.
- Switching presets updates it without a page reload.
- Nothing is saved: no site, Global Styles or profile exists until **Create my site**.
- Nothing loads from outside the network (rule 1): the theme's own CSS and fonts only.

## Acceptance criteria (spec §6)
- The preview uses the real theme: header and hero rendered with the preset palette.
- It matches what the provisioned site shows for the same choices.

## Open
- **How to render it.** One option is an iframe of a preview endpoint on the main site that renders the header part and a hero with the theme's CSS, overriding the `--wp--preset--color--primary|secondary|accent` variables. Another is `wp.blocks`/`@wordpress/block-editor` rendering in the page. Decide when building it; keep it small (rule 5).
