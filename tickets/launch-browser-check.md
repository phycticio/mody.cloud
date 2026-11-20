# Every member screen checked in a real browser before launch

**Builds on:** [free-mvp.md](free-mvp.md), [M1-03](M1-03-chat-signup-and-editor-tour.md), [M5-01](M5-01-hardening-launch.md) · **Feeds:** the launch · **Status:** planned on 2026-11-20; screens 1, 2, 3 and part of 6 checked locally the same day · **Issue:** [#17](https://github.com/phycticio/mody.cloud/issues/17)

## Goal (maintainer, 2026-11-20)

> Create the necessary tasks to achieve launch, start work on each one by one.

Many features are built and tested by scripts but wait on "a look in the browser" ([next-steps.md](next-steps.md), Waiting on the maintainer). Before launch, every screen a member meets is checked in a real browser, at desktop (1440 px) and phone (390 px) widths.

## How
- **Locally, by the agent:** headless Chromium (the cached Playwright build, Node 22, the Chrome DevTools Protocol; no installs), on `https://mody.local` and a member subsite. Screenshots and console errors kept in the scratchpad; findings written here.
- **On production, by the maintainer:** the same journey during the real signup ([launch-production-check.md](launch-production-check.md), step 4).

## The screens
1. `/register/`: the chat's four questions, the summary and "I live in Spain: create my site", an error (a taken address), the "Done!" message; the form without JavaScript.
2. The welcome email in Mailpit, and the set-password link.
3. The first login: the site's Identity screen and the editor tour (M1-03).
4. Mody in the editors: a page request in the post editor, a style change in the Site Editor, opening a screen (M3-09).
5. Settings → Brand, Settings → Login, Settings → Languages.
6. The Dashboard: Overview, Visitors (the board), Mody's work, Download your site, Your domain, Your sites, Your plan, Invite friends.
7. The login page in the site's brand.
8. An image upload (stored as WebP) and the SEO panel.
9. The home page at `/`, `/es/`, `/de/` and `/it/`.

## Open questions
None yet.

## Results
**2026-11-20, locally, on `feature/#13-free-mvp`** (headless Chromium, Node 22 over CDP; two throwaway accounts, removed after):
- **1. `/register/`** at 1440 and 390 px: the intro ("your free website is ready, no card needed… open to people who live in Spain"), the four answers, the address suggestion kept, the summary with "I live in Spain: create my site" and "Change something", then "Done!" and the spam note. No sideways scroll, no console errors. Both accounts: free, active, no end date, a 500 MB site. **Pass.** Not checked yet: an error answer (a taken address), the form without JavaScript in a browser (covered by `registration-test.php`).
- **2. The welcome email** in Mailpit for both: the site's address, the set-password link, "free, and no card is needed". **Pass.**
- **3. The first login:** the set-password page leads to the site's Identity screen with the editor tour ("1 of 13, Welcome, Lina Test!"). **Pass.** The tour's steps past the first, and its two requests to Mody, not walked yet.
- **6. The Dashboard:** Your plan (free, one site, 500 MB, 5,000,000 tokens a month, no EUR, "Paid plans with more will come later") and Invite friends (the link, joined, no points). **Pass.** The other sections not looked at yet.
- **Still to do:** screens 4 (Mody in the editors, real model calls), 5, 7, 8 and 9, the rest of 3 and 6, and the maintainer's look on production.
