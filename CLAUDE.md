# CLAUDE.md

@AGENTS.md

## Claude Code notes

`AGENTS.md` (imported above) holds the rules, layout, commands and the **Documentation map**. This file only adds Claude Code specifics. Add shared guidance to `AGENTS.md`, not here.

### Read project documentation on demand

- Before starting a task, find its row in the Documentation map in `AGENTS.md` and open that file with the Read tool. Open another document when the work shifts area. Do not preload them all.
- Anything unclear or ambiguous: look for the matching **Open question** in the mapped documents and ask instead of choosing for the team. Start from [documentation/README.md](documentation/README.md) if you are unsure which document applies.

### Skills

Project skills live in `.claude/skills/` (2026-11-17). Use the one that fits the task:

| Skill | For |
| --- | --- |
| `mody-ticket` | Writing or updating a ticket in `tickets/`, `next-steps.md`, and its GitHub issue |
| `mody-delivery` | A ticket to a tested local change on `feature/#NN-slug`; commit, push and a pull request with `Closes #NN` only when asked; the maintainer merges on GitHub |
| `mody-investigate` | Read-only diagnosis, locally or on production |
| `mody-test` | Which `scripts/*-test.php` to run and how; the agent's evaluation set; the headless browser check |
| `mody-plugin` | A new independent Mody plugin, or code crossing between plugins |
| `mody-dependency` | Composer, npm or core changes: ask first, rule 1 review, guard tests |
| `mody-deploy` | `./bin/deploy --env=prod`: builds the merged `origin/main` and syncs it, only when the maintainer asks |
| `mody-branding` | Mody Cloud's own logo, favicon, colors and voice (not members' brands) |

When a skill and a document disagree, the document wins: fix the skill in the same change.

### Working agreements

- Ask before running anything destructive or hard to reverse: `docker compose down -v` (deletes the database volume), `./bin/version` (replaces WordPress core), `./bin/db import` (overwrites the database), or deleting under `app/`. What these do is described in [local-development.md](documentation/local-development.md#commands).
- Run commands from the repository root and use the `bin/` wrappers so they run inside the containers. Some scripts are stale, check [local-development.md](documentation/local-development.md#known-stale-items) before trusting one.
