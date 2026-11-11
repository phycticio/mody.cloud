# The login page in each site's brand, and Settings → Login

**Milestone:** design follow-up (M1/M4) · **Status:** deployed to production on 2026-11-10 (`fd4a42d`); the maintainer's browser check pending

## Asked for
By the maintainer on 2026-11-10, first:

> the wp-login.php page needs to match mody's branding. But we don't want to have to change a color in the brand and have to go and edit the wp-login.php page.

Then, the same day, replacing the first version (every login page in the main site's brand):

> Now I want the wp-login.php to be styled by the theme variables and colors. And that the Login page had a setting in the Settings -> Login menu where you can see a tabs sidebar with a right content with only one tab: Login color scheme and present all the available color schemes in the theme up to 6 selected because of the color palette and information of the site. But the main thing is that if a site has a logo that should be the one displayed there and it should look good and responsive at all times.

## Decisions (the maintainer, 2026-11-10)
- **Each site's login page is that site's.** Its logo, its palette and its fonts, read on every request from its Global Styles and its settings. Changing a color in Editor → Styles, or the logo on the Identity screen, shows on the next page load.
- **The schemes are built from the site's own palette.** Up to six arrangements of its colors. A scheme that would be hard to read with those colors is not offered, so a site may get fewer than six.
- **Settings → Login is for whoever manages the site** (`manage_options`): members on their own sites, and super admins.

## The schemes
| Scheme | Page | Form | Button | Offered when |
| --- | --- | --- | --- | --- |
| Light | `base` | a lighter tint of `base` | `primary` | always: a color that would not read is shifted or replaced |
| Soft | `base` tinted with `primary` | `base` | `primary` | everything reads |
| Bold | `primary` | `base` | `accent` | everything reads |
| Dark (Inverted when `contrast` is light) | `contrast` | `contrast` lifted toward `base` | `primary` | everything reads |
| Accent | `base` | a lighter tint of `base` | `accent` | everything reads |
| Secondary | `secondary` | `base` | `primary` | everything reads |

"Reads" means text and links at 4.5:1 (WCAG AA) on what they sit on, the button at 3:1 against the form, and its label at 4.5:1 on it and on its hover shade. Two schemes that come out the same are offered once. A scheme chosen earlier that the palette no longer allows is shown as Light, and Settings → Login says so. Without `base`, `contrast` and `primary`, no scheme is offered and the page keeps WordPress's colors.

## Built
`app/web/mu-plugins/mody-platform/src/Login/`, registered in `mody-platform.php`:
- **`Schemes`:** the six recipes, as palette slugs. The site option `mody_login_scheme` stores the choice and accepts only a known scheme.
- **`LoginPage`** (wp-login.php):
  - **Theme variables:** the site's own preset variables (`wp_get_global_stylesheet( array( 'variables' ) )`) and its fonts' `@font-face` rules (`wp_print_font_faces()`, served from this server).
  - **The scheme:** its roles (`--mody-login-page`, `-card`, `-text`, `-button`…) are set inline on the body. Palette colors are the theme's own variables (`var(--wp--preset--color--primary)`); only tints and shades are hex.
  - **Core's colors:** core's `--wp-admin-theme-color` and its darker steps are set to the button color, so buttons, focus rings and checkboxes follow without restyling each one.
  - **Fonts:** the site's text and heading fonts, from Global Styles.
  - **`assets/login.css`** styles the page from those roles.
  - **The logo:** the site's logo (`site_logo`, else the `custom_logo` theme mod) at full size with `srcset`, else its site icon, else its name. It links to the site's home page. It fits inside the form's width and 84px high (64px on phones), without distortion. A logo is drawn for the site's background, so on a page of another color (Bold, Dark, Secondary) it sits on a plate of the background color.
  - **Preview:** `?mody-login-scheme=<slug>` shows another scheme to users who may choose one. An administrator opening it while logged in stays on the form instead of being sent to wp-admin.
- **`SettingsPage`** (Settings → Login):
  - **Layout:** tabs on the left (one today: Login color scheme) and the tab on the right. On narrow screens the tabs go above the panel, as a row.
  - **The site card:** its logo, name, tagline and palette, with links to the Identity and Styles screens.
  - **The schemes:** each one as a small login page with the site's logo, colors and fonts, a radio button, its description and a link to preview it on the real page. The choice is saved through core's `options.php`. Styles: `assets/login-settings.css`.
- **`Color`:** the color math (contrast, shades).
- `SiteDesign::palette()` is new: the whole palette. `SiteDesign::colors()` now reads it.

## Test
`scripts/login-page-test.php`, no model call, local only:
- **Schemes:** for every theme preset and some awkward palettes, at most six, Light first, no duplicates, and every offered scheme readable.
- **The chosen scheme:** its fallback, the option's sanitizing, the CSS (only theme variables, hex and numbers) and the fonts.
- **The logo:** the image, the icon and the name, escaped.
- **Over HTTP:** a throwaway member site with the Plum preset and its own logo; its login page shows its palette, logo, link and fonts. Its administrator opens Settings → Login, which lists the schemes; saving one changes the page. A preview is honored for the administrator and ignored for visitors, and an author cannot open the screen.

```bash
docker exec -i -u www-data mody_cloud_app \
  wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/login-page-test.php
```

## Still open
- The maintainer's browser check. An independent review was started and stopped before it reported (2026-11-10); the test passes.
- **The agent cannot choose a scheme yet** (no `mody/*` ability). Add one if members should be able to ask Mody for it.
