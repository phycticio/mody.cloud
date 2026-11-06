# AGENTS.md

## Project summary

Mody Cloud is a non-profit association for digital sovereignty. Members define their brand identity once (a wizard that produces the **brand profile**) and a sovereign AI agent generates every page of their WordPress site, faithful to that brand. The whole stack is European or Swiss. Fees cover real costs, with no profit margin. Full context: [documentation/project-overview.md](documentation/project-overview.md).

This repository is a clean base: a Dockerized WordPress stack (Nginx, PHP-FPM, MariaDB, Redis, Mailpit), a Full Site Editing theme scaffold at `app/web/themes/theme`, and the plugins pinned in `app/composer.json`: Wordfence (security), `ai` (WordPress AI features), `ai-provider-for-ollama`, `mcp-adapter` and Spectra (`spectra-blocks`, network-active, guarded by the MU plugin), plus Query Monitor (dev only). Production installs plugins only through Composer and `./bin/deploy`, because wp-admin file changes are disabled there. The product itself (AI agent, brand profile, membership gating) is not built yet. Status and next steps: [documentation/roadmap.md](documentation/roadmap.md).

## Documentation map (read on demand)

The project documentation lives in `documentation/`. **Before you start a task, find its row below and read that file.** Read another one when the work shifts to a different area. Do not preload all of them. If a document and the code disagree, say so and fix the document as part of the change. The same map, with more detail, is in [documentation/README.md](documentation/README.md).

| When you are... | Read | It covers |
| --- | --- | --- |
| New to the project, or a term is unclear | [project-overview.md](documentation/project-overview.md) | Purpose, principles, visual identity, economics, glossary (Spanish briefing terms mapped to English) |
| Making or reviewing any change | [constraints.md](documentation/constraints.md) | The eight non-negotiable rules and what they mean in this repository |
| Touching Docker, Nginx, `wp-config.php`, Composer, the repo layout, production or deploys, or deciding anything about infrastructure | [architecture.md](documentation/architecture.md) | Current stack, layout, target system, briefing recommendations not adopted, invariants, production host and deploy, open questions |
| Running or debugging the stack, using `bin/` scripts, or a script looks broken | [local-development.md](documentation/local-development.md) | First run, commands, environment variables, known stale items |
| Editing templates, parts, `theme.json`, palette, fonts or styles | [theme-development.md](documentation/theme-development.md) | The FSE theme structure, design tokens, rules, checks |
| Building or changing the AI agent: model calls, prompts, block whitelist, validation | [ai-engine.md](documentation/ai-engine.md) | Model, provider, block format, master prompt rules, validation pipeline, first task |
| Working on the wizard, the `mody_brand_profiles` table, prompt assembly or site creation | [brand-profile.md](documentation/brand-profile.md) | Wizard, schema, message to the model, site creation flow |
| Working on quotas, roles, site limits or dedicated VPS thresholds | [membership-tiers.md](documentation/membership-tiers.md) | Tiers, gating logic, dedicated VPS rules |
| Working on newsletter sending, sending caps or opt-in | [newsletter.md](documentation/newsletter.md) | Infomaniak Newsletter integration |
| Building or scoping the MVP, or needing an agreed product decision | [mvp-spec.md](documentation/mvp-spec.md) | The agreed MVP spec: tiers, billing, wizard, agent, dashboard, milestones |
| Choosing what to work on next, or checking milestone status | [roadmap.md](documentation/roadmap.md) | Milestones and the first concrete step |
| Creating, updating or picking up a ticket, or checking what the team is working on | [tickets.md](documentation/tickets.md) | Where tickets live, and how planning and in-progress work are tracked |
| A requirement is unclear or looks contradictory | `documentation/Mody_Cloud_Briefing_Agente_Codificacion_v4.pdf` | The original briefing (Spanish). The docs above are derived from it. |

## Non-negotiable rules

Full text and consequences: [documentation/constraints.md](documentation/constraints.md).

1. **Absolute sovereignty.** Nothing from Cloudflare, OpenAI, Google AI, AWS or Azure. Data and traffic stay in Europe or Switzerland. A US service makes a solution invalid.
2. **Exact Gutenberg format.** Generated output is always serialized `<!-- wp:block -->` comments. Validate the inner JSON. See [ai-engine.md](documentation/ai-engine.md).
3. **Only FSE, Gutenberg and Spectra.** No Astra, no Elementor, no builders with lock-in. Styles through `theme.json`. See [theme-development.md](documentation/theme-development.md).
4. **Brand coherence.** Every generation injects the brand profile into the system prompt. Never generate without brand context. See [brand-profile.md](documentation/brand-profile.md).
5. **Simplicity.** If something takes more than 2 hours, propose a simpler alternative. Finishing beats perfecting.
6. **Security.** Never expose credentials or WordPress Application Passwords. Only the web tier is public. Sanitize wizard inputs. See [architecture.md](documentation/architecture.md#invariants-that-carry-over-from-the-briefing).
7. **Non-profit.** Fees equal real cost. Never write "unlimited". Limits come from VPS resources. See [membership-tiers.md](documentation/membership-tiers.md).
8. **Language.** Everything is in English: code, comments, documentation, commit messages, prompts to the model and agent replies. This overrides the briefing's Spanish convention.

## Decisions already made

- **Infrastructure is the current stack.** Do not adopt the briefing's Nginx Proxy Manager, its sample `docker-compose`, or Matomo. Why and what replaces them: [architecture.md](documentation/architecture.md#briefing-recommendations-not-adopted).
- **AI model:** `Qwen/Qwen3.8-27B-FP8` (project-scoped build) through Infomaniak AI Services (OpenAI-compatible, base URL `https://api.infomaniak.com/2/ai/{PRODUCT_ID}/openai/v1`). Decided 2026-11-01, superseding the briefing's Ministral choice. See [mvp-spec.md](documentation/mvp-spec.md) and [ai-engine.md](documentation/ai-engine.md).
- **AI integration: the WordPress AI Client, no Python service** (decided 2026-11-04). Models are called with core's `wp_ai_client_prompt()`. The Infomaniak provider lives in the MU plugin, and its configuration comes only from the environment (`INFOMANIAK_API_KEY`, `INFOMANIAK_AI_BASE_URL`). The agent's tool loop and validator run in PHP in the MU plugin. See [ai-engine.md](documentation/ai-engine.md).
- **Agent actions are WordPress abilities ("Option C", decided 2026-11-06).** They are registered in the `mody` category, and the in-site agent and MCP clients both call them. The brand profile also goes into the `ai` plugin's features (`wpai_system_instruction`). M4 locks that plugin's settings, approvals and MCP on member sites. Its request log is the basis for per-member cost tracking. The first ability, `mody/generate-page`, saves validated drafts. See [mvp-spec.md](documentation/mvp-spec.md#8-the-agent) and [ai-engine.md](documentation/ai-engine.md#generating-a-page).
- **Spectra is network-active, behind `SpectraGuard`** (decided 2026-11-06). The guard in the MU plugin switches off everything in Spectra that connects outside Europe: the Google Map block, the AI design library, ZipWP, telemetry, Google Fonts, the dashboard's videos and nudges. It also refuses requests to those hosts, and pins Spectra's own abilities and MCP server off (weak permissions). If Mody uses those abilities, it is only behind its own `mody/*` abilities. After every Spectra update, run `scripts/spectra-guard-test.php`. Our own `mody/*` blocks will replace Spectra ([tickets/mody-blocks-from-spectra.md](tickets/mody-blocks-from-spectra.md)). See [mvp-spec.md §10](documentation/mvp-spec.md#10-plugin-market-and-capability-gating).
- **Payments:** Mollie (EU), decided 2026-11-01. Card at signup (0.01 EUR verification), then monthly tier billing; past-due sites read-only for 60 days before deletion. See [mvp-spec.md](documentation/mvp-spec.md#4-tiers-trial-and-billing).
- **Platform logic lives in one MU plugin:** `app/web/mu-plugins/mody-platform/` (AI provider and agent, registration, billing, dashboard, provisioning, wizard, market, gating, editor redirects). See [mvp-spec.md](documentation/mvp-spec.md#12-architecture-deltas).
- **Composer dev requirements are intentionally only Query Monitor.** Do not add dependencies (Composer or npm) without asking. See [architecture.md](documentation/architecture.md#current-stack-unchanged).
- **JS is built with `@wordpress/scripts`, and gets only the libraries it needs.** Sources live in `src/`, and `pnpm build` compiles them into `app/dist`. Use WordPress packages and the tooling wp-scripts already ships first. Add another library or framework only when a feature needs it and no WordPress package covers it, and record why in [theme-development.md](documentation/theme-development.md#rules). Bundle everything. Nothing loads from a CDN (rule 1).
- **Temporary exceptions to rule 1: Cloudflare and Wordfence.** Both stay in production until the association can afford to replace them (decided 2026-11-04). Do not add other US services, and do not use these two for anything new. See [architecture.md](documentation/architecture.md#temporary-exceptions-to-rule-1).
- **Production is `mody.cloud` on `home.local`, deployed with `./bin/deploy --env=prod`.** Never copy files to the server by hand, and never edit `.env` or `compose.prod.yml` from the repository. See [architecture.md](documentation/architecture.md#production).
- **Open questions are not decisions.** Check the "Open questions" sections ([architecture.md](documentation/architecture.md#open-questions), [brand-profile.md](documentation/brand-profile.md#open-questions), [membership-tiers.md](documentation/membership-tiers.md#open-questions), [newsletter.md](documentation/newsletter.md#open-questions), [ai-engine.md](documentation/ai-engine.md)) and ask instead of choosing for the team.

## Where to work

| Area | Path | Read |
| --- | --- | --- |
| Theme (templates, parts, styles) | `app/web/themes/theme` | [theme-development.md](documentation/theme-development.md) |
| Palette, fonts, spacing | `app/web/themes/theme/theme.json` | [theme-development.md](documentation/theme-development.md#design-tokens) |
| Composer dependencies | `app/composer.json` | [architecture.md](documentation/architecture.md) |
| WordPress config (env-driven) | `app/wp-config.php` | [architecture.md](documentation/architecture.md) |
| Docker, Nginx, PHP config | `compose.yml`, `config/` | [architecture.md](documentation/architecture.md), [local-development.md](documentation/local-development.md) |
| Helper scripts | `bin/` | [local-development.md](documentation/local-development.md) |
| Documentation | `documentation/` | [documentation/README.md](documentation/README.md) |
| Tickets | `tickets/` | [tickets.md](documentation/tickets.md) |
| Platform logic (MU plugin) | `app/web/mu-plugins/mody-platform/`, loaded by `app/web/mu-plugins/mody-platform.php` | [mvp-spec.md](documentation/mvp-spec.md) |
| AI provider, page generation and validator | `app/web/mu-plugins/mody-platform/src/AI/` (Infomaniak provider, `SystemPrompt`, `BlockValidator`, `PageGenerator`) | [ai-engine.md](documentation/ai-engine.md), [mvp-spec.md](documentation/mvp-spec.md) |
| Agent chat (wp-admin page, REST endpoint with background jobs, tool loop, audit log, Network Admin settings) | `app/web/mu-plugins/mody-platform/src/Agent/`, `assets/agent-chat.*` (plain JS, no build step), `src/SiteEditor.php` (Site Editor links, `mody/open-editor`), test `scripts/ai-agent-chat-test.php` | [ai-engine.md](documentation/ai-engine.md#the-agent), [mvp-spec.md §8](documentation/mvp-spec.md#8-the-agent) |
| Spectra guard (rule 1) | `app/web/mu-plugins/mody-platform/src/Spectra/SpectraGuard.php`, test `scripts/spectra-guard-test.php` | [mvp-spec.md](documentation/mvp-spec.md#10-plugin-market-and-capability-gating) |
| Brand profile and abilities | `app/web/mu-plugins/mody-platform/src/Brand/`, `src/Abilities.php` | [brand-profile.md](documentation/brand-profile.md), [mvp-spec.md](documentation/mvp-spec.md#8-the-agent) |
| Design presets and site colors (`mody/update-design`) | `app/web/mu-plugins/mody-platform/src/Design/` (`Presets`, the single list for the wizard and the agent; `SiteDesign`, which writes Global Styles) | [ai-engine.md](documentation/ai-engine.md#changing-the-colors), [mvp-spec.md §6](documentation/mvp-spec.md#6-brand-wizard) |
| Publishing and the site title and tagline (`mody/publish-page`, `mody/update-site-settings`) | `app/web/mu-plugins/mody-platform/src/Site/` (`PagePublisher`, `SiteSettings`); page lookup in `src/SiteEditor.php` | [ai-engine.md](documentation/ai-engine.md#publishing-and-site-settings), [mvp-spec.md §8](documentation/mvp-spec.md#8-the-agent) |

Do not edit WordPress core (`app/wp/`), anything installed by Composer (`app/web/plugins/`, `app/vendor/`), or put project behavior in plugin source.

## Layout and commands

Core lives in `app/wp`, content in `app/web` (Bedrock-style). Plugins and themes install into `app/web/` through Composer. Layout details: [architecture.md](documentation/architecture.md#repository-layout).

```bash
docker compose up -d                 # start the stack
./bin/wp <wp-cli args>               # WP-CLI in the app container
./bin/composer <args>                # Composer in the app container
./bin/wp theme activate theme        # activate the theme
cd app && composer update --no-install   # regenerate composer.lock on the host
```

`./bin/wp` needs `SITE_SLUG` in `.env` (the sample sets `mody`) and the matching alias in `wp-cli.yml`. First-run steps, all commands and environment variables: [local-development.md](documentation/local-development.md).

## Development rules

- Keep customizations in `app/web/themes/theme`. Keep `functions.php` minimal. Platform logic does not belong in the theme. See [theme-development.md](documentation/theme-development.md#rules).
- Use the theme's named palette colors (`primary`, `secondary`, `accent`, `base`, `contrast`). Never loose hex values in generated content or templates. See [theme-development.md](documentation/theme-development.md#design-tokens).
- Load nothing from third-party CDNs or font services. Serve fonts and assets locally (rule 1).
- Theme text domain is `mody_cloud`. Wrap user-facing PHP strings with WordPress i18n functions.
- Block markup in `templates/` and `parts/` must stay valid serialized blocks. See [ai-engine.md](documentation/ai-engine.md#validation-pipeline).
- Never commit `.env`, `auth.json` or credentials. Production uses `compose.prod.yml.sample` so Xdebug (9003) and MariaDB (3306) are not published. See [architecture.md](documentation/architecture.md#invariants-that-carry-over-from-the-briefing).
- Use the smallest validation that covers the change: `php -l <file>`, a JSON parse of `theme.json`, `docker compose config` (needs a `.env`). See [theme-development.md](documentation/theme-development.md#working-with-the-theme).
- Update the matching file in `documentation/` when behavior or decisions change. Mark guesses as **Open question** or **Inference**.

## Known stale items

Parts of the stack scripts and CI workflows still reference an earlier project (Tamarind Intelligence). Do not rely on `bin/install`, `bin/migrate-*` or the GitHub workflows without checking them first. `bin/build` and `bin/deploy` are current. The list is in [local-development.md](documentation/local-development.md#known-stale-items).

## Current focus

MVP execution follows [mvp-spec.md](documentation/mvp-spec.md): milestones M0-M5, one ticket each in `tickets/` (M0-01 to M5-01).

**M0-01 is done** (2026-11-01): subdomain Multisite is enabled locally with wildcard TLS, two subsites run the `theme` block theme, owned by one non-super-admin member. Per-site WP-CLI is `./bin/wp --site=<slug>`. See [local-development.md](documentation/local-development.md#multisite-subdomain-network).

Next up: **M1-01** - registration, trial and Mollie billing. One open question from M0-01 blocks it: WP-Cron does not work on subsites, because Docker's DNS has no wildcard and loopback requests cannot resolve `<slug>.${HOST_NAME}`. M1's hourly billing and sweep jobs depend on it. Candidates and the workaround are in [architecture.md](documentation/architecture.md#open-questions).
