# Next steps

The ordered list of what comes next. Each item links to its ticket, and the details live there. Update this list whenever a ticket is finished or the order changes.

**Last updated:** 2026-11-08, after M3-10 part A (`mody/update-styles`) and the deploy of `6819b67`.

## Waiting on the maintainer
- **The SMTP relay** ([M5-02](M5-02-production-member-sites.md), step 6). The answer on 2026-11-08 was cut off ("SMTP relay: Let"). I build the mail hook once the relay is named.
- **The browser check of [M3-09](M3-09-agent-navigates-site-editor.md)** (built 2026-11-08, deployed in `6819b67` before this check, by the maintainer's choice): the steps in its Results, plus "create a page", where the new draft opens.
- **Inputs for M1:**
  - the Mollie test keys in `.env` (M1-01 part 3);
  - the WP-Cron decision (part 5 and M1-02; architecture.md, Open questions);
  - the fee confirmation (5/15/50 EUR). The registration page already shows these fees, and it stays closed in production until part 3;
  - a review of `next_plan`, the column added for the plan chosen at signup ([M1-01](M1-01-registration-trial-billing.md), Results part 2).
- **[M5-02](M5-02-production-member-sites.md) steps 1 to 4:** the `*` DNS record, the API token and wildcard certificate in NPM ("Full (strict)", decided 2026-11-08), the proxy host, and the stack check. Then step 5, the `home` fix.
- **Browser checks:**
  - the registration page: open it locally with `./bin/wp site option update mody_registration_open 1`, visit `https://mody.local/register/`, then close it again;
  - M4-02 part A1: the Plugins screen as `member`;
  - the agent chat colors;
  - the network admin screens with no Updates entry.
- **The production disk** ([architecture.md](../documentation/architecture.md#open-questions), Production host). The USB disk threw I/O errors for about an hour on 2026-11-08, and the site was down. Copy the server-only files somewhere safe (`app/web/uploads/`, `.env`, `compose.prod.yml`, `app/auth.json`). The database is in Colima's volume, not on the disk.
- **Docker Desktop's uninstall** ([move-local-docker-to-colima.md](move-local-docker-to-colima.md), part F).

## Queue
1. **[M3-10](M3-10-agent-edits-global-styles.md): the agent edits every part of the site's styles.** Part A is built (2026-11-08): `mody/update-styles`, the guarded patch, not yet offered to the agent. Next is part B, the prompt and the tools JSON, with paced real runs. Part C is `mody/undo-styles` and the docs.
2. **[M1-01](M1-01-registration-trial-billing.md) part 4: the dashboard's My sites and Plan & billing.** Unblocked. Update card waits for part 3.
3. **[M1-01](M1-01-registration-trial-billing.md) part 3: Mollie card verification and webhook.** It needs the test keys.
4. **[M5-02](M5-02-production-member-sites.md) step 6: SMTP in production.** It needs the relay.
5. **[M1-01](M1-01-registration-trial-billing.md) part 5 and [M1-02](M1-02-past-due-readonly-deletion.md): billing jobs, past-due read-only, deletion.** They need the WP-Cron decision.
6. **[M5-01](M5-01-hardening-launch.md): the production host, the backup drill, the security pass** (including the registration form's rate limit and spam check, before it opens) **and the launch checklist.**

## Tech debt
- **[The design market](design-market-themes-patterns.md):** the patterns part was built and merged on 2026-11-08 (the Mody collection, 63 patterns). Still open:
  - which Mody themes members see;
  - the findings in the ticket: placeholder labels on covers, "Powered by WordPress" credits, two gradient patterns left out, patterns adapted to the brand by the agent.

## Set aside
- **[M4-02](M4-02-plugin-market.md) parts A2 and B.** Pick them up with the first plugin offered to members.
- **The remaining WordPress.org calls** ([updates-through-composer-only.md](updates-through-composer-only.md), Open questions).
- **[mody-blocks-from-spectra.md](mody-blocks-from-spectra.md):** no milestone yet.
