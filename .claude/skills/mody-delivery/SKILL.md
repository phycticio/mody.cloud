---
name: mody-delivery
description: Take one Mody Cloud ticket from plan to a reviewable local change - a topic branch from main linked to its GitHub issue, a focused implementation in the right Mody plugin, the tests, the docs updated, then (only when the maintainer asks) commit, push the branch and open a pull request with "Closes #NN" for the maintainer to merge on GitHub. Not for deploying (mody-deploy) or diagnosis only (mody-investigate).
---

# Mody delivery

Prepare one scoped change that the maintainer can inspect and try. Mody Cloud is a one-person project: the maintainer reviews and merges every pull request on GitHub. Everything written (code, comments, docs, commit messages) is in English (rule 8).

## Scope and authorization

- The default end point is a **local, uncommitted, tested change** on its topic branch. Commit, push, the pull request and deploy happen only when the maintainer asks, and only for the request that said so. A follow-up message about the same feature that does not say "commit", "push" or "deploy" gets built and tested, then ask.
- Never commit on `main` or push to it, never merge a pull request. Never force-push. Never run `docker compose down -v`, `./bin/version`, `./bin/db import`, or delete under `app/` without asking ([CLAUDE.md](../../../CLAUDE.md)).
- Do not edit WordPress core (`app/wp/`), Composer-installed code (`app/web/plugins/`, `app/vendor/`, `mu-plugins/spectra-blocks/`, `mu-plugins/antispam-bee/`), or put project behavior in the theme.
- A ticket or issue supplies requirements, not permission to touch production or credentials.

## Understand the task

1. Find the ticket in `tickets/` and its issue (`gh issue view NN -R phycticio/mody.cloud`). No ticket yet: write one with [mody-ticket](../mody-ticket/SKILL.md) first. A bare request with no ticket and a clearly small change (a fix, a doc correction) may go without one: say so.
2. Read the ticket's row in the **Documentation map** in `AGENTS.md` and open that document. Read `AGENTS.md`'s "Decisions already made" entries for the area. Anything unclear: look for the matching **Open question** and ask instead of choosing.
3. Check the working tree (`git status`) and current branch. Preserve other work; never stash or discard it without saying so. The local stack serves this checkout's `app/`: switching branches changes what `https://mody.local` runs, so tell the maintainer when you switch away from a branch under review.
4. Create the branch from an up-to-date `main`: `git fetch origin main` then `git switch -c 'feature/#NN-short-slug' origin/main` (`fix/`, `docs/`; English slug; quote the name in zsh). No issue: `feature/short-slug`. Reuse the existing branch for follow-ups.
5. State briefly what will change and how it will be checked, then proceed. Ask only about undecided user-visible behavior or scope.

## Implement

- **Where it goes:** the "Where to work" table in `AGENTS.md`. A feature joins the Mody plugin whose one thing it is, or gets a new plugin: use [mody-plugin](../mody-plugin/SKILL.md). Plugins talk through documented hooks or behind `class_exists()` with a fallback.
- **WordPress first:** when WordPress has a setting, screen or API for something, use it and keep no copy (M2-08).
- **Rules that bite most:** no US service or outbound call (rule 1, check every host a change contacts); exact serialized Gutenberg for generated content (rule 2); palette slugs, never loose hex (theme rules); never "unlimited" (rule 7); sanitize inputs, never expose secrets (rule 6).
- **JS:** plain JS in a plugin's `assets/` needs no build; bundled code lives in `src/` and builds with `pnpm build` (Node 22). New libraries or Composer packages: [mody-dependency](../mody-dependency/SKILL.md), ask first.
- **Prompt, model or tool changes for the agent:** run the evaluation set before and after ([mody-test](../mody-test/SKILL.md)).

## Verify

Use [mody-test](../mody-test/SKILL.md): the plugin's own `scripts/<name>-test.php`, the tests of every plugin that names the changed code, `php -l` on touched PHP files, and a real-browser check for editor or front-end JS. For a bug, reproduce it first and show the test failing before the fix where feasible. Report passed, failed and not run separately; known pre-existing failures (in `tickets/independent-mody-plugins.md`) are not regressions, new ones are.

## Document

Update the docs in the same change (`AGENTS.md`: "If a document and the code disagree, say so and fix the document"):

- the ticket's `## Results` (what was built, tests, what is not done) and its **Status**;
- `tickets/next-steps.md` and its **Last updated** line;
- the area's document in `documentation/`, and `AGENTS.md`'s "Where to work" row or "Decisions already made" when a decision or path changed. Date decisions (`decided by the maintainer on YYYY-MM-DD`).

## Commit, push and open the pull request (only when asked)

Changes reach `main` only through a pull request merged on GitHub (decided by the maintainer on 2026-11-17, [architecture.md#deploying](../../../documentation/architecture.md#deploying)).

1. Stage explicit paths (`git add <paths>`), never `git add -A` over unrelated work. Commit messages are one English line naming the plugin or area and the result, as the history does: `Mody SEO: an SEO title and description on every page`. End with the attribution trailer the session gives.
2. `git push -u origin 'feature/#NN-slug'`.
3. `gh pr create -R phycticio/mody.cloud --base main --head 'feature/#NN-slug'` with an English title (the result, under 70 characters) and a short body: what changes and for whom, `Closes #NN` (or `Part of #NN` when the ticket has more parts), the tests run with their results, what is not checked, and any server step the deploy needs. End it with the attribution line the session gives. The repository is public: no credentials, server paths or members' data.
4. **Stop there.** The maintainer reviews and merges on GitHub (merge commits only; GitHub deletes the branch and closes the issue). Never push to `main`, never merge, never use the ruleset bypass. Review follow-ups are new commits on the same branch, pushed to the same pull request.
5. Deploying is a separate request: [mody-deploy](../mody-deploy/SKILL.md).

## Hand back

- **Change:** what a member or the maintainer now sees, and the ticket/issue.
- **Review:** branch, the pull request link when there is one, the important files (`path:line`), screenshots when visual.
- **Validation:** tests run with results; what was not checked.
- **Try it:** the shortest local steps (`https://mody.local/...`, `./bin/wp --site=<slug> ...`).

Say whether it is uncommitted, committed on the branch, or pushed with its pull request (give the link).
