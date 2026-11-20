# Project overview

## What Mody Cloud is

Mody Cloud is a digital sovereignty service, run by its maintainer as a self-employed professional in Spain (until 2026-11-10 the docs described a non-profit association). Members create an account and define their brand identity once, through a wizard. A sovereign AI agent then generates every page of their WordPress site, staying faithful to that brand. The whole stack is European or Swiss. Every site gets the same features, and plans set only the number of sites. Members own their code and their data.

The MVP is a generator of sovereign WordPress sites with accounts, a brand per site and coherent generation.

## Principles

The full rules are in [constraints.md](constraints.md).

- **Absolute sovereignty.** Traffic and data stay in Europe or Switzerland. A solution that depends on a US service is invalid.
- **Zero lock-in.** Full Site Editing theme, Gutenberg and Spectra blocks. No Elementor or other builders. Templates are portable blocks, not proprietary configuration. See [theme-development.md](theme-development.md).
- **Coherence.** The site's brand (its WordPress settings) is injected into every generation. Nothing is generated without brand context. See [brand-profile.md](brand-profile.md).
- **Honest plans.** Public prices, the same for everyone; every limit is real (1 GB per site, Mody's tokens a month, the account's sites), never "unlimited". See [membership-tiers.md](membership-tiers.md).
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
- **Free, freemium later** (decided by the maintainer on 2026-11-20, [tickets/free-mvp.md](../tickets/free-mvp.md); it replaces the price per site and the launch offer of 2026-11-13): every account gets one site free, with no card and no end date, for people who live in Spain. Mody Cloud takes no payments and no donations while it is run by its maintainer as a private person. The price per site decided on 2026-11-13 (7.99 EUR a month or 79.99 a year, VAT included) and the billing built for it are kept, switched off, for optional paid tiers later.
- The bottleneck is **server capacity**, and while it is free, **Mody's tokens**: they are paid to Infomaniak for every site. The limits (one site per account, 500 MB per site, Mody's tokens a month) are capacity and cost control: a site's month costs about 0.85 EUR at regular use and at most about 3.25 EUR at the allowance (membership-tiers.md, Gating).
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
