=== Mody Fonts ===
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ten free font families in WordPress's Font Library, served from your site, with no Google Fonts.

== Description ==

Mody Fonts adds the Mody collection to WordPress's Font Library (Editor → Styles → Typography):
* ten free families, their files inside the plugin, each with its license;
* installed fonts are served from your site;
* the Google Fonts collection is removed, so nothing is fetched from Google.

It runs on a single site or a multisite: activate it per site, or for the whole network.

= What it calls =

It calls nothing outside your site while it works. Once or twice a day, WordPress asks mody.cloud whether a newer version exists: one request for every Mody plugin on the site, which sends nothing about the site (no address, no WordPress version). mody.cloud keeps no log of it. Cloudflare, in front of mody.cloud for now, sees the request.

= Support =

Report a problem: https://github.com/phycticio/mody.cloud/issues

Made by Mody Cloud in Spain: https://mody.cloud/plugins/fonts/

== Installation ==

1. Download the zip from https://mody.cloud/plugins/fonts/.
2. In wp-admin, Plugins → Add New → Upload Plugin, choose the zip, then Activate. Or with WP-CLI: `wp plugin install mody-fonts-1.0.0.zip --activate`.

== Changelog ==

= 1.0.0 =
* First release.
