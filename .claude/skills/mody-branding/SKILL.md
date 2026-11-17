---
name: mody-branding
description: Find and apply Mody Cloud's own brand - the platform's logo, isotype, favicon, colors, font and voice on the main site, the landing page, emails, wp-admin and the login page - and keep a short verified inventory of the assets. Use for the platform's own look. Not for a member site's brand (Identity, Global Styles, mody_brand; see documentation/brand-profile.md) and not for inventing a new identity.
---

# Mody branding

Mody Cloud's own identity: [project-overview.md#visual-identity](../../../documentation/project-overview.md#visual-identity). The inventory of assets, where each one is used and what is still unknown: [brand-assets.md](brand-assets.md). Do not turn observed styling into brand policy; the maintainer decides the brand.

A member's site has **its own** brand (its settings, Global Styles, `mody_brand`); the platform's brand never leaks into it. Changes to how members' brands work belong to [brand-profile.md](../../../documentation/brand-profile.md) and [mody-delivery](../mody-delivery/SKILL.md), not here.

## Find the source

- Start from [brand-assets.md](brand-assets.md). Use the master files it lists; a media-library upload or a resized copy is a derivative, not a source.
- If the master is not where the inventory says, stop and ask the maintainer; do not redraw, trace, regenerate or "clean up" the logo with AI or by hand. Preserve geometry, proportions, colors and transparency.
- Colors come from the theme's palette slugs (`primary` #005f6b teal, `secondary` #333333, `accent` #b50ba4 magenta, `base` #f5f5f5, `contrast` #01110a) in `app/web/themes/theme/theme.json`. Use the slug or its CSS variable (`var(--wp--preset--color--primary)`), never a loose hex in content or templates. The font is Inter, served locally (`mody-fonts`; no Google Fonts, rule 1).
- Copy is English, honest and plain (rule 7: real limits, never "unlimited"; prices as published). The landing page's words live in `scripts/home-page.php` and its translations in `scripts/home-page-translations.json`.

## Apply a change

1. Prepare one derivative (crop, size, format) mechanically from the master; note the command and the checksum (`shasum -a 256`). Logos and icons go through WordPress's media library on the main site (`mody-images` turns PNG/JPEG into WebP; an SVG or a GIF stays as it is), or into the repository when code ships them (a plugin's `assets/`).
2. **Locally first:** set it through WordPress (main site: `./bin/wp option update site_logo <id>`, `site_icon <id>`, or the Identity screen `site-editor.php?p=/identity`). Note the previous attachment ID for rollback. Check the result small and large, on light and dark backgrounds, in a real browser ([mody-test](../mody-test/SKILL.md)): the front page, `/wp-login.php`, the favicon, the dashboard.
3. **Production** only when the maintainer asks: the same derivative uploaded with WP-CLI over `ssh home.local` (see [mody-investigate](../mody-investigate/SKILL.md) for the Docker environment there), the previous IDs recorded, then the public HTML and the asset URLs checked, including through Cloudflare's cache. There is no staging.
4. Remove only temporary files you made; keep the previous assets for rollback.

## Keep the inventory small

Update [brand-assets.md](brand-assets.md) when a verified fact changes: the master's location, each derivative's use and where it is set, the transformation when it must be repeated, and the date checked. Link to code tokens rather than copying them. No customer data, no credentials, no discarded experiments.
