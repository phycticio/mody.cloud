# Product pages: the Bari stack and nine Mody plugins

**Builds on:** [independent-mody-plugins.md](independent-mody-plugins.md), [site-languages.md](site-languages.md), the home page script (`scripts/home-page.php`) · **Feeds:** Mody Cloud's main site · **Status:** planned on 2026-11-21 · **Issue:** [#42](https://github.com/phycticio/mody.cloud/issues/42)

## Goal (maintainer, 2026-11-21)
> I want to create product pages for our stack called Bari and all mody plugins. Bari stack is this stack used by mody.cloud which should work for single and multisites. All mody plugins are all the plugins that we are using with the exception of the chatbot.

Then narrowed the same day to Bari and nine plugins: Security, Analytics, Dashboard, Images, SEO, Profiles, Fonts, Patterns and Palette.

## Decisions (maintainer, 2026-11-21)
- **D1. Bari is a stack,** with its own landing page at `mody.cloud/bari`.
- **D2. Each plugin is a "plugin",** at `mody.cloud/plugin/<plugin>`: `security`, `analytics`, `dashboard`, `images`, `seo`, `profiles`, `fonts`, `patterns`, `palette`.
- **D3. Bari wears Mody Cloud's branding** with a small, different logo. The maintainer provides the logo; a placeholder until then.
- **D4. Only these nine plugins are offered.** Every other Mody plugin (Sites, Members, Billing, Domains, Storage, Locks, Health, Onboarding, Brand, Styles, Export, Languages, Login, Mail, Updates, Spectra, Antispam, Admin Colors, and the agent) gets no page.
- **D5. Only plugins that can live on their own:** each runs on a plain WordPress, single site or multisite, without the rest of Mody.
- **D6. The names stay:** Mody Security, Mody Analytics and so on.
- **D7. Languages:** English, Spanish and Catalan.
- **D8. Screenshots** are taken from the local stack with the headless browser.
- **D9. The main button is a free download,** GPL. Since the second round of answers (D12), the zips live on `mody.cloud`, not GitHub.
- **D10. The catalog lives at `mody.cloud/plugins`.**
- **D11. Bari gets single-site support first,** and only then does its page say it works on single sites.

Answers to Q1 to Q6 (maintainer, 2026-11-21):
- **D12. The zips live in the main site's uploads directory on `mody.cloud`.** The maintainer may also publish the plugins to Packagist, so Composer can install them (see Q7).
  - Checked 2026-11-21: Nginx already serves zips from uploads (`config/nginx/app.conf.template`: archives are refused only next to code and built assets), and `bin/deploy` excludes `app/web/uploads/`, so a deploy never removes them.
- **D13. Bari is a new public `bari` repository.**
- **D14. Every plugin starts at 1.0.0.** Each one checks `mody.cloud` for a newer version and shows WordPress's usual update notice. It is an outgoing call to our own server in Europe, and every plugin page says so.
- **D15. Navigation:** a dropdown in the main site's header menu named **Our source code**.
- **D16. The theme:**
  - The Mody theme, with its default values, becomes a download of its own.
  - Mody Patterns' page says it needs that theme, or one with the same palette slugs (`primary`, `secondary`, `accent`, `base`, `contrast`).
  - Bari includes the theme with its default values.
- **D17. Support is GitHub issues** (which repository: Q9).

## What exists today (checked 2026-11-21)
- **Plugin headers:** each of the nine has a plugin header with `License: GPL-2.0-or-later`. Six say `Version: 1.0.0`; Analytics, Fonts, Patterns and Palette have no version on their loader (**Inference** from a grep of the headers: check while packaging). The repository has no `LICENSE` file.
- **Standalone status:** what each plugin still needs before its zip is honest (rule 7):

  | Plugin | Needs before it lives on its own |
  | --- | --- |
  | Security | Its settings exist only on **Network Admin → Mody → Security** (`network_admin_menu`), so a single site has no page. ALTCHA loads `app/dist/altcha.js` from the platform's build (`WP_CONTENT_DIR . '/../dist/'`, `WP_HOME . '/dist/'`), which a plain WordPress does not have. The file check reads the list `bin/deploy` writes. |
  | Analytics | Country and city need DB-IP's file in `app/data/geoip/`, put there by `bin/geoip` (not in git). Without it there is no location (**Inference**: check that it degrades cleanly). |
  | Dashboard | The board loads `app/dist/analytics-board.js` from the platform's build. The board needs Analytics. The analyst needs the agent (already behind `class_exists`). Your sites and Your plan are about Mody Cloud accounts. |
  | Images | Nothing found. The WP-CLI command handles multisite. |
  | SEO | Nothing found. Languages are optional (`class_exists`). |
  | Profiles | Single-site behavior exists (`is_multisite()` checks); not tried on a plain site. |
  | Fonts | Nothing found. |
  | Patterns | The collection uses the theme's palette slugs (`primary`, `secondary`, `accent`, `base`, `contrast`) and fonts. On another theme, colors may be missing. |
  | Palette | Nothing found. Spectra's pickers are optional. |

  `scripts/export-portable-test.sh` already runs Mody plugins on a plain single-site WordPress. It is the model for the checks in part 3.
- **The stack and single site:** multisite is already a switch in `app/wp-config.php` (`WORDPRESS_MULTISITE`, `WORDPRESS_SUBDOMAIN_INSTALL`; `SUNRISE` only inside `if ( MULTISITE )`). Nobody has run the stack single site (**Inference**). Nginx's templates, `bin/` (some scripts are stale), `sunrise.php` and the docs all assume a network. The stack also carries Mody Cloud itself: production `compose`, the backup service, deploys to home.local.
- **The main site today (locally):**
  - Pages: the home page and its copies (`home-es`, `home-de`, `home-it`, `home-en`), legal pages, `how-it-works`, `pricing`, `digital-sovereignty` and `register`.
  - `mody_languages` is `en_US, ca`.
  - Mody Languages refuses some top-level slugs (`reserved_slug`) and finds pages by path. Translated child pages (`/es/plugin/seo/`) are not tried yet.
- **GitHub is a US service** (Microsoft). The repository already lives there, and AGENTS.md avoids GitHub's CI because its runners are on Azure. A download button on `mody.cloud` that sends visitors to `github.com` is new: see Q1.

## Plan
Each part is about one sitting (rule 5). Parts 1 to 7 can start now. Parts 9 and 10 wait on Q10, and the theme's place on Q8.

1. **The page builder and the catalog.**
   - `scripts/product-pages.php`, run with `eval-file` on the main site like `scripts/home-page.php`. It builds:
     - `/plugins/`: the catalog, with Bari first, then the nine plugins as cards: name, one line, single site and multisite, "needs" where one applies;
     - the parent page `/plugin/`, which redirects to `/plugins/`;
     - each `/plugin/<slug>/`;
     - `/bari/`.
   - The texts live in one data file, `scripts/product-pages.json` (English).
   - The pages use Spectra containers and palette colors only (rules 2 and 3).
   - Pages that are not ready stay drafts.
   - A link to the catalog in the header or footer menu (Q4).
2. **The pilot: Mody SEO's page,** the plugin with the fewest dependencies, in the shared template:
   - the hero and the download button;
   - the problem it solves;
   - three to five features;
   - "What it never calls" (sovereignty);
   - screenshots;
   - requirements (WordPress and PHP versions; single site, multisite);
   - how to install;
   - "Built into every Mody Cloud site";
   - FAQ, version and license.

   The maintainer reviews it before the other eight.
3. **Standalone readiness,** one small fix per plugin from the table above:
   - Security: a site's own Settings → Security when not a network, and its ALTCHA script shipped inside the plugin.
   - Dashboard: its script shipped inside the plugin.
   - Analytics: runs without the location file.
   - Patterns: its page names the theme it needs (D16).

   A check on a plain WordPress, single site and multisite, per plugin, modeled on `scripts/export-portable-test.sh`. A page goes public only once its plugin passes.
4. **Packaging and downloads (D12):**
   - `bin/package <slug>` builds `mody-<slug>-<version>.zip`: the folder, its built assets, a `readme.txt` and the GPL `LICENSE`. The same script builds the theme's zip with its default values (D16).
   - The zips go to a fixed folder in the main site's uploads, such as `uploads/downloads/` (**Inference**: the exact path).
   - A small `latest.json` next to them gives each product's version, zip and changelog.
   - Uploading to production is the maintainer's step, or an option of the script he runs.
5. **The update check (D14):** a small class, copied into each plugin so each still runs alone:
   - It asks `mody.cloud`'s `latest.json` at most twice a day and feeds WordPress's update notice.
   - It sends nothing but the request itself: no site address, no version in the query.
   - It is off on Mody Cloud's own network, where versions come only through the repository (Mody Updates).
6. **The other eight plugin pages,** one or two a sitting, each with screenshots taken from the local stack with the headless browser (D8) and uploaded to the main site's media library.
7. **Spanish and Catalan:**
   - Spanish and Catalan become languages of the main site.
   - The translations go in `scripts/product-pages-translations.json`, keyed by the English texts, like `scripts/home-page-translations.json`.
   - Each page becomes a Mody Languages translation (`/es/plugin/seo/`, `/ca/plugin/seo/`).
   - The translated child pages are checked first, and so is the **Our source code** menu in each language.
   - A native speaker reads the translations (the maintainer).
8. **Bari single site (D11),** its own ticket when it starts:
   - The stack runs with `WORDPRESS_MULTISITE=false`: Nginx, `wp-config.php`, `bin/wp`, the Mody plugins, and a `sample.env` for single site.
   - It is documented in local-development.md.
9. **The `bari` repository (D13),** its own ticket:
   - The stack without Mody Cloud's own operations: no production files, no deploys to home.local, backups optional.
   - Stale scripts out.
   - The theme with its default values (D16).
   - A README, the GPL license, issues on.
   - Creating the public repository on GitHub is the maintainer's step.
10. **Bari's page:**
    - The stack: Docker, Nginx, PHP-FPM, MariaDB, Redis, Mailpit, the Composer layout, the `bin/` tools, deploys with a file list, backups.
    - Single site and multisite (after part 8).
    - The theme.
    - The logo from the maintainer (D3).

## Open questions
- **Q7. Composer:** Packagist only lists a package. It reads the package from a code repository, usually GitHub, and by default Composer downloads the zip from GitHub (**Inference**: check before choosing). For Composer installs that never touch a US service, `mody.cloud` could serve its own Composer repository: a `packages.json` that lists the same zips in uploads. Which one, if any?
- **Q8. Where the theme's download shows:** the Bari page, Mody Patterns' page, and the **Our source code** menu? It has no page of its own (D4).
- **Q9. Which repository takes the issues:** the new `bari` repository for everything, or this one (`phycticio/mody.cloud`)?
- **Q10. Does the `bari` repository also carry the nine plugins,** or only the stack and the theme, with the plugins downloaded on their own?

## Results
Not started.
