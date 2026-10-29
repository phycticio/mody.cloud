# Mody Cloud documentation

The product source of truth is `Mody_Cloud_Briefing_Agente_Codificacion_v4.pdf` (v4, October 2026, written in Spanish). The documents here are derived from it and adapted to the repository as it exists today.

## Precedence

1. The briefing's non-negotiable constraints apply ([constraints.md](constraints.md)), with one deliberate override: **everything is written in English**. That replaces the briefing's rule 8 (Spanish).
2. For infrastructure, **the stack in this repository wins** over the briefing's stack recommendations. See [Briefing recommendations not adopted](architecture.md#briefing-recommendations-not-adopted).
3. For everything else (product, data model, AI engine, roadmap) the briefing is authoritative. These docs record it and flag open questions instead of inventing answers.

Statements marked **Open question** or **Inference** are not in the briefing.

The briefing's Spanish terms were translated. The mapping is in the glossary in [project-overview.md](project-overview.md#glossary).

## Index and when to read

Coding agents and developers: read the document that matches the work **before** starting, and open another one when the work shifts to a different area. Do not preload all of them.

| Document | Read when you are... | Covers | Briefing § |
| --- | --- | --- | --- |
| [project-overview.md](project-overview.md) | New to the project, or unsure what the product is or what a term means | What Mody Cloud is, principles, visual identity, economics, glossary | Intro, 1, 12 |
| [constraints.md](constraints.md) | Making or reviewing any change | The eight non-negotiable rules and their consequences in the repository | 11 |
| [architecture.md](architecture.md) | Touching Docker, Nginx, `wp-config.php`, Composer or the repository layout, or making an infrastructure decision | Current stack, layout, target system, what was not adopted, open questions | 2, 8 |
| [local-development.md](local-development.md) | Running or debugging the stack, using `bin/` scripts, or hitting a script that looks broken | First run, commands, environment variables, known stale items | n/a |
| [theme-development.md](theme-development.md) | Editing templates, parts, `theme.json`, palette, fonts or styles | The FSE theme scaffold and how to extend it | 1, 2, 4 |
| [ai-engine.md](ai-engine.md) | Building or changing the AI agent: model calls, prompts, block whitelist, validation | Model, provider, block serialization, master prompt rules, first task | 3, 4, 12 |
| [brand-profile.md](brand-profile.md) | Working on the wizard, the `mody_brand_profiles` table, prompt assembly or site creation | Wizard, schema, coherent generation, site creation flow | 6, 7 |
| [membership-tiers.md](membership-tiers.md) | Working on quotas, roles, site limits or dedicated VPS thresholds | Tiers, gating logic, dedicated VPS rules | 5 |
| [newsletter.md](newsletter.md) | Working on newsletter sending, sending caps or opt-in | Infomaniak Newsletter integration | 9 |
| [roadmap.md](roadmap.md) | Choosing what to work on next, or checking milestone status | Milestones and the first concrete step | 10, 12 |

## Keeping the docs current

When behavior or a decision changes, update the matching document in the same change. If you add a document, add it to the index above and to the documentation maps in `readme.md` and `AGENTS.md`.
