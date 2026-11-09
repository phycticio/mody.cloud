# AGENTS.md

## Project summary

Mody Cloud is a non-profit association for digital sovereignty. Members define their brand identity once (a wizard; the brand then lives in each site's own WordPress settings and styles) and a sovereign AI agent generates every page of their WordPress site, faithful to that brand. The whole stack is European or Swiss. Fees cover real costs, with no profit margin. Full context: [documentation/project-overview.md](documentation/project-overview.md).

This repository holds the platform. It has a Dockerized WordPress Multisite stack (Nginx, PHP-FPM, MariaDB, Redis, Mailpit), the Full Site Editing theme at `app/web/themes/theme`, and the platform's MU plugin at `app/web/mu-plugins/mody-platform/`. Production installs plugins only through Composer and `./bin/deploy`, because wp-admin file changes are disabled there.

**Built so far:**
- the brand wizard and site provisioning, with each site's brand in its own settings, design presets and fonts (M2);
- the AI agent: page generation and validation, the chat bubble in the editors, and tools for design, publishing and site settings (M3);
- member-site gating (M4, in progress);
- the signup: a chat that creates the account, a 30-day trial without a card and the first site, then the editor tour (M1-03).

**Not built yet:** billing and the member dashboard (M1), and the production launch (M5). Status: [documentation/roadmap.md](documentation/roadmap.md). Next steps, in order: [tickets/next-steps.md](tickets/next-steps.md).

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
| Working on the wizard, the brand (each site's settings and styles), prompt assembly or site creation | [brand-profile.md](documentation/brand-profile.md) | Wizard, where the brand lives, the brand context, site creation flow |
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
4. **Brand coherence.** Every generation injects the site's brand (its WordPress settings) into the system prompt. Never generate without brand context. See [brand-profile.md](documentation/brand-profile.md).
5. **Simplicity.** If something takes more than 2 hours, propose a simpler alternative. Finishing beats perfecting.
6. **Security.** Never expose credentials or WordPress Application Passwords. Only the web tier is public. Sanitize wizard inputs. See [architecture.md](documentation/architecture.md#invariants-that-carry-over-from-the-briefing).
7. **Non-profit.** Fees equal real cost. Never write "unlimited". Limits come from VPS resources. See [membership-tiers.md](documentation/membership-tiers.md).
8. **Language.** Everything is in English: code, comments, documentation, commit messages, prompts to the model and agent replies. This overrides the briefing's Spanish convention.

## Decisions already made

- **Infrastructure is the current stack.** Do not adopt the briefing's Nginx Proxy Manager, its sample `docker-compose`, or Matomo. Why and what replaces them: [architecture.md](documentation/architecture.md#briefing-recommendations-not-adopted).
- **AI model:** `Qwen/Qwen3.8-27B-FP8` (project-scoped build) through Infomaniak AI Services (OpenAI-compatible, base URL `https://api.infomaniak.com/2/ai/{PRODUCT_ID}/openai/v1`). Decided 2026-11-01, superseding the briefing's Ministral choice. See [mvp-spec.md](documentation/mvp-spec.md) and [ai-engine.md](documentation/ai-engine.md).
- **AI integration: the WordPress AI Client, no Python service** (decided 2026-11-04). Models are called with core's `wp_ai_client_prompt()`. The Infomaniak provider lives in the MU plugin, and its configuration comes only from the environment (`INFOMANIAK_API_KEY`, `INFOMANIAK_AI_BASE_URL`). The agent's tool loop and validator run in PHP in the MU plugin. See [ai-engine.md](documentation/ai-engine.md).
- **Agent actions are WordPress abilities ("Option C", decided 2026-11-06).** They are registered in the `mody` category, and the in-site agent and MCP clients both call them. The site's brand also goes into the `ai` plugin's eight writing features (`wpai_system_instruction`, `src/AI/PluginBrandContext.php`; analysis features are left unchanged, decided 2026-11-06). M4 locks that plugin's settings, approvals and MCP on member sites. Its request log is the basis for per-member cost tracking. The first ability, `mody/generate-page`, saves validated drafts. See [mvp-spec.md](documentation/mvp-spec.md#8-the-agent) and [ai-engine.md](documentation/ai-engine.md#generating-a-page).
- **Generated layout is Spectra containers** (decided 2026-11-07). Every section, row and column the page writer builds is a `spectra/container` with fixed responsive settings (`AI\SpectraLayout`: sections, grid rows, columns), so pages are built the same way on every site. New pages may not use core group, columns or column. The theme's templates and parts use containers too (2026-11-08), and `post-content` is never inside one. See [ai-engine.md](documentation/ai-engine.md#layout-spectra-containers) and [theme-development.md](documentation/theme-development.md#structure).
- **Spectra is the platform's block library: a MU plugin, behind `SpectraGuard`** (network-active from 2026-11-06; a MU plugin since 2026-11-08, [M3-11](tickets/M3-11-spectra-mu-plugin-all-blocks.md)). Composer installs it into `app/web/mu-plugins/spectra-blocks/`, and `mu-plugins/spectra-blocks.php` loads it after `mody-platform.php`, so the guard is in place first. Nobody can switch it off. `Spectra\SpectraLoader` keeps the old network-activated copy from loading. The agent will use every Spectra block except the popup builder (M3-11, in parts): the validator accepts them since 2026-11-09 (`BlockValidator::SPECTRA_BLOCKS`), and the prompts learn them family by family. The guard in the MU plugin switches off everything in Spectra that connects outside Europe: the Google Map block, the AI design library, ZipWP, telemetry, Google Fonts, the dashboard's videos and nudges. It also refuses requests to those hosts, and pins Spectra's own abilities and MCP server off (weak permissions). If Mody uses those abilities, it is only behind its own `mody/*` abilities. After every Spectra update, run `scripts/spectra-guard-test.php`. The fork into our own `mody/*` blocks is set aside (decided 2026-11-08, [tickets/mody-blocks-from-spectra.md](tickets/mody-blocks-from-spectra.md)). See [mvp-spec.md §10](documentation/mvp-spec.md#10-plugin-market-and-capability-gating).
- **Plugins on member sites: core's Plugins screen, minus a hidden list** (redesigned 2026-11-08, [M4-02](tickets/M4-02-plugin-market.md)). Every plugin installed through Composer appears on each member's own Plugins screen, where they activate and deactivate it for their site; they still cannot install, upload, update, delete or edit plugins. Platform plugins (`Gating\MemberPlugins::PLATFORM_PLUGINS`) and network-active ones are never shown; super admins will hide others (part A2). Mody does nothing plugin-specific. No plugin is offered yet: Redirection was dropped (it came from the old stack). Check every plugin against rule 1 before adding it to Composer. New sites get none switched on. See [mvp-spec.md §10](documentation/mvp-spec.md#10-plugin-market-and-capability-gating).
- **Nobody updates WordPress or a plugin from wp-admin** (decided 2026-11-08). Versions change only in the repository: core with `./bin/version`, plugins in `app/composer.json`. They reach production through `./bin/deploy`. `Gating\Updates` refuses the four update capabilities to every user, super admins included, on every environment. It also removes core's automatic update checks to WordPress.org. Wordfence's scans and WP-CLI still call them directly. See [tickets/updates-through-composer-only.md](tickets/updates-through-composer-only.md) and [mvp-spec.md §10](documentation/mvp-spec.md#10-plugin-market-and-capability-gating).
- **A site's brand is its own WordPress data; WordPress's settings and screens come first** (decided 2026-11-08, [M2-08](tickets/M2-08-brand-profile-wordpress-settings.md)).
  - **Identity:** name (the site title), tagline, logo and icon are WordPress settings, changed on the Identity screen (`site-editor.php?p=/identity`) or through `mody/update-site-settings` for words.
  - **Styles:** colors, fonts and every other style are the site's Global Styles (Editor → Styles).
  - **Mission and tone** are two site settings, `mody_mission` and `mody_tone` (`Brand\BrandSettings`). They are shown on the Identity screen (`assets/identity-fields.js`) and in Settings → General.
  - **No profile:** the `mody_brand_profiles` table was dropped, and prompts read the brand when they run.
  - **For every feature:** when WordPress has a setting or a screen for something, use it, and never keep a copy.
- **Patterns are the Mody collection** (the design market, built 2026-11-08). Every site's patterns come from `mody-platform/patterns/collection.json`: 63 patterns rewritten from the WordPress.org Pattern Directory and core by `scripts/import-patterns.php` (`Design\PatternImport`).
  - **What the rewrite does:** palette slugs, the theme's fonts, the platform's own placeholder images (`/mody-placeholder/<w>x<h>.svg`), no links to other sites.
  - **Where they show:** on the Patterns screen and in the inserter.
  - **What is switched off:** the remote Pattern Directory and core's bundled patterns, and the Directory proxy is refused to members.
  - **Themes:** Mody is the only theme provider; which themes members see is tech debt.

  See [tickets/design-market-themes-patterns.md](tickets/design-market-themes-patterns.md).
- **Payments:** Mollie (EU), decided 2026-11-01. A card during the trial (0.01 EUR verification; it was "at signup" until 2026-11-08), then monthly tier billing; past-due sites read-only for 60 days before deletion. See [mvp-spec.md](documentation/mvp-spec.md#4-tiers-trial-and-billing).
- **The trial needs no card, and the signup is a chat** (decided by the maintainer on 2026-11-08, [M1-03](tickets/M1-03-chat-signup-and-editor-tour.md)). `/register/` asks the visitor's name, email, project name and address one at a time; then the account, an active 30-day trial and the first site (a starter site: no model call) exist, and an email holds the set-password link. The first password logs the member in on their site's Identity screen, where the editor tour shows the agent, asks for two requests, then explains the Site Editor. The card and the plan come before the trial ends (M1-01 part 3, reworked). See [mvp-spec.md §4](documentation/mvp-spec.md#4-tiers-trial-and-billing).
- **Platform logic lives in one MU plugin:** `app/web/mu-plugins/mody-platform/` (AI provider and agent, registration, billing, dashboard, provisioning, wizard, market, gating, editor redirects). See [mvp-spec.md](documentation/mvp-spec.md#12-architecture-deltas).
- **Composer dev requirements are intentionally only Query Monitor and User Switching** (User Switching 1.12.2 added at the maintainer's request on 2026-11-08, to act as another user locally; no outbound calls). Do not add dependencies (Composer or npm) without asking. See [architecture.md](documentation/architecture.md#current-stack-unchanged).
- **JS is built with `@wordpress/scripts`, and gets only the libraries it needs.** Sources live in `src/`, and `pnpm build` compiles them into `app/dist`. Use WordPress packages and the tooling wp-scripts already ships first. Add another library or framework only when a feature needs it and no WordPress package covers it, and record why in [theme-development.md](documentation/theme-development.md#rules). Bundle everything. Nothing loads from a CDN (rule 1).
- **Temporary exceptions to rule 1: Cloudflare, Wordfence and Brevo.** Cloudflare and Wordfence stay in production until the association can afford to replace them (decided 2026-11-04). Do not add other US services, and do not use these two for anything new. The one exception (2026-11-08) is a Cloudflare API token for the wildcard certificate's DNS challenge (M5-02). Brevo sends production mail since 2026-11-09: the maintainer already pays for it, and an EU relay replaces it later. See [architecture.md](documentation/architecture.md#temporary-exceptions-to-rule-1).
- **Outgoing mail: WP Mail SMTP, set only by constants** (decided 2026-11-09). It is required in `app/composer.json`, network-active, and the only way the platform sends email. It is hidden from members and limited to super admins, and its calls home are off (`Mail\WpMailSmtp`). Its settings are `WPMS_*` constants that `app/wp-config.php` sets from the environment, so its screen cannot change them: SMTP to Mailpit locally, Brevo's API in production. In production, the server's `compose.prod.yml` maps the `BREVO_*` lines of its `.env`. See [architecture.md](documentation/architecture.md#production) and [M5-02](tickets/M5-02-production-member-sites.md) step 6.
- **Production is `mody.cloud` on `home.local`, deployed with `./bin/deploy --env=prod`.** Never copy files to the server by hand, and never edit `.env` or `compose.prod.yml` from the repository. See [architecture.md](documentation/architecture.md#production).
- **Deploy strategy** (decided 2026-11-08): each change goes on its own topic branch (`feature/…`, `fix/…`, `docs/…`). It is merged into `main` locally with `--no-ff`, then `main` is pushed and deployed. Only when the maintainer asks. See [architecture.md](documentation/architecture.md#deploying).
- **Open questions are not decisions.** Check the "Open questions" sections ([architecture.md](documentation/architecture.md#open-questions), [brand-profile.md](documentation/brand-profile.md#open-questions), [membership-tiers.md](documentation/membership-tiers.md#open-questions), [newsletter.md](documentation/newsletter.md#open-questions), [ai-engine.md](documentation/ai-engine.md)) and ask instead of choosing for the team.

## Where to work

| Area | Path | Read |
| --- | --- | --- |
| Theme (templates, parts, styles) | `app/web/themes/theme` | [theme-development.md](documentation/theme-development.md) |
| Palette, fonts, spacing, gradients; color pickers offer only the palette (M1-04) | `app/web/themes/theme/theme.json`; Spectra's own pickers: `app/web/mu-plugins/mody-platform/src/Design/PaletteOnly.php`; tests `scripts/style-variations-test.php` and `scripts/spectra-guard-test.php` | [theme-development.md](documentation/theme-development.md#design-tokens) |
| Composer dependencies | `app/composer.json` | [architecture.md](documentation/architecture.md) |
| WordPress config (env-driven) | `app/wp-config.php` | [architecture.md](documentation/architecture.md) |
| Docker, Nginx, PHP config | `compose.yml`, `config/` | [architecture.md](documentation/architecture.md), [local-development.md](documentation/local-development.md) |
| Helper scripts | `bin/` | [local-development.md](documentation/local-development.md) |
| Documentation | `documentation/` | [documentation/README.md](documentation/README.md) |
| Tickets | `tickets/` | [tickets.md](documentation/tickets.md) |
| Platform logic (MU plugin) | `app/web/mu-plugins/mody-platform/`, loaded by `app/web/mu-plugins/mody-platform.php` | [mvp-spec.md](documentation/mvp-spec.md) |
| AI provider, page generation and validator | `app/web/mu-plugins/mody-platform/src/AI/` (Infomaniak provider, `SystemPrompt`, `BlockValidator`, `PageGenerator`, `SpectraLayout` for the layout recipe, `SpectraBlocks` for the Spectra families the writers know, `PluginBrandContext` for the `ai` plugin's features; tests `scripts/spectra-layout-test.php`, `scripts/spectra-blocks-test.php` for the Spectra blocks, `scripts/spectra-content-test.php` for the families, and `scripts/ai-plugin-brand-context-test.php`) | [ai-engine.md](documentation/ai-engine.md), [mvp-spec.md](documentation/mvp-spec.md) |
| Agent chat (editor chat bubble, wp-admin page for super admins on the main site, REST endpoint with background jobs, tool loop, audit log, Network Admin settings) | `app/web/mu-plugins/mody-platform/src/Agent/` (`EditorBubble` for the bubble, `EditorContext` for what is open), `assets/agent-chat.*` and `assets/agent-bubble.*` (plain JS, no build step), tests `scripts/agent-bubble-test.php`, `scripts/agent-edit-test.php` and `scripts/agent-template-test.php`, `src/SiteEditor.php` (Site Editor links and screens, the site's lists, `mody/open-editor`; the bubble opens screens in place, M3-09), tests `scripts/ai-agent-chat-test.php` and `scripts/editor-navigation-test.php` | [ai-engine.md](documentation/ai-engine.md#the-agent), [mvp-spec.md §8](documentation/mvp-spec.md#8-the-agent) |
| Spectra guard (rule 1) and loading (a MU plugin) | `app/web/mu-plugins/mody-platform/src/Spectra/SpectraGuard.php`, `src/Spectra/SpectraLoader.php`, the loader `app/web/mu-plugins/spectra-blocks.php`, test `scripts/spectra-guard-test.php` | [mvp-spec.md](documentation/mvp-spec.md#10-plugin-market-and-capability-gating) |
| The brand (mission and tone site settings, the Identity screen's two fields, the migration) and abilities | `app/web/mu-plugins/mody-platform/src/Brand/` (`BrandSettings`), `assets/identity-fields.js`, `src/Abilities.php`, test `scripts/brand-settings-test.php` | [brand-profile.md](documentation/brand-profile.md), [mvp-spec.md](documentation/mvp-spec.md#8-the-agent) |
| Design presets and site colors (`mody/update-design`) | The presets are the theme's style variations, `app/web/themes/theme/styles/*.json` (M2-06). `app/web/mu-plugins/mody-platform/src/Design/` holds `Presets` (reads them: the single list for the wizard, provisioning and the agent), `SiteDesign` (writes Global Styles: a whole style, or single colors), `SiteStyles` (`mody/update-styles`: a guarded patch of the rest of the styles, M3-10) and `Fonts` (the Font Library: the Mody fonts collection, real Inter for the theme, no Google Fonts; the files are in `mody-platform/fonts/<slug>/`, with licenses and `fonts/sources.json`, M2-07). Tests `scripts/style-variations-test.php`, `scripts/site-styles-test.php` and `scripts/font-library-test.php` | [ai-engine.md](documentation/ai-engine.md#changing-the-colors), [mvp-spec.md §6](documentation/mvp-spec.md#6-brand-wizard) |
| Member accounts and registration (`mody_members`, plans and their site limits, the trial, the dashboard capability; the `/register/` page and its `mody/register` block, a chat with two REST routes, closed until `mody_registration_open`, M1-03; the block with every style support, insertable on the main site only, M1-04; Mollie billing to come, M1-01) | `app/web/mu-plugins/mody-platform/src/Members/` (`Members`, `Registration`), `blocks/register/block.json`, `assets/register.js`, `register.css` and `register-block.js` (the editor's preview) (plain JS, no build step), tests `scripts/members-test.php` and `scripts/registration-test.php` | [tickets/M1-01-registration-trial-billing.md](tickets/M1-01-registration-trial-billing.md), [mvp-spec.md §4](documentation/mvp-spec.md#4-tiers-trial-and-billing), [membership-tiers.md](documentation/membership-tiers.md) |
| The design market: patterns (the Mody collection, the import and rewrite, placeholders, remote patterns off) | `app/web/mu-plugins/mody-platform/src/Design/` (`Patterns`, `PatternImport`), `patterns/collection.json`, `scripts/import-patterns.php`, test `scripts/patterns-test.php` | [tickets/design-market-themes-patterns.md](tickets/design-market-themes-patterns.md), [theme-development.md](documentation/theme-development.md) |
| Site provisioning (wizard answers → a member's site; `mody_provisioning_jobs`, the quota) | `app/web/mu-plugins/mody-platform/src/Provisioning/` (`Pipeline`, `Jobs`, `Quota`), test `scripts/provisioning-test.php` | [brand-profile.md](documentation/brand-profile.md#site-creation-flow), [mvp-spec.md §7](documentation/mvp-spec.md#7-site-provisioning-pipeline) |
| Brand wizard (Network Admin → Mody → New site; REST `mody-platform/v1/sites`) | `app/web/mu-plugins/mody-platform/src/Wizard/` (`WizardPage`, `WizardController`, `WizardPreview` for step 2's live preview), `assets/wizard.*` (plain JS, no build step), test `scripts/wizard-test.php` | [brand-profile.md](documentation/brand-profile.md#wizard-steps), [mvp-spec.md §6](documentation/mvp-spec.md#6-brand-wizard) |
| Member-site gating (no plugin installs or theme changes, editor-first redirects; plugins on core's Plugins screen minus the hidden ones, M4-02; the `ai` plugin curated and locked; no updates from wp-admin for anyone) | `app/web/mu-plugins/mody-platform/src/Gating/` (`MemberSite`, `MemberPlugins` for which plugins members see, `AiPlugin`, `Updates`), tests `scripts/gating-test.php`, `scripts/updates-test.php`, `scripts/member-plugins-test.php` and `scripts/ai-plugin-lockdown-test.php` | [mvp-spec.md §9, §10](documentation/mvp-spec.md#9-editor-first-rules) |
| Onboarding (the first password opens the site's Identity screen; the editor tour; user meta `mody_onboarding`, M1-03) | `app/web/mu-plugins/mody-platform/src/Onboarding/` (`Onboarding`, `EditorTour`), `assets/editor-tour.js` and `.css` (plain JS, no build step), test `scripts/onboarding-test.php` | [tickets/M1-03-chat-signup-and-editor-tour.md](tickets/M1-03-chat-signup-and-editor-tour.md), [mvp-spec.md §4](documentation/mvp-spec.md#4-tiers-trial-and-billing) |
| wp-admin colors (Ocean by default; the agent chat in the user's scheme) | `app/web/mu-plugins/mody-platform/src/Admin/AdminColors.php`, `assets/agent-chat.css`, test `scripts/admin-colors-test.php` | [tickets/admin-colors.md](tickets/admin-colors.md), [ai-engine.md](documentation/ai-engine.md#the-agent) |
| Publishing and the site title and tagline (`mody/publish-page`, `mody/update-site-settings`) | `app/web/mu-plugins/mody-platform/src/Site/` (`PagePublisher`, `SiteSettings`); page lookup in `src/SiteEditor.php` | [ai-engine.md](documentation/ai-engine.md#publishing-and-site-settings), [mvp-spec.md §8](documentation/mvp-spec.md#8-the-agent) |
| Outgoing mail (WP Mail SMTP: its constants, super admins only, its calls home off; Brevo in production) | `app/wp-config.php` (the `WPMS_*` constants), `app/web/mu-plugins/mody-platform/src/Mail/WpMailSmtp.php`, `compose.prod.yml.sample`, `sample.env`, test `scripts/mail-test.php` | [architecture.md](documentation/architecture.md#production) |

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

MVP execution follows [mvp-spec.md](documentation/mvp-spec.md): milestones M0-M5, one ticket each in `tickets/`. Milestone status: [roadmap.md](documentation/roadmap.md); what to do next, in order: [tickets/next-steps.md](tickets/next-steps.md). Per-site WP-CLI is `./bin/wp --site=<slug>` ([local-development.md](documentation/local-development.md#multisite-subdomain-network)).
