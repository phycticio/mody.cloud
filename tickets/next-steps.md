# Next steps

The ordered list of what comes next. Each item links to its ticket, and the details live there. Update this list whenever a ticket is finished or the order changes.

**Last updated:** 2026-11-21, refreshed by the agent after the night's work: production runs `990aa62` (deployed 2026-11-20), so everything merged before it is live; items that said "not deployed" were brought up to date, finished ones moved to the end.

## Now: the launch
Mody Cloud launches as a free service ([free-mvp.md](free-mvp.md), deployed 2026-11-20 in `990aa62`): one free site per account, 500 MB, Mody's 5 million tokens a month, sign-ups from Spain only, no payments. The checklist is [M5-01](M5-01-hardening-launch.md) ([#10](https://github.com/phycticio/mody.cloud/issues/10)).
1. **Pull requests from the night of 2026-11-20, waiting on the maintainer's review and merge:** #23 (the deploy recorded), #24 (production check, read-only part), #25 (core's welcome guides off: s.w.org pictures, and a guide over the tour), #26 (browser check results), #28 (a login counts only where it was made, #27), #30 (the health page, #29), and this list. The night report says what each decided.
2. **Production checked end to end** ([launch-production-check.md](launch-production-check.md), [#16](https://github.com/phycticio/mody.cloud/issues/16)): configuration, Spain only, addresses and backups checked read-only on 2026-11-20. **The maintainer's:** a real signup from Spain (the email through Brevo, the tour, two requests to Mody), the restore's full-stack check (WordPress on restored data), rotating the `plugins.mody.cloud` Composer credential.
3. **Every member screen in a real browser** ([launch-browser-check.md](launch-browser-check.md), [#17](https://github.com/phycticio/mody.cloud/issues/17)): every screen passes locally (2026-11-20); one fix (#25). The maintainer's look on production comes with the real signup.
4. **The security sign-off** in M5-01, after 2 and 3.
5. **Deploy** what is merged, then record it.

## Waiting on the maintainer
- **The legal pages:** the free version is published (2026-11-20); the adviser's read ([legal-pages.md](legal-pages.md)).
- **Native speakers' read** of the home page in Spanish, German and Italian (`scripts/home-page-translations.json`; the free-service texts of 2026-11-20 are Claude's).
- **Decisions still open:**
  - the paid tiers of the freemium, and who may sign up beyond Spain ([membership-tiers.md](../documentation/membership-tiers.md#open-questions));
  - an alert when accounts or Mody's cost pass a threshold (production check, Q2; the health page shows the numbers);
  - single sign-on between `mody.cloud` and members' own domains (members log in once more on their domain; [custom-domains.md](custom-domains.md));
  - the signup chat in Spanish, German and Italian (queue item 1: a plan to confirm).
- **A look in the browser at features built and deployed before 2026-11-20:** the analytics board and its analyst ([analytics-chat.md](analytics-chat.md)), Download your site ([take-your-site-with-you.md](take-your-site-with-you.md); its phase 0 production spike, a 3 GB download through Cloudflare, is the maintainer's), custom domains on a second real domain ([custom-domains.md](custom-domains.md)), images ([image-optimization.md](image-optimization.md)), the login page ([login-branding.md](login-branding.md)), M3-09's steps ([M3-09](M3-09-agent-navigates-site-editor.md)). The launch browser check covered the member's journey; these are the parts it did not open.
- **Docker Desktop's uninstall** on this Mac ([move-local-docker-to-colima.md](move-local-docker-to-colima.md), part F).

## Queue
1. **The signup chat in Spanish, German and Italian.** On `/es/`, `/de/` and `/it/` the home page is translated but the chat inside it is English, and sign-ups are open to people in Spain only. WordPress switches its words only for installed languages ([site-languages.md](site-languages.md), part 9), the chat's answers come from REST routes that do not know the page's language, and the welcome email has none either. **Proposed (not decided):** the chat sends its page's language; `mody_cloud` translations for the chat, the emails and Your plan in `es_ES`, `de_DE` and `it_IT`, loaded for that language; the account remembers it (the user's locale), so its emails and wp-admin follow. Touches [M1-04](M1-04-registration-block.md) part C, which the maintainer has in progress (a git stash).
2. **[Site languages](site-languages.md):** parts 1 to 7 and 11 built and deployed. Next: part 8 (Translate with Mody), part 9 (WordPress's own translations: language packs at build time) and part 10 (documents).
3. **[M3-10](M3-10-agent-edits-global-styles.md):** parts A and B deployed. Next is part C: `mody/undo-styles`, the editor check and the docs.
4. **[M3-11](M3-11-spectra-mu-plugin-all-blocks.md):** parts 1 to 3 deployed; parts 4 to 7 were taken over by [agent-knows-gutenberg-spectra-settings.md](agent-knows-gutenberg-spectra-settings.md) (27 block families, deployed 2026-11-11).
5. **[M1-04](M1-04-registration-block.md):** parts 0, A and B done. Next is part C, the questions and their mapping (the maintainer's work in progress, stashed).
6. **[The analytics chat](analytics-chat.md):** phases 1 to 3 deployed. Next: phase 4 (across sites: Your sites' board, the network page for super admins).
7. **[Take your site with you](take-your-site-with-you.md):** phases 1 and 2 deployed. Next: phase 3, the one-click Mody Import.
8. **[SEO](seo.md):** the SEO title and description deployed. Not built: archives, noindex, Open Graph, the sitemap and the audit.

## Built and paused
- **Card payments and the billing lifecycle** ([M1-01](M1-01-registration-trial-billing.md) part 3, [M1-02](M1-02-past-due-readonly-deletion.md), [pricing-per-site.md](pricing-per-site.md)): built, checked on production with Mollie's test key (2026-11-18), switched off since 2026-11-20 (`mody_payments_open`). Before switching on: the paid tiers, the paid legal texts (git history), invoices, the Dashboard's paid-path texts (they still speak of free months).

## Done recently
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
