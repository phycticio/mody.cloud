# Every Mody plugin is an independent plugin

**Builds on:** the `mody-seo` split ([seo.md](seo.md), 2026-11-16) · **Status:** decided by the maintainer on 2026-11-16, documentation changed the same day. The three questions answered on 2026-11-17 ([Decisions](#decisions-2026-11-17)). **Built on 2026-11-17 on `feature/independent-mody-plugins`, merged and deployed the same day (`6db8aaf`): `mody-platform` is gone, 22 new plugins** ([Results](#results)).

## Goal (maintainer, 2026-11-16)
> Every plugin should be an independent plugin. Some mody plugins should be MU and some not. For now all the things we have available are MU plugin.

## The rule
This replaces "platform logic lives in one MU plugin" (`mody-platform`) and its six exceptions (`mody-mail`, `mody-languages`, `mody-analytics`, `mody-images`, `mody-seo`, and the rule of 2026-11-16 that each Mody plugin does one thing).

- **One thing each.** A Mody plugin takes care of one single thing (the rule of 2026-11-16, [seo.md](seo.md)).
- **Independent.** It runs with no other Mody plugin present. When another Mody plugin is there, it may do more, but it never needs it. See [What "independent" means](#what-independent-means).
- **Every Mody plugin is a normal plugin** (D1): it is built as one, with a header, and runs on any WordPress, activated like any plugin.
- **Some are loaded as MU plugins** (D2): plugins for performance, for languages and global plugins (what must hold on every site of the network, with no switch). **Every Mody plugin that exists today is loaded as a MU plugin.**

## What "independent" means
Each Mody plugin has, like `mody-seo` today:

1. **Its own folder and loader:** `app/web/mu-plugins/mody-<name>/`, loaded by `app/web/mu-plugins/mody-<name>.php` (WordPress loads only the top-level files of `mu-plugins/`).
2. **Its own plugin header** in `mody-<name>/mody-<name>.php` (name, description, version, `Requires PHP`, `Update URI: false`, text domain `mody_cloud`). So the same folder runs as a normal plugin, on the platform or in a site downloaded with [Take your site with you](take-your-site-with-you.md).
3. **Its own namespace and autoloader:** `Mody\<Name>\…`, classes in `src/`. Nothing is loaded from another plugin's folder.
4. **Its own assets, blocks and data** (`assets/`, `blocks/`, JSON) inside its folder.
5. **No hard link to another Mody plugin.** It reaches another plugin only:
   - through hooks: actions and filters that the other plugin documents (for example, a `mody_dashboard_sections` filter that Billing, Export and Domains add to, instead of the dashboard calling them);
   - or behind a `class_exists()` or `function_exists()` check, with a fallback for when it is missing (as `mody-analytics` does with the client address today).

   A missing plugin means less is done, never a fatal error.
6. **Its own test**, `scripts/<name>-test.php` or the existing one, which passes with the plugin alone where that makes sense.
7. **Its own row** in AGENTS.md's "Where to work" table, and one decision line if it has one.

## Today (2026-11-16)
**Already independent (MU plugins):**

| Plugin | One thing |
| --- | --- |
| `mody-mail` | Outgoing mail through Brevo's API, set by the environment |
| `mody-languages` | Site languages and translations |
| `mody-analytics` | The cookieless visitor counter |
| `mody-images` | Every upload made a web-efficient WebP |
| `mody-seo` | A page's SEO title and SEO description |

**Still together in `mody-platform`:** about 36,000 lines of PHP in 20 modules. They reference each other heavily (from `use` statements and class names, checked 2026-11-16):

| Module | Lines | Names classes of |
| --- | --- | --- |
| Admin | 141 | none |
| Domains | 1,222 | none (and `app/web/sunrise.php` loads `Domains/Mapping.php` by path) |
| Login | 1,074 | Design |
| Design | 2,801 | Admin |
| Users | 609 | Design, Media |
| Media | 109 | Gating, Users |
| Spectra | 683 | Export, Gating |
| Onboarding | 406 | Members |
| Export | 4,761 | Dashboard, Design |
| Gating | 1,346 | AI, Media, Provisioning |
| Brand | 1,595 | AI, Design, Login |
| Site | 1,334 | Agent, Brand, Login |
| Security | 1,846 | Agent, Members, Onboarding |
| Agent | 3,659 | Admin, AI, Billing, Site |
| AI | 3,791 | Agent, Brand, Design, Site, Users |
| Provisioning | 1,234 | Agent, AI, Brand, Design, Members |
| Billing | 2,041 | Agent, Dashboard, Members, Provisioning, Security |
| Members | 2,576 | Agent, Billing, Dashboard, Onboarding, Provisioning, Security, Wizard |
| Wizard | 873 | Agent, AI, Billing, Design, Gating, Members, Provisioning |
| Dashboard | 4,112 | Admin, Agent, Billing, Brand, Domains, Export, Gating, Login, Members, Provisioning, Site, Wizard |

Plus `Abilities.php` and `SiteEditor.php` at the top (the agent's), `mody-platform.php` (every hook, about 400 lines), `assets/`, `blocks/register`, `export-kit/`, `fonts/`, `patterns/` and `reference/`. 60 scripts in `scripts/` name `Mody\Platform` classes.

## The split (confirmed by the maintainer on 2026-11-17, D3)
Each line is one plugin, all MU plugins for now.

| Plugin | One thing | From `mody-platform` |
| --- | --- | --- |
| `mody-spectra` | Spectra without anything that leaves Europe, and its loading | `Spectra/` (must load before `spectra-blocks.php`: alphabetical order does this) |
| `mody-patterns` | The Mody pattern collection | `Design/Patterns`, `Design/PatternImport`, `patterns/` |
| `mody-fonts` | The Font Library with the Mody fonts, no Google Fonts | `Design/Fonts`, `fonts/` |
| `mody-palette` | Color pickers offer only the palette | `Design/PaletteOnly` |
| `mody-styles` | Writing a site's Global Styles: presets, colors, the guarded patch | `Design/Presets`, `SiteDesign`, `SiteStyles` |
| `mody-admin-colors` | Ocean as every user's wp-admin scheme | `Admin/` |
| `mody-login` | The login page in the site's brand, Settings → Login | `Login/`, `assets/login*.css` |
| `mody-profiles` | One network profile, local pictures, no Gravatar | `Users/` |
| `mody-antispam` | Antispam Bee's outside checks off | `Security/AntispamBee` (was `mody-antibot`, see below) |
| `mody-security` | ALTCHA on the forms, rate limits, the client address, secrets, hardening | `Security/` but `AntispamBee` (with ALTCHA, see below) |
| `mody-updates` | No updates from wp-admin | `Gating/Updates` |
| `mody-locks` | What members may not do: plugins, screens, the `ai` plugin, isolation | the rest of `Gating/` |
| `mody-domains` | Custom domains | `Domains/`, and the path in `sunrise.php` |
| `mody-brand` | The brand settings and Settings → Brand | `Brand/` |
| `mody-export` | Take your site with you | `Export/`, `Dashboard/ExportPanel`, `export-kit/` |
| `mody-members` | Accounts, the signup chat, the launch offer, invites | `Members/`, `blocks/register` |
| `mody-onboarding` | The first login and the editor tour | `Onboarding/` |
| `mody-billing` | Card payments through Mollie | `Billing/` |
| `mody-sites` | Creating sites: the wizard and provisioning | `Wizard/`, `Provisioning/` |
| `mody-storage` | 1 GB per site | `Site/Storage` |
| `mody-dashboard` | Every site's Dashboard and the analytics board | `Dashboard/` (not `Analyst`, not `ExportPanel`) |
| `mody-agent` | Mody: the model, the chat, the tools, the analyst | `Agent/`, `AI/`, `Abilities.php`, `SiteEditor.php`, the rest of `Site/`, `Dashboard/Analyst`, `reference/` |

**Changed while building (2026-11-17):** `mody-antibot` and `mody-security` became `mody-antispam` and `mody-security`. FormGuard ties ALTCHA to the login limits (a wrong password counts only when the puzzle was solved), so splitting it would put a cross-plugin interface into security code; and Antispam Bee's pins travel with a downloaded site, where the platform's hardening must not. So ALTCHA joins the hardening, and Antispam Bee's pins are their own plugin.

**Also changed while building (2026-11-17):**
- **The analyst and Mody's work stay with the dashboard** (`Dashboard/Analyst`, `Dashboard/Usage`), not in `mody-agent`: they are the Visitors board's chat and the dashboard's view of the agent's log. The analyst registers only when Mody Agent is there (its model limits and log); Mody's work shows only with the agent's log.
- **Your sites and Plan & billing are still drawn by the dashboard**, reading Mody Members, Mody Billing and Mody Sites behind `class_exists()`, rather than as sections those plugins add. Moving them would split a 1,400-line class for little gain; they can move to the section hooks later.
- **`SiteEditor` went to `mody-agent`**, and the few plugins that only built Site Editor links got a two-line helper of their own.
- **`Look`** (the site's palette, fonts and logo on wp-admin pages) is copied into Mody Brand and Mody Dashboard; Mody Login keeps its own.

`Media/ImageSize` (how big a picture may be before it is decoded) joins `mody-images` (**Inference**: it is about images and is carried by Mody Site today).

`mody-platform` is gone at the end. Its namespace `Mody\Platform` is replaced by each plugin's own.

## Order of work
Rule 5: each step is one plugin, small enough to finish in one sitting, tested and committed on its own. **Inference:** about an hour per leaf plugin and two to four for each of the tangled ones; a few nights in all.

1. **Leaves first**, with no or few inbound links: `mody-admin-colors`, `mody-spectra`, `mody-palette`, `mody-fonts`, `mody-patterns`, `mody-login`, `mody-profiles`, `mody-updates`, `mody-domains`, `mody-antibot`, `mody-security`, `mody-storage`.
2. **Hooks before the tangled ones.** Before `mody-dashboard` moves, its sections come from a filter that each plugin adds to (Plan & billing, Your domain, Download your site, Invite friends, Visitors). The same for the agent's tools: each plugin registers its own abilities in the `mody` category (as `mody-seo` would for `mody/update-seo`), or the agent keeps them behind `class_exists`.
3. **Then:** `mody-brand`, `mody-styles`, `mody-export`, `mody-locks`, `mody-onboarding`, `mody-members`, `mody-billing`, `mody-sites`, `mody-dashboard`, `mody-agent`.

For each step:
- move the files with `git mv`, so history follows;
- change the namespace and every caller, in the code and in `scripts/`;
- move its hooks out of `mody-platform.php` into the plugin's own `register()`;
- run its tests and the tests of every plugin that named it;
- update the paths in AGENTS.md, the documentation and `Export\Portable`.

## What else changes
- **Take your site with you.** Today `Export\Portable` copies a list of files from `mody-platform` into Mody Site (`SITE_FILES`). Once those are whole plugins, a download carries `mody-login`, `mody-spectra`, `mody-palette`, `mody-patterns`, `mody-profiles` (pictures only), `mody-admin-colors` and the rest as they are, like `mody-seo` today. Mody Site may then go away (**Inference**; decided when `mody-export` moves).
- **`app/web/sunrise.php`** loads the domain mapping by path; it follows `mody-domains`.
- **Load order.** WordPress loads `mu-plugins/*.php` in alphabetical order. A plugin that needs another at load time waits for `muplugins_loaded` instead of relying on the order. `mody-spectra.php` sorts before `spectra-blocks.php`, as `mody-platform.php` does today.
- **Deploy.** `bin/deploy` syncs all of `app/web/mu-plugins/` with deletes, so old files go without a change to it. Check once with `--dry-run`.
- **Network options and tables keep their names** (`mody_*`). Nothing in the database changes.

## Decisions (2026-11-17)
The maintainer answered the three questions of 2026-11-16:

- **D1. Every plugin is a normal plugin.** Each Mody plugin is written as a normal plugin: its own header, its own folder, nothing outside it.
- **D2. Performance, language and global plugins are loaded as MU plugins; every plugin that exists today is a MU plugin.** A future plugin that is none of these is installed as a normal plugin.
- **D3. The split** in the table above is confirmed: names and groups.

## Open questions
1. **Where a normal Mody plugin lives** (none yet). `app/web/plugins/` is Composer-managed and not tracked. Options: a tracked folder installed through a Composer path repository, or tracked exceptions in `.gitignore`. Answered when the first one is needed.

## Results
**Step 1, the leaves (2026-11-17, branch `feature/independent-mody-plugins`, not merged):** one commit per plugin, each with its tests run in the local stack.

| Plugin | From | Notes |
| --- | --- | --- |
| `mody-admin-colors` | `Admin/` | The agent's bubble and the dashboard call it only when it is there; the chat's CSS has a fallback. |
| `mody-spectra` | `Spectra/` | The loader runs only where Spectra is a MU plugin. |
| `mody-palette` | `Design/PaletteOnly` | |
| `mody-fonts` | `Design/Fonts`, `fonts/` | The download bakes Inter only when it is there. |
| `mody-patterns` | `Design/Patterns`, `PatternImport`, `patterns/` | A downloaded site now keeps the whole collection, not only the placeholders. |
| `mody-login` | `Login/`, its CSS | Settings → Brand and the dashboard used its helpers; they now use the platform's own `Look`. The agent offers `login_color_scheme` only with it. |
| `mody-profiles` | `Users/` | The network profile's hooks run only on a multisite. |
| `mody-images` (existing) | `Media/ImageSize`, `Gating/ImageLimits` | One class, `ImageSize`: the header check, the upload prefilter, Imagick's limits. |
| `mody-updates` | `Gating/Updates` | |
| `mody-domains` | `Domains/` | `sunrise.php` loads its `Mapping`. First user of the dashboard's section hooks. |
| `mody-antispam` | `Security/AntispamBee` | Replaces the planned `mody-antibot` (see above). |
| `mody-security` | the rest of `Security/` | ALTCHA keeps its route `mody-platform/v1/altcha`. The signup chat and billing use it only when it is there. |
| `mody-storage` | `Site/Storage` | The dashboard reads the space with core's quota functions. |

- **Hooks added:** `mody_dashboard_sections` (filter, slug => label in order) and `mody_dashboard_section_<slug>` (action, prints the panel), in `Dashboard\DashboardPage`.
- **Mody Site is gone:** a download carries the independent plugins whole (`Export\Portable::PLUGINS`), and `Portable` refuses one that names a `Mody\Platform` class.
- **Found on the way:** `scripts/export-archives-test.php` touched a path that no longer existed, which created an empty file; it now touches Mody Fonts. `scripts/wizard-test.php` fails one check locally ("the theme's header with the brand name") because the local main site's header part has no site title (edited by the home page script on 2026-11-10); not related to this work. One `export-archives-test` run failed on its rename race once, then passed twice.

**Step 2, the tangled ones (2026-11-17, same branch):**

| Plugin | From | Notes |
| --- | --- | --- |
| `mody-styles` | `Design/Presets`, `SiteDesign`, `SiteStyles` | A library with no hooks, plus `Screens` (the Styles screen a change shows on). The agent offers `mody/update-design` and `mody/update-styles` only with it. |
| `mody-brand` | `Brand/`, its assets | The agent shows what it sends about the brand through the new `mody_brand_context` filter. Without it the prompt keeps WordPress's name and tagline. |
| `mody-export` | `Export/`, `Dashboard/ExportPanel` (now `Panel`), `export-kit/` | Its own section through the dashboard hooks, with its own stylesheet. |
| `mody-locks` | `Gating/` | The site count's hooks stay with the quota (Mody Sites). |
| `mody-onboarding` | `Onboarding/`, the tour's assets | Asks through `mody_onboarding_no_site_url` and `mody_onboarding_free_until`. |
| `mody-agent` | `Agent/`, `AI/`, `Site/`, `SiteEditor`, `Abilities`, `reference/`, the chat's assets | Billing locks it through `mody_agent_site_locked`. The chat's styles are registered by handle. Mody's own MU plugins are approved for the Infomaniak connector (see below). |
| `mody-sites` | `Wizard/`, `Provisioning/` | Asks through `mody_sites_payment_pending`, `mody_sites_on_launch_offer`, `mody_sites_plan_url`. Without Mody Agent, a plain first page. |
| `mody-members` | `Members/`, `blocks/register`, its assets | The signup runs only with Mody Sites, paid sign-ups only with Mody Billing (checked from `plugins_loaded`). Invite friends is a dashboard section through the hooks. |
| `mody-billing` | `Billing/` | Runs only with Mody Members and Mody Sites, checked from `plugins_loaded`. |
| `mody-dashboard` | `Dashboard/`, `Look` | The board and the analyst register from `plugins_loaded`. |

- **The `ai` plugin's connector approvals:** it names a MU plugin by its folder and treats the plugin that registers a provider as infrastructure. While everything was in `mody-platform`, every call passed; now the wizard, provisioning and the analyst call the model through `mody-agent` from their own folders, and were refused ("pending approval"). Mody's own MU plugins (`mody-*`) are now approved for Infomaniak: `Mody\Agent\AI\ConnectorApprovals` on every site, and Mody Locks' pinned approvals on member sites.
- **Load order:** `mody-billing` loads before `mody-members`, which loads before `mody-sites`, so the checks for another plugin run from `plugins_loaded`, never at load time.
- **Tests fixed on the way:** `export-runner-test.php` skipped its end-to-end build without failing because it looked for the builder's files at their old path (it now runs, 164 checks); `ai-plugin-lockdown-test.php` opened `index.php`, which redirects to the Mody dashboard since 2026-11-11; `member-plugins-test.php` and `security-signup-test.php` named old paths.

**The whole suite (2026-11-17, offline, `MODY_TEST_OFFLINE=1`):** 53 test scripts plus `export-portable-test.sh` (55 checks on a plain WordPress) run in the local stack. Everything passes but 8 checks in three scripts, which fail the same way on `main`: `wizard-test.php` (1), `agent-template-test.php` (3) and `editor-navigation-test.php` (4). They read the local main site's content: its header part has no site title (the home page script's), and its lists differ from what the tests expect. Not checked here: the real-model evaluations (`agent-eval.php`, `analytics-eval.php`) and `nginx-hardening-test.sh`.

**Before the deploy:** `./bin/deploy --dry-run` should show `mody-platform/` and `mody-platform.php` deleted and the 22 new folders added. The REST routes keep `mody-platform/v1`, the options, tables and post meta keep their names, so nothing in the database changes.

**Deployed (2026-11-17, `6db8aaf`, at the maintainer's request).** The production database was dumped first (`backups/mody_cloud-prod-before-independent-plugins-20261117-140920.sql`). The first dry run stopped: `bin/deploy` took `mody-platform/` and `mody-platform.php` on the server for plugins installed outside Composer. It now lets the sync remove an entry git tracked at the deployed commit (`fix/deploy-removed-tracked-files`). The second dry run deleted only `mody-platform` (236 paths). After the sync: the main site, `/wp-login.php`, `/register/`, `/es/`, the ALTCHA route and both member sites answer 200, and the 29 MU plugins load. One 500 (Uptime Kuma's check at 13:11:18) and one fatal error came while files were changing (a loader before its folder); none since.
