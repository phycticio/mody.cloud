# Site languages: a multilingual site on every site of the network

**Builds on:** the agent (M3), the editor-first rules (M4-01) · **Spec:** documentation/mvp-spec.md decision 12 (changed by this ticket) · **Status:** planned and approved by the maintainer on 2026-11-10; parts 1 to 7 and part 11 built the same day, not committed (Results below). Next: parts 8 to 10.

## Goal (maintainer, 2026-11-10)
> I need a simple translation matrix that works natively with WordPress. What can we do to have the website in multiple languages and offer that to our users and have it as a MU plugin enabled for everyone.

> Let's create a custom plugin.

## Decided by the maintainer (2026-11-10, "Approve everything with your recommendations")
1. **Our own MU plugin, `mody-languages`**, not Polylang or another multilingual plugin. It sits in `app/web/mu-plugins/mody-languages/`, loaded by `mu-plugins/mody-languages.php` on every site, main site included. It is the second exception to "platform logic lives in one MU plugin" (after `mody-mail`): the language engine stands alone, and `mody-platform` only adds Mody's translation (part 8).
2. **Addresses carry a language prefix:** the default language has none (`/about/`), the others do (`/es/sobre-nosotros/`, `/es/` for the translated home page). No language subdomains: they would clash with the member sites' subdomains.
3. **Languages offered at launch:** English, Spanish, French, German, Italian, Portuguese, Dutch and Catalan.
4. **Mody's translations are drafts**, for the member to check and publish.

## How it works (the design)
- **WordPress first.** A translation is an ordinary page, edited in the Site Editor. Two post meta fields link it: `mody_lang` (its locale) and `mody_source` (the original page's ID). A page with neither is in the site's default language, which is core's Site Language (`WPLANG`). Removing the plugin leaves ordinary pages.
- **A site with one language behaves as before:** no prefixes, no switcher, no extra tags.
- **The language comes only from the address,** never from a cookie or the browser's language, so the proxies' caches (NPM locally, Cloudflare in production) stay correct.
- **Slugs stay unique, as in core.** A copy gets the original's slug plus the language (`about-es`), which the member can change. A page asked for under the wrong prefix (or none) is redirected to its own address.

## Parts (each 1 to 2 hours)

### Part 1 · The plugin and the site's languages
- The MU plugin, its loader and autoloader (`Mody\Languages`).
- `Languages`: the offered languages (code, address prefix, native name, `hreflang` code), the site's default language (`WPLANG`, `en_US` when empty) and its other languages (site option `mody_languages`).
- **Settings → Languages** (`manage_options`): the other languages as checkboxes; the default language links to Settings → General.

### Part 2 · Translations
- `Translations`: the two meta fields (registered, shown in REST), a page's language, its original, its group of translations, and `create()`: a draft copy of the original (title, content, excerpt, template, the parent's translation when there is one).

### Part 3 · Addresses
- `Router`: the prefix is read and removed before WordPress parses the request, then put back, so core's canonical redirects see the real address. `/es/` shows the translation of the front page, or redirects to `/` when there is none.
- Links: `page_link`, `post_link` and `post_type_link` add the prefix; the front page's translation is `/es/`.
- A page asked for in the wrong language is redirected (301) to its own address.

### Part 4 · The page's language
- `<html lang>` follows the page's language; `hreflang` links for every published translation, plus `x-default`.
- WordPress's own words (dates, "Read more") switch with `switch_to_locale()`, once the language is installed (part 9).

### Part 5 · The switcher and the menus
- The `mody/language-switcher` block (server-rendered; plain editor script, no build step), placed after every navigation block by a block hook. It links to the current page in each language, or to that language's home page.
- Menu links to pages point to the page's translation and show its title (unless the link has a label of its own). The site title, logo and home link lead to the language's home page.

### Part 6 · Header and footer per language
- A template part named after the original plus the language (`header-es`, `footer-es`) replaces the original on that language's pages. The matrix offers to create it.

### Part 7 · The translation matrix
- Below the languages on Settings → Languages: one row per page in the default language, one column per other language. A cell is **missing** (with **Create**: a draft copy, then the editor), **draft**, **translated** or **outdated** (the original changed after the translation was last saved).
- The filter `mody_languages_cell_actions` lets `mody-platform` add its action (part 8).

### Part 8 · Translate with Mody (in `mody-platform`)
- An ability, `mody/translate-page`: Mody translates a page's blocks into a language with `PageGenerator::edit_markup()` (brand context, rule 4; the validator, rule 2) and saves a draft translation.
- The matrix's **Translate with Mody** button, and the agent learns the language of the page it is editing.

### Part 9 · WordPress's own translations
- Language packs for core, the theme and the plugins, for the offered languages, brought in at build time (no wp-admin installs: `Gating\Updates` refuses `update_languages`). A `bin/` script with no new dependency; how they reach production (committed or fetched by `./bin/deploy`) is decided in this part.

### Part 11 · The working language in wp-admin (asked 2026-11-10, built the same day)
- **Goal (maintainer):** "if there are five languages activated I would like users and us to have a language toggler in the wp-admin to change language and see what exists on each language. Like WPML does and filter all queries in the wp-admin by current language."
- **Decided** ("Continue with your recommendations"): a page or post created while another language than the default is chosen is in that language, standalone (no original); a new user starts in the default language; pages and posts.
- `AdminLanguage`: the choice per user and per site (user option `mody_admin_language`: a site language or `all`); the toolbar's **Language** menu (wp-admin, and the Site Editor, which shows the toolbar in WordPress 7.1); **Content language** entries in the editors' ⋮ menu (`assets/admin-language.js`).
- **Filtered:** the Pages and Posts lists (and Quick Edit's parent choices), the REST collections of pages and posts (the Site Editor's page list), the editors' link search; only for logged-in users who can edit posts, never lookups by ID or slug.
- **Not filtered:** single posts, the matrix, the agent (no REST), WP-CLI, the front end, templates, parts, menus, patterns, Settings → Reading, the Trash (its Empty Trash button deletes every language's trashed items). The lists' status counts (All, Published) still count every language.
- **Every post is in exactly one list** (`AdminLanguage::list_of()`): its own language when the site has it, else the default one (no language, the default's own tag, or a language the site no longer has).
- **The lists:** a language bar with counts ("Language: English (2) · Español (1) · … · All languages (5)") and a Languages column (the post's own language, then each other one: ✓ to edit, + to create).

### Part 10 · Documents and the home page
- mvp-spec decision 12 and the non-goal on a multi-language UI; AGENTS.md (the second MU plugin exception, Where to work); the home page's languages question.

## Not in the first version
- Per-language archives, search and feeds (the blog index lists every language).
- A translated site title and tagline.
- Text inside patterns; custom menu links that are not pages.
- When an original is deleted, its translations keep pointing to it.

## Results

### Parts 1 to 7 (built 2026-11-10)
- **The plugin:** `app/web/mu-plugins/mody-languages/` (`Languages`, `Translations`, `Router`, `Head`, `Switcher`, `Menus`, `Parts`, `SettingsPage`), loaded by `mu-plugins/mody-languages.php`, the block in `blocks/language-switcher/`, its editor script `assets/language-switcher-block.js`.
- **Test:** `scripts/languages-test.php`, 52 checks, all passing (37 at first, 15 more after the review below). It works on alpha and leaves it as it was: the languages, translations and their refusals, the states, the links, then over HTTP: `lang` and `hreflang`, both redirects of the wrong language, `/es/`, the switcher, the page list in one language, a header copy on Spanish pages only, a draft that stays private, the main site (one language) unchanged; the menu links; the matrix.
- **Checked in a browser** (alpha with Spanish and French, then cleaned): the Spanish page with the switcher and its menu in Spanish only; Settings → Languages with the checkboxes and the matrix (translated, missing with Create, the header and footer rows).
- **Found while building:**
  - **The block hook puts the switcher in every header,** also in what the agent reads and writes. So `BlockValidator::TEMPLATE_BLOCKS` now allows `mody/language-switcher`, and the template prompt names it and asks to keep it. Since the review, the hook applies only on sites with more than one language (`hooked_block_types`), so one-language sites, their editors and the agent see their headers exactly as before.
  - **WordPress strips `hreflang` from links in rendered blocks,** so the switcher's links carry `lang` only; the `hreflang` links are in the page's head.
  - **The navigation's fallback lists every page,** both languages included: `get_pages` is filtered to the request's language on the front end.
  - **On a site whose front page lists the posts,** `/es/` lists them too (no redirect).
  - **Requests from the app container to subsites** need curl's resolve option: `alpha.mody.local` resolves to the container itself there (the test sets it).
- **Review (2026-11-10, three reviewers, each finding verified):** 16 defects confirmed, all fixed and covered by the test:
  - a redirect loop for a page in a language the site no longer has (now served where it is);
  - a top-level page whose slug is a language prefix was unreachable: such a page keeps its address, new top-level pages and posts cannot take an offered prefix, and saving the languages warns about it;
  - the front page's translation: page numbers (`/es/2/`, `/es/page/2/`) and previews kept, a 301 from its own slug to `/es/`, treated as a front page (Front Page template, title, `home` class);
  - the wrong-language redirect keeps the query string and the page number;
  - `url_to_postid()` understands prefixed addresses (embeds, pingbacks);
  - **menus stored as navigation posts** (what the Site Editor makes) were not translated: links are now rewritten on the rendered output;
  - the meta fields were writable through REST by any author: now protected, out of REST, administrators only, and only offered languages are stored;
  - a copy dropped the original's password; a draft original gave the slug `es`;
  - the error message travelled in the address (spoofing): now fixed codes;
  - one-language sites ran a meta query per permalink; page lists of other post types were emptied; the default language ignored the network's language.
- **The main site's home page in Spanish, German and Italian** (asked by the maintainer on 2026-11-10, published locally): `scripts/home-page-translations.json` holds the 109 texts per language, keyed by the English ones, plus each title, excerpt and menu; `scripts/home-page-translations.php` adds the languages, publishes the translations (`/es/`, `/de/`, `/it/`), and gives each language a menu and a header copy that uses it. Translated by Claude, not yet read by native speakers. Not translated yet: the signup chat (`mody/register` has no text settings; M1-04 part C), the site's tagline in the title and footer, and WordPress's own words (part 9). Found on the way: the menus are dated before the English one, because the header's navigation without a menu of its own shows the newest; `scripts/home-page.php` now skips menus with a language; on phones the switcher shows ES, DE, IT (the names stay for screen readers).
- **Part 11, the working language** (built 2026-11-10): checked over HTTP and in a browser (the Pages list with Spanish chosen: the toolbar, the language bar, the Languages column; the Site Editor's page list showing only "Inicio"). Fixed while building: the switch address was HTML-escaped (the editors' menu uses it as it is); Quick Edit's parent choices listed every language; the agent template test assumed one language on the main site. **Review** (two reviewers, every finding verified; 8 confirmed, 10 refuted), all fixed and in the test:
  - menu links translate both ways: a link to a translation (which the filtered link search offers) leads default-language visitors to the original;
  - the Trash is not filtered, so Empty Trash never deletes items the list hid;
  - a switch without a way back lands on the dashboard (it was a blank page);
  - every post is in exactly one list, and the counts add up;
  - the toolbar's Language item links to Settings → Languages for administrators only; the column offers + only to users who may create the translation, and edit links only to users who may edit it;
  - the counts leave out other authors' private posts for users who cannot read them.
- **Test:** `scripts/languages-test.php`, 78 checks, all passing (sections 6 and 9 cover part 11).
- **Other tests:** registration, members, gating, spectra-layout, spectra-blocks, agent-edit and agent-template pass. `editor-navigation-test` fails 4 checks with or without the plugin (the post list templates; not this ticket), and a fifth, model-dependent check varies between runs.
