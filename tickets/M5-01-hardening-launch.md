# M5-01 · Hardening, production and launch checklist

**Milestone:** M5 · **Spec:** documentation/mvp-spec.md §15 · **Status:** todo

## Goal
The platform is launch-ready for 100 founding members: production-safe compose, TLS for wildcard subdomains, backups, and a security pass over everything the MVP added.

## Deliverables
- **Production compose:** use `compose.prod.yml.sample` (no Xdebug 9003, no published MariaDB 3306); fix its stale Tamarind server names; confirm the agent service has no published ports in prod.
- **Wildcard TLS:** production front end terminates a wildcard cert for `*.mody.cloud` (infra task — spec §16.3). Local stays on mkcert wildcard.
- **Real mail:** replace Mailpit/MSMTP with the production SMTP relay (spec §16.2 — provider to confirm: Infomaniak Mail or self-hosted).
- **Backup/restore drill:** full DB + uploads backup, restore into a clean stack, verify one member site end-to-end.
- **Security pass** over M1–M4 code:
  - All endpoints: nonce + capability checks; all inputs sanitized (wizard fields, chat relay, market toggles).
  - Mollie webhook signature verification + idempotency replay test.
  - Internal agent endpoint: `hash_equals` on `MODY_INTERNAL_SECRET`, unreachable from outside the compose network.
  - No secrets in repo/logs/prompts (constraint 6); `.env` untracked.
- **Monitoring:** Query Monitor (existing) wired for the main site; error log at `app/logs/debug.log`; a simple "system health" dashboard page for platform admins (site count, past-due count, failed jobs).
- **Stale cleanup** (after launch is stable): remove/replace the Tamarind leftovers listed in `local-development.md` "Known stale items" (`bin/migrate-*`, `bin/build`, ofelia labels, `.github/workflows/*`, `app/assets/`, `test.md`).

## Acceptance (launch checklist)
- [ ] Fresh machine: `cp sample.env .env`, certs, `docker compose up`, core install, multisite activation — the site is usable (documented in local-development.md).
- [ ] Register → trial → create site → edit in Site Editor → agent tool → all work over HTTPS on the production front end.
- [ ] Restore drill completes with zero data loss on the test account.
- [ ] Security pass sign-off recorded in this ticket.
