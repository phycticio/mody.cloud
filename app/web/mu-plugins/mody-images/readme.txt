=== Mody Images ===
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Every uploaded picture made a light WebP: upright, at most 2560 pixels, without its camera data.

== Description ==

Mody Images changes each JPEG, PNG or HEIC picture before WordPress stores it:
* turned upright;
* at most 2560 pixels on a side, with no extra -scaled copy;
* its camera data removed (EXIF, XMP, IPTC, the place included), its color profile kept;
* saved as WebP (quality 82 for photos, 90 for PNG), and every size as WebP.

GIF, SVG and animations are left alone. It uses Imagick, or GD without it. `wp mody-images optimize` converts the pictures already in the media library (back up first: it rewrites the content's addresses and deletes the old files).

It runs on a single site or a multisite: activate it per site, or for the whole network.

= What it calls =

It calls nothing outside your site while it works. Once or twice a day, WordPress asks mody.cloud whether a newer version exists: one request for every Mody plugin on the site, which sends nothing about the site (no address, no WordPress version). mody.cloud keeps no log of it. Cloudflare, in front of mody.cloud for now, sees the request.

= Support =

Report a problem: https://github.com/phycticio/mody.cloud/issues

Made by Mody Cloud in Spain: https://mody.cloud/plugins/images/

== Installation ==

1. Download the zip from https://mody.cloud/plugins/images/.
2. In wp-admin, Plugins → Add New → Upload Plugin, choose the zip, then Activate. Or with WP-CLI: `wp plugin install mody-images-1.0.0.zip --activate`.

== Changelog ==

= 1.0.0 =
* First release.
