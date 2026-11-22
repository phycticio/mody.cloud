# The code, tickets and pull requests move to Codeberg

**Builds on:** [deploy-through-pull-requests.md](deploy-through-pull-requests.md) (the workflow this keeps), [tickets.md](../documentation/tickets.md) · **Feeds:** [product-pages-bari-and-plugins.md](product-pages-bari-and-plugins.md) (D17, Q7, Q9: where support issues and Composer packages live) · **Status:** planned on 2026-11-21, after the launch (`post-launch`) · **Issue:** [#46](https://github.com/phycticio/mody.cloud/issues/46)

## Goal (maintainer, 2026-11-21)
> Deberiamos movernos a codeberg.org y salir de github? Podriamos tener el mismo workflow que tenemos aqui?

Then: "Create the ticket to do this and tag it post-launch."

Move the repository, its issues, labels, milestones and pull requests from GitHub (Microsoft, US) to [Codeberg](https://codeberg.org) (Codeberg e.V., a non-profit in Berlin, running Forgejo), and keep the workflow of 2026-11-17 as it is: an issue per ticket, `feature/#NN-slug` branches, pull requests whose body says `Closes #NN`, merge commits only, the maintainer merges, `./bin/deploy --env=prod` builds `origin/main`.

**Why:** Mody Cloud says that its whole stack is European or Swiss. Rule 1 binds what the platform runs, not the development tools, so GitHub does not break it, but it is the most visible US service left in how the project is made. It is not one of the temporary exceptions (Cloudflare, Brevo) either.

## What exists today (checked 2026-11-21)
- `origin` is `git@github.com:phycticio/mody.cloud.git`, a **public** repository of about 150 MB. Merge commits only, branches deleted on merge, a `main` ruleset that asks for pull requests (the maintainer can bypass it, [deploy-through-pull-requests.md](deploy-through-pull-requests.md) Q2).
- About 46 issues and pull requests; 14 labels (with `post-launch`, created for this ticket); milestones M1 to M5.
- **No `LICENSE` file.** `package.json` says `GPL-2.0-or-later`.
- **No CI** (removed on 2026-11-17: GitHub's runners are on Azure).
- `bin/deploy` does not name GitHub's address: it fetches `origin main` and builds in a worktree of the working copy. Only one message mentions GitHub ("merged on GitHub").
- The `gh` CLI is used by the `mody-ticket`, `mody-delivery` and `mody-deploy` skills, and `architecture.md#deploying` shows `gh pr create`.
- 27 links to `github.com/phycticio/mody.cloud` in 15 files: `tickets/` (most of them, `next-steps.md` among them), the `mody-ticket` skill and `package.json`. Since 2026-11-22 also the `readme.txt` of the nine offered plugins and the theme (their Support line; [product-pages-bari-and-plugins.md](product-pages-bari-and-plugins.md), part 4): a changed link there reaches people only with a new version of each zip.
- Not part of this ticket: Composer and npm still download packages from GitHub and Packagist; the SatisPress repository `plugins.mody.cloud` is ours and does not change.

## What Codeberg offers for the same workflow
| Today on GitHub | On Codeberg (Forgejo) |
| --- | --- |
| Issues, labels, milestones | The same. Forgejo's migration from GitHub (with a GitHub token) imports issues, pull requests, labels, milestones and releases. **Inference:** it keeps the issue numbers; check in part 1. |
| Pull requests, `Closes #NN` closes the issue on merge | The same closing keywords. |
| Merge commits only, delete the branch on merge | Repository settings: allowed merge styles, "Delete branch after merge" by default. |
| The `main` ruleset | Branch protection on `main`: no direct pushes, pull requests only. |
| `gh issue …`, `gh pr create …` | `tea` (the Gitea CLI, works with Forgejo) or `fj` (forgejo-cli), or the API with `curl` (Q3). |
| No CI (Azure) | Codeberg's own CI in the EU (Forgejo Actions or Woodpecker), on request (Q4). |

**Codeberg's terms:** repositories must be under a free licence, and Codeberg does not host private commercial projects. This repository is public and GPL (`package.json`), so it fits once a `LICENSE` file is added (Q1). Codeberg's terms change; read the current Terms of Use before part 1.

## Plan
Each part is one sitting (rule 5). Part 1 is the maintainer's: it needs the maintainer's Codeberg account and a GitHub token.

0. **Before the move.** Add `LICENSE` (GPL-2.0-or-later, Q1) through a normal pull request on GitHub. Read Codeberg's current Terms of Use. Check that nothing in the repository should not be public under that licence (the briefing PDF, `documentation/`; fonts keep their own licences, already in `mody-fonts/fonts/`).
1. **The repository on Codeberg (maintainer).** Create it with Forgejo's migration from GitHub: code, issues, pull requests, labels, milestones. Check that `#41`, `#42` and `#46` kept their numbers. Settings: merge commits only, delete branches after merge, branch protection on `main` (pull requests only, Q5), the maintainer's SSH key. Issues and pull requests closed on GitHub stay as they are.
2. **The working copy and the deploy.** `git remote set-url origin git@codeberg.org:<owner>/mody.cloud.git` (keep `github` as a second remote while it mirrors). `./bin/deploy --env=prod --dry-run` must fetch `origin/main` from Codeberg and show the same commit as production. Change the "merged on GitHub" message.
3. **The CLI.** Install the chosen CLI (Q3) on the Mac (Homebrew; a development tool, not a project dependency, but ask first). Its token stays in its own configuration, never in the repository. Try each command the skills use: list and view issues, create an issue with a label, milestone and assignee, create a pull request from `feature/#NN-slug`, edit an issue. Check that branch names with `#` open correctly in Forgejo's web pages; if not, propose `feature/NN-slug` (Q6).
4. **Skills and documentation.** The `mody-ticket`, `mody-delivery` and `mody-deploy` skills; `AGENTS.md` (the deploy strategy, the "Decisions already made" line for this move), `CLAUDE.md` if needed, `documentation/architecture.md` (Deploying, the "No CI" paragraph), `documentation/tickets.md`, `documentation/local-development.md`; `package.json`'s repository field; the 27 links in `tickets/` rewritten to Codeberg's address (issue links only once part 1 proves the numbers are kept).
5. **The first pull request through Codeberg.** Part 4 goes as this ticket's own pull request on Codeberg (`Closes #46`): the issue, the branch, `Closes`, the merge commit, the issue closed, then a deploy dry run. Write the result here.
6. **GitHub afterwards (Q2).** With a read-only mirror: a push mirror from Codeberg to GitHub, GitHub's issues, pull requests, wiki and projects switched off, and the description pointing at Codeberg. Otherwise archive or delete it.

## Open questions
- **Q1. The licence.** `package.json` says GPL-2.0-or-later. Is that the licence for the whole repository (code, theme, documentation, tickets, the briefing PDF)? Should the documentation have another one (CC BY-SA, for example)?
- **Q2. GitHub after the move:** a read-only mirror (recommended for a few months: old links keep working, and nothing is lost if Codeberg is down), archived, or deleted?
- **Q3. Which CLI:** `tea` (the Gitea CLI, more widely used) or `fj` (forgejo-cli, made for Forgejo), or the API through `curl`?
- **Q4. CI on Codeberg's EU runners.** Possible now that it would not be on Azure. The tests need the Docker stack and MariaDB, and Codeberg's CI is given on request with limited resources. **Inference:** keep running the tests locally, as today.
- **Q5. Branch protection:** may the maintainer still push to `main` past it in an emergency ([deploy-through-pull-requests.md](deploy-through-pull-requests.md) Q2)?
- **Q6. Branch names with `#`** if Forgejo's web pages handle them badly: switch to `feature/NN-slug`?
- **Q7. Product pages:** with this move, support issues (D17, Q9) and the code Packagist reads (Q7) would live on Codeberg too, so `mody.cloud` would no longer send visitors to `github.com`. Decide together with [product-pages-bari-and-plugins.md](product-pages-bari-and-plugins.md). **Partly answered there on 2026-11-21 (D20):** support is GitHub issues until this move; the pages' link is one line (`support_url` in `scripts/product-pages.json`), to change with the other links in part 4.
- **Q8. Codeberg's availability.** It has had outages (attacks). A deploy needs only `git fetch`, and the GitHub mirror (Q2) is a fallback. Is that enough, or should a self-hosted Forgejo be considered later? (Not on `home.local`: production and the only copy of the code would share one laptop.)
