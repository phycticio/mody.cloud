# Mody Cloud brand assets

The platform's own brand (not members' sites). Verified facts only, with the date checked. Update an entry when it changes.

## Masters

| Asset | Master file | Checked |
| --- | --- | --- |
| Logo: "O" isotype with the teal arc plus the MODY CLOUD wordmark | **Open question:** the maintainer gives the location of the originals (2026-11-17). project-overview.md says the logo "exists, not yet in this repo". | - |
| Square logo (black) | **Open question:** same as above. | - |

## Derivatives in use

| Use | What and where | Set by | Checked |
| --- | --- | --- | --- |
| Main site logo (local) | Attachment 2474, `logo-mody-cloud.png` (`app/web/uploads/2026/11/`) | `site_logo` on the main site | 2026-11-17 |
| Main site icon / favicon (local) | Attachment 2475, `logo-black-square.png` | `site_icon` on the main site | 2026-11-17 |
| Production main site logo and icon | Not checked: read `site_logo` and `site_icon` on production before changing them | WP-CLI over `ssh home.local` | - |

Older uploads `logo.png` and `logo-1.png` are also in `app/web/uploads/2026/11/` locally; their use is not checked.

## Tokens (link, do not copy elsewhere)

- Palette: `app/web/themes/theme/theme.json` (`primary`, `secondary`, `accent`, `base`, `contrast`), described in [theme-development.md](../../../documentation/theme-development.md#design-tokens).
- Font: Inter, `app/web/mu-plugins/mody-fonts/fonts/`.
- wp-admin: the Ocean color scheme for every user (`mody-admin-colors`).

## Where the platform's brand shows

- The main site (`mody.cloud`): header, the landing page (`scripts/home-page.php`), the legal pages (`scripts/legal-pages.php`), `/register/` (the signup chat, `mody-members`).
- The login page of the main site (`mody-login` dresses each site's login in that site's own brand, so the main site's login wears the platform's).
- Emails from `mody-mail`: the sender name `Mody Cloud` (`MAIL_SENDER_NAME`); no logo in emails today.
