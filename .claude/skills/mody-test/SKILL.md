---
name: mody-test
description: Verify a Mody Cloud change in the local stack - pick the scripts/*-test.php that cover the touched plugins, run them with WP-CLI eval-file on the right site, run the agent's evaluation set around prompt or model changes, and check editor or front-end JS in a real headless browser. Use whenever a change needs proof, or the maintainer asks to run the tests.
---

# Mody test

Tests are plain PHP scripts in `scripts/`, run inside the app container with WP-CLI's `eval-file`. Each prints one line per check (`ok` or `FAIL`) and ends with `All checks passed.` or `N check(s) failed.` They clean up what they create. The stack must be up (`docker compose up -d`).

## Run one test

Each script's header comment says which site it runs on and its options. Read it first.

```bash
docker exec -i -u www-data mody_cloud_app \
  wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/<name>-test.php
```

- **Site:** `https://mody.local` (main site) for most. These run on a member site, `https://alpha.mody.local`: `seo-test`, `security-agent-test`, `security-isolation-test`, `export-archives-test`, `export-database-test`.
- **Options** go after `docker exec`, before `-i`: `-e MODY_TEST_OFFLINE=1` skips real model calls (Infomaniak; tokens and a rate limit shared with production), `-e MODY_TEST_KEEP_PAGE=1` keeps a generated draft, `-e MODY_TEST_RUNS=3` repeats real cases where supported.
- **Shell tests** from the repository root: `./scripts/export-portable-test.sh [--keep]` (a plain single-site WordPress), `./scripts/nginx-hardening-test.sh`.
- Pipe long output through `tail -40` and grep for `FAIL`.

## Which tests

Start from the "Where to work" table in `AGENTS.md`: each row names its tests. Then add the tests of every plugin that names the changed code (`grep -rln '<ClassName>\|<hook_name>' app/web/mu-plugins scripts`). Common pairs:

| Changed | Run |
| --- | --- |
| A plugin `mody-<name>` | `scripts/<name>-test.php` (or the one its AGENTS.md row names) |
| The agent, its prompts, tools or the validator | `ai-agent-chat-test`, `agent-bubble-test`, `agent-edit-test`, `agent-template-test`, `block-reference-test`, `spectra-layout-test`, `spectra-blocks-test`, `spectra-content-test`, then the evaluation set below |
| Spectra or its guard, Composer updates | `spectra-guard-test` (always after a Spectra update), `spectra-blocks-test`, `member-plugins-test` |
| Members, billing, signup | `members-test`, `registration-test`, `billing-test` (a fake Mollie), `invites-test`, `security-signup-test` |
| Security (login, two-step login, firewall, file check) | `security-test`, `antibot-test`, `security-signup-test`; for the profile's and login's scripts, the headless browser with Chromium's virtual security key (`WebAuthn.addVirtualAuthenticator`) |
| Gating, locks, updates | `gating-test`, `updates-test`, `member-plugins-test`, `ai-plugin-lockdown-test` |
| Theme, styles, palette | `style-variations-test`, `site-styles-test`, `gradients-test`, `font-library-test`, plus a JSON parse of `theme.json` |
| Export (Take your site with you) | `export-*-test.php` and `export-portable-test.sh` |
| Anything broad (a refactor across plugins) | the whole suite, offline (below) |

**Known failures** that also fail on `main` (2026-11-17, `tickets/independent-mody-plugins.md`): `wizard-test` (1), `agent-template-test` (3), `editor-navigation-test` (4), because of the local main site's content. Report them as pre-existing, not as regressions; anything else that fails is new until proven otherwise.

## The whole suite, offline

```bash
for f in scripts/*-test.php; do
  url=$(grep -o -m1 'url=https://[a-z.]*' "$f" | cut -d= -f2); url=${url:-https://mody.local}
  printf '%s ' "$f"
  docker exec -e MODY_TEST_OFFLINE=1 -i -u www-data mody_cloud_app \
    wp --path=/var/www/html/wp --url="$url" eval-file - < "$f" 2>&1 | tail -1
done
```

## The agent's evaluation set

Run before **and** after any change to a prompt, a tool's schema, the block reference cards, or the model, and compare the summaries (`documentation/ai-engine.md#performance-and-measurement-2026-11-11`). It uses the real model on a throwaway site and takes minutes:

```bash
docker exec -i -u www-data mody_cloud_app \
  wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/agent-eval.php
```

`-e MODY_EVAL_ONLY=<group>` (questions, settings, brand, styles, navigation, pages, refusals, editor, long-page, asking) and `-e MODY_EVAL_RUNS=3`. For the analyst: `scripts/analytics-eval.php`. Turn metrics: `scripts/agent-metrics.php`.

## Other quick checks

- `php -l <file>` on every touched PHP file (inside the container: `docker exec mody_cloud_app php -l /var/www/html/<path under app/>`).
- `pnpm build` after changing `src/` (Node 22: prefix `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH"`).
- Generated block markup in the real editor: `node scripts/editor-check.mjs <markup-file>` (Node 22).
- `docker compose config` after compose changes (needs `.env`).

## Real browser

Editor, Site Editor, signup chat and front-end JS need a browser check; PHP tests do not catch them. No install is needed:

- Binary: `~/Library/Caches/ms-playwright/chromium_headless_shell-1223/chrome-headless-shell-mac-arm64/chrome-headless-shell`, launched with `--remote-debugging-port=<port> --host-resolver-rules="MAP *.mody.local 127.0.0.1, MAP mody.local 127.0.0.1" --ignore-certificate-errors --user-data-dir=<scratchpad>/profile`.
- Drive it over CDP from Node 22 (built-in `WebSocket`): `Page.navigate`, `Runtime.evaluate`, `Input.insertText`, `Page.captureScreenshot`; look at the PNGs with Read. `scripts/editor-check.mjs` is a working example.
- Log in through WordPress's screens, or mint a set-password link in the container with `get_password_reset_key()`. Use User Switching to act as a member.

Screenshots are evidence only after you have looked at them. If no browser check ran, say that visual verification is pending.

## Report

Per test: passed, failed (with the failing lines), or not run (and why). Separate pre-existing failures from new ones. Never weaken a check to make it pass.
