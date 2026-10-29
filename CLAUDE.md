# CLAUDE.md

@AGENTS.md

## Claude Code notes

- `AGENTS.md` above is the source of truth for rules, layout and commands. Keep it that way: add shared guidance there, and only Claude-specific guidance here.
- Briefing rule 8 applies to you: reply and write code comments in Spanish, except technical names. Files under `documentation/` stay in English.
- Ask before running anything destructive or hard to reverse: `docker compose down -v` (deletes the database volume), `./bin/version` (replaces WordPress core), `./bin/db import` (overwrites the database), or deleting under `app/`.
- Run commands from the repository root. Use the `bin/` wrappers so commands run inside the containers.
- When the briefing is silent or ambiguous, check the **Open question** items in `documentation/` and ask instead of choosing for the team.
