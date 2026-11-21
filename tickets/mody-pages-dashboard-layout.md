# Every Mody page in the Mody dashboard's layout

**Builds on:** [mody-dashboard.md](mody-dashboard.md), [security-plugin-replaces-wordfence.md](security-plugin-replaces-wordfence.md) · **Feeds:** M5-01 · **Status:** built on 2026-11-21 · **Issue:** [#41](https://github.com/phycticio/mody.cloud/issues/41)

## Goal (maintainer, 2026-11-21)

> "All mody pages should have the same template as this page mody-dashboard. The sidebar should be displayed no matter what, even when there's only one section in the admin page, just showing that tab in the sidebar as the current. Modify all mody pages that don't have the mody-dashboard template to have it."

## What exists today (checked 2026-11-21)

Already in the layout: the Dashboard (`mody-dashboard`), Settings → Brand (`mody-brand`, the layout's origin) and Network Admin → Mody → Security (`mody-security`). Each shows its sidebar whatever the number of sections.

Not in it: Network Admin → Settings → Mody Agent, Mody → Health, Mody → Offers and Mody → New site (the wizard), and the site's Settings → Languages and Settings → Login. They used core's `form-table`, plain headings, or (Login) a layout of their own.

## Plan

One part. Each of the six pages gets:

- the layout: `wrap mody-brand`, the sections on the left (`mody-brand__sections`, with the current one marked `is-active` and `aria-current`, shown even when it is the only one), then on the right the card, the notice and the section's panel;
- the layout's stylesheet, a copy of Mody Dashboard's `admin-page.css` in the plugin's own `assets/` (each plugin runs alone, [independent-mody-plugins.md](independent-mody-plugins.md)), enqueued on `load-<page>`;
- sections where the page has natural parts, chosen by `?section=`.

| Page | Plugin | Sections | Card |
| --- | --- | --- | --- |
| Settings → Mody Agent | `mody-agent` | Agent events | Mody Agent |
| Mody → Health | `mody-health` | Mody's tokens this month, Accounts, Sites and space, What failed | The network |
| Mody → Offers | `mody-members` | Sign-ups, Invites (each saves only its own setting) | The network |
| Mody → New site | `mody-sites` | New site (the wizard's three steps stay inside the panel; the payment step too) | The network, with the account's sites used |
| Settings → Languages | `mody-languages` | Languages; Translations (the matrix) once the site has another language | The site and its languages |
| Settings → Login | `mody-login` | Login color scheme | The site: logo, name, tagline, palette, Change the logo and Change the colors and fonts |

## Open questions

None.

## Results

Built on 2026-11-21 on `feature/#41-mody-pages-dashboard-layout`.

- The six pages above are in the layout. Login's own layout CSS is gone from `login-settings.css` (the palette on the card and the schemes stay). Offers' save now merges: saving Invites keeps who may sign up, and the other way round. The Languages screen's create errors and missing targets go back to Translations.
- **Tests (local, offline):** `health-test`, `invites-test`, `languages-test`, `login-page-test` and `ai-agent-chat-test` pass, with new checks for the sidebar, the card and the sections. `wizard-test` passes, apart from the check that was already failing locally ("the theme's header with the brand name", recorded in [independent-mody-plugins.md](independent-mody-plugins.md)).
- **Browser (headless Chromium, 1440 and 390 px):** every page shows its sidebar with the current section marked, the card and the panel. No JavaScript errors, no request outside `mody.local`, no sideways scroll on a phone. The Dashboard looks as before.
- **Not done:** `export-kit/mody-import`, the importer that runs on a plain WordPress after Take your site with you, keeps core's look. It is not a page of the platform.
