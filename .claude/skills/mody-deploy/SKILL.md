---
name: mody-deploy
description: Deploy Mody Cloud's pushed main branch to production (mody.cloud on home.local) with ./bin/deploy - preconditions, Node 22, the geoip file, dry run, the real sync, server-side steps the script only prints, and the checks after. Only when the maintainer explicitly asks to deploy.
---

# Mody deploy

Production is `mody.cloud`, a Docker stack on the maintainer's laptop `home.local` (SSH alias), project directory `/Volumes/Extreme/mody.cloud`. Procedure and checks: [architecture.md#deploying](../../../documentation/architecture.md#deploying) and [#production](../../../documentation/architecture.md#production).

## Authorization

Deploy **only when the maintainer's current message asks to deploy**. "Commit and push" is not a deploy request, and a deploy request does not carry over to later changes. If a tool refuses the deploy, give the maintainer the exact command to run with `! <command>`. Never copy files to the server by hand, never edit the server's `.env` or `compose.prod.yml`, never run destructive commands there.

## Before

1. `main` is checked out, clean, and pushed (`git status`, `git log origin/main..main` empty). The work was merged with [mody-delivery](../mody-delivery/SKILL.md).
2. The local stack is running (the build runs `composer install --no-dev` in the local app container).
3. Node 22: prefix every command with `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH"` (the shell's default Node 20 fails in the JS step).
4. The visitor-location file: `bin/deploy` warns when `app/data/geoip/dbip-city-lite.mmdb` is missing or older than 45 days. Missing means the sync **removes** the server's copy: run `./bin/geoip` first.
5. Check the ticket for **server steps** the change needs (new `.env` variables, labels from `compose.prod.yml.sample`, a script to run once). Those are the maintainer's; list them.
6. A risky change (database migration, billing, deletions): suggest a database backup first (the `mariadb-dump --no-defaults` command in architecture.md's Production host question, into the gitignored `backups/`).

## Deploy

```bash
export PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH"
DEPLOY_PROD_DIR=/Volumes/Extreme/mody.cloud ./bin/deploy --env=prod --dry-run
```

Read the preview: the files to send and **every deletion**. Unexpected deletions (uploads, a plugin installed outside Composer, `app/data/geoip`) stop the deploy: report them. Then, with no terminal to confirm on:

```bash
DEPLOY_PROD_DIR=/Volumes/Extreme/mody.cloud ./bin/deploy --env=prod --yes
```

`DEPLOY_PROD_DIR` is needed while `bin/deploy`'s default is the old path; check `docker inspect mody_cloud_app` mounts on the server if in doubt.

## After

- When `config/` or a compose file changed, the script prints the server command (`docker compose up -d --build && docker compose restart nginx`). It is the maintainer's to run unless he asked you to; Docker over SSH needs `export PATH=/opt/homebrew/bin:$PATH DOCKER_HOST=unix:///Users/javo/.colima/default/docker.sock`.
- Wait about 5 seconds (OPcache revalidates every 2 s; 500s in the first seconds heal by themselves), then check `https://mody.cloud/`, a member site, and `/wp-login.php` answer 200, and `docker logs --since 2m --timestamps mody_cloud_server | grep '" 500 '` is empty.
- Run the feature's own checks on production only when they are read-only or the maintainer asked.
- Record it: the ticket's Results ("deployed YYYY-MM-DD in `<sha>`") and `tickets/next-steps.md`. Those doc edits go on a `docs/` branch and wait for the next commit request.

## Hand back

The deployed commit (the server's `REVISION`), what the dry run showed, the post-deploy checks and their results, and any server step still waiting on the maintainer.
