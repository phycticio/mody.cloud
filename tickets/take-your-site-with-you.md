# Take your site with you: download the whole site, restore it anywhere

**Builds on:** [mody-dashboard.md](mody-dashboard.md) (the section), [membership-tiers.md](../documentation/membership-tiers.md) ("a way out"), [next-steps.md](next-steps.md) item 8 (the way-out check) · **Touches:** [M1-02](M1-02-past-due-readonly-deletion.md) (the day-53 email, read-only mode) · **Status:** planned 2026-11-12; the maintainer accepted every recommendation the same day ("Save the plan and proceed with it"). Phases 1 and 2 built and tested locally on 2026-11-12; not deployed.

## Asked for
By the maintainer on 2026-11-12:

> Let's section a button in the dashboard to download all your site completely. There should be always the request for the code and database download at any time no questions asked. So let's do this process very kind and seamless. The user wants to download their whole site to take out elsewhere. The site goes as is. All plugins get downloaded (even the mody ones) so the user could still use their site whenever they want to. The only thing that does not go. The agent, mody. The user looses complete access to mody agent when they leave. Then three files need to be generated. Code, media and database. And along in the code the mody plugin import should be packed there so users can just install wordpress, that plugin and restore the whole site simply with three simple steps.

## What the owner sees
- **Download your site**, a section of every member site's Mody dashboard (not the main site), reached also from the Overview card and from each card in Your sites.
- **One button, Prepare my download.** No confirmation, no "why are you leaving". It works whatever the plan or status (trial, active, past due, read-only), for as long as the site exists.
- **Progress** (Database → Media → Code), then an email when it is ready. The email links to the dashboard, never to the files.
- **Three downloads** with their sizes: `<slug>-code-<date>.zip` (about 15 MB), `<slug>-media-<date>.zip` (up to 3 GB), `<slug>-database-<date>.sql.gz` (a few MB). Downloads resume after a dropped connection. Kept 3 days; **Prepare a fresh copy** is always there.
- **An honest list** of what goes and what stays with Mody, and the three restore steps.

## The three files
1. **Code: the Mody Import plugin.** The zip is an installable WordPress plugin, `mody-import/`, with the importer and a payload:
   - the theme `theme`, with Inter baked into its theme.json;
   - Spectra Blocks and Antispam Bee, the exact installed versions (both unmodified wp.org plugins, GPL);
   - Mody Languages (it stands alone);
   - **Mody Site**: the part of the platform the site needs to look and work the same (below).
2. **Media:** the site's uploads folder, the fonts the owner installed included. STORE zip, uploads root at the zip root.
3. **Database:** only this site's tables, cleaned; gzip, data only, one statement per line, a JSON manifest on the first line.

Every file carries a manifest (`mody-export.json`: export id, source home and hosts, uploads path, WordPress and db_version, PHP floor, plugin slugs and versions, row counts, SHA-256 of each file). The importer recognizes a file by its manifest, not by its name.

### What does not go
- **Mody:** the chat bubble, the `mody/*` abilities, MCP, the `ai` plugin and its connectors, `mcp-adapter`, `ai-provider-for-ollama`, the chat history (`mody_agent_events`), tokens.
- **The platform:** billing, signup, the wizard, provisioning, the 3 GB quota, the network profile, and every lock (no plugin installs, no updates, no Plugins screen). The owner gets a normal WordPress they control completely.
- **Wordfence** (tied to our server) and **mody-mail** (the new host sends the mail; ours would fail every message there).
- **Credentials:** API keys and connector keys, password hashes, application passwords, sessions, activation keys. Nothing from other members.
- **The visitor counter** (no screen outside our dashboard) and the **antibot check** (ALTCHA; Antispam Bee still guards comments).

## Restoring: three steps
1. Install a fresh WordPress (7.1 or newer, PHP 8.1 or newer).
2. Plugins → Add New → Upload the code zip → Activate.
3. Tools → Mody Import: add the media and the database files (uploaded from the browser in chunks, or dropped into a folder by FTP) → **Restore**.

The owner stays logged in as themselves. Pages, design, languages, menus, media and users come back. **Undo** stays possible until **Finish**, which also removes the importer and its payload.

## Decisions (the maintainer's, 2026-11-12: every recommendation accepted)
1. **"All plugins, even the Mody ones"** means everything the site needs to run, shipped as **normal plugins** the owner can update and switch off (not MU plugins). The `ai` plugin, `mcp-adapter` and `ai-provider-for-ollama` are Mody's machinery and stay out; Wordfence and mody-mail stay out.
2. **Who:** any administrator of the site (`manage_options`), on member sites only. Every administrator gets a "your site was downloaded" email; no other question.
3. **No password hashes leave the platform.** The exported account that pressed the button becomes the person running the import; other users come over with an unusable password and set a new one with Lost password.
4. **Smaller defaults:**
   - no ALTCHA on the exported site;
   - no visitor counter;
   - Your brand's texts (`mody_brand`, `mody_mission`, `mody_tone`) travel as data, without their screen;
   - the Spectra privacy guard stays in Mody Site (the owner can switch it off with the plugin);
   - files kept 3 days; one export per site at a time, two builds at once on the network;
   - the export stays open until a site is deleted, past due included; the day-53 email links to it (M1-02).
5. **The build runs as a detached WP-CLI process** spawned from the request (it holds none of the 5 PHP workers), with a job table and a watchdog in the status poll; the `fastcgi_finish_request()` pattern is the fallback.
6. **Files live in `app/dumps/exports/<blog>/<random>/`** (already denied by Nginx, git-ignored, skipped by the deploy) and are served through signed, expiring links with Nginx's `X-Accel-Redirect` (resumable, no PHP worker held), `Cache-Control: private, no-store`.

## Mody Site: the portable part of the platform
Not a copy of `mody-platform`: shipped as it is, it would break the new site:
- **Fatal on every request:** `Security\Altcha` reads the constant `WP_HOME`, which only our wp-config.php defines.
- **Fatal on login redirects:** `Users\NetworkProfile::allow_main_host` calls `get_network()`, which exists only on multisite.
- **Login lock-out:** `Security\FormGuard` refuses every login without the ALTCHA widget, served from `app/dist/`.
- **No mail:** mody-mail fails every message without our Brevo key.
- **No Spectra:** `Spectra\SpectraLoader` strips Spectra from the active plugins.
- **No control:** `Gating\Updates` and `Gating\HiddenScreens` refuse updates and hide the Plugins screen for everyone.

So `scripts/build-mody-site.php` assembles **Mody Site** from an allowlist of classes, under the same namespace (`Mody\Platform`), so every option, meta and URL keeps its name:
- `Design\Patterns` (only `/mody-placeholder/` serving) and the placeholder SVG;
- `Design\Fonts` (only the theme's Inter faces) and `fonts/inter/`, unless baked into the theme;
- `Site\Seo`;
- `Login\{LoginPage, Schemes, Color, SettingsPage}` with `assets/login.css` and `login-settings.css` (the palette read and the Site Editor links inlined);
- `Spectra\SpectraGuard`, `Security\AntispamBee`;
- `Design\PaletteOnly`, `Admin\AdminColors`;
- `Users\Avatars` (initials, single-site URLs).

Its own bootstrap registers only these hooks. It is tested on a plain single-site WordPress (a throwaway container), not only on the multisite stack.

## How it is built
- **Exporter** (`mody-platform/src/Export/`): a network table `mody_export_jobs` (claim, heartbeat, stale restart, like `Provisioning\Jobs`); `wp mody export run <job>`; the database first (pure-PHP streaming writer, consistent snapshot, every `{blog_prefix}*` table except a blocklist, users and usermeta filtered and re-keyed, transients, agent and `ai` data and secrets dropped, WPLANG materialized), then media, then code (a shared cached payload plus the per-site manifest); a sweep that deletes expired files; a free-disk check.
- **Importer** (`mody-platform/export-kit/mody-import/`, never loaded on the platform, zipped by the exporter): preflight (versions, disk, writable folders, DB privileges, single site); chunked browser upload and folder drop; the database into staging tables, URLs rewritten (`/web/uploads/sites/<N>/` under any host to `/wp-content/uploads/`, `/web/` to the content URL, the site host to the new home; plain, `\/`-escaped and protocol-relative; serialized-safe; `guid` untouched), users merged keeping the running admin's login, options kept or overridden, one atomic `RENAME TABLE` swap, caches and rewrites flushed; code and media copied with zip-slip and PHP-in-uploads guards; Undo until Finish.

## Phases
| Phase | What | Estimate |
|---|---|---|
| 0 | **Spike on production:** a 3 GB zip on the external disk, and a 3 GB download with a resume through Cloudflare. Production is blocked to the coding agent: the maintainer runs it or allows it. | ~2 h |
| 1 | Mody Site: the build script, the standalone fixes, the theme with Inter baked in; a test on a clean single-site WordPress | ~1 day |
| 2 | The exporter and the dashboard section; downloadable files with a README of manual restore steps | ~2 days |
| 3 | The Mody Import plugin | 2–3 days |
| 4 | The drill: export a real member site, restore it on a clean WordPress, compare. Then the docs (membership-tiers.md's way out, mvp-spec, M1-02's day-53 email, AGENTS.md, the home page FAQ) | ~½ day |

Rule 5: phases 1–2 are the simpler first cut (the download works, the restore follows written steps). Rule 7: the dashboard and the home page do not promise "three simple steps" before phase 4 passes.

## Not in this ticket
- Pulling the files by link from the new host (server-to-server): later, if the spike shows uploads are the pain.
- The agent's chat history as a personal-data export (GDPR access): a separate request if it comes.
- Exporting the main site.

## Results

### Phase 1: Mody Site (2026-11-12)
- **`Export\Portable::assemble()`** builds the code payload: `themes/theme` with Inter's two files in `assets/fonts/inter/` and their faces in theme.json (`file:./assets/...`), and `plugins/{mody-site, mody-languages, spectra-blocks, antispam-bee}`, each Mody one with a GPL-2.0 text (`export-kit/LICENSE-GPL-2.0.txt`). It refuses to build when Mody Site names a `Mody\Platform` class it does not carry (`Portable::unresolved()`, a token-level check of every file).
- **Mody Site** (`export-kit/mody-site/mody-site.php`): 11 classes, the hooks of the site features only. Its main file sorts before `spectra-blocks`, so the guard loads first.
- **Couplings cut in the platform's own code** (one source for both): `Login\Schemes` reads the palette itself (not `Design\SiteDesign`), `Login\SettingsPage` builds its two Site Editor links itself (not `SiteEditor`), and the placeholder path lives in `Design\Patterns` (`PatternImport::PLACEHOLDER_PATH` points to it). `mody-languages/mody-languages.php` has its own plugin header (version 1.0.0), so the folder is a normal plugin.
- **Test:** `scripts/export-portable-test.sh` builds the payload, makes a plain single-site WordPress in the container's `/tmp` with its own database (core copied from the stack, no download), activates the theme and the four plugins and checks 52 things: the owner has every update and plugin capability, no agent, gating, antibot or mail class exists, no `mody/*` ability, `wp_mail` is core's, the guard is in place, the SEO title and description, Inter, Spectra and the language switcher render, `/es/` answers, placeholders, initials and the branded login page are served, no antibot widget, no PHP warning. **52 of 52 pass.** The platform's `login-page-test`, `patterns-test` and `languages-test` still pass.
- **Found on the way:** PHP's built-in server sets `PATH_INFO`, which makes WordPress miss `/es/` addresses; the test's router clears it, as Nginx and Apache do.

### Phase 2: the exporter (2026-11-12)
Built in four parts against one contract (`Export\Exporter`, `Export\Jobs`), each reviewed adversarially and fixed.
- **The job** (`Export\Jobs`, network table `mody_export_jobs`): one live job per site (a named lock), claimed atomically, a heartbeat in `updated_at`, restarted when stale, given up after 3 attempts. Files in `app/dumps/exports/<site>/<token>/`. One finished copy per site: a new one retires the older. Kept 3 days, then `Exporter::sweep()` deletes them (hourly at most; it also removes orphan folders).
- **The builder** runs in a detached `wp mody-export run <job>` process (`Export\Runner`), never in a PHP worker; at most 2 at once on the network, the others queued. `Runner::watch()` starts and restarts them: from the dashboard's poll and render, at the end of every build, and from `wp mody-export watch` (for a host cron, not installed yet). Operators: `wp mody-export start --site=<slug> [--owner=<user>]`, `run`, `watch`, `purge`, `list`.
- **Database** (`Export\DatabaseDump`): pure PHP on its own connection with a consistent snapshot, keyset paging, gzip, one statement per line, the manifest as line 1 and an end marker as the last line, `{{prefix}}` only in table names and the site's own meta keys. Every table of the site except the `ai` plugin's (`wpai_*`); users without password hashes, usermeta by allowlist; no transients, no agent, `ai`, platform or credential-like options (their names listed as `stripped`, shown to the owner), no `_mody_*`/`wpai_` meta; `WPLANG` materialized; authors outside the export point to the owner. Plugin tables come with their `CREATE TABLE`. Test: `scripts/export-database-test.php` (84 checks, a round trip into a scratch single-site database).
- **Media and code** (`Export\Archives`): `media.zip` is the uploads folder at the zip root, stored (text types deflated), never PHP, `.htaccess` or symlinks; what was left out is listed in `mody-export-skipped.txt`. `code.zip` is the Mody Import plugin (`export-kit/mody-import/`: for now a screen that shows `README-restore.txt`, the nine manual restore steps with the exact search-replace pairs) and its `payload/` from `Portable` (cached once per deploy). Mody's plugins and the theme carry `Update URI: false`. Test: `scripts/export-archives-test.php` (132 checks).
- **Downloads** (`Export\Downloads`): `/mody-export/<token>` links, cookie-less, signed with a random network key (`mody_export_link_key`), 6 hours, bound to the site and the file. Nginx streams the file through an internal location (`/_mody_exports/`, `X-Accel-Redirect`) with Range, only when its PHP location passes `MODY_EXPORT_ACCEL` (a stale Nginx falls back to PHP streaming). A bad or expired link returns to the dashboard with a kind note. Test: `scripts/export-runner-test.php` (164 checks).
- **The section** (`Dashboard\ExportPanel`, `assets/export-panel.js`): Download your site on member sites for administrators; one button, progress by step, three downloads with sizes, kept-until date, Prepare a fresh copy, what comes and what stays, the restore steps (manual until phase 3). Emails (`Export\Notices`), in each recipient's language: every administrator is told a download is being prepared; the asker is told when it is ready (a link to the dashboard, never to the files). A super admin's request is recorded for the site's first administrator. Test: `scripts/export-panel-test.php` (131 checks); `scripts/dashboard-test.php` updated.
- **End to end, locally:** `wp mody-export start --site=alpha` built the three files in about 10 seconds (database 6.7 KB, media 549 B, code 11.8 MB); the signed link downloaded the code file through Nginx with the right checksum, a Range request got 206, a tampered link went back to the dashboard, and `/_mody_exports/` asked directly is a 404.
- **For production:** a deploy (the Nginx template changed: restart Nginx after it), and the phase 0 spike. Optional: the host cron `*/5 * * * * docker exec -u www-data mody_cloud_app wp --path=/var/www/html/wp --url=https://mody.cloud mody-export watch --quiet`, so a queued or crashed build moves even when nobody has the dashboard open.
