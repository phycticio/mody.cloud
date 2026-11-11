# Non-negotiable constraints

These eight rules come from the briefing (§11) and apply to every change, human or agent. Rule 8 is a deliberate override of the briefing, which asked for Spanish. Rule 7 was changed by the maintainer on 2026-11-10: Mody Cloud is run by a self-employed professional, not a non-profit association, so "fees equal real cost" no longer applies.

1. **Absolute sovereignty.** Nothing from Cloudflare, OpenAI, Google AI, AWS or Azure. All traffic and data stay in Europe or Switzerland. If a solution uses an American service, it is invalid.
2. **Exact Gutenberg format.** Output is always serialized `<!-- wp:block -->` comments. Validate the inner JSON.
3. **Only FSE, Gutenberg and Spectra.** No Astra, no builders with lock-in. Styles through `theme.json`. Portable blocks.
4. **Brand coherence.** Every generation MUST inject the site's brand into the system prompt: its WordPress settings (title, tagline, mission, tone), read when the prompt is built (M2-08). Never generate without brand context.
5. **Simplicity.** If a solution takes more than 2 hours, propose a simpler alternative. Finishing beats perfecting.
6. **Security.** Do not expose credentials or WordPress Application Passwords. Only the web tier is public, and any proxy admin panel is IP-restricted. Containers sit on an internal network. Sanitize wizard inputs.
7. **Honest plans.** Prices are the published plans, the same for everyone, and every site gets the same features. Never use "unlimited": every limit is a real one (3 GB per site, the plan's number of sites), set by what the servers can carry.
8. **Language (override).** Everything is written in English: code, code comments, documentation, commit messages, prompts to the model and agent replies. The briefing's Spanish convention no longer applies.

## How the rules show up in the repository

| Rule | Practical consequence | Read |
| --- | --- | --- |
| 1 | No third-party CDNs or fonts in the theme. EU or Swiss providers only (Infomaniak, Hetzner). Review any new plugin or service for US data flows before adding it. Two temporary, cost-driven exceptions are in production: Cloudflare and Wordfence. The rule binds what the platform runs and loads by itself, not what a member chooses to embed on their own site (decided 2026-11-11: core's embeds stay available). | [architecture.md](architecture.md#temporary-exceptions-to-rule-1) |
| 2, 3 | Theme templates and agent output use the same serialized format. Validate with a parser, never by eye. | [ai-engine.md](ai-engine.md), [theme-development.md](theme-development.md) |
| 4 | Every prompt is built with `SystemPrompt::brand_context()`, read from the site's own settings; no path calls the model without it. | [brand-profile.md](brand-profile.md) |
| 5 | Prefer the smallest change that works. Raise it if an estimate exceeds 2 hours. | [roadmap.md](roadmap.md) |
| 6 | Secrets come from `.env` or environment variables. `.env` and `auth.json` stay out of version control. Use `compose.prod.yml.sample` in production so Xdebug and MariaDB ports are not published. | [architecture.md](architecture.md#invariants-that-carry-over-from-the-briefing), [local-development.md](local-development.md) |
| 7 | Never write "unlimited" in the product or the docs. Prices and limits shown anywhere match [membership-tiers.md](membership-tiers.md). | [membership-tiers.md](membership-tiers.md) |
| 8 | Do not write Spanish into code, comments, templates or docs. Spanish terms from the briefing appear only where needed to trace back to it (the glossary and translation notes). | [project-overview.md](project-overview.md#glossary) |
