# Mody Cloud documentation

The product source of truth is `Mody_Cloud_Briefing_Agente_Codificacion_v4.pdf` (v4, October 2026, written in Spanish). The documents here are derived from it and adapted to the repository as it exists today.

## Precedence

1. The briefing's non-negotiable constraints apply ([constraints.md](constraints.md)), with one deliberate override: **everything is written in English**. That replaces the briefing's rule 8 (Spanish).
2. For infrastructure, **the stack in this repository wins** over the briefing's stack recommendations. See [Briefing recommendations not adopted](architecture.md#briefing-recommendations-not-adopted).
3. **Dated decisions by the maintainer win over the briefing**: those in [mvp-spec.md](mvp-spec.md) and AGENTS.md ("Decisions already made"). One of them (2026-11-10) replaced the briefing's non-profit association and its tiers with a self-employed business and plans of 1, 3 and 10 sites ([membership-tiers.md](membership-tiers.md)).
4. For everything else (product, data model, AI engine, roadmap) the briefing is authoritative. These docs record it and flag open questions instead of inventing answers.

Statements marked **Open question** or **Inference** are not in the briefing.

The briefing's Spanish terms were translated. The mapping is in the glossary in [project-overview.md](project-overview.md#glossary).

## Index and when to read

Coding agents and developers: read the document that matches the work **before** starting, and open another one when the work shifts to a different area. Do not preload all of them.

| Document | Read when you are... | Covers | Briefing § |
| --- | --- | --- | --- |
| [project-overview.md](project-overview.md) | New to the project, or unsure what the product is or what a term means | What Mody Cloud is, principles, visual identity, economics, glossary | Intro, 1, 12 |
| [constraints.md](constraints.md) | Making or reviewing any change | The eight non-negotiable rules and their consequences in the repository | 11 |
| [architecture.md](architecture.md) | Touching Docker, Nginx, `wp-config.php`, Composer or the repository layout, production or deploys, or making an infrastructure decision | Current stack, layout, target system, what was not adopted, production host and deploy, open questions | 2, 8 |
| [local-development.md](local-development.md) | Running or debugging the stack, using `bin/` scripts, or hitting a script that looks broken | First run, commands, environment variables, known stale items | n/a |
| [theme-development.md](theme-development.md) | Editing templates, parts, `theme.json`, palette, fonts or styles | The FSE theme scaffold and how to extend it | 1, 2, 4 |
| [ai-engine.md](ai-engine.md) | Building or changing the AI agent: model calls, prompts, block whitelist, validation | Model, provider, block serialization, master prompt rules, first task | 3, 4, 12 |
| [brand-profile.md](brand-profile.md) | Working on the wizard, the brand (each site's own settings and styles), prompt assembly or site creation | Wizard, where the brand lives, the brand context, site creation flow | 6, 7 |
| [membership-tiers.md](membership-tiers.md) | Working on plans, prices, site limits or the space per site | The plans (1, 3 and 10 sites, monthly or yearly), what every site gets, gating | 5 |
| [newsletter.md](newsletter.md) | Working on newsletter sending, sending caps or opt-in | Infomaniak Newsletter integration | 9 |
| [roadmap.md](roadmap.md) | Choosing what to work on next, or checking milestone status | Milestones and the first concrete step | 10, 12 |
| [tickets.md](tickets.md) | Creating, updating or picking up a ticket, or checking what the team is working on | Where tickets live, and how planning and in-progress work are tracked | n/a |

## Keeping the docs current

When behavior or a decision changes, update the matching document in the same change. If you add a document, add it to the index above and to the documentation maps in `readme.md` and `AGENTS.md`.
