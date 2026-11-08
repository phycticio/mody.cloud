# Tech debt · The design market: Mody's themes and a curated pattern library

**Milestone:** not assigned (tech debt) · **Spec:** documentation/mvp-spec.md §10 (capability gating), documentation/theme-development.md, documentation/constraints.md (rules 1, 3 and 4) · **Status:** tech debt 2 (patterns) built 2026-11-08, Results below; tech debt 1 (themes) open, with a recommendation.

> "Let's open the app to see the WordPress patterns in the templates so users have a design market. Also we will be the only providers of themes, so add as a tech debt the definition of themes to show and investigate if we can have filtered patterns too." (maintainer, 2026-11-08)

## Where it stands (checked 2026-11-08 in the app)
Checked in the Site Editor of `alpha.mody.local`, logged in as the member (`member`, not a super admin), in a headless Chromium. The screenshots were for the maintainer and are not kept in the repository.

- **Themes:** Mody is the only provider. Members cannot install, upload, update, delete or switch themes (M4-01, and no updates for anyone since 2026-11-08). Every site runs `theme`. The six design presets are its style variations (M2-06).
- **The inserter, editing a template:** the Patterns tab offers **63 patterns in 10 categories** (About, Banners, Call to action, Footers, Gallery, Headers, Posts, Text, Uncategorized, plus All). "Explore all patterns" browses the whole WordPress.org Pattern Directory.
  - 11 are bundled with WordPress core (`core`).
  - 43 come from the Pattern Directory's "core" keyword (`pattern-directory/core`), and 9 from its "featured" category (`pattern-directory/featured`).
- **The Site Editor's Patterns screen:** **"No results"**. WordPress hides the `core` and Pattern Directory sources there (`EXCLUDED_PATTERN_SOURCES` in `wp-includes/js/dist/patterns.js`), and the theme registers no patterns. Only the template parts (Header, Footer) are listed. A pattern shows on this screen only when the theme, a plugin or the member provides it.
- **Where the Directory patterns come from:** each time the editor asks for patterns (`/wp/v2/block-patterns/patterns`), core fetches them server-side from `api.wordpress.org` through its proxy (`_load_remote_block_patterns()`, `_load_remote_featured_patterns()`, `_register_remote_theme_patterns()`). Nothing switches this off today. The container reaches WordPress.org (HTTP 200).
- **Conflicts with the rules:**
  - **Rule 1 (load nothing from third-party CDNs; data stays in Europe):** image links in the patterns point to `s.w.org` (14), `pd.w.org` (10), `wordpress.org` (3), `img.rawpixel.com` (2) and `thato.test` (1). A member who inserts such a pattern publishes a page whose visitors load those images. The editor calls also go to WordPress.org, the open question in architecture.md.
  - **Rule 4 and the palette rule:** 28 of the 63 use loose hex colors, and many carry their own fonts and imagery, so they do not follow the site's brand or palette slugs.

## Tech debt 1 · Which themes members see
- **Decide the offer:** one Mody theme with style variations (today), or several Mody themes. If several:
  - which ones;
  - how a member chooses (the wizard, the Site Editor, the agent);
  - whether switching is allowed later (`switch_themes` is refused today).
- **Rules that stay:** themes come only from Mody, through the repository and `./bin/deploy` (no wp-admin installs or updates). Each theme follows theme-development.md: FSE, palette slugs, Spectra containers, local fonts.
- **Where it would live:** like the hidden plugins list (M4-02 A2), a list kept by super admins or fixed in code. To decide.

## Tech debt 2 · Filtered patterns (investigate)
**What WordPress 7.1 offers** (read in core, 2026-11-08):
- **`should_load_remote_block_patterns` → false:** no Pattern Directory patterns, and no calls to `api.wordpress.org` for them. To check: whether "Explore all patterns" also goes. It uses another route, `/wp/v2/pattern-directory/patterns`, which may need refusing for members.
- **`theme.json` `"patterns": [slugs]`:** registers chosen Directory patterns by slug. This is a filter, but still remote, with the same image hosts.
- **`remove_theme_support( 'core-block-patterns' )`, `unregister_block_pattern()`, `unregister_block_pattern_category()`:** remove core's bundled patterns or whole categories.
- **Our own patterns:** `register_block_pattern()` from the MU plugin, or `patterns/*.php` in the theme. They are local, filtered by us, and shown on **both** the Patterns screen and the inserter, because their source is not excluded. Mody's own categories are possible (`register_block_pattern_category()`).
- **Per site or per plan:** possible on the REST response (`rest_request_after_callbacks` on `/wp/v2/block-patterns/patterns`), as M4-02 does for plugins. To investigate.

**Proposal to decide:** a curated, local **Mody patterns** collection, like the fonts collection (M2-07):
- **Source:** GPL patterns copied from the Directory.
- **Colors:** rewritten to palette slugs.
- **Images:** files served from the MU plugin.
- **Checks:** validated by `BlockValidator`.
- **Categories:** Mody's own.
- **Remote patterns:** switched off.

It makes a real design market on the Patterns screen, keeps every site on-brand, and fits "Mody is the only provider". Later, the agent could insert them by name.

## Open question (answered)
- ~~Until the collection exists, keep the remote Pattern Directory?~~ The collection was built the same day, and the remote Directory is off.

## Results (tech debt 2, built 2026-11-08)
The maintainer said "implement this ticket now" without choosing among the three options offered in chat, so the recommendations were taken:
- **Which patterns:** the ones WordPress offered by default. That is the Directory's "core" keyword and "featured" category (54), plus core's 11 bundled patterns: 65 read.
- **Images:** the platform's own placeholder generator.
- **Colors:** mapped to the palette.

**Built:**
- **`src/Design/PatternImport.php`: the rewrite.** It changes attributes and the saved HTML together, so blocks stay valid.
  - **Images:** `<img src>`, cover `url`, CSS `url(...)` backgrounds → `/mody-placeholder/<w>x<h>.svg` at the block's size. `srcset`, `sizes`, attachment IDs, `wp-image-N` and the Media & Text `size-*` class are removed.
  - **Colors:** core's slugs (`black`, `white`…) and custom hex values (text, background, cover overlays, borders, links, separators, inline text colors) → the nearest palette slug (weighted RGB distance to the theme's palette). Social icons get `var(--wp--preset--color--<slug>)`, so they follow each site's preset. Duotone goes, and stray color classes typed by authors go.
  - **Fonts:** families the theme lacks are dropped; sizes are mapped to the theme's (`extra-small` → `small`, `normal` → `medium`).
  - **Links** to other sites → `#`.
  - **Refused:** patterns with gradients (the theme defines none), and any pattern where a URL or custom color would be left.
- **`src/Design/Patterns.php`: the loader.**
  - **Registration:** the collection is registered on `rest_api_init` as `mody/<slug>` with source `plugin`, so it shows on the Patterns screen and in the inserter, plus a "Wireframes" category. The Directory's `images` category maps to `media`.
  - **Switched off:** `should_load_remote_block_patterns`, and core's bundled patterns (their rewritten copies are in the collection). The Directory proxy `/wp/v2/pattern-directory/…` is refused to everyone but super admins.
  - **Placeholders:** `serve_placeholder()` answers `GET /mody-placeholder/<w>x<h>.svg` (16 to 4000 px) when the MU plugin loads, before the rest of WordPress runs. It is a neutral SVG (picture sign and size), cached for a year.
- **`scripts/import-patterns.php`:** builds `patterns/collection.json`, about 160 KB, with each pattern's source and license. Run it again to refresh the collection.

**Result: 63 of 65 patterns imported.** Left out, both for a gradient: "Centered image with two-tone background color" and "Fullwidth headline with links and gradient offset background". The import report counts:
- 34 images and 2 backgrounds replaced;
- 43 color slugs, 75 custom colors and 22 style colors mapped, plus 2 separators, 4 social icon colors and 1 inline text color;
- 7 duotone filters removed;
- 12 font sizes mapped and 1 font family removed;
- 10 links replaced and 6 stray classes removed.

**Checked:**
- **`scripts/patterns-test.php`** (new, 21 checks, offline):
  - the rewrite on a sample;
  - every pattern: source and license, no URL, palette colors only, theme fonts, placeholders, stable through parse and serialize;
  - what a member's editor gets: exactly the 63, as `plugin` patterns, with no request to WordPress.org;
  - the proxy refused to members;
  - the placeholders over HTTP.
- **In the block editor itself** (headless Chromium, as the member): **all 63 patterns parse as valid blocks**. The first runs found three Media & Text blocks, fixed in the rewrite. The Patterns screen lists the 63 in 11 categories, and the inserter shows them with placeholders and the site's colors. This check needs a browser, which the committed tests do not have. It used a scratchpad copy of `playwright-core` with the Playwright browser already cached on the Mac.
- **All 21 suites pass offline.**

## Findings and recommendations (2026-11-08)
1. **The placeholder's label shows through cover text.** On covers ("Take a hike…"), "1600 × 900" shows behind the headline. Recommendation: no label on cover placeholders (a `-plain` variant of the path), or a lower-contrast label.
2. **Patterns still carry their authors' words**, such as "Enjoy 20% off", "Proudly powered by WordPress" (credit lines, now linking to `#`) and English sample copy. Recommendations:
   - remove or rewrite the "Powered by WordPress" credits in the import, as a list of phrases;
   - later, a `mody/insert-pattern` ability, so the agent inserts a pattern with the text written in the brand's voice (rule 4).
3. **Two gradient patterns were left out.** If gradients are wanted, define palette-based gradients in `theme.json` (none today), then map gradients to them in the rewrite.
4. **Gravatar (rule 1).** The validation found `secure.gravatar.com` requested by every member's admin bar. Recommendation: switch avatars to a local default (`pre_get_avatar_data`) or off. Recorded in architecture.md, Open questions.
5. **Block deprecations change with WordPress.** Two patterns rely on Media & Text deprecations (media on the right). Recommendation: after each WordPress update, rerun the import and the browser validation, like the M3-09 browser check, and fix or drop what breaks.
6. **A committed browser check.** The editor validation is the check that matters most. Recommendation: a dev-only Playwright setup in the repository, which needs your approval because it is a dependency, also for M3-09's in-place navigation.
7. **The local proxy rewrites `Cache-Control`** on the placeholders (a short `max-age` instead of a year). Check what production (Cloudflare, NPM) sends once deployed.
8. **The market's size.** 63 patterns is a start. The import takes any Directory query, so categories (headers, footers, pricing, testimonials…) can be added. Each addition must still pass the rewrite and the browser validation.

## Tech debt 1 · Recommendation (themes)
- **For the MVP:** stay with one Mody theme, `theme`, and its six presets (style variations): one theme to keep valid, secure and on-brand, while the presets and the pattern collection give the variety. Keep `switch_themes` refused.
- **Later:** if a second theme is wanted, add it to Composer like the first, offer it through a super-admin list (as M4-02 A2 does for plugins), and let the wizard choose it at site creation, not afterwards. The pattern collection then needs per-theme checks (fonts, palette slugs).
