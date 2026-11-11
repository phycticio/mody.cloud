# One profile for the whole network, with a local picture instead of Gravatar

**Milestone:** M4 (member sites) · **Status:** built 2026-11-11, not deployed

## Asked for
By the maintainer on 2026-11-11, about Gravatar (found while planning [agent-knows-gutenberg-spectra-settings.md](agent-knows-gutenberg-spectra-settings.md): `get_avatar_url()` returned `secure.gravatar.com`, rule 1):

> Remove gravatar and replace it with a user picture that can be added locally. Think of the profile of the user as a unique profile for all sites so a user can be added to different sites, but the information in all sites is always synced and only maintained in the main site. What is on the main site (site id 1) is the truth.

## Plan
WordPress already keeps users and their profile fields once for the whole network (`wp_users`, `wp_usermeta`). What changes is where they are edited, and the picture.
1. **The profile is edited on the main site only.** On every other site, Profile (`profile.php`) and the toolbar's "Edit Profile" lead to the main site's user dashboard (`mody.cloud/wp-admin/user/profile.php`). The login cookie covers every subdomain, so the member stays logged in. **Inference:** a custom domain (not built) will need its own way there.
2. **The picture.** An upload field on that profile screen. The image is stored in the main site's media library, owned by the user, and its ID in the user meta `mody_avatar`. Every site shows it: `pre_get_avatar_data` returns its URL for that user, at the size asked.
3. **No Gravatar, ever.** Without a picture, and for visitors who comment, the avatar is a local image (the person's initials, served by the platform), never a call to gravatar.com. Core's "Default Avatar" choices that come from Gravatar are removed from Settings → Discussion. Antispam Bee's Gravatar check stays pinned off.
4. **Tests:** no avatar URL on any site points outside the network; the picture shows the same on two sites; a subsite's profile leads to the main site's; only the user (or a super admin) can change their picture.

## Results (built 2026-11-11, not deployed)
- **`Users\NetworkProfile`:** on every site but the main one, `profile.php` leads to the network's user dashboard (`/wp-admin/user/profile.php`, which opens for any logged-in user, with or without a role on the main site), and `user-edit.php` to Network Admin's Edit User. `edit_profile_url` (the toolbar) points there too. The REST API refuses changes to a user's profile there; reading stays, and so do changes to user meta only, because the block editor saves its preferences and the editor tour its stage that way (found by `onboarding-test.php`).
- **`Users\Avatars`:**
  - `pre_get_avatar_data` answers every avatar: the user's picture, or initials at `/mody-avatar/<color>/<letters>.svg`, drawn by the platform before WordPress loads (like the pattern placeholders). A `data:` URL was tried first: `get_avatar()` drops it (`esc_url`), and allowing `data:` everywhere would be unsafe.
  - The picture is uploaded on the Profile Picture row (core's "change it on Gravatar" text is replaced), or in a section of its own when the main site hides avatars. JPEG, PNG, WebP or GIF, at most 2 MB, stored in the main site's media library and owned by the user; a new one deletes the old one, Remove deletes it. The user meta `mody_avatar` holds its ID and `mody_avatar_urls` its URLs per size, so other sites show it without switching.
  - Settings → Discussion offers one default avatar, the initials.
- **Test:** `scripts/network-profile-test.php` (avatars for users, emails, comments; the initials route; the redirects; the REST refusals and the meta exception; an upload over HTTP through the profile form, shown on two sites; a text file refused; replace; Remove). `onboarding-test.php` and `admin-colors-test.php` still pass.
- **Inference:** a custom domain (not built) is outside the shared login cookie and will need its own way to the main site's profile.

## Review (2026-11-11)
An independent review found, and these were fixed the same day:
- **The REST refusal had gaps.** Other casings (`/wp/v2/Users/me`) and PUT or PATCH forms got past it, and inside `/batch/v1` a refusal ended in a 500. The refusal now lowercases the route, reads every parameter, and returns a response.
- **Application Passwords** are only for super admins now (rule 6). The section on the network profile could not work for members, who have no use for them.
- **The picture is drawn again.** It is centered and cropped square, at most 512 pixels, by GD, which writes no metadata: no camera, place or date from the original. It is stored as `profile-picture-<user>`, stays out of the main site's media lists (attachment meta `_mody_avatar_of`), and is deleted with its user. Deleting the attachment clears the user's avatar.
- **Not changed:** there is no pixel cap beyond the 2 MB size limit, the same as any upload on the platform. Pictures will count against the main site's space when the 3 GB quota is enforced.

Tests added for each to `scripts/network-profile-test.php`.
