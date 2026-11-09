# Next steps

The ordered list of what comes next. Each item links to its ticket, and the details live there. Update this list whenever a ticket is finished or the order changes.

**Last updated:** 2026-11-09, after M1-04 parts A and B.

## Waiting on the maintainer
- **The theme's spacing sizes are ignored on every site** (found 2026-11-09, [M1-04](M1-04-registration-block.md) part B results). WordPress uses its own sizes for the same slugs because `defaultSpacingSizes` is on. Turning it off fixes it, but changes every site's spacing.
- **Production mail: Brevo** ([M5-02](M5-02-production-member-sites.md), step 6). Built on 2026-11-09: WP Mail SMTP with Brevo's API, set only by constants. It works after a deploy and the server steps in M5-02 step 6: a sending domain and an API key in Brevo, the `BREVO_*` lines in the server's `.env`, the `compose.prod.yml` block, and network activation.
- **The browser check of [M3-09](M3-09-agent-navigates-site-editor.md)** (built 2026-11-08, deployed in `6819b67` before this check, by the maintainer's choice): the steps in its Results, plus "create a page", where the new draft opens.
- **Inputs for M1:**
  - the Mollie test keys in `.env` (M1-01 part 3);
  - the WP-Cron decision (part 5 and M1-02; architecture.md, Open questions);
  - the fee confirmation (5/15/50 EUR). Since M1-03 the signup shows no fees; the dashboard will;
  - a review of `next_plan`, the column added for the plan chosen at signup ([M1-01](M1-01-registration-trial-billing.md), Results part 2). Since M1-03 it is set when the member chooses a plan during the trial;
  - **for [M1-03](M1-03-chat-signup-and-editor-tour.md):** a review of its seven planning choices (site at signup, no AI call at signup, the first password logging in, and the rest), and two new open questions (mvp-spec §16, 7 and 8): the end of a trial without a card, and when to remove accounts that never set a password.
- **[M5-02](M5-02-production-member-sites.md) steps 1 to 4:** the `*` DNS record, the API token and wildcard certificate in NPM ("Full (strict)", decided 2026-11-08), the proxy host, and the stack check. Then step 5, the `home` fix.
- **Browser checks:**
  - the signup chat and the editor tour ([M1-03](M1-03-chat-signup-and-editor-tour.md), Results: the steps). Open registration locally with `./bin/wp site option update mody_registration_open 1`, sign up at `https://mody.local/register/`, open the email in Mailpit, choose a password, and follow the tour with real requests to Mody. Then close registration again;
  - M4-02 part A1: the Plugins screen as `member`;
  - the agent chat colors;
  - the network admin screens with no Updates entry.
- **Docker Desktop's uninstall** ([move-local-docker-to-colima.md](move-local-docker-to-colima.md), part F).

## Queue
1. **[M3-10](M3-10-agent-edits-global-styles.md): the agent edits every part of the site's styles.** Parts A and B are built (2026-11-08): `mody/update-styles`, the guarded patch, which the agent now uses for any style. Parts A and B are deployed (`8b67ce9`). Next is part C: `mody/undo-styles`, the editor check and the docs.
2. **[M3-11](M3-11-spectra-mu-plugin-all-blocks.md): Spectra as a MU plugin, and every Spectra block for the agent.** Decided 2026-11-08. Part 1 (the MU plugin) is built and deployed. Part 2 (the validator accepts every Spectra block but the popup builder) and part 3 (accordion and tabs) are built and deployed (2026-11-09). Next is part 4: buttons, icons, list, separator and content.
3. **[M1-01](M1-01-registration-trial-billing.md) part 4: the dashboard's My sites and Plan & billing.** Unblocked. Update card waits for part 3.
4. **[M1-01](M1-01-registration-trial-billing.md) part 3: the plan and the card during the trial (Mollie checkout and webhook).** Reworked by M1-03. It needs the test keys and the answer to mvp-spec §16 question 7.
5. **[M5-02](M5-02-production-member-sites.md) step 6: mail in production.** Built on 2026-11-09 (Brevo). It waits for the deploy and the server steps above.
6. **[M1-01](M1-01-registration-trial-billing.md) part 5 and [M1-02](M1-02-past-due-readonly-deletion.md): billing jobs, past-due read-only, deletion.** They need the WP-Cron decision.
7. **[M5-01](M5-01-hardening-launch.md): the production host, the backup drill, the security pass** (including the registration form's rate limit and spam check, before it opens) **and the launch checklist.**
8. **[M1-04](M1-04-registration-block.md): the registration chat as a block you can style and place anywhere.** Planned on 2026-11-09. It covers questions with a mapping (account, site, brand, custom answers) and palette-only color pickers on every site. Part 0 (M1-03 committed), part A (palette-only pickers) and part B (the block and its styles) are done. Next is part C, the questions and their mapping.

## Tech debt
- **[The design market](design-market-themes-patterns.md):** the patterns part was built and merged on 2026-11-08 (the Mody collection, 63 patterns). Still open:
  - which Mody themes members see;
  - the findings in the ticket: placeholder labels on covers, "Powered by WordPress" credits, two gradient patterns left out, patterns adapted to the brand by the agent.

## Set aside
- **[M4-02](M4-02-plugin-market.md) parts A2 and B.** Pick them up with the first plugin offered to members.
- **The remaining WordPress.org calls** ([updates-through-composer-only.md](updates-through-composer-only.md), Open questions).
- **[mody-blocks-from-spectra.md](mody-blocks-from-spectra.md):** set aside on 2026-11-08: Spectra stays the block library (M3-11).
