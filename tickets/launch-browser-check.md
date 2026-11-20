# Every member screen checked in a real browser before launch

**Builds on:** [free-mvp.md](free-mvp.md), [M1-03](M1-03-chat-signup-and-editor-tour.md), [M5-01](M5-01-hardening-launch.md) · **Feeds:** the launch · **Status:** planned on 2026-11-20 · **Issue:** [#17](https://github.com/phycticio/mody.cloud/issues/17)

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
Not started.
