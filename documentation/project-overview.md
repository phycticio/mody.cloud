# Project overview

## What Mody Cloud is

Mody Cloud is a digital sovereignty service, run by its maintainer as a self-employed professional in Spain (until 2026-11-10 the docs described a non-profit association). Members create an account and define their brand identity once, through a wizard. A sovereign AI agent then generates every page of their WordPress site, staying faithful to that brand. The whole stack is European or Swiss. Every site gets the same features, and plans set only the number of sites. Members own their code and their data.

The MVP is a generator of sovereign WordPress sites with accounts, a brand per site and coherent generation.

## Principles

The full rules are in [constraints.md](constraints.md).

- **Absolute sovereignty.** Traffic and data stay in Europe or Switzerland. A solution that depends on a US service is invalid.
- **Zero lock-in.** Full Site Editing theme, Gutenberg and Spectra blocks. No Elementor or other builders. Templates are portable blocks, not proprietary configuration. See [theme-development.md](theme-development.md).
- **Coherence.** The site's brand (its WordPress settings) is injected into every generation. Nothing is generated without brand context. See [brand-profile.md](brand-profile.md).
- **Honest plans.** Public prices, the same for everyone; every limit is real (3 GB per site, the plan's sites), never "unlimited". See [membership-tiers.md](membership-tiers.md).
- **Simplicity.** Finishing beats perfecting.

## Visual identity

| Element | Value |
| --- | --- |
| Domain | `mody.cloud` (registered) |
| Primary color | `#005f6b` (teal) |
| Dark gray | `#333333` |
| Light background | `#f5f5f5` |
| Magenta accent | `#b50ba4` |
| Deep black | `#01110a` |
| Logo | "O" isotype with teal arc plus the MODY CLOUD wordmark (exists, not yet in this repo) |

These colors are the named palette in the theme's `theme.json`. See [theme-development.md](theme-development.md#design-tokens).

## How it works

1. A registered member checks whether they still have quota for another site ([membership-tiers.md](membership-tiers.md)).
2. They complete the 3-step brand wizard, stored in the new site's own settings and styles ([brand-profile.md](brand-profile.md)).
3. The platform creates the site and writes the brand's palette and fonts into the site's styles.
4. The agent combines the site's brand (system prompt) with the member's prompt (user prompt), calls the model and validates the serialized Gutenberg blocks it returns ([ai-engine.md](ai-engine.md)).
5. The validated content is inserted as the site's home page. "Add page" reuses the site's brand without asking again.
6. Later: the site's newsletter domain is registered with a monthly sending cap ([newsletter.md](newsletter.md); out of the MVP).

## Economics

- AI cost per generated site is about 0.002 EUR. With 1M free credits that is thousands of sites at no cost. See [ai-engine.md](ai-engine.md).
- **Plans** (decided by the maintainer on 2026-11-10): 1 site 9.99 EUR a month or 89.99 EUR a year, 3 sites 25.99 or 249.99, 10 sites 49.99 or 499.99, after a free 30-day trial. Every site gets the same: 3 GB, every plugin in the market, its own Mody, a custom domain and a way out. These are prices, not costs passed through: the briefing's "fees equal real cost" belonged to the non-profit association it described. See [membership-tiers.md](membership-tiers.md).
- The bottleneck is **server capacity**. The limits (sites per plan, 3 GB per site) are capacity control.
- The briefing's dedicated VPS tier is not part of the plans (membership-tiers.md, Open questions).

## Glossary

The briefing is written in Spanish. These docs use English terms, mapped here so the briefing stays traceable.

| Term used here | Briefing term | Meaning |
| --- | --- | --- |
| Brand (formerly brand profile) | Expediente de Marca | A site's identity: its WordPress settings (title, tagline, logo, icon, plus the mission and tone site settings) and its Global Styles. Read by the agent on every generation. The separate profile table was dropped on 2026-11-08 (M2-08). |
| Wizard | Wizard de identidad | The 3-step flow that creates a site and its brand. |
| Member | Socio | Any account holder (a customer). The briefing's tiers (Colaborador, Socio, Patrono at 5, 15 and 50 EUR, and VPS dedicado) were replaced on 2026-11-10 by plans named by their number of sites. The code still uses `collaborator`, `member` and `patron` until the billing work. |
| Self-employed professional | Autónomo | How the maintainer runs Mody Cloud in Spain (2026-11-10). It replaced the briefing's non-profit association. |
| Milestone | Hito | A roadmap deliverable. See [roadmap.md](roadmap.md). |
| Tone: `friendly`, `technical` | `cercano`, `tecnico` | Values of the tone field, translated. `formal` is unchanged. |
| Serialized blocks | Formato serializado de bloques | Gutenberg's `<!-- wp:... -->` comment format, used for page content and theme templates alike. |
