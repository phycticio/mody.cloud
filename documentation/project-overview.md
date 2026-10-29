# Project overview

## What Mody Cloud is

Mody Cloud is a non-profit association for digital sovereignty. Members create an account and define their brand identity once, through a wizard. A sovereign AI agent then generates every page of their WordPress site, staying faithful to that brand. The whole stack is European or Swiss. Fees cover real costs with no profit margin. Members own their code and their data.

The MVP is a generator of sovereign WordPress sites with accounts, a brand profile and coherent generation.

## Principles

The full rules are in [constraints.md](constraints.md).

- **Absolute sovereignty.** Traffic and data stay in Europe or Switzerland. A solution that depends on a US service is invalid.
- **Zero lock-in.** Full Site Editing theme, Gutenberg and Spectra blocks. No Elementor or other builders. Templates are portable blocks, not proprietary configuration. See [theme-development.md](theme-development.md).
- **Coherence.** The brand profile is injected into every generation. Nothing is generated without brand context. See [brand-profile.md](brand-profile.md).
- **Non-profit.** Fees equal real cost. Limits are defined by resources, never "unlimited". See [membership-tiers.md](membership-tiers.md).
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
2. They complete the 3-step brand wizard, stored as the brand profile ([brand-profile.md](brand-profile.md)).
3. The platform creates the site and writes the brand's palette and fonts into the site's styles.
4. The agent combines the brand profile (system prompt) with the member's prompt (user prompt), calls the model and validates the serialized Gutenberg blocks it returns ([ai-engine.md](ai-engine.md)).
5. The validated content is inserted as the site's home page. "Add page" reuses the brand profile without asking again.
6. The site's newsletter domain is registered with a monthly sending cap for the member's tier ([newsletter.md](newsletter.md)).

## Economics

- AI cost per generated site is about 0.002 EUR. With 1M free credits that is thousands of sites at no cost. See [ai-engine.md](ai-engine.md).
- The real cost per tier is Hetzner hosting plus Infomaniak Newsletter credits plus AI plus maintenance. Each fee covers that cost with no profit.
- The bottleneck is **VPS capacity**. Role-based gating (sites, sends, disk) is capacity control. See [membership-tiers.md](membership-tiers.md).
- Dedicated VPS tier: price is the real VPS cost plus management. Expansion is negotiated when consumption reaches a threshold.

## Glossary

The briefing is written in Spanish. These docs use English terms, mapped here so the briefing stays traceable.

| Term used here | Briefing term | Meaning |
| --- | --- | --- |
| Brand profile | Expediente de Marca | The member's identity record. Single source of truth the agent reads on every generation. |
| Wizard | Wizard de identidad | The 3-step flow that creates the brand profile. |
| Collaborator, Member, Patron | Colaborador, Socio, Patrono | The three fixed-quota tiers (5, 15, 50 EUR per month). "Member" is also the generic word for any account holder, as "socio" is in the briefing. |
| Dedicated VPS | VPS dedicado | Top tier with no fixed limits. Sized to the member's own VPS. |
| Milestone | Hito | A roadmap deliverable. See [roadmap.md](roadmap.md). |
| Tone: `friendly`, `technical` | `cercano`, `tecnico` | Values of the tone field, translated. `formal` is unchanged. |
| Serialized blocks | Formato serializado de bloques | Gutenberg's `<!-- wp:... -->` comment format, used for page content and theme templates alike. |
