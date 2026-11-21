# Next steps

The ordered list of what comes next. Each item links to its ticket, and the details live there. Update this list whenever a ticket is finished or the order changes.

**Last updated:** 2026-11-21: the move to Codeberg planned for after the launch (#46); product pages for Bari and nine plugins planned (#42), parts 1, 2 and 5 built (the page builder, the catalog and Mody SEO's page, drafts; the plugins' update check from mody.cloud; not deployed, #48); every Mody page in the dashboard's layout, deployed (#41). Production runs `9ef1070` (the code of `66edcd9`, #43: every Mody page in the dashboard's layout; Mody Security replaced Wordfence in `d78f519`, #39, and Wordfence's files and data were removed from production the same day). Deploys that day: `3a39a7b` (#23 to #34), `8a4966d` (#36), `b8dec7c` (docs, #37), `d78f519` (#39), `6d154be` (#40: Wordfence's last traces; the deploy reloads PHP-FPM), `66edcd9` (#43), `9ef1070` (docs, #44: no files changed).

## Now: the launch
Mody Cloud launches as a free service ([free-mvp.md](free-mvp.md), deployed 2026-11-20 in `990aa62`): one free site per account, 500 MB, Mody's 5 million tokens a month, sign-ups from Spain only, no payments. The checklist is [M5-01](M5-01-hardening-launch.md) ([#10](https://github.com/phycticio/mody.cloud/issues/10)).
1. **Production checked end to end** ([launch-production-check.md](launch-production-check.md), [#16](https://github.com/phycticio/mody.cloud/issues/16)): configuration, Spain only, addresses and backups checked read-only on 2026-11-20. **The maintainer's:** a real signup from Spain (the email through Brevo, the tour, two requests to Mody), the restore's full-stack check (WordPress on restored data), rotating the `plugins.mody.cloud` Composer credential.
2. **Every member screen in a real browser** ([launch-browser-check.md](launch-browser-check.md), [#17](https://github.com/phycticio/mody.cloud/issues/17)): every screen passes locally (2026-11-20); one fix (#25, deployed 2026-11-21 in `3a39a7b`). The maintainer's look on production comes with the real signup.
3. **Mody Security** ([security-plugin-replaces-wordfence.md](security-plugin-replaces-wordfence.md), #38): deployed 2026-11-21 in `d78f519`, checked on production, Wordfence's leftovers removed (`6d154be`, #40). **The maintainer's:** set up two-step login on your profile (wp-admin asks for it), and the ticket's Q1 to Q4.
4. **The security sign-off** in M5-01, after 1, 2 and 3.
5. **Every Mody page in the dashboard's layout** ([mody-pages-dashboard-layout.md](mody-pages-dashboard-layout.md), [#41](https://github.com/phycticio/mody.cloud/issues/41)): built and deployed on 2026-11-21 in `66edcd9` (Agent, Health, Offers, New site, Languages and Login). Not yet opened in a browser on production.

## Waiting on the maintainer
- **The legal pages:** the free version is published (2026-11-20); the adviser's read ([legal-pages.md](legal-pages.md)).
- **Native speakers' read** of the home page in Spanish, German and Italian (`scripts/home-page-translations.json`; the free-service texts of 2026-11-20 are Claude's), and of the signup chat in seven languages (`app/web/mu-plugins/mody-members/languages/`, 2026-11-21).
- **Decisions still open:**
  - the paid tiers of the freemium, and who may sign up beyond Spain ([membership-tiers.md](../documentation/membership-tiers.md#open-questions));
  - an alert when accounts or Mody's cost pass a threshold (production check, Q2; the health page shows the numbers);
  - single sign-on between `mody.cloud` and members' own domains (members log in once more on their domain; [custom-domains.md](custom-domains.md));
  - the signup chat in other languages: the open questions of [signup-chat-languages.md](signup-chat-languages.md) (the account's language before part 9, Spanish sites for Spanish signups, `/register/` in English only);
  - Mody Security: security keys on members' own domains, who must use two-step login at launch, scheduling the file check, and recovery without codes ([security-plugin-replaces-wordfence.md](security-plugin-replaces-wordfence.md), Q1 to Q4);
  - the product pages: Composer (Packagist or our own repository), where the theme's download shows, whether `bari` carries the plugins ([product-pages-bari-and-plugins.md](product-pages-bari-and-plugins.md), Q7, Q8 and Q10);
  - the language switcher: the flag for English (the United Kingdom's, built, or the United States'), and whether one choice of layout and display applies to every header copy ([language-switcher-settings.md](language-switcher-settings.md)).
- **A look in the browser at features built and deployed before 2026-11-20:** the analytics board and its analyst ([analytics-chat.md](analytics-chat.md)), Download your site ([take-your-site-with-you.md](take-your-site-with-you.md); its phase 0 production spike, a 3 GB download through Cloudflare, is the maintainer's), custom domains on a second real domain ([custom-domains.md](custom-domains.md)), images ([image-optimization.md](image-optimization.md)), the login page ([login-branding.md](login-branding.md)), M3-09's steps ([M3-09](M3-09-agent-navigates-site-editor.md)). The launch browser check covered the member's journey; these are the parts it did not open.
- **Docker Desktop's uninstall** on this Mac ([move-local-docker-to-colima.md](move-local-docker-to-colima.md), part F).

## Queue
1. **[Site languages](site-languages.md):** parts 1 to 7 and 11 built and deployed. Next: part 8 (Translate with Mody), part 9 (WordPress's own translations: language packs at build time) and part 10 (documents).
2. **[M3-10](M3-10-agent-edits-global-styles.md):** parts A and B deployed. Next is part C: `mody/undo-styles`, the editor check and the docs.
3. **[M3-11](M3-11-spectra-mu-plugin-all-blocks.md):** parts 1 to 3 deployed; parts 4 to 7 were taken over by [agent-knows-gutenberg-spectra-settings.md](agent-knows-gutenberg-spectra-settings.md) (27 block families, deployed 2026-11-11).
4. **[M1-04](M1-04-registration-block.md):** parts 0, A and B done. Next is part C, the questions and their mapping (the maintainer's work in progress, stashed).
5. **[The analytics chat](analytics-chat.md):** phases 1 to 3 deployed. Next: phase 4 (across sites: Your sites' board, the network page for super admins).
6. **[Take your site with you](take-your-site-with-you.md):** phases 1 and 2 deployed. Next: phase 3, the one-click Mody Import.
7. **[SEO](seo.md):** the SEO title and description deployed. Not built: archives, noindex, Open Graph, the sitemap and the audit.
8. **[Product pages](product-pages-bari-and-plugins.md) ([#42](https://github.com/phycticio/mody.cloud/issues/42)):** `mody.cloud/bari`, the catalog `mody.cloud/plugins` with a page per plugin under it (`/plugins/<slug>`), in English, Spanish and Catalan, planned 2026-11-21. Parts 1 and 2 are built (2026-11-21, drafts, not deployed): `scripts/product-pages.php` builds the catalog and Mody SEO's page (`/plugins/seo/`) from `scripts/product-pages.json`, and the menu's **Our source code**; **Mody SEO's page waits for the maintainer's review** before the other eight. Part 5, the update check, is built too (`UpdateCheck` in each of the nine plugins, `scripts/update-check-test.sh`). Next: part 3 (each plugin made to stand alone); then the zips in uploads with an update check, Spanish and Catalan, Bari single site and the `bari` repository.

## After the launch
Issues labelled `post-launch` wait until the launch is done.
- **[Move to Codeberg](move-to-codeberg.md) ([#46](https://github.com/phycticio/mody.cloud/issues/46)):** the repository, issues and pull requests move from GitHub to Codeberg (Berlin), with the same workflow; GitHub kept as a read-only mirror. Planned 2026-11-21. First the maintainer's answers to Q1 (the licence) and Q2 (GitHub afterwards).

## Built and paused
- **Card payments and the billing lifecycle** ([M1-01](M1-01-registration-trial-billing.md) part 3, [M1-02](M1-02-past-due-readonly-deletion.md), [pricing-per-site.md](pricing-per-site.md)): built, checked on production with Mollie's test key (2026-11-18), switched off since 2026-11-20 (`mody_payments_open`). Before switching on: the paid tiers, the paid legal texts (git history), invoices, the Dashboard's paid-path texts (they still speak of free months).

## Done recently
- **Deployed 2026-11-21** in `3a39a7b`: core's welcome guides off (#25), a login counts only where it was made (#28, #27), Network Admin → Mody → Health (#30, [health-page.md](health-page.md)), the signup chat in seven languages (#34, [signup-chat-languages.md](signup-chat-languages.md)), and the night's docs and tests (#23, #24, #26, #31, #32). Then in `8a4966d`: the language switcher as a dropdown, with flags, and Mody knowing those settings (#36, [language-switcher-settings.md](language-switcher-settings.md)).
- **The free service** ([free-mvp.md](free-mvp.md)) and **a site's admin on its own domain** ([custom-domains.md](custom-domains.md), #19): deployed 2026-11-20 in `990aa62`, with the home page in four languages and the free legal pages.
- **Deploys through pull requests** ([deploy-through-pull-requests.md](deploy-through-pull-requests.md), #11) and **independent Mody plugins** ([independent-mody-plugins.md](independent-mody-plugins.md)): 2026-11-17.
- Deployed before 2026-11-20 and live: invites and the Offers page, backups (restic to Swiss Backup, running), web-efficient images, custom domains (`www.phycticio.com`), the legal pages, the Mody dashboard and analytics, Your brand, the security pass, antibot and rate limits, mail through Brevo (its key set on the server; a real email not seen yet), registration open (since 2026-11-10), the home page and its translations, the theme's spacing fix.

## Tech debt
- **[The design market](design-market-themes-patterns.md):** the patterns part was built and merged on 2026-11-08 (the Mody collection, 63 patterns). Still open:
  - which Mody themes members see;
  - the findings in the ticket: placeholder labels on covers, "Powered by WordPress" credits, two gradient patterns left out, patterns adapted to the brand by the agent.

## Set aside
- **[M4-02](M4-02-plugin-market.md) parts A2 and B.** Pick them up with the first plugin offered to members.
- **The remaining WordPress.org calls** ([updates-through-composer-only.md](updates-through-composer-only.md), Open questions).
- **[mody-blocks-from-spectra.md](mody-blocks-from-spectra.md):** set aside on 2026-11-08: Spectra stays the block library (M3-11).
