---
name: mody-dependency
description: Add, update or remove a dependency of Mody Cloud - a Composer plugin or library, Spectra, Antispam Bee, WordPress core, or an npm package - with the maintainer's approval, a sovereignty (rule 1) review, the lockfile regenerated on the host, and the guard tests. Use before touching app/composer.json, package.json or ./bin/version.
---

# Mody dependency

Versions change only in the repository and reach production through `./bin/deploy`; nobody updates from wp-admin (`mody-updates`). Background: `AGENTS.md` ("Composer dev requirements", "JS is built with @wordpress/scripts", "Plugins: no screen for anyone", "Nobody updates WordPress or a plugin from wp-admin") and [architecture.md#current-stack-unchanged](../../../documentation/architecture.md#current-stack-unchanged).

## Ask first

**Do not add a dependency (Composer or npm) without the maintainer's yes.** Before asking, check whether WordPress core, a WordPress package or a few lines of our own code cover it (rule 5: simplicity; `mody-mail` posts to Brevo with `wp_remote_post()` instead of a library). Updating a pinned version or replacing core is also asked first: `./bin/version` replaces WordPress core.

## Review it (rule 1 and rule 6)

Bring the maintainer these facts with the question:

- **Who makes it and where:** the vendor's country. US services are invalid (rule 1); the only exceptions are Cloudflare and Brevo (Wordfence was replaced by Mody Security on 2026-11-21), and they are not used for anything new.
- **What it calls:** grep the package for `wp_remote_`, `curl`, `http://`, `https://`, `fetch(`, telemetry, update checks, Google Fonts, CDNs, Gravatar. Every outside host must be switched off (as `mody-spectra`'s `SpectraGuard` and `mody-antispam` do) or the package is not fit.
- **What it exposes:** REST routes, abilities, MCP, admin screens, capabilities. Members must not get plugin screens (`HiddenScreens`); platform plugins never switch (`MemberPlugins::PLATFORM_PLUGINS`).
- **License** (GPL-compatible) and size.

## Composer

- Edit `app/composer.json`: exact versions (`"wpackagist-plugin/foo": "1.2.3"`). WordPress.org plugins come from `wpackagist-plugin/*`; Mody's own packages from the `satispress` repository (`mody/*`). A plugin that must always be on goes to `web/mu-plugins/{$name}/` through `extra.installer-paths` plus a loader `app/web/mu-plugins/<name>.php` (as Spectra and Antispam Bee), and a Mody plugin to pin its outside calls off sorts before it.
- Dev-only tools go in `require-dev` (today only Query Monitor and User Switching; `--no-dev` keeps them off production).
- Regenerate the lock on the host: `cd app && composer update --no-install <package>`; install in the container: `./bin/composer install`. Commit `composer.json` and `composer.lock` together.
- Never edit files under `app/web/plugins/`, `app/vendor/` or a Composer-installed MU plugin folder; project behavior goes in a Mody plugin ([mody-plugin](../mody-plugin/SKILL.md)).

## npm

- `package.json` with an exact version; bundle it through `pnpm build` (`@wordpress/scripts`, Node 22); nothing loads from a CDN. Record why the library is needed in [theme-development.md#rules](../../../documentation/theme-development.md#rules).

## WordPress core

`./bin/version <x.y.z>` replaces `app/wp` without touching `app/web`; ask first, then run the whole test suite offline.

## After every change

- **Spectra updated:** `scripts/spectra-guard-test.php` (the guard still blocks every outside host), then `spectra-blocks-test`, `spectra-content-test`, `block-reference-test` (the validator's `BlockValidator::SPECTRA_BLOCKS` and the reference cards may need the new blocks or attributes).
- **Antispam Bee updated:** `scripts/antibot-test.php` (its three outside checks still pinned off).
- **Any plugin:** `member-plugins-test`, `gating-test`, `updates-test`; check the plugin screens and update checks stay hidden.
- Watch the local logs and Query Monitor's HTTP API calls panel for new outbound requests.
- Update architecture.md's "Current stack" Plugins row (name, version, date, why) in the same change. Run tests through [mody-test](../mody-test/SKILL.md).
