# Non-negotiable constraints

These eight rules come from the briefing (§11) and apply to every change, human or agent. Rule 8 is a deliberate override of the briefing, which asked for Spanish.

1. **Absolute sovereignty.** Nothing from Cloudflare, OpenAI, Google AI, AWS or Azure. All traffic and data stay in Europe or Switzerland. If a solution uses an American service, it is invalid.
2. **Exact Gutenberg format.** Output is always serialized `<!-- wp:block -->` comments. Validate the inner JSON.
3. **Only FSE, Gutenberg and Spectra.** No Astra, no builders with lock-in. Styles through `theme.json`. Portable blocks.
4. **Brand coherence.** Every generation MUST inject the brand profile into the system prompt. Never generate without brand context.
5. **Simplicity.** If a solution takes more than 2 hours, propose a simpler alternative. Finishing beats perfecting.
6. **Security.** Do not expose credentials or WordPress Application Passwords. Only the web tier is public, and any proxy admin panel is IP-restricted. Containers sit on an internal network. Sanitize wizard inputs.
7. **Non-profit.** Fees equal real cost, with no margin. Never use "unlimited". Limits are defined by VPS resources.
8. **Language (override).** Everything is written in English: code, code comments, documentation, commit messages, prompts to the model and agent replies. The briefing's Spanish convention no longer applies.

## How the rules show up in the repository

| Rule | Practical consequence | Read |
| --- | --- | --- |
| 1 | No third-party CDNs or fonts in the theme. EU or Swiss providers only (Infomaniak, Hetzner). Review any new plugin or service for US data flows before adding it. | [architecture.md](architecture.md) |
| 2, 3 | Theme templates and agent output use the same serialized format. Validate with a parser, never by eye. | [ai-engine.md](ai-engine.md), [theme-development.md](theme-development.md) |
| 4 | The agent code has no path that calls the model without a brand profile. | [brand-profile.md](brand-profile.md) |
| 5 | Prefer the smallest change that works. Raise it if an estimate exceeds 2 hours. | [roadmap.md](roadmap.md) |
| 6 | Secrets come from `.env` or environment variables. `.env` and `auth.json` stay out of version control. Use `compose.prod.yml.sample` in production so Xdebug and MariaDB ports are not published. | [architecture.md](architecture.md#invariants-that-carry-over-from-the-briefing), [local-development.md](local-development.md) |
| 7 | Never write "unlimited" in the product or the docs. | [membership-tiers.md](membership-tiers.md) |
| 8 | Do not write Spanish into code, comments, templates or docs. Spanish terms from the briefing appear only where needed to trace back to it (the glossary and translation notes). | [project-overview.md](project-overview.md#glossary) |
