# AGENTS.md

## Project summary

Mody Cloud is a non-profit association for digital sovereignty. Members define their brand identity once (wizard, the "Expediente de Marca") and a sovereign AI agent generates every page of their WordPress site, faithful to that brand. The whole stack is European or Swiss. Fees cover real costs, with no profit margin.

This repository is a clean base: a Dockerized WordPress stack (Nginx, PHP-FPM, MariaDB, Redis, Mailpit), a Full Site Editing theme scaffold at `app/web/themes/mody-cloud`, and no plugins except Query Monitor (dev). The product itself (AI agent, brand profile, membership gating) is not built yet. See `documentation/roadmap.md`.

## Read first

- `documentation/README.md` (index and precedence rules)
- `documentation/constraints.md`
- `documentation/architecture.md`
- `documentation/theme-development.md`
- `documentation/local-development.md`
- `documentation/Mody_Cloud_Briefing_Agente_Codificacion_v4.pdf` (the original briefing, in Spanish)

## Non-negotiable rules

From the briefing. Full text in `documentation/constraints.md`.

1. **Absolute sovereignty.** Nothing from Cloudflare, OpenAI, Google AI, AWS or Azure. Data and traffic stay in Europe or Switzerland. A US service makes a solution invalid.
2. **Exact Gutenberg format.** Generated output is always serialized `<!-- wp:block -->` comments. Validate the inner JSON.
3. **Only FSE, Gutenberg and Spectra.** No Astra, no Elementor, no builders with lock-in. Styles through `theme.json`.
4. **Brand coherence.** Every generation injects the Expediente into the system prompt. Never generate without brand context.
5. **Simplicity.** If something takes more than 2 hours, propose a simpler alternative. Finishing beats perfecting.
6. **Security.** Never expose credentials or WordPress Application Passwords. Only the web tier is public. Sanitize wizard inputs.
7. **Non-profit.** Fees equal real cost. Never write "unlimited". Limits come from VPS resources.
8. **Language.** Code comments and agent replies are in Spanish, except technical names. Files under `documentation/` are in English.

## Decisions already made

- **Infrastructure is the current stack.** Do not adopt the briefing's Nginx Proxy Manager, its sample `docker-compose`, or Matomo. See "Briefing recommendations not adopted" in `documentation/architecture.md`.
- **AI model:** `mistralai/Ministral-3-14B-Instruct-2512` through Infomaniak AI Services (OpenAI-compatible), escalating to `google/gemma-4-31B-it`. The briefing's base URL needs verification. See `documentation/ai-engine.md`.
- **Dev requirements are intentionally only Query Monitor.** Do not add dependencies (Composer or npm) without asking.
- **No JS toolchain at the moment.** `package.json` has no dependencies. Add one only when a feature needs it, and document it.

## Where to work

| Area | Path |
| --- | --- |
| Theme (templates, parts, styles) | `app/web/themes/mody-cloud` |
| Theme styles, palette, fonts | `app/web/themes/mody-cloud/theme.json` |
| Composer dependencies | `app/composer.json` |
| WordPress config (env-driven) | `app/wp-config.php` |
| Docker, Nginx, PHP config | `compose.yml`, `config/` |
| Helper scripts | `bin/` |
| Documentation | `documentation/` |
| Python AI agent | Not created yet. Proposed location: `agent/` at the repository root. |

Do not edit WordPress core (`app/wp/`), anything installed by Composer (`app/web/plugins/`, `app/vendor/`), or put project behavior in plugin source.

## Layout and commands

Core lives in `app/wp`, content in `app/web` (Bedrock-style). Plugins and themes install into `app/web/` through Composer.

```bash
docker compose up -d                 # start the stack
./bin/wp <wp-cli args>               # WP-CLI in the app container
./bin/composer <args>                # Composer in the app container
./bin/wp theme activate mody-cloud   # activate the theme
cd app && composer update --no-install   # regenerate composer.lock on the host
```

`./bin/wp` needs `SITE_SLUG` in `.env` (the sample sets `mody`) and the matching alias in `wp-cli.yml`. First-run steps are in `documentation/local-development.md`.

## Development rules

- Keep customizations in `app/web/themes/mody-cloud`. Keep `functions.php` minimal. Platform logic does not belong in the theme.
- Use the theme's named palette colors (`primary`, `secondary`, `accent`, `base`, `contrast`). Never loose hex values in generated content or templates.
- Load nothing from third-party CDNs or font services. Serve fonts and assets locally.
- Theme text domain is `mody-cloud`. Wrap user-facing PHP strings with WordPress i18n functions.
- Block markup in `templates/` and `parts/` must stay valid serialized blocks.
- Never commit `.env`, `auth.json` or credentials. Production uses `compose.prod.yml.sample` so Xdebug (9003) and MariaDB (3306) are not published.
- Use the smallest validation that covers the change: `php -l <file>`, a JSON parse of `theme.json`, `docker compose config` (needs a `.env`).
- Update the matching file in `documentation/` when behavior or decisions change. Mark guesses as **Open question** or **Inference**.

## Known stale items

Parts of the stack scripts, compose samples and CI workflows still reference earlier projects (Tamarind Intelligence). They are listed in `documentation/local-development.md#known-stale-items`. Do not rely on `bin/build`, `bin/install`, `bin/migrate-*` or the GitHub workflows without checking them first.

## Current focus

Milestone 1.1: `hello_ai.py` connects to Ministral through the Infomaniak API (token from an environment variable) and returns one valid Gutenberg paragraph block. Validate the serialized syntax before Milestone 1.2.
