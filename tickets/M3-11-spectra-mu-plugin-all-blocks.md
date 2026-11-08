# M3-11 · Spectra as a MU plugin, and every Spectra block for the agent

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §10 (Spectra and `SpectraGuard`), documentation/ai-engine.md ("Initial block whitelist", "Layout: Spectra containers", "Validation pipeline"), documentation/architecture.md (layout, deploy) · **Status:** decided 2026-11-08; **part 1 built 2026-11-08** (Results below), parts 2 to 7 to do.

## The goal (maintainer, 2026-11-08)
> Spectra should be a mu-plugin and the agent should be able to use all blocks available from Spectra.

## Decisions (maintainer, 2026-11-08)
1. **Spectra stays: it is the platform's block library.** The fork into our own `mody/*` blocks ([mody-blocks-from-spectra.md](mody-blocks-from-spectra.md)) is set aside. `SpectraGuard` stays: every Spectra update is checked with `scripts/spectra-guard-test.php`.
2. **Split into parts** (rule 5): Spectra as a MU plugin first, then the validator, then the block families in groups, each measured with real runs and a browser check.
3. **Out:** `spectra/popup-builder` (it builds site-wide popups, not page content) and the Google Map block (rule 1; `SpectraGuard` unregisters it).

## What Spectra has (1.0.13, checked 2026-11-08)
47 blocks, all **dynamic**: rendered on the server from their attributes, none read back from saved HTML. The agent writes only block comments with JSON attributes and inner blocks; text lives in attributes (`spectra/button` has `text`). 14 top-level families: `accordion`, `buttons`, `container`, `content`, `countdown`, `counter`, `icons`, `list`, `modal`, `popup-builder`, `post`, `separator`, `slider`, `tabs`. Each has its child blocks, with `parent` or `ancestor` rules.

## Plan

### Part 1 · Spectra as a MU plugin
- **Composer** installs `wpackagist-plugin/spectra-blocks` into `app/web/mu-plugins/spectra-blocks/` (an `installer-paths` entry before the plugin rule). Gitignored like the other installed code.
- **The loader** `app/web/mu-plugins/spectra-blocks.php` requires it. MU plugins load in file-name order, so `mody-platform.php` (and `SpectraGuard`) loads first.
- **The old network activation:** `Spectra\SpectraLoader` (in mody-platform) drops `spectra-blocks/spectra-blocks.php` from what WordPress reads as active, network-wide and per site, so a leftover copy in `app/web/plugins/` never loads beside the MU one. A one-time step removes it from the stored option.
- **Activation:** MU plugins have none. Spectra's `on_activation()` only sets defaults existing sites already have (and network activation never ran it for new sites either), so nothing is lost.
- **Deploy:** `bin/deploy` refuses a server folder in `app/web/plugins` that the build lacks. It now accepts one that the deployed `composer.lock` installed (Composer moved it), and still refuses anything installed outside Composer.
- **Members:** Spectra disappears from every Plugins screen; it can no longer be switched off anywhere.
- **Tests:** Spectra loads from `mu-plugins` and is not network-active, its blocks are registered, the guard still holds (`spectra-guard-test`), and the Plugins screen tests use another network-active plugin.

### Part 2 · The validator accepts every Spectra block
`BlockValidator` takes the registered `spectra/*` blocks (minus the two above), with their `parent` and `ancestor` rules, valid attribute JSON, colors only as palette slugs, and no URLs to other sites. Page generation and editing use the same validator.

### Parts 3 to 7 · The agent learns the families
For each group: a compact reference of the attributes the agent writes (in the page writer's and the editor's prompts), an example, offline checks, real runs, and a browser check that the editor shows no invalid blocks.
3. `accordion` (FAQ) and `tabs`.
4. `buttons`, `icons`, `list`, `separator`, `content`.
5. `counter` and `countdown`.
6. `slider` and `modal`.
7. `post` (lists of posts).

## Results

### Part 1 (built 2026-11-08)
- **Where it runs:**
  - Composer's `installer-paths` puts `wpackagist-plugin/spectra-blocks` in `web/mu-plugins/{$name}/`, before the plugin rule (the lock's content hash changed, nothing else). It is gitignored.
  - `app/web/mu-plugins/spectra-blocks.php` requires it. WordPress lists it as a must-use plugin, after `mody-platform.php`.
- **The old activation:** `Spectra\SpectraLoader`, booted right after `SpectraGuard`:
  - filters `site_option_active_sitewide_plugins` and `option_active_plugins`, so `spectra-blocks/spectra-blocks.php` is never active;
  - on the first `init`, removes it from the stored network option (flag `mody_spectra_mu_plugin`).
  - Locally the stored activation went from `ai/ai.php, spectra-blocks/spectra-blocks.php` to `ai/ai.php`.
- **Checked locally:**
  - `SPECTRA_BLOCKS_FILE` is under `mu-plugins`, with all 47 `spectra/*` blocks registered;
  - asset URLs come from `/web/mu-plugins/spectra-blocks/`, and the front end loads none from `plugins/`;
  - the guard still holds;
  - both sites answer 200.
- **Composer leaves the old copy.** It installs the new path but does not remove `app/web/plugins/spectra-blocks/`. The filter keeps that copy from ever loading. `wp plugin list` shows it as inactive.
- **`bin/deploy`:** a server entry missing from the build passes when the server's deployed `composer.lock` has a package of that name (so Composer installed it), and the sync removes it. Anything else still stops the deploy.
- **Tests:**
  - `scripts/spectra-guard-test.php`: Spectra is a MU plugin and loads after mody-platform.
  - `scripts/member-plugins-test.php`: the network-active example is now the `ai` plugin, and Spectra is on no Plugins screen.
  - All 23 suites pass offline.
