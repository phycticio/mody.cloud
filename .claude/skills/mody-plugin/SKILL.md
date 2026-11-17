---
name: mody-plugin
description: Create a new independent Mody plugin, move a feature into one, or connect two Mody plugins - the folder, loader, plugin header, namespace, autoloader, hooks between plugins, test, and the lists in docs and code that must name it. Use whenever a feature needs its own plugin or code crosses from one Mody plugin to another.
---

# Mody plugin

Rule (decided by the maintainer on 2026-11-16 and 2026-11-17): every Mody plugin does **one thing**, is an **independent, normal plugin**, and every one that exists today is **loaded as a MU plugin**. Full text: [architecture.md#mody-plugins](../../../documentation/architecture.md#mody-plugins) and [tickets/independent-mody-plugins.md](../../../tickets/independent-mody-plugins.md). A new feature gets a plugin of its own, or joins the plugin whose one thing it is. Ask the maintainer when that is not obvious.

## Shape

Copy the shape of a small existing plugin, `mody-seo`:

```
app/web/mu-plugins/mody-<name>.php        # loader: header + require_once __DIR__ . '/mody-<name>/mody-<name>.php';
app/web/mu-plugins/mody-<name>/
  mody-<name>.php                          # main file: full plugin header, namespace, autoloader, register calls
  src/<Class>.php                          # Mody\<Name>\<Class>, one class per file
  assets/                                  # plain JS/CSS, no build step (bundled JS lives in /src and pnpm build)
scripts/<name>-test.php                    # its test
```

- **Main file header:** `Plugin Name: Mody <Name>`, a one-sentence `Description`, `Version: 1.0.0`, `Author: Mody Cloud`, `License: GPL-2.0-or-later`, `Requires PHP: 8.1`, `Update URI: false`, `Text Domain: mody_cloud`, then a doc comment: what its one thing is, the decision and ticket with dates, which other plugins it names and how. The header lets the same folder run as a normal plugin in a downloaded site.
- **Namespace** `Mody\<Name>`; the autoloader maps `Mody\<Name>\Foo\Bar` to `src/Foo/Bar.php` (copy `mody-seo/mody-seo.php`). `defined( 'ABSPATH' ) || exit;` at the top of every file.
- **Strings** wrapped with WordPress i18n in the `mody_cloud` text domain. English everywhere (rule 8).
- **REST routes** keep the namespace `mody-platform/v1`.

## Independence

- It runs with **no other Mody plugin present**. It reaches another only through hooks that plugin documents (`mody_dashboard_sections`, `mody_brand_context`, `mody_site_limit`... listed in architecture.md), or behind `class_exists()` / `function_exists()` with a fallback. A missing plugin means less is done, never a fatal error.
- WordPress loads `mu-plugins/*.php` alphabetically: a plugin built on another checks for it **from `plugins_loaded`**, never at load time, and checks once, where it registers.
- A new hook another plugin can answer is documented in the plugin's main-file comment and in architecture.md's "How they talk".
- `grep -rn 'Mody\\\\<Other>\\\\' app/web/mu-plugins/mody-<name>/` should show only guarded uses.

## Lists that must name it

When a plugin is added, renamed or split, update in the same change:

1. [architecture.md](../../../documentation/architecture.md#mody-plugins): the plugins table (one thing, carried by a downloaded site or not).
2. `AGENTS.md`: the "Where to work" row for the area (paths, classes, test), and "Decisions already made" when a decision made it.
3. **Take your site with you:** `Portable::PLUGINS` in `app/web/mu-plugins/mody-export/src/Portable.php` when a downloaded site needs it to look and work the same (never the agent, billing, signup, locks, mail, analytics or credentials). Ask when unsure. Then run `./scripts/export-portable-test.sh`.
4. The `ai` plugin approves Mody's MU plugins by folder (`mody-*`): a plugin that calls the model must keep the `mody-` prefix.
5. The ticket's Results and `tickets/next-steps.md`.

## Checks

- Rule 1: the plugin contacts no host outside Europe or Switzerland (grep for `wp_remote_`, `http`, CDN URLs, fonts). Rule 6: capabilities on every route and ability, sanitized input, no secrets in output.
- `php -l` on every new file; its own test passes **with the plugin alone where that makes sense** and alongside the others; the tests of every plugin that named moved code ([mody-test](../mody-test/SKILL.md)).
- After moving code: `grep -rn` the old class names across `app/web/mu-plugins`, `scripts`, `documentation`, `AGENTS.md` until only intended references remain.
- **Open question** (ticket): where a future plugin loaded the normal way lives, since `app/web/plugins/` is Composer-managed and untracked. Ask before creating one that is not a MU plugin.
