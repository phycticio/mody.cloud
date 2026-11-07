# M2-05 · Logo upload in the brand wizard

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §6 · **Status:** done (2026-11-07)

Split from M2-03 on 2026-11-06 (rule 5). Builds on [M2-03](M2-03-brand-wizard.md) and [M2-04](M2-04-wizard-live-preview.md).

## Goal
In step 2 of the wizard, the member can upload a logo (optional). The new site shows it, and the brand profile records it.

## How it is built
- **In the wizard** (step 2): an optional file field for PNG, JPEG, GIF or WebP, at most 2 MB (`WizardController::MAX_LOGO_BYTES`).
  - The script checks type and size first, and shows the logo in the live preview's header, before the site title, 48 px wide like the theme's Site Logo block.
  - **Create my site** now sends the answers as multipart form data, the file included.
- **On the server** (`WizardController::create()`):
  - the answers are validated first, so no file is stored for a request that fails anyway;
  - the file's content decides its type (`wp_check_filetype_and_ext()`, the four image types only), and its size is checked again;
  - it is stored in the main site's media library (`media_handle_sideload()`). The wizard runs there, and the new site does not exist yet;
  - if the creation is then refused (the site limit, say), the stored file is deleted.
  - Errors come back as `mody_invalid_input` on the `logo` field.
- **In the pipeline** (`Pipeline::validate()`, step `profile`):
  - `logo_id` must be an image on the main site that this user uploaded.
  - The `profile` step copies it into the new site's media library, with its sizes. The copy records its job (`_mody_provisioning_job`), so a resumed run does not copy it twice.
  - The step sets the site's `site_logo` (the theme's header has a Site Logo block), saves the address in the profile's `logo_url`, and deletes the upload on the main site.
  - A logo that cannot be copied (the upload is gone, say) does not stop the site: the site is created without a logo, and `result.logo_error` says why.
- **The logo's address.** `wp-config.php` builds `WP_CONTENT_URL` from the request's host. An upload URL made while the wizard's request is switched to the new site therefore has the wizard's host. The file is served on every host of the network, but the profile keeps the new site's own host. Pages rendered on the new site build their URLs on its host anyway.
- Rule 1: the file never leaves the network.

## Acceptance criteria (spec §6)
- [x] The logo goes through the media library and is validated server-side (type from the content, size).
- [x] The provisioned site's header shows it; the brand profile's `logo_url` points to it.
- [x] Without a logo, nothing changes (the earlier tests pass unchanged).

## Results (2026-11-07, local)
- `scripts/provisioning-test.php`, "Logo":
  - refused: someone else's upload, an unknown ID, a file that is not an image;
  - a full run: the logo is in the new site's media library, set as `site_logo`, shown in the header part, recorded in the profile on the site's host, and the upload on the main site is removed;
  - killed in the profile step after the copy, then resumed: one logo;
  - the upload gone before the run: the site, without a logo, with `logo_error`.
- `scripts/wizard-test.php`, "Logo":
  - a PNG through the endpoint;
  - text named `.png` and a file over 2 MB are refused on the logo field;
  - a wrong answer elsewhere stores nothing;
  - at the site limit, the stored file is deleted;
  - over HTTP, as the browser sends it (multipart): 202 in 0.3 s, ready 5.2 s later, with the logo.
- The main site's attachment count is unchanged after the tests.
- **Production** (deployed 2026-11-07, commit `3d16b6b`, together with M2-04, after a backup in `backups/2026-11-07-pre-m2-05/`):
  - the preview renders, with the fixed links, and refuses logged-out visitors (403);
  - the wizard page loads;
  - the offline agent test and the Spectra test pass, with no PHP warnings.
  - The maintainer's own production site, `phycticio.mody.cloud` (created 14:38 UTC with M2-03), finished with a generated page. Its welcome email failed, because production has no SMTP relay yet, and its address does not resolve until the wildcard DNS (M5).
- **Seen once and not reproduced** (`provisioning-test.php`, "killed at site"): the resumed run made 0 model requests instead of 1, with everything else right. The same case passed in 15 repeated runs and in every later full run. The check now prints the job's result if it fails again.
