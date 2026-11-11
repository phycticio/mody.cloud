# Next steps

The ordered list of what comes next. Each item links to its ticket, and the details live there. Update this list whenever a ticket is finished or the order changes.

**Last updated:** 2026-11-10, after site languages part 11 (the working language in wp-admin) and the antibot check (ALTCHA).

## Waiting on the maintainer
- **Mody knows Gutenberg and Spectra, and changes the site's settings** ([agent-knows-gutenberg-spectra-settings.md](agent-knows-gutenberg-spectra-settings.md), deployed 2026-11-11 in `a7ca266`; gradients made of palette colors deployed the same day): `mody/update-settings` (46 settings, the risky ones held for Apply), `mody/update-menu`, and 27 block families taught to the writers through cards picked per request, with the validator checking every attribute. Mody also creates gradients from the palette and uses them on sections and buttons. Waiting on a browser check. It takes over M3-11 parts 4 to 7.
- **No Plugins or Connectors screens for anyone** ([hide-plugins-and-connectors.md](hide-plugins-and-connectors.md)) and **one network profile with local pictures, no Gravatar** ([network-profile-local-avatars.md](network-profile-local-avatars.md)): reviewed and deployed 2026-11-11 in `a7ca266`.
- **The login page in each site's brand, and Settings → Login** ([login-branding.md](login-branding.md), in production since 2026-11-10): wp-login.php wears the site's own logo, palette and fonts, arranged by one of up to six schemes built from its palette, chosen in Settings → Login. Waiting on a browser check.
- **The main site's home page** (built locally on 2026-11-10, `scripts/home-page.php`; the new plans the same day): a review of its copy and its note on the US services, and the open questions on the plans ([membership-tiers.md](../documentation/membership-tiers.md#open-questions)): VAT in the prices, the trial's 3 sites against the 1-site plan, and more than 10 sites. **In production since 2026-11-10** (page 229, the front page, header menu 4; the database before it: `backups/mody_cloud-prod-before-home-page-20261110-182420.sql`). Rerun the script there after editing it; it replaces the page.
- **The home page in Spanish, German and Italian** (published locally on 2026-11-10, [site-languages.md](site-languages.md)): a read by native speakers of `scripts/home-page-translations.json`.
  - **In production since 2026-11-10** at `/es/`, `/de/` and `/it/`: pages 231, 236 and 241, menus 233, 238 and 243, headers `header-es`, `header-de` and `header-it`. To update, copy the JSON into the container (`docker exec -i mody_cloud_app sh -c 'cat > /tmp/home-page-translations.json' < scripts/home-page-translations.json` over ssh), then run the script.
  - **Still in English on those pages:** the chat block's "Registration opens soon." (M1-04 part C).
- **Antibot** ([antibot-altcha.md](antibot-altcha.md), built and deployed 2026-11-10):
  - ALTCHA guards the signup chat, login, lost password and comments.
  - Antispam Bee checks comments, with its outside checks pinned off.
  - Still open: a rate limit (M5-01), which needs the real visitor address through the proxies.

  After pulling, run `pnpm build` once locally: the widget is in `app/dist/`, which git does not track.
- **The theme's spacing sizes are ignored on every site** (found 2026-11-09, [M1-04](M1-04-registration-block.md) part B results). WordPress uses its own sizes for the same slugs because `defaultSpacingSizes` is on. Turning it off fixes it, but changes every site's spacing.
- **Production mail: Brevo** ([M5-02](M5-02-production-member-sites.md), step 6). Built on 2026-11-10: the `mody-mail` MU plugin posts every email to Brevo's API (it replaced WP Mail SMTP). It works after a deploy and the server steps in M5-02 step 6: a sending domain and an API key in Brevo, the key in the server's `.env`, and the old WP Mail SMTP block out of `compose.prod.yml`.
- **The browser check of [M3-09](M3-09-agent-navigates-site-editor.md)** (built 2026-11-08, deployed in `6819b67` before this check, by the maintainer's choice): the steps in its Results, plus "create a page", where the new draft opens.
- **Inputs for M1:**
  - the Mollie test keys in `.env` (M1-01 part 3);
  - the WP-Cron decision (part 5 and M1-02; architecture.md, Open questions);
  - ~~the fee confirmation~~: answered on 2026-11-10 with the new plans ([membership-tiers.md](../documentation/membership-tiers.md)). `Members::PLANS` and its enums still hold the old ones; they change with part 3, which also adds yearly billing;
  - a review of `next_plan`, the column added for the plan chosen at signup ([M1-01](M1-01-registration-trial-billing.md), Results part 2). Since M1-03 it is set when the member chooses a plan during the trial;
  - **for [M1-03](M1-03-chat-signup-and-editor-tour.md):** a review of its seven planning choices (site at signup, no AI call at signup, the first password logging in, and the rest), and two new open questions (mvp-spec §16, 7 and 8): the end of a trial without a card, and when to remove accounts that never set a password.
- **[M5-02](M5-02-production-member-sites.md) steps 1 to 4:** the `*` DNS record, the API token and wildcard certificate in NPM ("Full (strict)", decided 2026-11-08), the proxy host, and the stack check. Step 5 (no `/wp` in any address) is done in production since 2026-11-10.
- **Browser checks:**
  - the signup chat and the editor tour ([M1-03](M1-03-chat-signup-and-editor-tour.md), Results: the steps). Open registration locally with `./bin/wp site option update mody_registration_open 1`, sign up at `https://mody.local/register/`, open the email in Mailpit, choose a password, and follow the tour with real requests to Mody. Then close registration again;
  - M4-02 part A1: the Plugins screen as `member`;
  - the agent chat colors;
  - the network admin screens with no Updates entry.
- **Docker Desktop's uninstall** ([move-local-docker-to-colima.md](move-local-docker-to-colima.md), part F).

## Queue
1. **[Site languages](site-languages.md): every site in several languages, through the `mody-languages` MU plugin.** Approved by the maintainer on 2026-11-10. Parts 1 to 7 and 11 are built:
   - the languages, translations, addresses, `lang` and `hreflang`, the switcher and menus, the matrix;
   - part 6 (header and footer copies);
   - part 11, the working language in wp-admin: a Language menu in the toolbar and the editors that filters the Pages and Posts lists, the Site Editor's page list and the link search.

   Next: part 8 (Translate with Mody), part 9 (WordPress's own translations) and part 10 (documents and the home page).
2. **[M3-10](M3-10-agent-edits-global-styles.md): the agent edits every part of the site's styles.** Parts A and B are built (2026-11-08): `mody/update-styles`, the guarded patch, which the agent now uses for any style. Parts A and B are deployed (`8b67ce9`). Next is part C: `mody/undo-styles`, the editor check and the docs.
3. **[M3-11](M3-11-spectra-mu-plugin-all-blocks.md): Spectra as a MU plugin, and every Spectra block for the agent.** Decided 2026-11-08. Part 1 (the MU plugin) is built and deployed. Part 2 (the validator accepts every Spectra block but the popup builder) and part 3 (accordion and tabs) are built and deployed (2026-11-09). Next is part 4: buttons, icons, list, separator and content.
4. **[M1-01](M1-01-registration-trial-billing.md) part 4: the dashboard's My sites and Plan & billing.** Unblocked. Update card waits for part 3.
5. **[M1-01](M1-01-registration-trial-billing.md) part 3: the plan and the card during the trial (Mollie checkout and webhook).** Reworked by M1-03. It needs the test keys and the answer to mvp-spec §16 question 7.
6. **[M5-02](M5-02-production-member-sites.md) step 6: mail in production.** Built on 2026-11-10: the `mody-mail` MU plugin posts every email to Brevo (it replaced WP Mail SMTP). It waits for the deploy and the server steps above.
7. **[M1-01](M1-01-registration-trial-billing.md) part 5 and [M1-02](M1-02-past-due-readonly-deletion.md): billing jobs, past-due read-only, deletion.** They need the WP-Cron decision.
8. **What the home page promises on every site (2026-11-10), before the launch:** the 3 GB quota (`upload_space_check_disabled` is 1 today, so nothing is enforced), custom domains (mvp-spec §14, "soon" on the page), a plugin in the market (it offers none yet), and a check of the way out: export a member site's theme and content, and restore them on a clean WordPress with Spectra. [membership-tiers.md](../documentation/membership-tiers.md).
9. **[M5-01](M5-01-hardening-launch.md): the production host, the backup drill, the security pass** (including the registration form's rate limit before it opens; its bot check is done, [antibot-altcha.md](antibot-altcha.md)) **and the launch checklist.**
10. **[M1-04](M1-04-registration-block.md): the registration chat as a block you can style and place anywhere.** Planned on 2026-11-09. It covers questions with a mapping (account, site, brand, custom answers) and palette-only color pickers on every site. Part 0 (M1-03 committed), part A (palette-only pickers) and part B (the block and its styles) are done. Next is part C, the questions and their mapping.

## Tech debt
- **[The design market](design-market-themes-patterns.md):** the patterns part was built and merged on 2026-11-08 (the Mody collection, 63 patterns). Still open:
  - which Mody themes members see;
  - the findings in the ticket: placeholder labels on covers, "Powered by WordPress" credits, two gradient patterns left out, patterns adapted to the brand by the agent.

## Set aside
- **[M4-02](M4-02-plugin-market.md) parts A2 and B.** Pick them up with the first plugin offered to members.
- **The remaining WordPress.org calls** ([updates-through-composer-only.md](updates-through-composer-only.md), Open questions).
- **[mody-blocks-from-spectra.md](mody-blocks-from-spectra.md):** set aside on 2026-11-08: Spectra stays the block library (M3-11).
