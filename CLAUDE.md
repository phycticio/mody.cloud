# CLAUDE.md

@AGENTS.md

## Claude Code notes

`AGENTS.md` (imported above) holds the rules, layout, commands and the **Documentation map**. This file only adds Claude Code specifics. Add shared guidance to `AGENTS.md`, not here.

### Read project documentation on demand

- Before starting a task, find its row in the Documentation map in `AGENTS.md` and open that file with the Read tool. Open another document when the work shifts area. Do not preload them all.
- New to the project, or a term is unclear: read [project-overview.md](documentation/project-overview.md) (its glossary maps the briefing's Spanish terms to English). Choosing what to build next, or checking milestone status: read [roadmap.md](documentation/roadmap.md).
- Theme work (templates, parts, `theme.json`, palette): read [theme-development.md](documentation/theme-development.md) first and run its checks afterwards.
- AI agent work (prompts, model calls, validation): read [ai-engine.md](documentation/ai-engine.md) and [brand-profile.md](documentation/brand-profile.md) first.
- Quotas, roles, newsletter caps: read [membership-tiers.md](documentation/membership-tiers.md) and [newsletter.md](documentation/newsletter.md).
- Infrastructure or Composer changes: read [architecture.md](documentation/architecture.md) first. It also says which parts of the briefing were deliberately not adopted.
- Anything unclear or ambiguous: look for the matching **Open question** in the documents above and ask instead of choosing for the team. Start from [documentation/README.md](documentation/README.md) if you are unsure which document applies.

### Working agreements

- Everything you write is in English: replies, code, comments, commit messages and docs ([constraints.md](documentation/constraints.md), rule 8).
- Ask before running anything destructive or hard to reverse: `docker compose down -v` (deletes the database volume), `./bin/version` (replaces WordPress core), `./bin/db import` (overwrites the database), or deleting under `app/`. What these do is described in [local-development.md](documentation/local-development.md#commands).
- Run commands from the repository root and use the `bin/` wrappers so they run inside the containers. Some scripts are stale, check [local-development.md](documentation/local-development.md#known-stale-items) before trusting one.
- When behavior or a decision changes, update the matching document in the same change ([documentation/README.md](documentation/README.md#keeping-the-docs-current)).
