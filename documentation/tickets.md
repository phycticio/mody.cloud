# Tickets

All tickets live in the `tickets/` directory at the root of this repository. Each ticket has a GitHub issue on `phycticio/mody.cloud` that points at it (decided by the maintainer on 2026-11-17).

## Why tickets live in the code

The tickets are part of the codebase so that **every agent can see what everyone is working on**. Any coding agent or human that opens this repository has access to the full list of tickets, which keeps work visible and avoids duplication. Ticket changes are committed like code, so what was agreed and why is preserved in the history.

## Where things live

| Thing | Location |
| --- | --- |
| Ticket definitions (the durable record) | `tickets/` at the repository root |
| The ordered list of next steps | [`tickets/next-steps.md`](../tickets/next-steps.md). Update it when a ticket is finished or the order changes. |
| A ticket's GitHub issue | `phycticio/mody.cloud` issues: a short title, a link to the ticket file, one type label (`enhancement`, `bug`, `task` or `documentation`), the milestone (`M1`...`M5`) when it has one, assigned to the maintainer. The repository is public: no credentials, server details or members' data in an issue. |

## Conventions

- New tickets go in `tickets/`, with `**Issue:** #NN` in their header line.
- The issue is a pointer; the ticket file holds the goal, decisions, open questions and results.
- Branches carry the issue number (`feature/#NN-slug`), and the pull request's body says `Closes #NN` (or `Part of #NN`), so merging it on GitHub closes the issue ([architecture.md](architecture.md#deploying)).
- Close an issue by hand only to decline it, with a comment.
- The early milestone issues (#2 to #10, 2026-11-02) copied their ticket's body; newer issues only link to it.
- The Claude Code skills `mody-ticket` and `mody-delivery` (`.claude/skills/`) follow these conventions.
