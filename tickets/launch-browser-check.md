# Every member screen checked in a real browser before launch

**Builds on:** [free-mvp.md](free-mvp.md), [M1-03](M1-03-chat-signup-and-editor-tour.md), [M5-01](M5-01-hardening-launch.md) · **Feeds:** the launch · **Status:** every screen checked locally on 2026-11-20; one fix (core's welcome guides, PR #25); the maintainer's look on production remains · **Issue:** [#17](https://github.com/phycticio/mody.cloud/issues/17)

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

**2026-11-20, night, locally, on `main` (`990aa62`)** (a throwaway member made by the real signup code, `noranightceramics.mody.local`; every request outside `*.mody.local` recorded):
- **3. The whole tour:** 13 steps, Back and Next, both "Try it" steps with a real request to Mody (the mission saved; a home page draft created and opened). **Pass.** **Found:** when Mody opened the page, core's "Editing a page" guide opened on top of the tour's card.
- **4. Mody in the editors** (real model calls, 4 to 6 seconds each): a paragraph added to the open page, "make the headings a little bigger" applied to the site's styles (and Styles → Typography opened), "open the navigation menus" opened the Navigation screen. **Pass.** `post-new.php?post_type=page` opens the Site Editor's pages (the editor-first rule), as designed.
- **5. Settings:** Your brand, Login, Languages load without errors or notices. **Pass.**
- **6. The Dashboard:** Overview, Visitors, Mody's work, Download your site, Your domain, Your sites, Your plan, Invite friends: no errors, no sideways scroll. **Pass.**
- **7. The login page** wears the site's name and colors. **Pass.**
- **8. An upload:** a 3200 × 2000 JPEG stored as a 2560 × 1600 WebP, its 7 sizes WebP. The Search engines panel is in the page's sidebar in the Site Editor. **Pass.**
- **9. The home page** at `/`, `/es/`, `/de/`, `/it/` at 390 px: no price, 500 MB named, no sideways scroll. **Pass.**
- **Outside requests:** none, except core's welcome guide: with fresh editor preferences, opening a page or Styles for editing showed "Edit your site" and requested `https://s.w.org/images/block-editor/edit-your-site.gif` (rule 1). **Fixed** in PR #25 (`CoreGuides`, Mody Onboarding: every core guide off in both editors); checked again: no guide, no outside request.
- **No console errors** on any screen.
- **Still to do:** the maintainer's look on production during the real signup ([launch-production-check.md](launch-production-check.md), step 4).

