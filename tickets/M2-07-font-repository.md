# M2-07 · A repository of free fonts for members' sites

**Milestone:** M2 (follow-up) · **Spec:** documentation/mvp-spec.md §6, §14 · **Status:** done (2026-11-07; the browser walk-through is the maintainer's)

Decided by the maintainer on 2026-11-07 (with M2-06): members choose their sites' typography from a repository of free fonts that the network serves itself.

## Goal
In the Site Editor (Styles → Typography → Fonts → Manage fonts), a member installs fonts from a Mody collection of open-licensed fonts and uses them for headings and text. Nothing loads from outside the network (rule 1).

## Decisions (maintainer, 2026-11-07)
- **The list:** proposed by the agent, ten families (below).
- **Where the files live:** in the platform MU plugin, `app/web/mu-plugins/mody-platform/fonts/<slug>/`.
- **Where they come from:** each font's own repository, downloaded once and committed (approved). `fonts/sources.json` records the repository, the commit and the size of every file; each family's folder has its `LICENSE.txt`.

## The fonts (all SIL Open Font License 1.1, WOFF2, 3.8 MB in all)
| Category | Family | Files | Size | Source |
| --- | --- | --- | --- | --- |
| Sans serif | Inter | variable 100–900, roman and italic | 722 KB | rsms/inter |
| Sans serif | IBM Plex Sans | variable 100–700, roman and italic | 481 KB | IBM/plex |
| Sans serif | Work Sans | 400, 400 italic, 600, 700 | 284 KB | weiweihuanghuang/Work-Sans |
| Sans serif | Space Grotesk | variable 300–700 | 50 KB | floriankarsten/space-grotesk |
| Serif | Lora | variable 400–700, roman and italic | 172 KB | cyrealtype/Lora-Cyrillic |
| Serif | Merriweather | 400, 400 italic, 700, 700 italic | 303 KB | SorkinType/Merriweather |
| Serif | EB Garamond | 400, 400 italic, 700 | 648 KB | octaviopardo/EBGaramond12 |
| Display | Playfair | variable 300–900 | 636 KB | clauseggers/Playfair |
| Display | Fraunces | 400, 400 italic, 600, 700 (72 pt optical size) | 163 KB | undercasetype/Fraunces |
| Monospace | JetBrains Mono | variable 100–800, roman and italic | 234 KB | JetBrains/JetBrainsMono |

Manrope was on the first list and was dropped: its repository has no web font files.

## How it is built (`src/Design/Fonts.php`)
- **`Fonts::FAMILIES`** is the list: name, category, fallback stack, and each face (file, weight, style). To add a family, put its files and license under `fonts/<slug>/`, add it to `sources.json` and to `FAMILIES`.
- **The collection** `mody-fonts` ("Mody fonts") is registered on `init` with `wp_register_font_collection()`, from `FAMILIES`. Its file URLs are built with `plugins_url()`, so they are on the current site's host: the editor downloads them from its own origin and uploads them into the site's font directory (`uploads/sites/<id>/fonts/`), as core does for any collection. Google Fonts stays removed, so it is the only collection.
- **The theme's Inter is real Inter:** a `wp_theme_json_data_theme` filter gives the theme's `inter` family its two font faces from the plugin, and every page prints their `@font-face` rules. theme.json is not edited.
- **The style variations no longer list font families** (`styles/*.json`): they would replace the theme's list, Inter's faces with it. They keep their font sizes.
- **Installed fonts survive a preset:** `SiteDesign::apply_preset()` (the agent's "switch to Ocean", and provisioning) keeps the site's `settings.typography.fontFamilies`, and `Presets::active()` ignores them, since presets define none. The Site Editor's own style switch drops activated fonts (core behavior); they stay installed and can be turned on again in the Font Library.
- **Activation:** installing a font activates it at once. Spectra's style-guide bridge adds every installed family to the site's Global Styles on save (`save_post_wp_font_family`, `save_post_wp_font_face`); the editor does the same.
- **Production:** core's Font Library does not check `DISALLOW_FILE_MODS`, so members can install fonts there too (checked in core 7.1).

## Acceptance criteria
- [x] The Font Library offers the Mody collection and nothing else; a member installs a font from it on their site.
- [x] Every file comes from the network, under an open license, with its source recorded.
- [x] The theme's default typography is real Inter.

## Results (2026-11-07, local)
- `scripts/font-library-test.php`: all checks pass.
  - **The collections:** Mody fonts registered, Google Fonts not; every family listed, with its files (WOFF2, the sizes in `sources.json`), its OFL license and its source commit; four categories.
  - **The URLs:** every file on the site's own host, and served as `font/woff2`.
  - **The theme's Inter:** its faces in the merged settings, theme.json unchanged, no variation replacing the families, the `@font-face` rules printed, and the home page loading them with nothing from Google.
  - **On member site `alpha`, as a member over HTTP:** the Font Library lists only the Mody collection, with URLs on `alpha`'s host; Lora installs the way the editor does it (the family, then a face with its file, 201 each), into the site's font directory, byte for byte.
  - **A preset afterwards:** Ocean, then the default, keep Lora active, and each is still the active style.
  - Everything is removed afterwards and `alpha`'s Global Styles restored.
- All other suites pass, `style-variations-test.php` included.
- **Not checked by the tests:** the Font Library in a real browser. On a member site, open the Site Editor → Styles → Typography → Fonts → Manage fonts → Install fonts, pick a family, install it, and choose it for headings.

## Related
- [M2-06](M2-06-presets-as-style-variations.md): the presets as style variations.
