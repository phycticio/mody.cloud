# Images: every upload made web efficient

**Status:** planned and built 2026-11-14; tested locally, not deployed.

## Asked for
By the maintainer on 2026-11-14:

> Let's create a custom plugin that compress images on upload and makes them web efficient

## Decisions (the maintainer's, 2026-11-14: every recommendation accepted)
1. **WebP.** It is about 25 to 35% smaller than JPEG, works in every browser, and encodes fast enough to run during an upload. AVIF was left out: it is smaller still, but several times slower to encode, and each upload makes 6 to 8 sizes.
2. **The original is replaced, not kept.** WordPress stores only the optimized picture: at most 2560 pixels on a side, so there is no `-scaled` copy and no untouched original next to it. This saves the most of each site's 1 GB. The camera original is not kept.
3. **Its own MU plugin, `mody-images`** (`app/web/mu-plugins/mody-images/`), always on like `mody-mail` and `mody-languages`. It is the fifth exception to "platform logic lives in one MU plugin". **Take your site with you** carries it as a normal plugin, so a downloaded site keeps optimizing its uploads.
4. **New uploads, plus a WP-CLI command** for the pictures already in media libraries: `wp mody-images optimize`. It is run by hand, never by itself.

## What a picture becomes (`Optimizer`)
- **Upright:** the camera's orientation is applied to the pixels.
- **At most 2560 pixels on its longest side.** This is WordPress's own "big image" threshold, so WordPress never makes a `-scaled` copy.
- **No camera data:** EXIF, XMP, IPTC and comments are removed, and with them the place a phone writes into a photo. The color profile stays, so a photo in Display P3 keeps its colors. CMYK and grayscale pictures become sRGB.
- **WebP** at quality 82 for photos (JPEG, HEIC) and 90 for PNG, which keeps logos and screenshots sharp. Transparency is kept. The sizes WordPress makes from it are WebP at 82.
- **Which pictures:**
  - JPEG, PNG and HEIC: always.
  - WebP and AVIF: only when larger than 2560 pixels, because they are already efficient.
  - GIF, SVG and animations (APNG, animated WebP and AVIF): never, because they would lose their frames.
- **Never worse:** if nothing had to be made smaller and the WebP is not smaller than the file, the file is kept as it came.
- **Never fails an upload:** a picture that cannot be read or written is uploaded as it came.

Imagick does the work when it writes WebP, which it does on our stack (ImageMagick 7.1 with WebP, AVIF and HEIC). Otherwise GD does it through WordPress's image editor (GD cannot read HEIC). Nothing calls an outside service (rule 1). There is no setting, and nothing is stored.

## How it works
- **Uploads** (`Uploads`): on `wp_handle_upload_prefilter` and `wp_handle_sideload_prefilter` at priority 20, after the platform's checks at 10. `Gating\ImageLimits` refuses a picture too big to decode before this decodes it. These hooks see every upload: the Media Library, the block editor's REST uploads, and `media_handle_sideload()` (the wizard's logo, profile pictures).
  - The temporary file is replaced in place by its WebP, and its name and type follow, so `Holiday Photo.JPG` is stored as `Holiday-Photo.webp`.
  - The quota check in the Media Library runs at priority 10, before this, so it counts the uploaded size. The REST upload counts it before any filter. Both are conservative.
- **The sizes:** WordPress makes them from the WebP (`wp_editor_set_quality` 82 for WebP).
- **Client-side media processing is off** (`wp_client_side_media_processing_enabled`). Since WordPress 7.1, the block editor on HTTPS makes the sizes in the browser and uploads each one. Here the server makes them from the optimized WebP, so every upload takes the same path.
- **The media library already there** (`Library`, `Command`):
  - `wp mody-images optimize [--url=<site>|--network] [--dry-run] [--limit=<n>] [--yes]` converts each older picture from its uploaded original.
  - The attachment keeps its ID, title and alt text, and points at the WebP. Its sizes are made again.
  - In every post, page, template, part, pattern, the global styles and revisions, the address of each old file becomes the WebP file of the same size. This covers plain and JSON-escaped addresses, matched by their path from the uploads folder, so the network's address and a custom domain both follow.
  - Spectra's cached CSS for those posts is dropped. The old files are deleted: the original, `-scaled`, the sizes, and edit backups.
  - **It cannot be undone:** back up the uploads and the database first, and try `--dry-run`.

## Not changed
- Pictures already uploaded stay as they are until the command runs.
- Post meta that holds addresses as text: serialized values cannot be edited as text. WordPress and Spectra keep images by ID.
- IPTC titles and captions that WordPress used to read from a photo when it was uploaded are gone with the camera data. The title comes from the file name.

## Files
- `app/web/mu-plugins/mody-images.php` (the loader) and `app/web/mu-plugins/mody-images/`: `mody-images.php` (its header, so it also runs as a normal plugin), `src/Optimizer.php`, `src/Uploads.php`, `src/Library.php` and `src/Command.php`.
- `Export\Portable::PLUGINS` carries it into a downloaded site.
- Test: `scripts/images-test.php`. The export tests check that it is in the code file, that it activates on a plain WordPress, and that an upload there becomes WebP.

## Results (2026-11-14, locally)
- `scripts/images-test.php`: every check passes.
  - A 3200 × 2400 phone photo with orientation 6 and a GPS place became a 1920 × 2560 upright WebP without EXIF: 4.0 MB became 330 KB.
  - A PNG with transparency: 3.1 MB became 125 KB, with its alpha kept.
  - An older JPEG with `-scaled` and its sizes: 5.9 MB of files became 0.6 MB, and its content follows each size.
- A REST upload as the block editor sends it: a 3000 × 2000 JPEG of 2 MB became a 2560 × 1707 WebP of 342 KB, with WebP sizes.
- The regression tests pass: `network-profile-test`, `provisioning-test`, `gating-test`, `registration-test`, `export-runner-test`, `export-archives-test` and `export-portable-test.sh`.
  - The logo checks of `wizard-test` and `provisioning-test` now expect WebP.
  - `wizard-test`'s check "the theme's header with the brand name" fails with or without this plugin. It was already failing, and it is not part of this ticket.

## Open questions
- **The existing media on production:** run `wp mody-images optimize --network --dry-run`, then the real run after a backup. It needs the maintainer.
- **The quota counts the uploaded size**, not the stored one, so a 20 MB photo needs 20 MB free even though it is stored in a few hundred KB. Counting the stored size would mean running the conversion before WordPress's quota checks (REST checks before any filter).
