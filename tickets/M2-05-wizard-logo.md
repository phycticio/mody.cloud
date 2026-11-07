# M2-05 · Logo upload in the brand wizard

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §6 · **Status:** todo

Split from M2-03 on 2026-11-06 (rule 5). Comes after [M2-03](M2-03-brand-wizard.md).

## Goal
In step 2 of the wizard, the member can upload a logo (optional). The new site shows it, and the brand profile records it.

## Deliverables
- **Upload in the wizard:** a file field (or drag and drop) for an image, validated server-side: an image type WordPress accepts, a size limit. The wizard runs in Network Admin, on the main site, so decide where the file waits until the site exists. The main site's media library is the simplest place; delete it there once it is copied.
- **Pipeline:** a step (or the `profile` step extended) that copies the file into the new site's media library, sets the site's `site_logo` option (the Site Logo block in the theme's header), and saves the URL in the profile's `logo_url`. It must stay idempotent like the other steps (M2-01): a resumed job must not import the logo twice.
- **Rule 1:** the file stays on the network; nothing is fetched from outside.
- Test: extend `scripts/wizard-test.php` and `scripts/provisioning-test.php`.

## Acceptance criteria (spec §6)
- The logo goes through the media library and is validated server-side.
- The provisioned site's header shows it; the brand profile's `logo_url` points to it.
- Without a logo, nothing changes.

## Notes
- The theme's header part (`parts/header.html`) already has a Site Logo block (checked 2026-11-06), so setting `site_logo` is enough for the header to show it.
