=== Mody Patterns ===
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A collection of block patterns with placeholder images drawn on your site; nothing fetched from WordPress.org.

== Description ==

Mody Patterns gives the inserter and the Patterns screen the Mody collection:
* block patterns written for the Mody theme's palette and fonts;
* placeholder images drawn by your site, with no picture from another site;
* WordPress.org's remote patterns and core's bundled patterns are off.

It needs the Mody theme, or a theme with the same palette slugs: primary, secondary, accent, base and contrast. On another theme, some colors are missing.

It runs on a single site or a multisite: activate it per site, or for the whole network.

= What it calls =

It calls nothing outside your site while it works. Once or twice a day, WordPress asks mody.cloud whether a newer version exists: one request for every Mody plugin on the site, which sends nothing about the site (no address, no WordPress version). mody.cloud keeps no log of it. Cloudflare, in front of mody.cloud for now, sees the request.

= Support =

Report a problem: https://github.com/phycticio/mody.cloud/issues

Made by Mody Cloud in Spain: https://mody.cloud/plugins/patterns/

== Installation ==

1. Download the zip from https://mody.cloud/plugins/patterns/.
2. In wp-admin, Plugins → Add New → Upload Plugin, choose the zip, then Activate. Or with WP-CLI: `wp plugin install mody-patterns-1.0.0.zip --activate`.

== Changelog ==

= 1.0.0 =
* First release.
