# Product pages: the Bari stack and nine Mody plugins

**Builds on:** [independent-mody-plugins.md](independent-mody-plugins.md), [site-languages.md](site-languages.md), the home page script (`scripts/home-page.php`) · **Feeds:** Mody Cloud's main site · **Status:** planned on 2026-11-21; parts 1, 2 and 5 built the same day (drafts, not deployed; Mody SEO's page waits for the maintainer's review) · **Issue:** [#42](https://github.com/phycticio/mody.cloud/issues/42)

## Goal (maintainer, 2026-11-21)
> I want to create product pages for our stack called Bari and all mody plugins. Bari stack is this stack used by mody.cloud which should work for single and multisites. All mody plugins are all the plugins that we are using with the exception of the chatbot.

Then narrowed the same day to Bari and nine plugins: Security, Analytics, Dashboard, Images, SEO, Profiles, Fonts, Patterns and Palette.

## Decisions (maintainer, 2026-11-21)
- **D1. Bari is a stack,** with its own landing page at `mody.cloud/bari`.
- **D2. Each plugin is a "plugin",** at `mody.cloud/plugins/<plugin>` (D18; the plan said `/plugin/<plugin>`): `security`, `analytics`, `dashboard`, `images`, `seo`, `profiles`, `fonts`, `patterns`, `palette`.
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
- **D17. Support is GitHub issues** (which repository: Q9, answered by D20).

Answers to the first review (maintainer, 2026-11-21):
- **D18. Everything under `/plugins/`,** no `/plugin/` and no redirect: the catalog is `/plugins/`, and each plugin's page is its child, `/plugins/<slug>/`.
- **D19. Requirements:** "WordPress 7.1 (tested)" is right for the first version; more versions are tested later.
- **D20. Support:** issues on GitHub until the code moves to Codeberg. The repository is this one, `phycticio/mody.cloud`, until a `bari` repository exists (**Inference** from "GitHub issues": the `bari` repository does not exist yet).
- **D21. The update check's request** (part 5): the recommendation written in part 5, accepted by the maintainer the same day ("Go ahead with your recommendation"): its own User-Agent, a plain GET of `latest.json`, one request per site shared by every plugin, and no access log on `mody.cloud` for the downloads.

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
  - Mody Languages refuses some top-level slugs (`reserved_slug`) and finds pages by path. Translated child pages (`/es/plugins/seo/`) are not tried yet.
- **GitHub is a US service** (Microsoft). The repository already lives there, and AGENTS.md avoids GitHub's CI because its runners are on Azure. A download button on `mody.cloud` that sends visitors to `github.com` is new: see Q1.

## Plan
Each part is about one sitting (rule 5). Parts 1 to 7 can start now. Parts 9 and 10 wait on Q10, and the theme's place on Q8.

1. **The page builder and the catalog.**
   - `scripts/product-pages.php`, run with `eval-file` on the main site like `scripts/home-page.php`. It builds:
     - `/plugins/`: the catalog, with Bari first, then the nine plugins as cards: name, one line, single site and multisite, "needs" where one applies;
     - each `/plugins/<slug>/`, a child of the catalog (D18);
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
   - **Decided (D21):**
     - **Its own User-Agent.** WordPress's HTTP API sends `WordPress/<version>; <home URL>` by default, so the check sets a fixed `Mody-Update-Check`, with no version and no address.
     - **A plain request:** a GET of the one static `latest.json`, with no query, no cookies and no other headers; HTTPS with the certificate checked.
     - **One request per site, not one per plugin:** every copy of the class shares one transient, so a site with all nine plugins still asks at most twice a day.
     - **On `mody.cloud`:** Nginx keeps no access log for `latest.json` and the zips. The only thing left is the server's own address in the connection, and Cloudflare sees it while it stays in front (each page says so).
   - It is off on Mody Cloud's own network, where versions come only through the repository (Mody Updates).
6. **The other eight plugin pages,** one or two a sitting, each with screenshots taken from the local stack with the headless browser (D8) and uploaded to the main site's media library.
7. **Spanish and Catalan:**
   - Spanish and Catalan become languages of the main site.
   - The translations go in `scripts/product-pages-translations.json`, keyed by the English texts, like `scripts/home-page-translations.json`.
   - Each page becomes a Mody Languages translation (`/es/plugins/seo/`, `/ca/plugins/seo/`).
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
- ~~**Q9. Which repository takes the issues.**~~ **Answered on 2026-11-21 (D20):** GitHub issues until the move to Codeberg, on this repository for now.
- **Q10. Does the `bari` repository also carry the nine plugins,** or only the stack and the theme, with the plugins downloaded on their own?

## Results

### Part 5: the update check (2026-11-21, not deployed)
**Built:**
- **`UpdateCheck`,** one class with an identical copy in each of the nine plugins (`mody-<name>/src/UpdateCheck.php`; only the namespace differs). Each plugin's main file registers it, and each header now says `Update URI: https://mody.cloud/` instead of `false`. Mody Analytics' main file got the plugin header it lacked (Version 1.0.0).
- **How it works:**
  - WordPress's own plugin update check, about twice a day, asks the `update_plugins_mody.cloud` filter about each plugin whose Update URI is on mody.cloud.
  - The class answers from `https://mody.cloud/web/uploads/downloads/latest.json`: one `wp_safe_remote_get()` with the User-Agent `Mody-Update-Check`, no query, no cookies, no redirects, a 5-second timeout.
  - The answer is cached in one site transient (`mody_update_check`, 12 hours) that every copy shares, so a site asks at most twice a day however many Mody plugins it has. A failed request waits as long.
  - Only a version and a zip or page on `https://mody.cloud` are trusted.
  - The update notice's "View details" is answered from the same file (`plugins_api`), so WordPress does not ask WordPress.org about a plugin with the same name.
- **`latest.json`** is keyed by the plugin's folder: `{"plugins": {"mody-seo": {"version", "package", "url", "requires", "tested", "requires_php", "changelog"}}}`. Part 4's packaging writes it. Until it exists, the request gets a 404 and nothing is offered.
- **Never on Mody Cloud's network:** the plugins are MU plugins there, which core never checks. The class also does nothing when Mody Updates is loaded.
- **Nginx:** `location ~* ^/web/uploads/downloads/` with `access_log off` (`config/nginx/app.conf.template`). Checked with `nginx -t` on a copy of the running configuration; not reloaded.
- **"Take your site with you"** carries five of the nine (Images, Palette, Patterns, Profiles, SEO). A downloaded site now gets their update notices from mody.cloud too, as D14 says each plugin does. `scripts/export-archives-test.php` expects `https://mody.cloud/` for those and `false` for the rest.

**Found while testing:** WordPress core's own update check sends WordPress.org the header of every installed plugin, Mody's included, with its Update URI. WordPress.org leaves a plugin whose Update URI points elsewhere alone. That request is core's, on every WordPress, with or without our plugins.

**Tests:** `scripts/update-check-test.sh` (new; this checkout's plugin folders, so it does not depend on the checkout the stack serves). It builds a plain single-site WordPress with the Mody theme and Mody SEO, Images and Palette, and answers mody.cloud with PHP's built-in server, which writes down the headers that arrive. 57 of 57 passed:
- the nine copies are the same class with the right header;
- one request for three plugins, exactly as described; on the wire, the User-Agent and nothing about the site (no address, no WordPress version, no cookie, no referrer);
- Mody SEO 1.0.1 offered, Mody Images up to date, a zip elsewhere ignored;
- no second request within 12 hours; the details popup without any request;
- a missing `latest.json` gives no update and also waits;
- with Mody Updates present, nothing is asked;
- no PHP notice.

**Not run:**
- the platform's tests for these nine plugins and `export-archives-test.php`: the local stack serves the other checkout, so they would test its code, not this branch's;
- `php -l` passed on every changed file.

### Parts 1 and 2: the page builder, the catalog and Mody SEO's page (2026-11-21, not deployed)
**Built:**
- **`scripts/product-pages.php`,** run with `eval-file` on the main site after `scripts/home-page.php`, as its header says (the JSON and the screenshots are copied into the container first). It finds each page by its path and replaces its content, so a rerun with nothing changed leaves the pages as they are (their `spectraId`s are kept). Every page uses the template `page-blank`, Spectra containers, palette slugs and the theme's gradients (rules 2 and 3).
- **The texts** are in `scripts/product-pages.json` (English): the catalog, a card for Bari and for each of the nine plugins (name, one line, "Single site and multisite", "Needs" for Dashboard and Patterns), and Mody SEO's whole page. A plugin with a `page` gets `/plugins/<slug>/`.
- **The catalog, `/plugins/`:** a hero, then Bari's card ("Multisite only, for now", D11), then the nine cards. Each card links to its page once that page is published; while the catalog is a draft, it links to the draft's preview, for review. The footnote says each plugin checks `mody.cloud` for updates.
- **No `/plugin/` (D18):** the plugin pages are children of the catalog, and nothing redirects. WordPress's own 404 guessing (`redirect_guess_404_permalink`) still answers a typed `/plugin/` or `/plugin/seo/` with a 301 to the closest published page (`/plugins/`, `/plugins/seo/`); that is core's, for every address, and no code of ours.
- **Mody SEO's page, `/plugins/seo/`,** in the order of part 2:
  - the hero, with the download button (`uploads/downloads/mody-seo-1.0.0.zip`) and the first screenshot;
  - why;
  - four features;
  - "What it calls": nothing while it works; `mody.cloud` for updates, at most twice a day, with nothing about the site (D14, part 5); and that Cloudflare sees that request while it is in front of `mody.cloud`;
  - two screenshots;
  - requirements;
  - how to install (the upload screen and WP-CLI);
  - "Built into every Mody Cloud site", with the signup;
  - seven questions, among them where to report a problem;
  - the version and license line, with links to the catalog and to the issues on GitHub (D20).

  Its SEO title and description are set (Mody SEO).
- **Publishing:** a plugin's page is published only when the JSON says `"ready": true`, its zip is in the main site's `uploads/downloads/`, and its screenshots are in the media library. Otherwise it stays a draft and the script says what it waits for. The catalog is published once one product page is. Today everything is a draft: Mody SEO waits for the review, its zip (part 4) and the update check its page describes (part 5).
- **Screenshots (D8):** `scripts/product-pages/screenshots/mody-seo-panel.png` and `mody-seo-empty.png`, taken in headless Chromium from the Site Editor on a local member site (two throwaway pages, deleted afterwards). Mody's chat bubble and the `ai` plugin's Editorial Notes button were hidden, because a plain WordPress with Mody SEO has neither. The script imports each PNG into the main site's media library once (Mody Images makes it a WebP) and finds it again by the post meta `mody_product_shot`.
- **The menu (D15):** an **Our source code** dropdown (a `navigation-submenu` with the class `mody-source-code`) before Log in, with Overview (the catalog) and every published product page. It is there only while the catalog is published. `scripts/home-page.php` now keeps it when it rebuilds the menu. The translated menus (`home-page-translations.php`) do not have it yet (part 7).

**Checked locally:**
- Both pages open in the real block editor (`scripts/editor-check.mjs`) with no invalid or unknown blocks: 117 blocks on Mody SEO's page, 62 on the catalog. The editor only reorders attributes.
- Screenshots in headless Chromium: the drafts at 1440 and 390 pixels wide. Then, published for the test (`"ready": true` and a dummy zip, removed afterwards), as a visitor: the dropdown with Overview and Mody SEO, and the catalog's link.
- `/plugins/` and `/plugins/seo/` answered 200, and the page's head had its SEO title and description. After D18 the test ran again: the dropdown's links, the catalog's Read more and the page's GitHub link point to the new addresses, and the page opens in the block editor (123 blocks, none invalid).
- Back to drafts: the three addresses are 404, and the dropdown is gone.
- `scripts/home-page.php` run again: the dropdown stays.

**Not done yet:**
- **The maintainer's review of Mody SEO's page** (part 2), before the other eight.
- **Other WordPress versions** are tested later (D19); the page names 7.1 only.
- **Production:** the scripts have not run there.
- **For part 5:** WordPress's HTTP API sends the site's address in its default `User-Agent` (`WordPress/<version>; <home URL>`). So "no site address" needs the update check to set its own User-Agent.
