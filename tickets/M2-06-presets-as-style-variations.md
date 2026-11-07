# M2-06 · The design presets as the theme's style variations

**Milestone:** M2 (follow-up) · **Spec:** documentation/mvp-spec.md §6, §14 · **Status:** done (2026-11-07; the Site Editor walk-through is the maintainer's)

Asked for by the maintainer on 2026-11-07.

## Goal
The six presets of the wizard's step 2 (Mody Teal, Forest, Ocean, Plum, Graphite, Sand) are the theme's **style variations**: one file each in `app/web/themes/theme/styles/`, defining the color palette, typography, spacing and element styles. Each site can pick and edit them in the Site Editor (Styles → Browse styles). The wizard, provisioning and the agent's `mody/update-design` read the same files.

## Why it fits WordPress
- A block theme's `styles/*.json` files are listed by WordPress (`WP_Theme_JSON_Resolver::get_style_variations()`) and shown in the Site Editor's **Styles → Browse styles**.
- Choosing one writes it into the site's own Global Styles (`wp_global_styles`), which each site of the network has separately. Every site can then change colors, fonts and spacing in the Site Editor, with revisions.
- Today the theme has no `styles/` directory, so the Styles panel offers no variations.

## Deliverables
- **Theme:** `styles/forest.json`, `ocean.json`, `plum.json`, `graphite.json`, `sand.json`, each with a `title` and a full set: the five palette slugs (`primary`, `secondary`, `accent`, `base`, `contrast`), typography, spacing and element styles (buttons, links, headings). `theme.json` stays Mody Teal, the default.
- **Fix found on 2026-11-07:** `theme.json` gives every heading the `secondary` color, so a heading inside a colored group (the hero of generated pages, the wizard's preview) stays dark gray on teal. Headings should take their group's text color.
- **`Design\Presets`** reads the theme's variations instead of a PHP constant: names, slugs (the file names), colors. It stays the single source for the wizard, provisioning and the agent.
- **Applying a preset** (provisioning's `styles` step and `mody/update-design` with a preset): write the whole variation into the site's Global Styles, as the Site Editor does when a style is chosen. The Styles panel then shows it as the active style. Single colors (`mody/update-design` with colors) still patch the palette.
- **The wizard:** cards from the variations; the live preview renders the chosen variation in full (colors, fonts, spacing), reloading the preview when the choice changes.
- Tests: the variations' JSON and palette slugs, `Presets` from the files, provisioning and the agent applying a whole variation, the Site Editor seeing it as active; theme checks from theme-development.md.
- Docs: theme-development.md (`styles/`), mvp-spec §6 (this replaces "Fonts are Inter, fixed" if fonts change) and §14, ai-engine.md (changing the colors), brand-profile.md.

## Decisions (maintainer, 2026-11-07)
- **Typography:** the six styles keep Inter. Members choose their sites' fonts from a repository of free fonts served by the network: [M2-07](M2-07-font-repository.md).
- **Mody Teal** stays the theme's default (`theme.json`, "Default" in the Site Editor), with no file of its own.
- **Order:** M2-06, then M4-03.

## Results (2026-11-07, local)
- **Built:**
  - `themes/theme/styles/forest.json`, `graphite.json`, `ocean.json`, `plum.json`, `sand.json`, generated from the preset colors. They also declare theme.json's Inter, font sizes, spacing scale and root and element styles, so each can be tuned on its own.
  - `Design\Presets` reads them through `WP_Theme_JSON_Resolver::get_style_variations()`, with the slug from the title. Its order is the Site Editor's: Mody Teal, then alphabetical.
  - `SiteDesign::apply_preset()` and `active_preset()`; provisioning's `styles` step and `mody/update-design` with a preset apply the whole style.
  - The wizard preview renders the chosen style in full (`wp_theme_json_data_user` replaced for the preview) and reloads when the preset changes.
  - The heading rule in `style.css`.
- `scripts/style-variations-test.php`:
  - the files are valid, with five colors and a title, and WordPress lists them;
  - `Presets` reads them;
  - on `alpha`: Forest applied; **the Site Editor's REST routes see the site's Global Styles equal to the Forest variation**; a revision is saved; a color changed afterwards leaves no active style; the default empties the Global Styles;
  - the heading rule is in `style.css`.
- Provisioning and the agent tests check that the whole style is active. All suites pass, and a real provisioning run passes.
- **Contrast fixed (approved 2026-11-07).** Four presets failed WCAG AA in one pair each. Forest, Ocean and Plum's accents and Sand's primary were darkened to same-hue shades that pass (`#9c6224`, `#2075a9`, `#c92c75`, `#926630`). Every pair now passes (theme-development.md, "Contrast of the presets").
- **Not checked by the tests:** the Site Editor's Styles panel in a browser (Browse styles, editing a style per site).

