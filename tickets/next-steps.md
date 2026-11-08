# Next steps

The ordered list of what comes next. Each item links to its ticket, and the details live there. Update this list whenever a ticket is finished or the order changes.

**Last updated:** 2026-11-08, after M1-01 part 1, the M1-01 split, M5-02's instructions, the M5-01 refresh and the certificate decision.

## Waiting on the maintainer
- **Deploy `f22e1ce`** (updates only through the repository). It is committed and pushed, and its dry run passed. The auto-mode classifier refused the real deploy, so run it yourself: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" ./bin/deploy --env=prod`. The Node path is needed because the build wants the `.nvmrc` Node (22).
- **Decisions for M1-01 part 2** ([ticket](M1-01-registration-trial-billing.md), part 2): how the registration page is rendered, the WordPress role, and what a `pending_payment` account may do. Each has a proposal.
- **Inputs for M1:**
  - the Mollie test keys in `.env` (part 3);
  - the WP-Cron decision (part 5 and M1-02; architecture.md, Open questions);
  - the fee confirmation (5/15/50 EUR, before part 2 shows prices).
- **[M5-02](M5-02-production-member-sites.md):**
  - the Cloudflare and NPM steps 1 to 4. The certificate is decided: "Full (strict)" plus a Let's Encrypt wildcard through Cloudflare's DNS challenge (2026-11-08);
  - the SMTP relay (step 6), not chosen yet.
- **Browser checks:**
  - M4-02 part A1: the Plugins screen as `member` ([M4-02](M4-02-plugin-market.md), "Not checked by the tests");
  - the agent chat colors ([admin-colors.md](admin-colors.md));
  - the network admin screens for a super admin, with no Updates entry and no update notices ([updates-through-composer-only.md](updates-through-composer-only.md)).
- **Docker Desktop's uninstall** ([move-local-docker-to-colima.md](move-local-docker-to-colima.md), part F): it needs your admin password. `Docker.app` was still installed on 2026-11-08.

## Queue (what I build once the inputs above arrive)
1. **[M1-01](M1-01-registration-trial-billing.md) part 2: the registration page.** It needs the three part 2 decisions.
2. **[M1-01](M1-01-registration-trial-billing.md) part 3: Mollie card verification and webhook.** It needs the test keys.
3. **[M1-01](M1-01-registration-trial-billing.md) part 4: the dashboard's My sites and Plan & billing.** It can be built after part 2, without Mollie, except for the Update card button.
4. **[M5-02](M5-02-production-member-sites.md) step 6: SMTP in production** (a `phpmailer_init` hook from the environment), once the relay is chosen.
5. **[M1-01](M1-01-registration-trial-billing.md) part 5 and [M1-02](M1-02-past-due-readonly-deletion.md): billing jobs, past-due read-only, and deletion.** They need the WP-Cron decision.
6. **[M5-01](M5-01-hardening-launch.md): the production host decision, the backup drill, the security pass and the launch checklist.**

## Set aside
- **[M4-02](M4-02-plugin-market.md) parts A2 and B** (the super admins' hidden list, the agent's plugin abilities). They make no difference until a first plugin is offered to members, because members' Plugins screen is empty today. Pick them up with that plugin.
- **The remaining WordPress.org calls:** Wordfence's scans, Site Health → Info and WP-CLI still call the update checks, and the Dashboard has its news and version checks. See [updates-through-composer-only.md](updates-through-composer-only.md), Open questions.
- **[mody-blocks-from-spectra.md](mody-blocks-from-spectra.md):** our own `mody/*` blocks to replace Spectra. No milestone yet.
