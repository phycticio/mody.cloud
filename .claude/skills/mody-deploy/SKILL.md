---
name: mody-deploy
description: Deploy what GitHub merged into main to production (mody.cloud on home.local) with ./bin/deploy - it builds origin/main in its own release checkout and syncs the built files. Covers Node 22, the geoip file, the dry run and its deletions, rollbacks with --ref, server-side steps the script only prints, and the checks after. Only when the maintainer explicitly asks to deploy.
---

# Mody deploy

Production is `mody.cloud`, a Docker stack on the maintainer's laptop `home.local` (SSH alias), project directory `/Volumes/Extreme/mody.cloud` (the script's default). Changes reach `main` only through pull requests merged on GitHub; `bin/deploy` deploys `origin/main`, never the working copy. Procedure and checks: [architecture.md#deploying](../../../documentation/architecture.md#deploying) and [#production](../../../documentation/architecture.md#production).

## Authorization

Deploy **only when the maintainer's current message asks to deploy**. A merged pull request is not a deploy request, and a deploy request does not carry over to later changes. If a tool refuses the deploy, give the maintainer the exact command to run with `! <command>`. Never copy files to the server by hand, never edit the server's `.env` or `compose.prod.yml`, never run destructive commands there.

## Before

1. The work is merged on GitHub ([mody-delivery](../mody-delivery/SKILL.md) opens the pull request; the maintainer merges). The branch you have checked out and uncommitted work do not matter: the script builds `origin/main` in `~/.cache/mody.cloud/release`.
2. The local stack's app container exists (its image runs Composer for the release).
3. Node 22: prefix every command with `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH"` (the script stops on another version).
4. WordPress core is in the working copy's `app/wp` (copied into the release; its version shows in the preview). The visitor-location file `app/data/geoip/dbip-city-lite.mmdb` exists (the script stops without it; it warns after 45 days: run `./bin/geoip`).
5. Check the ticket and the pull request for **server steps** the change needs (new `.env` variables, labels from `compose.prod.yml.sample`, a script to run once). Those are the maintainer's; list them.
6. A risky change (database, billing, deletions, a large refactor): back up the database first, into the gitignored `backups/` (the `mariadb-dump --no-defaults` command in architecture.md's Production host question; `ssh home.local` with `export PATH=/opt/homebrew/bin:$PATH DOCKER_HOST=unix:///Users/javo/.colima/default/docker.sock`).

## Deploy

```bash
export PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH"
./bin/deploy --env=prod --dry-run
```

Read the preview: the commit and WordPress version, the commits merged since the deployed one, the files to send and **every deletion** (the dry run lists them all). Unexpected deletions (uploads, a plugin installed outside Composer, `app/data/geoip`) stop the deploy: report them. Then, with no terminal to confirm on:

```bash
./bin/deploy --env=prod --yes
```

**Rollback:** `./bin/deploy --env=prod --ref=<earlier sha of main>`; the preview warns that it goes back in history. A database change is not rolled back by this.

## After

- When `config/` or a compose file changed, the script prints the server command (`docker compose up -d --build && docker compose restart nginx`). It is the maintainer's to run unless he asked you to.
- The script reloads PHP-FPM after a sync that changed files (since 2026-11-21: once, OPcache kept the old code of changed PHP files until a reload). If it warns that it could not, run `docker exec mody_cloud_app kill -USR2 1` on the server. **Check that new PHP really runs**, not only that pages answer: a route, a class or a behavior the change added (for example `curl -s -o /dev/null -w '%{http_code}' https://mody.cloud/wp-json/<new-namespace>`).
- Wait a few seconds (500s while files change heal by themselves), then check `https://mody.cloud/`, `/wp-login.php`, `/register/`, a member site, and `docker logs --since 5m --timestamps mody_cloud_server | grep '" 500 '` on the server: only the seconds of the sync may show any.
- Run the feature's own checks on production only when they are read-only or the maintainer asked.
- Record it: the ticket's Results ("deployed YYYY-MM-DD in `<sha>`") and `tickets/next-steps.md`. Those doc edits go through a pull request like any change.

## Hand back

The deployed commit (the server's `REVISION`: commit, WordPress version, time), what the dry run showed, the post-deploy checks and their results, and any server step still waiting on the maintainer.
