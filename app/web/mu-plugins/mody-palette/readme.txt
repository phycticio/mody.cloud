=== Mody Palette ===
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Color pickers that offer only your site's palette, Spectra's own pickers included.

== Description ==

Mody Palette keeps colors on brand:
* the editor's color pickers offer only the theme's palette, with no custom color;
* Spectra's own pickers too, when Spectra is installed.

It runs on a single site or a multisite: activate it per site, or for the whole network.

= What it calls =

It calls nothing outside your site while it works. Once or twice a day, WordPress asks mody.cloud whether a newer version exists: one request for every Mody plugin on the site, which sends nothing about the site (no address, no WordPress version). mody.cloud keeps no log of it. Cloudflare, in front of mody.cloud for now, sees the request.

= Support =

Report a problem: https://github.com/phycticio/mody.cloud/issues

Made by Mody Cloud in Spain: https://mody.cloud/plugins/palette/

== Installation ==

1. Download the zip from https://mody.cloud/plugins/palette/.
2. In wp-admin, Plugins → Add New → Upload Plugin, choose the zip, then Activate. Or with WP-CLI: `wp plugin install mody-palette-1.0.0.zip --activate`.

== Changelog ==

= 1.0.0 =
* First release.
