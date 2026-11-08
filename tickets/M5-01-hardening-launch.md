# M5-01 · Hardening, production and launch checklist

**Milestone:** M5 · **Spec:** documentation/mvp-spec.md §15 · **Status:** todo. Refreshed 2026-11-08: items that were already done or stale were updated, and the wildcard DNS and TLS, `home` and mail moved to [M5-02](M5-02-production-member-sites.md).

## Goal
The platform is launch-ready for 100 founding members: member sites reachable in production, backups proven, and a security pass over everything the MVP added.

## Already done
- **Production compose** (2026-11-04): the server runs `compose.yml` with `compose.prod.yml` from the sample. Only nginx's HTTP port is published; Xdebug and MariaDB publish nothing; Mailpit never starts (architecture.md, Host). There is no agent service any more: the AI runs in PHP since 2026-11-04.
- **Deploys** (2026-11-04): `./bin/deploy --env=prod`, with checks, from pushed commits on `main` only.
- **No updates from wp-admin** (2026-11-08): [updates-through-composer-only.md](updates-through-composer-only.md).

## Deliverables
- **[M5-02](M5-02-production-member-sites.md):** member sites in production (wildcard DNS and certificate, the proxy host), `home` without `/wp`, and real mail through an EU SMTP relay.
- **The production host** (architecture.md, Open questions): stay on the laptop with the USB disk, or move to a European VPS (Hetzner, Infomaniak) before launch. Replacing Cloudflare depends on it.
- **Backup and restore drill:** a full database and uploads backup, restored into a clean stack, with one member site checked end to end. Then a backup schedule, which does not exist yet.
- **WP-Cron on subsites** (architecture.md, Open questions), if M1 has not settled it already.
- **Security pass** over M1 to M4:
  - **Endpoints:** nonce and capability checks on the wizard REST routes (`mody-platform/v1/sites`), the agent chat endpoint and its jobs, and the `mody/*` abilities' permission callbacks (REST and MCP). Inputs sanitized: the wizard fields, the logo upload, chat messages.
  - **Mollie:** the webhook's verification and the idempotency replay test (M1-01 part 3).
  - **Plugins on member sites:** the M4-02 rules (hidden plugins by every path) and the `ai` plugin's lock-down (M4-03).
  - **Secrets:** `INFOMANIAK_API_KEY`, the Mollie keys and the SMTP password only in the server `.env`, never in the database, logs or prompts (rule 6). `.env` untracked.
- **Monitoring:** Query Monitor is a dev requirement and never reaches production, so it is not the answer here. What is left:
  - the error log at `app/logs/debug.log`;
  - a simple system health page for super admins: site count, past-due count, failed provisioning jobs, agent errors from `mody_agent_events`.
- **Stale cleanup** (after launch is stable): the Tamarind leftovers listed in local-development.md, "Known stale items". `bin/build` is current and not on that list.

## Acceptance (launch checklist)
- [ ] Fresh machine: `cp sample.env .env`, certs, `docker compose up`, core install, multisite activation — the site is usable (documented in local-development.md).
- [ ] Register → trial → create site → edit in Site Editor → agent tool → all work over HTTPS on the production front end.
- [ ] Restore drill completes with zero data loss on the test account.
- [ ] Security pass sign-off recorded in this ticket.
