# Deploys through pull requests, built from what GitHub merged

**Builds on:** the deploy strategy of 2026-11-08 ([architecture.md](../documentation/architecture.md#deploying)), [M5-01](M5-01-hardening-launch.md) · **Status:** decided by the maintainer on 2026-11-17, built the same day on `feature/#11-deploy-through-pull-requests`, in review as the first pull request of the new flow · **Issue:** [#11](https://github.com/phycticio/mody.cloud/issues/11)

## Goal (maintainer, 2026-11-17)
> Let's change the deployment process. We need to submit a PR and merge in github. Then we need to sync the latest main in the production server home.local with all the artifacts and builds already run.

## What it replaces
Until 2026-11-17 every branch was merged into `main` locally with `--no-ff` and pushed, bypassing the `main` ruleset's pull-request rule; `bin/deploy` then built inside the working copy (it had to be a clean, pushed `main`), ran `composer install --no-dev` in the local stack's app container, synced, and put the dev requirements back.

## Decision
1. **Pull requests.** A topic branch per change, named after its issue (`feature/#NN-slug`), pushed, with a pull request against `main` whose body says `Closes #NN`, the tests run and what is not checked. The maintainer merges it on GitHub with a merge commit. Agents push and open pull requests when asked; they never push to `main` and never merge.
2. **The build is made from the merged commit, on the Mac, before the sync.** `bin/deploy` fetches `origin/main`, checks the commit out in its own worktree (`~/.cache/mody.cloud/release`), builds it there (pnpm with the frozen lockfile; Composer without dev requirements in a one-off container of the app image), and syncs that release. The server receives built files only; it never builds.
3. **No CI on GitHub.** Its hosted runners run on Azure (rule 1). Tests run locally and the pull request lists them. The previous project's workflows are removed.

### Not chosen
- **GitHub Actions builds and sends to the server:** Azure runners (rule 1); the SatisPress credential and an SSH key into the home laptop would live on GitHub; home.local takes no inbound connection; the visitor-location file is not in git.
- **The server pulls from git and builds:** production would become a git checkout with Node, pnpm and the Composer credential; a failed build would leave the live site half changed; the build load lands on the production laptop.
- **Atomic release swap** (a new directory, then a switch): the containers' bind mounts make it more than 2 hours (rule 5). Later, if the second of 500s after a sync matters.

## Plan
1. `bin/deploy`: deploy `origin/main` (or `--ref=<sha>` for a rollback, which must be part of `origin/main`) from a release worktree; copy in what git does not hold (WordPress core from `app/wp`, the visitor-location file, `app/auth.json`); keep every server check; compare by checksum; show the commits merged since the deployed one; write `wordpress=` into `REVISION`; the default production directory `/Volumes/Extreme/mody.cloud`.
2. Remove `.github/workflows/` (Tamarind's).
3. GitHub settings: merge commits only, delete branches on merge.
4. Documentation: architecture.md (Deploying), local-development.md, tickets.md, AGENTS.md, CLAUDE.md, the `mody-delivery`, `mody-deploy` and `mody-ticket` skills.

## Open questions
- **Q1. WordPress core is not pinned in git.** The release copies `app/wp` from the working copy, as deploys always did (7.1.4 on 2026-11-17, while `.env` says `WORDPRESS_VERSION=7.1.3`). Pinning the version in a tracked file and downloading it in the build would make a release depend on git alone. **Open question:** worth doing?
- **Q2. The ruleset bypass.** The maintainer's account can still push to `main` past the pull-request rule. Keep it for emergencies, or remove it?

## Results
**Built 2026-11-17.**
- `bin/deploy` rewritten as planned. A dry run of the deployed commit (`6db8aaf`) against production, built from scratch, changed only Composer's autoloader files (a new hash per install) and an empty `app/vendor/bin` on the server: the release reproduces the old build. Without `--checksum` every tracked file showed as changed (new modification times). About a minute for a build and dry run.
- Refused as expected: a commit not in `origin/main` ("open a pull request, merge it, then deploy"), an unknown ref, Node 20.
- The first attempt put the release inside the repository: ESLint 8 reads its parents' configuration and found `@wordpress` twice. The release lives in `~/.cache/mody.cloud/` since.
- GitHub, set on 2026-11-17: the repository allows merge commits only and deletes branches on merge.
- **For the maintainer, in GitHub (Settings → Rules → Rulesets → `main` → Require a pull request before merging):** set **Required approvals** to 0 (it is 1: nobody else can approve his pull requests, so every merge would need the bypass) and **Allowed merge methods** to Merge only. The agent's change was refused as loosening a protection; it is his to make. The ruleset's required status checks list is empty, so it blocks nothing.
- **Not checked yet:** a real deploy with the new script (the first one is this pull request's own deploy, after the merge).
