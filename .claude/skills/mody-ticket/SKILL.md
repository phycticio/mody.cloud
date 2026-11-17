---
name: mody-ticket
description: Create, update or pick up a Mody Cloud ticket - the durable record in tickets/<slug>.md, its place in tickets/next-steps.md, and its linked GitHub issue on phycticio/mody.cloud. Use when planning work, writing down a decision, or before mody-delivery starts something new. Not for implementing changes.
---

# Mody ticket

A ticket is a Markdown file in `tickets/` (the durable record: goal, decisions, open questions, results). Every ticket has one GitHub issue on `phycticio/mody.cloud` that points at it, so the work can be linked from branches and merge commits. The ticket file holds the substance; the issue is a short pointer. Rules: [documentation/tickets.md](../../../documentation/tickets.md).

**The repository is public.** Issues are visible to anyone. Never put credentials, `.env` values, server paths, member or customer data, or the maintainer's private details in an issue. Summarize; the detail stays in the ticket file.

## Scope

Write or update one ticket and its issue. Do not implement, branch or commit. Creating or editing the GitHub issue is part of this skill (decided by the maintainer on 2026-11-17); committing the ticket file follows the normal rule: only when the maintainer asks.

## Create or update the ticket file

1. **Search first.** `ls tickets/`, `grep -ril <topic> tickets/ documentation/`, and `gh issue list -R phycticio/mody.cloud --state all --search <topic>`. Reuse an existing ticket or issue rather than duplicating. Milestone tickets `M1-01`... map to the early issues #3-#10.
2. **Name:** `tickets/<kebab-slug>.md` (English, what the work is: `seo.md`, `custom-domains.md`). Milestone work keeps `M<n>-<nn>-<slug>.md`.
3. **Header line** under the title, as the existing tickets do:
   `**Builds on:** ... · **Feeds:** ... · **Status:** planned on YYYY-MM-DD · **Issue:** [#NN](https://github.com/phycticio/mody.cloud/issues/NN)`
4. **Body** (short English sections, the shape of `tickets/seo.md`):
   - `## Goal (maintainer, YYYY-MM-DD)` with the maintainer's own words quoted when he gave them;
   - `## What exists today` (checked, with the date);
   - `## Plan` in parts small enough to finish in one sitting (rule 5: more than about 2 hours means propose a simpler cut);
   - `## Open questions` numbered `Q1`... Mark guesses **Inference** or **Open question**; never decide for the maintainer;
   - `## Results` added when work is built: what was built, tests run, what is not done.
5. Check the plan against the eight rules in [constraints.md](../../../documentation/constraints.md) (rule 1 sovereignty and rule 7 honest plans most often) and against "Decisions already made" in `AGENTS.md`.
6. **Next steps:** add or move the item in [tickets/next-steps.md](../../../tickets/next-steps.md) (Now / Waiting on the maintainer / later sections) and update its **Last updated** line. When a milestone changes status, update [roadmap.md](../../../documentation/roadmap.md) too.

## Create or update the GitHub issue

Use `gh` against `phycticio/mody.cloud` explicitly (`-R phycticio/mody.cloud`).

- **Title:** English, under 70 characters, what changes for whom. `Members set a custom domain for their site`, not `mody-domains: add ProxyManager`.
- **Body:** two to five lines: one sentence of goal, the link to the ticket file (`Ticket: https://github.com/phycticio/mody.cloud/blob/main/tickets/<slug>.md`; it resolves once the ticket reaches `main`), and the status. No copied ticket body.
- **Labels:** one type: `enhancement` (new behavior), `bug`, `task` (chores, infrastructure, operations) or `documentation` (docs only). Add `design`, `ux` or `accessibility` when they apply. Only labels that exist (`gh label list -R phycticio/mody.cloud`); do not create new ones without asking.
- **Milestone:** `M1`...`M5` when the ticket belongs to one (`--milestone M3`); none otherwise.
- **Assignee:** `phycticio`.
- Put the issue number back into the ticket's header line.

## Keep the link derivable

- Branch `feature/#NN-slug` (or `fix/`, `docs/`), and the `--no-ff` merge commit's body says `Closes #NN`, or `Part of #NN` when more work remains. Pushing `main` then closes the issue. [mody-delivery](../mody-delivery/SKILL.md) owns this.
- Close an issue by hand only to decline it (`wontfix`, `duplicate`) with a one-line comment, and say so in the ticket.

## Hand back

The ticket path, the issue link (or the draft if `gh` failed), the labels and milestone, and the next-steps line added or changed. Say that nothing is committed.
