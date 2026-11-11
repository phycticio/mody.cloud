# M4-02 · Plugins on member sites: core's Plugins screen, minus a hidden list

**Milestone:** M4 · **Spec:** documentation/mvp-spec.md §7, §8, §10, §11, §16.5 (updated with each part) · **Status:** superseded on 2026-11-11 by [hide-plugins-and-connectors.md](hide-plugins-and-connectors.md): nobody has a Plugins screen; what follows is kept for when plugins are offered to members again. Before that: in progress: part A1 done and deployed 2026-11-08; A2 and B to do, postponed by the maintainer the same day (order in [next-steps.md](next-steps.md)). Redesigned 2026-11-08: a first design was built locally the same day (a Mody market page and Redirection). It is replaced, and was never committed or deployed; see "First design" at the end.

## Goal
Every plugin installed through Composer appears on each member site's own Plugins screen (`/wp/wp-admin/plugins.php`), and members activate and deactivate it for their site there. The plugin market is no longer a page members use: it is the list of installed plugins members **never see**, kept by super admins. Members still cannot install, upload, update, delete or edit plugins. All plugins are free.

## Decisions (maintainer, 2026-11-08)
1. **Core's Plugins screen, not a Mody page.** A plugin added to `app/composer.json` and deployed appears for every member, with no other step, unless it is hidden.
2. **The market is a hidden list:**
   - **Fixed in code:** the platform plugins are always hidden: `spectra-blocks`, `ai`, `ai-provider-for-ollama`, `mcp-adapter`, `wordfence`, `query-monitor`, plus `user-switching` (a dev tool, added 2026-11-08).
   - **Super admins' list:** they hide anything else, from Network Admin.
   - **Storage:** the list is the network option `mody_hidden_plugins` (plugin slugs). The table `mody_market_plugins` leaves the spec.
3. **Redirection is dropped entirely.** It came from the old stack (`config/bin/install.config`). It leaves Composer, and `RedirectionGuard` and its tests go with it.
   - Until a plugin is chosen, members' Plugins screen is empty.
   - Each plugin added later is checked against rule 1 before it goes into Composer.
4. **Mody does nothing plugin-specific.** Activation is core's. Whatever a plugin does when it is activated (Redirection's activation hook was the example) is that plugin's own business, not Mody's business logic. Members decide whether to switch a plugin on.
5. **The agent keeps plugin tools for visible plugins** (part B), with the same rule as the screen.
6. **Carried over from the first design:**
   - New sites get no plugin switched on (spec §7 step 4).
   - A plugin hidden later stays active where it is active. Members can no longer see or switch it, and a super admin decides what happens next.

## The rule (members: everyone but super admins, on member sites)
| Capability | Before (M4-01) | Now |
| --- | --- | --- |
| `activate_plugins`, `deactivate_plugins`: the screen, its menu and bulk actions | refused | **allowed**, without core's network switch (Network Settings → "Enable administration menus: Plugins") |
| `activate_plugin`, `deactivate_plugin` for one plugin (single, bulk and REST all check it) | refused | **allowed for visible plugins**, refused for hidden ones |
| install, upload, update, delete, edit, resume plugins; every theme capability | refused | refused (unchanged) |

- **Hidden** means: in the fixed list, in `mody_hidden_plugins`, or network-active. Core already hides network-active plugins on the screen, but its REST list shows them to site admins.
- **Hidden from where:**
  - the screen and its counts (`all_plugins`);
  - REST `/wp/v2/plugins`: not listed, and their item routes refused;
  - the agent (part B).
- **Unchanged:** on the main site nothing changes. Super admins see and manage everything everywhere. Core's Must-Use and Drop-ins tabs show only to super admins in Network Admin (checked in core).

## Split (rule 5: three parts of 1 to 1.5 hours)

### Part A1 · Members use core's Plugins screen (done 2026-11-08, Results below)
- **Remove the first design** (uncommitted):
  - `src/Market/` and its wiring in `mody-platform.php`, and `scripts/market-test.php`;
  - `composer remove wpackagist-plugin/redirection`;
  - locally, drop `wp_mody_market_plugins` and the network option `mody_market_plugins_db_version`;
  - the doc text about the first design.
- **`Gating\MemberSite`:**
  - the rule above, with the fixed list as `MemberSite::PLATFORM_PLUGINS` and an `is_hidden( $file )` check;
  - the `all_plugins` filter and the REST filter;
  - `mody_manage_market` goes: core's capabilities do the job.
- **Test fixture:** members see no plugin at all yet, so the tests need one. The test writes a one-file plugin, `app/web/plugins/mody-test-plugin/`, and removes it at its start and end. `bin/deploy` gets an exclude for that path, so a leftover can never reach production.
- **Tests:**
  - `scripts/gating-test.php` updated: the plugins screen now opens; install, upload, update, delete and edit are still refused.
  - New `scripts/member-plugins-test.php`:
    - the fixture is visible and switched on and off through the screen's real links and the bulk form;
    - a platform plugin is not listed and is refused when activated through a crafted single link, a bulk request or REST;
    - the main site and super admins are unchanged.

### Part A2 · Super admins' hidden list
- **Network Admin → Mody → Plugins** (`manage_network_plugins`) lists every installed plugin:
  - its name and version;
  - Visible or Hidden, with a switch;
  - platform plugins marked "Platform: always hidden", with no switch;
  - network-active plugins marked as such;
  - on how many sites it is active.
- **Storage:** `mody_hidden_plugins` holds slugs only. A slug no longer installed is shown and can be removed.
- **Tests:** in `scripts/member-plugins-test.php`. Hiding the fixture removes it from the screen, bulk actions and REST, and it stays active where it was. Showing it again brings it back.

### Part B · The agent's plugin tools
- **Abilities** `mody/activate-plugin` and `mody/deactivate-plugin`. They replace the spec's proposed `…-market-plugin` names.
  - **Input:** `plugin`, a slug or the plugin's name.
  - **Permission:** core's `activate_plugin` or `deactivate_plugin` on the resolved file, the same rule as the screen.
  - **Effect:** core's `activate_plugin()` or `deactivate_plugins()`.
  - **A hidden or unknown plugin** is refused with an explanation, and the reply never names hidden plugins. The reply may list the visible ones.
  - **The reply** names the plugin and offers a button to the Plugins screen.
- **The agent:** both abilities are offered to it, with a prompt line saying it may switch the site's plugins on or off when asked.
- **Tests:** the fixture plugin, offline checks, then paced real requests:
  - "Activate the Mody test plugin": done;
  - deactivating it: done;
  - a hidden plugin: refused;
  - an unknown one: refused.
- **Spec:** §8's tool row and acceptance item updated.

## Results (part A1, 2026-11-08)
**First design removed:**
- `src/Market/` (four classes) and its wiring;
- `scripts/market-test.php`;
- Redirection, removed with `composer remove`, which also deleted `app/web/plugins/redirection`. `app/composer.json` and the lock file are back to their committed state;
- locally, `wp_mody_market_plugins` and `mody_market_plugins_db_version`;
- the docs' first-design text: mvp-spec, AGENTS.md, architecture.md, roadmap.md.

**Built:**
- `Gating\MemberSite::map_meta_cap()` (now with 4 arguments) does three things for members on member sites:
  - opens `activate_plugins` and `deactivate_plugins` as `activate_plugins`, which drops core's `manage_network_plugins` requirement;
  - allows `activate_plugin` and `deactivate_plugin` only for a named plugin that is not hidden;
  - keeps refusing the 13 other plugin and theme capabilities. Install, upload, resume, update, delete and edit plugins are among them.
- `Gating\MemberPlugins`:
  - `PLATFORM_PLUGINS` and `is_hidden()` (platform or network-active);
  - `filter_screen()` on `all_plugins`;
  - `refuse_rest_item()` on `rest_pre_dispatch`: a hidden plugin's route answers 404 "Plugin not found";
  - `filter_rest_list()` on `rest_request_after_callbacks`. Unlike `rest_post_dispatch`, it also runs for internal requests and the editor's preloading.
  - `keep_hidden()` on `pre_update_option_active_plugins`: during a logged-in member's request, a hidden plugin keeps its state whatever code writes the option. WP-CLI, cron without a user and super admins are not affected.
- **Found while building, and closed by `keep_hidden()`:** giving members `activate_plugins` also opens other plugins' routes that check only that capability.
  - Spectra's onboarding API, `one-onboarding/v1/activate-plugin`, is registered on every site and activates any installed plugin by slug. Without the guard, a member's call switched `mcp-adapter` on. With it, the route answers "activated" but nothing changes for hidden plugins.
  - Also checked and harmless: Spectra's other AJAX handlers (two only return a link to the Plugins screen; the third activates ZipWP AI, which isn't installed), the `ai` plugin's MCP Adapter auto-installer (its experiment is pinned off on member sites by M4-03), and Wordfence (its menus exist only in Network Admin on multisite).
- `bin/deploy` excludes `app/web/plugins/mody-test-plugin/`.

**Checked in core** (WordPress 7.1):
- Without our mapping, `activate_plugins` on multisite also needs `manage_network_plugins` unless Network Settings → "Enable administration menus: Plugins" is on.
- Bulk activation checks `activate_plugin` for each plugin.
- REST status changes check `activate_plugin` and `deactivate_plugin`.
- The Must-Use and Drop-ins tabs show only to super admins in Network Admin.

**Tests:**
- **`scripts/member-plugins-test.php`**, 38 checks, no model call. It uses the fixture plugin `mody-test-plugin` (written, then removed with any activation):
  - **The rule:** what is hidden; the member, an editor, the main site and a super admin.
  - **REST:** the list is exactly the visible plugins; a platform or network-active plugin answers 404 and stays off; the fixture switches on and off; install and delete are refused.
  - **Spectra's onboarding API**, as the member: mcp-adapter stays off while the fixture switches on. `keep_hidden()` holds both ways, and steps aside without a logged-in user.
  - **Over HTTP:**
    - the screen and its menu, with rows that are exactly the visible plugins, and no Add Plugin, Must-Use or Drop-ins;
    - the row's Activate link, and bulk Deactivate;
    - a crafted bulk request with mcp-adapter activates only the fixture;
    - crafted single links with a valid nonce, to activate mcp-adapter or deactivate Spectra: refused by the capability;
    - an editor is refused;
    - on the main site, the member is refused; a super admin sees mcp-adapter.
- **`scripts/gating-test.php`** updated for the new rule.
- **Every suite passes** (17, offline).

**Not checked by the tests:** the screen in a real browser.
- Log in as `member` on https://alpha.mody.local/wp/wp-admin/plugins.php. It's empty today, because every installed plugin is a platform plugin.
- To see a row, put a plugin at the fixture's path, `app/web/plugins/mody-test-plugin/mody-test-plugin.php`, with a `Plugin Name:` header. `bin/deploy` never ships that path. Any other folder there would be deployed, and the test removes this one when it runs.

## Acceptance criteria
- A member opens Plugins on their site and sees every installed plugin except the hidden ones. Activate and Deactivate work, single and bulk.
- A plugin newly added to Composer and deployed appears for members with no other step. Hiding it on the super admins' page removes it from members' screens, bulk actions, REST and the agent.
- There is no path, UI or REST, to install, upload, update, delete or edit a plugin on a member site.
- Members cannot activate or deactivate a hidden plugin by any path: a crafted link, a bulk request or REST.
- When asked, the agent switches a visible plugin on or off, and refuses a hidden or unknown one with an explanation (part B).

## Docs to update with the build
- **mvp-spec:**
  - decision 7;
  - §7 step 4 (wording);
  - §8: the tool row and the acceptance item;
  - §10: the market design and the capabilities list;
  - §11: remove `mody_market_plugins`;
  - §16.5.
- **architecture.md:** the plugins row (Redirection out), plus the open question below.
- **AGENTS.md:** the decisions, and the Where to work row.
- **roadmap.md.**
- **The M4-01 ticket:** a note that its plugin rule changed.
- **`config/bin/install.config`:** still lists `redirection`. It's a stale script (local-development.md, Known stale items); note it there.

## Open question (not part of this ticket)
- **WordPress.org update checks.** Core asks `api.wordpress.org` for plugin updates on a timer, on admin page loads, and on every load of the Plugins screen (at most hourly).
  - **Not new:** it already happens today for every admin.
  - **What changes:** members opening the screen make it more frequent.
  - **Rule 1:** it hasn't been checked against rule 1 or documented. Record it in architecture.md, Open questions, and decide separately.
  - **Decided and built 2026-11-08** in [updates-through-composer-only.md](updates-through-composer-only.md): nobody updates from wp-admin, and core no longer checks on its own, so the Plugins screen never asks WordPress.org for updates.

## First design (built locally 2026-11-08, replaced the same day)
- **What was built:**
  - a Mody "Plugins" page on member sites, listing a table of "market" plugins (`mody_market_plugins`: name, description, listed, sort) with Activate and Deactivate;
  - a super admins' page to list and delist them;
  - Redirection 5.10.1 as the seed, behind `RedirectionGuard`. It blocked the browser calls to `api.redirect.li` (DigitalOcean, US) with `connect-src 'self'` and refused pass-through redirects.
- **Why it was replaced:** the maintainer preferred core's Plugins screen, with the market as a list of what not to show, and dropped Redirection.
- **What carries over:**
  - the decisions in item 6 above;
  - the platform-plugin list;
  - the finding that core's plugin routes need the per-plugin capability.
