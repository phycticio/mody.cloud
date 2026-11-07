# M2-07 · A repository of free fonts for members' sites

**Milestone:** M2 (follow-up) · **Spec:** documentation/mvp-spec.md §6, §14 · **Status:** todo

Decided by the maintainer on 2026-11-07 (with M2-06): members choose their sites' typography from a repository of free fonts that the network serves itself.

## Goal
In the Site Editor (Styles → Typography → Fonts → Manage fonts), a member installs fonts from a Mody collection of open-licensed fonts and uses them for headings and text. Nothing loads from outside the network (rule 1).

## How WordPress supports it
- WordPress 6.5+ has a **Font Library**: font collections (`wp_register_font_collection()`), fonts installed per site (files under the site's font directory, `wp_get_font_dir()`), and fonts chosen in Global Styles.
- Core registers a **Google Fonts** collection. Its list and previews load from wordpress.org, and its files from Google. **Removed on every site since 2026-11-07** (`Design\Fonts::remove_collections()`; checked in `scripts/spectra-guard-test.php`). `SpectraGuard` already refused requests to Google's font hosts.

## Deliverables
- **The repository:** a curated set of open-licensed fonts (SIL OFL or similar), as `woff2` files served from the network. For each: name, category, weights and styles, license, and a preview.
- **A Mody font collection** registered from it (`wp_register_font_collection()` with a local JSON). Installing a font copies it into the site's font directory, as core does.
- **Inter bundled** and declared with `fontFace` in `theme.json`, so the default typography is real Inter and not a system fallback (theme-development.md).
- Tests: the collection is registered, every file URL is on the network, installing a font works on a member site; no request leaves the network.
- Docs: theme-development.md (typography), mvp-spec §6 and §14.

## Decisions (ask the maintainer)
- **Which fonts:** the first list, for example 10 to 20 families covering sans, serif, display and monospace.
- **Where the files live:** in the theme (`assets/fonts/`), in the MU plugin, or a network-wide directory. They are binary files in the repository; the size grows with the list.
- **Where the files come from:** each font's own release (GitHub releases of the font projects), not a font CDN. Adding them to the repository may count as a dependency to approve (AGENTS.md).

## Related
- [M2-06](M2-06-presets-as-style-variations.md): the style variations keep Inter; fonts come from this repository.
