# M2-04 · Wizard live preview

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §6 · **Status:** done (2026-11-07)

Split from M2-01 on 2026-11-06. Builds on [M2-03](M2-03-brand-wizard.md).

## Goal
In step 2 of the wizard, choosing a preset updates a live preview of the site's header and hero, rendered with the real theme.

## How it is built
- **A preview page on the main site** (`src/Wizard/WizardPreview.php`): `/?mody_wizard_preview=1&brand=…&mission=…&preset=…`, answered on `template_redirect`.
  - The theme's header part, with the brand name as the site title.
  - A hero: the brand name as the level-1 heading, the "what do you do?" sentence, a button in the accent color, and a heading and paragraph in the primary and secondary colors.
  - The preset's three colors are set as `--wp--preset--color--*` variables on `:root`.
  - Blocks are rendered before `wp_head()`, as block templates are, so their styles load. Every value is escaped.
  - Only users with `mody_access_dashboard` see it (403 otherwise). Nothing is saved. It is `noindex` and not cached.
- **In the wizard** (`assets/wizard.js`): step 2 shows it in an iframe, rendered at desktop width and shown at 60%, not clickable.
  - The page loads when step 2 opens, and again only if the brand name or the sentence changed.
  - Choosing another preset sets the three variables in the iframe's document directly, with no reload: the preview has the wizard's origin (the main site's host).
- **Where it differs from the new site:**
  - The navigation shows three fixed links (Home, About, Contact), because the main site's pages would be wrong. A `pre_render_block` filter gives the navigation block those links during the preview.
  - The logo is added by the wizard's script ([M2-05](M2-05-wizard-logo.md)).
  - The main site's own Global Styles apply, apart from the three brand colors. Today it uses the theme's defaults, so nothing else differs.

## Acceptance criteria (spec §6)
- [x] The preview uses the real theme: header and hero rendered with the preset palette.
- [x] It matches what the provisioned site shows for the same choices. The palette variables carry the same preset values that provisioning writes to the new site's Global Styles (`provisioning-test.php` checks those), and the header is the same template part.
- [x] Switching presets updates it without a page reload.
- [x] Nothing is saved, and nothing loads from outside the network (rule 1): the test checks every `src` and `href` host.

## Results (2026-11-07, local)
- `scripts/wizard-test.php`, new checks:
  - the page has the preview, and its settings carry the preview's address and the presets' colors;
  - the preview has the header with the brand name (escaped), the hero with the sentence, the preset's colors, the three fixed links, no host outside the network, and no admin bar;
  - an unknown preset falls back to the default colors;
  - over HTTP, the preview loads for the super admin on the wizard's host, and a logged-out visitor gets 403.
- **Found:** the preview page weighs about 460 KB, like every front-end page on the network. 394 KB of it is Spectra's inline utility CSS (`spectra-gs-utility-classes-inline-css`). **Open question:** whether to trim it (a SpectraGuard filter, or with the mody blocks ticket).
- **Not checked by the tests:** the iframe and the color switching in a real browser. Walk through step 2 once.
