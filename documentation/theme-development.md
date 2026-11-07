# Theme development

The site theme is a **block theme (Full Site Editing)** at `app/web/themes/theme`. Header, footer, templates and global styles are blocks and JSON, editable only with Gutenberg and Spectra. There is no page builder and no proprietary configuration, so everything stays portable.

Related documents: [ai-engine.md](ai-engine.md) (agent output uses the same block format as the templates), [brand-profile.md](brand-profile.md) (how a member's identity maps onto the palette), [constraints.md](constraints.md) (rules every change must respect).

## Structure

```text
app/web/themes/theme/
├── style.css          # theme header + a few rules theme.json cannot express
├── theme.json         # version 3: palette, typography, spacing, element styles
├── functions.php      # minimal: translations, editor style, front-end style.css enqueue
├── index.php          # empty, "Silence is golden"
├── templates/
│   ├── index.html     # fallback and blog index
│   ├── page.html      # title + content
│   ├── page-blank.html# custom template: no title, full width (for generated landing pages)
│   ├── single.html    # post
│   ├── archive.html
│   ├── search.html
│   └── 404.html
└── parts/
    ├── header.html    # site logo, site title, navigation
    └── footer.html    # site title and tagline on the contrast background
```

Not included yet: `patterns/` (add PHP or HTML patterns there and WordPress registers them), `styles/` (style variations, for example the Mody presets suggested in the wizard), `languages/` and `screenshot.png`.

## Design tokens

The palette is the briefing's visual identity. Default palette, gradients and duotones are turned off, so the editor only offers brand colors. The agent must reference these slugs and never loose hex values.

| Slug | Value | Role | Contrast on `base` |
| --- | --- | --- | --- |
| `primary` | `#005f6b` | Teal. Links and buttons. | 6.76 |
| `secondary` | `#333333` | Dark gray. Headings. | 11.59 |
| `accent` | `#b50ba4` | Magenta. Hover and focus. | 5.43 |
| `base` | `#f5f5f5` | Light background. Text on `primary` and `accent` (6.76 and 5.43). | n/a |
| `contrast` | `#01110a` | Deep black. Body text, footer background. | 17.74 |

All pairs the theme actually uses pass WCAG AA for normal text (4.5:1).

- **Typography:** `Inter` first, then a system stack. No font files are bundled and nothing loads from a CDN (sovereignty and GDPR). To really serve Inter, add the files under `assets/fonts/` and declare them with `fontFace` in `theme.json`. Members will choose fonts from a repository of free fonts served by the network ([M2-07](../tickets/M2-07-font-repository.md)). Core's Google Fonts collection is removed from the Font Library on every site (`Design\Fonts`, 2026-11-07, rule 1).
- **Font sizes:** `small`, `medium`, `large`, `x-large`, `xx-large`, fluid between viewport sizes.
- **Spacing presets:** `20` to `80` (0.5 rem up to 8 rem). Templates use `var:preset|spacing|NN`.
- **Layout:** content width 720px, wide 1200px, root-padding-aware alignments.

When a member's brand profile is applied, `color_primary`, `color_secondary` and `color_accent` override the `primary`, `secondary` and `accent` values. See [brand-profile.md](brand-profile.md#data-schema-custom-wordpress-table) for the mapping and [architecture.md](architecture.md#open-questions) for the open question about doing it per site.

## Rules

- The serialized block format in `templates/` and `parts/` is the same format the agent generates ([ai-engine.md](ai-engine.md)). Keep markup valid: block comment JSON must parse, blocks must be balanced, saved HTML must match the attributes.
- No Elementor or similar builders. No Astra. No external fonts, scripts or CDNs.
- Styles go in `theme.json` first. `style.css` is only for what `theme.json` cannot express.
- Keep `functions.php` small. Platform logic (tiers, brand profile, agent) does not belong in the theme.
- Text domain is `mody_cloud`. It differs from the directory name (`theme`), so `functions.php` loads translations explicitly. Wrap user-facing PHP strings with WordPress i18n functions. Text in HTML templates is plain and editable in the Site Editor.
- Code comments, documentation and commit messages are written in English (rule 8 in [constraints.md](constraints.md)). Do not write Spanish into code or templates.
- **JavaScript and CSS go through `@wordpress/scripts`.**
  - Sources live in `src/`. `pnpm build` lints them and compiles them into `app/dist`, and `pnpm start` watches. `config/webpack/config.js` extends the wp-scripts defaults.
  - Each entry gets an `*.asset.php` file with its dependencies and version. Enqueue with it, so WordPress packages load as script dependencies instead of being bundled.
  - Nothing enqueues `app/dist` yet.
- **Only the libraries a feature needs.** Prefer WordPress packages (`@wordpress/element`, `@wordpress/components`, `@wordpress/i18n`, `@wordpress/api-fetch`, and so on) and the webpack, Babel, Sass, ESLint and Stylelint setup that wp-scripts already ships. Add a third-party library or framework only when no WordPress package covers the need. Ask first (see `AGENTS.md`), and record it here with the reason. Everything is bundled locally. No script or style loads from a CDN.
- PHP-only custom blocks (`block.json` plus `render.php`, as the briefing suggests) need no build step.

## Working with the theme

```bash
./bin/wp theme activate theme          # activate (single site)
./bin/wp theme list                    # confirm it is active
```

Then open **Appearance > Editor** to check templates, parts and Styles. Smallest useful checks after a change:

```bash
php -l app/web/themes/theme/functions.php
python3 -c "import json; json.load(open('app/web/themes/theme/theme.json'))"
```

The scaffold was checked statically (JSON validity, block comment balance, preset slugs, PHP lint). It has not been loaded into a running WordPress yet, so open the Site Editor once and look for "block contains unexpected content" warnings.
