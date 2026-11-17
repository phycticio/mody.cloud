---
name: mody-investigate
description: Diagnose a Mody Cloud problem (local stack or production on home.local) by reproducing it, tracing evidence through the right Mody plugin, separating proven causes from hypotheses, and proposing a fix and the test that would prove it. Read-only - no code changes, no data changes, no restarts. Not for implementing the fix (mody-delivery).
---

# Mody investigate

Deliver a short diagnosis the maintainer can verify. Investigation does not authorize implementation, deploys or changes to live data.

## Establish the scope

- Symptom, site (main site, a member subsite `<slug>.mody.cloud`, a custom domain), environment (local `mody.local` or production `mody.cloud`), when, and who (super admin, member, visitor). Reuse what was given; ask only for facts that change the investigation. Never ask for passwords.
- Find the owning plugin and document: the "Where to work" table and Documentation map in `AGENTS.md`. Read the ticket for the feature; its **Results** and **Open questions** often say what is expected. A missing rule is not proof of a bug.
- Know which code is running. Locally: the current branch (`git branch --show-current`; the stack serves this checkout). Production: `REVISION` in the server's project directory is the deployed commit; compare it with `git log` before blaming code that may not be there yet.

## Investigate with bounded effects

**Locally** you may read anything, run WP-CLI reads (`./bin/wp --site=<slug> option get ...`, `post list`, `eval` that only reads), run the existing tests ([mody-test](../mody-test/SKILL.md)), read logs (`docker logs mody_cloud_app`, `docker logs mody_cloud_server`), and write throwaway probes in the scratchpad (run them with `eval-file -`). Do not change the repository to investigate. Tests that make real model calls cost tokens: prefer `-e MODY_TEST_OFFLINE=1`.

**Production** (`ssh home.local`, already allowed) is read-only:

- Project directory `/Volumes/Extreme/mody.cloud` (check `docker inspect mody_cloud_app` mounts if in doubt). `REVISION` there holds the deployed commit and WordPress's version. Docker over non-interactive SSH needs `export PATH=/opt/homebrew/bin:$PATH DOCKER_HOST=unix:///Users/javo/.colima/default/docker.sock`. The remote shell is zsh.
- Allowed: `docker logs --timestamps mody_cloud_server | grep '" 500 '`, `docker logs mody_cloud_app`, WP-CLI reads, `cat REVISION`, HTTP requests to the public site.
- Not without the maintainer's explicit yes: restarts, `docker compose up`, cache flushes, option or database writes, imports, `wp mody-billing run`, `wp mody-images optimize`, sending mail, anything touching members' accounts or payments. If the USB/external disk seems hung, stop and say so.
- Never print `.env`, API keys (Infomaniak, Brevo, Mollie, NPM, Cloudflare), password hashes or members' personal data into the conversation or a ticket. Redact emails and names in evidence.

**Method:** the smallest reproduction first. Record the inputs, the expected and actual result, the command, and `path:line` references. Trace the actual path (REST route → ability → plugin class → stored option or table → what the browser shows); a unit result does not prove the browser path. Typical layers here: multisite (which blog, `switch_to_blog`), the member gates (`mody-locks`), Nginx rules and rate limits (`mody-security`, `config/nginx/`), OPcache right after a deploy (500s for a few seconds heal by themselves), the agent's budgets and slots (`Agent\Budget`, `Agent\Slots`), the background job checker. Label a cause **demonstrated** only with a reproduction or direct trace; otherwise name the hypothesis and the next check that would decide it.

## Hand back

1. **Failure:** behavior, impact, environment and revision.
2. **Reproduction:** minimal steps or command and what happened; say so if not reproduced.
3. **Cause:** demonstrated mechanism with evidence, or the hypothesis and the missing check.
4. **Proposed fix:** the smallest change, which plugin, and any decision the maintainer must make (check the ticket's open questions first).
5. **Tests:** what ran, and the regression check a fix should add.

Write the finding into the ticket's notes only if the maintainer asks; otherwise keep it in the reply. Implementation is a separate request handed to [mody-delivery](../mody-delivery/SKILL.md).
