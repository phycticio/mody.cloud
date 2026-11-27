# Spanish by default: mody.cloud in Spanish, Catalan and English, and every Mody screen in Spanish

**Builds on:** [site-languages.md](site-languages.md) (part 9, WordPress's own translations), [signup-chat-languages.md](signup-chat-languages.md) (Q2, Q3, Q5), [free-mvp.md](free-mvp.md) (sign-ups from Spain only) · **Feeds:** the launch ([M5-01](M5-01-hardening-launch.md)), [product-pages-bari-and-plugins.md](product-pages-bari-and-plugins.md) (already planned in English, Spanish and Catalan) · **Status:** planned on 2026-11-26; Q1 to Q6 answered 2026-11-27 · **Issue:** [#54](https://github.com/phycticio/mody.cloud/issues/54)

## Goal (maintainer, 2026-11-26)

> Make spanish the default language for everything and mody.cloud needs to be in three languages ESP, CAT, ENG.

Answers the same day, to the questions asked before this ticket ("Go ahead with your recommendations"):
- **D1.** mody.cloud has exactly three languages: Spanish (the default), Catalan and English. German and Italian go.
- **D2.** "Everything" is the main site, members' sites and every Mody screen, not only mody.cloud.
- **D3.** (Not this ticket: two-step login stays optional for everyone and required for super admins, [security-plugin-replaces-wordfence.md](security-plugin-replaces-wordfence.md) Q2.)

## What exists today (checked 2026-11-26)

- **The main site on production** is English, with Spanish, German and Italian translations of the home page (`/es/`, `/de/`, `/it/`): `scripts/home-page.php` writes the English page, `scripts/home-page-translations.php` the others from `scripts/home-page-translations.json` (keyed by the English texts; languages `es_ES`, `de_DE`, `it_IT`).
- **The local main site** was switched by hand: `WPLANG` is `es_ES` and `mody_languages` is `["en_US","ca"]`. Its pages without a language still hold the English home page, so locally English text is now counted as Spanish, and the Spanish translation is orphaned. Core's Spanish files were downloaded into `app/web/languages/` (2026-11-21, 4.5 MB, untracked, through wp-admin or WP-CLI); WP-CLI lists only `en_US` as installed.
- **WordPress's own words** are English everywhere: no language pack is part of the build or the deploy (site-languages part 9, not built). `Updates` refuses `update_languages`, so nobody installs one from wp-admin.
- **Mody's own words:** about 1,400 strings in the `mody_cloud` text domain across 20 Mody plugins (most in Mody Agent, Mody Security, Mody Dashboard, Mody Members, Mody Brand and Mody Sites), plus the plain JS files that receive theirs from PHP. Only the signup has translations (`mody-members/languages/`, seven languages, `SignupLanguage`, loaded through gettext filters because core's language may not be installed).
- **Mody the agent** already answers in the language of the owner's last message (`SystemPrompt`, rule 5 of its reply rules). That conflicts with rule 8's "agent replies" in English.
- **New member sites** start in English (`WPLANG` empty); the account's language is kept only once WordPress has the language (signup-chat-languages Q2). The starter page is English.

## Plan

Rule 5: each part is about one sitting. Parts 1 to 3 make the main site Spanish; 4 and 5 the members' experience; 6 the rest of Mody's screens.

### Part 1 · Rule 8 says what it covers
- Rule 8 (`AGENTS.md`, [constraints.md](../documentation/constraints.md)): code, comments, documentation, commit messages and prompts to the model stay English; what members and visitors read (pages, screens, emails, Mody's replies) is in their language, Spanish by default.
- **Inference:** this narrows rule 8 as the maintainer asked; the briefing's Spanish convention stays overridden for the repository.

### Part 2 · WordPress's own translations at build time (site-languages part 9)
- `bin/languages`: downloads the language packs for `es_ES` and `ca` (core, admin, network admin, and the plugins and theme WordPress.org translates: `ai`, Spectra, Antispam Bee) from WordPress.org's translation API, for the exact versions in the repository, into `app/web/languages/`. No new dependency (curl and unzip in the app container).
- Runs on the maintainer's machine or in `bin/build`, never from wp-admin; nothing on the server calls WordPress.org.
- How the files reach production is Q1.
- Test: the packs load (`wp language core list` shows them installed, `switch_to_locale( 'es_ES' )` translates "Dashboard").

### Part 3 · mody.cloud in Spanish, Catalan and English
- `scripts/home-page.php` writes the default page in Spanish; English becomes a translation (`/en/`), Catalan a new one (`/ca/`). German and Italian are removed: their pages, menus and header copies deleted, their addresses sent to the Spanish page with a 301.
- `scripts/home-page-translations.json`: `de_DE` and `it_IT` go, `ca` comes (Claude's translation, for a native read). **Inference:** the JSON stays keyed by the English texts (they are the source in the script, rule 8), and the script applies Spanish to the page with no language.
- The main site: `WPLANG` `es_ES`, other languages `en_US` and `ca`. `/register/`: the Spanish page, with `/en/register/` and `/ca/register/` (signup-chat-languages Q4).
- The product pages script ([#42](https://github.com/phycticio/mody.cloud/issues/42)) follows the same default.
- The legal pages in Spanish (the default), Catalan and English, written by Claude for the adviser's read (Q3).

### Part 4 · New members and their sites in Spanish
- The network's default language (`WPLANG` network option) `es_ES`, so sites made any other way (the wizard, WP-CLI) start Spanish. The starter page in the site's language.
- Existing member sites switched to Spanish (Q4), on production with the maintainer.
- A new account and its site start in the language the person signs up in (the page's: Spanish, Catalan or English; Q4); Spanish when it is none of the installed ones. The account's `locale` and the site's `WPLANG`.
- The welcome email, the editor tour and the Dashboard follow from parts 2 and 5.
- Existing member sites: Q4.

### Part 5 · Mody's screens in Spanish: the tooling and what members see
- Each Mody plugin carries `languages/mody_cloud-<locale>.l10n.php` (and `.po` as the source), loaded with `load_muplugin_textdomain()` (or `load_plugin_textdomain()` when it runs as a normal plugin), so it still runs alone. The signup's gettext filters stay for languages core does not have.
- `scripts/i18n.sh`: `wp i18n make-pot` per plugin, merge into its `.po`, `wp i18n make-php`; a test that every plugin's Spanish file loads and keeps its placeholders (like `scripts/signup-languages-test.php`).
- Spanish first for what members see: Mody Dashboard, Mody Brand, Mody Onboarding (the tour), Mody Agent (the bubble), Mody Members, Mody Profiles, Mody Login, Mody Export, Mody Domains, Mody Languages, Mody SEO, Mody Styles, Mody Analytics, Mody Locks, Mody Images, Mody Fonts, Mody Patterns. Claude's translation, for a native read.
- A headless browser look at the Dashboard, Settings → Brand and the editor tour in Spanish.

### Part 6 · The super admins' screens in Spanish, and Catalan everywhere
- Spanish for Mody Security, Mody Health, Mody Sites (the wizard), Offers and Mody Billing.
- Catalan for every Mody screen (Q2).

## Open questions

- ~~**Q1. How the language packs reach production.**~~ Decided on 2026-11-27: **committed** under `app/web/languages/`, so a deploy never depends on WordPress.org.
- ~~**Q2. Catalan for Mody's screens.**~~ Decided on 2026-11-27: **every screen** in Catalan too.
- ~~**Q3. The legal pages.**~~ Decided on 2026-11-27: **write them now** in Spanish (the default), Catalan and English, for the adviser to read.
- ~~**Q4. Existing member sites.**~~ Decided on 2026-11-27: **switch them** to Spanish. New sign-ups start in the language the person signs up in (the page's: Spanish, Catalan or English), not always Spanish.
- ~~**Q5. The signup chat's other five languages.**~~ Decided on 2026-11-27: **kept**.
- ~~**Q6. The Mody pattern collection's texts.**~~ Decided on 2026-11-27: **left to Mody**, which rewrites them in the brand's language.

## Results

**Part 1, 2026-11-26** (`docs/#54-spanish-by-default-plan`): rule 8 narrowed in `AGENTS.md`, [constraints.md](../documentation/constraints.md), [mvp-spec.md](../documentation/mvp-spec.md) (the non-goal marked superseded), [documentation/README.md](../documentation/README.md) and the `mody-plugin` skill.

**Part 2, 2026-11-27** (`feature/#54-spanish-by-default`): `bin/languages` downloads, for the exact versions in the repository (core 7.1.4, `app/composer.lock`), WordPress.org's Spanish and Catalan packs for core (front end, admin, network admin, the editor's JavaScript) and the Composer plugins it translates: the `ai` plugin (Spanish only) and Antispam Bee (both). Spectra, MCP Adapter and the Ollama provider have none. It keeps the `.l10n.php` and `.json` files (3.3 MB for both languages, committed), checks that every PHP file is a plain array, and replaces `app/web/languages/` only when core came down. The maintainer's earlier download (2026-11-21, Spanish only, with `.po` and `.mo`) was set aside, not committed. Test: `scripts/language-packs-test.php` (14 checks, passing).

**Part 3, 2026-11-27, local:**
- `scripts/home-page-translations.php` rewritten: the English source from `scripts/home-page.php` becomes the `/en/` translation, Catalan `/ca/` (107 texts, Claude's, in the JSON), and the page itself the Spanish texts; the default menu gets Spanish labels, `/en/` and `/ca/` their own menus and header copies; languages not in the JSON are removed from the site and their pages, menus and header copies go to the trash; the legal links point at each language's legal pages; `/register/` gets its Spanish title and translations at `/en/sign-up/` and `/ca/registre/`. Run twice locally: no duplicates.
- The languages FAQ on the page no longer says the editor is in English: "your site starts in the language you sign up in…".
- **Mody Languages:** a 404 whose first segment is the prefix of an offered language the site does not have goes, with a 301, to the same address without it (`Router::redirect_missing_language()`): `/de/`, `/it/` and `/es/` go to `/`, `/de/foo/` to `/foo/`.
- **The signup** (`SignupLanguage`): the page's language is Mody Languages' current one, so the default page is Spanish; a chosen language also switches WordPress's own locale (to English when the language is not installed), so an English visitor on `/en/` gets core's words in English, not the site's Spanish; English is kept as `en_US` for the account.
- **The legal pages** (`scripts/legal-pages.php`, Q3): every sentence written once in English, Spanish and Catalan side by side; Spanish at `/legal-notice/`, `/terms/` and `/privacy/` (so `wp_page_for_privacy_policy` keeps its page), Catalan at `/ca/avis-legal/`, `/ca/condicions/`, `/ca/privadesa/`, English at `/en/legal/`, `/en/terms-of-service/`, `/en/privacy-policy/`; dates and numbers in each language; links between them in the same language; formal register (usted, vostè); the Catalan and English versions say the Spanish one prevails (**Open question** for the adviser). All nine are built and checked before anything is saved; a second run makes no duplicates; drafts until the seller's four details are set, as before. Claude's texts, for the adviser's read ([legal-pages.md](legal-pages.md)); the words to look at first are in that ticket.
- The home page's legal links point at the legal pages in its own language (`/en/legal/`, `/ca/avis-legal/`).
- Checked with curl: `/` is `lang="es"` with the Spanish page and chat, `/en/` `en-US`, `/ca/` `ca`; `/register/`, `/en/sign-up/` and `/ca/registre/` the same.

**Part 4, 2026-11-27, local (started):** a new site takes its owner's account language as `WPLANG` (`Pipeline::language_option()`; `en_US` gives English), else the network's default (`es_ES`); the starter page is written in the site's language. The account's language is the one the person signs up in (`SignupLanguage::for_account()`).

**Found on the way (2026-11-27):** on a Spanish network, core makes a new site's sample post and page in Spanish (`hola-mundo`, `pagina-ejemplo`), and `Pipeline` deleted only the English slugs, so new member sites kept them. Fixed: the post and page core makes first on a new site (IDs 1 and 2) are deleted whatever their language. `scripts/provisioning-test.php` checks it without relying on slugs.

**Tests (2026-11-27):** the suite assumed an English network. 21 tests were updated so a check that reads English text asks for English (a user's `locale`, `wp_lang`, `switch_to_locale('en_US')`, `Router::set_current('en_US')`), and checks about the default language expect Spanish; new checks cover the signup's language, the account's and site's language, and the language packs. Not language: `security-test`'s rate-limit counts used a sliding window (made fixed), `agent-template-test` used `core/code`, a page block since 2026-11-11 (now `core/html`). The export tests run on a member site (`--url=https://alpha.mody.local`), as their headers say.

## Production steps (with the maintainer, after the merge and the deploy)
1. The network's default language and the main site's: `./bin/wp site option update WPLANG es_ES`, then `./bin/wp option update WPLANG es_ES` (main site).
2. The main site's pages: `scripts/home-page.php`, then `scripts/home-page-translations.php` (with the JSON copied into the container), then `scripts/legal-pages.php`. German and Italian go to the trash; check `/`, `/en/`, `/ca/`, `/en/sign-up/`, and that `/de/` and `/es/` answer 301.
3. Existing member sites to Spanish (Q4): `./bin/wp --url=<site> option update WPLANG es_ES` for each member site.
4. A look in the browser: the home page in the three languages, wp-admin in Spanish.
