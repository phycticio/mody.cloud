# CLAUDE.md

@AGENTS.md

## Claude Code notes

`AGENTS.md` (imported above) holds the rules, layout, commands and the **Documentation map**. This file only adds Claude Code specifics. Add shared guidance to `AGENTS.md`, not here.

### Read project documentation on demand

- Before starting a task, find its row in the Documentation map in `AGENTS.md` and open that file with the Read tool. Open another document when the work shifts area. Do not preload them all.
- Anything unclear or ambiguous: look for the matching **Open question** in the mapped documents and ask instead of choosing for the team. Start from [documentation/README.md](documentation/README.md) if you are unsure which document applies.

### Working agreements

- Ask before running anything destructive or hard to reverse: `docker compose down -v` (deletes the database volume), `./bin/version` (replaces WordPress core), `./bin/db import` (overwrites the database), or deleting under `app/`. What these do is described in [local-development.md](documentation/local-development.md#commands).
- Run commands from the repository root and use the `bin/` wrappers so they run inside the containers. Some scripts are stale, check [local-development.md](documentation/local-development.md#known-stale-items) before trusting one.
