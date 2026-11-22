=== Mody Analytics ===
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visitors and page views counted without cookies, with nothing stored in the browser and no address kept.

== Description ==

Mody Analytics counts visits and page views on your own server:
* no cookie and nothing stored in the visitor's browser;
* no address kept: a visitor is recognised for one day by a hash with a salt that changes every day, then forgotten;
* the page, where the visit came from and the device; logged-in users count too, anonymously, and bots do not.

Country and city are optional: put DB-IP's free City Lite file (CC BY 4.0) on your server and name it with the `MODY_GEOIP_FILE` constant in wp-config.php. Without it, there is no location.

Mody Dashboard shows the numbers.

It runs on a single site or a multisite: activate it per site, or for the whole network.

= What it calls =

It calls nothing outside your site while it works. Once or twice a day, WordPress asks mody.cloud whether a newer version exists: one request for every Mody plugin on the site, which sends nothing about the site (no address, no WordPress version). mody.cloud keeps no log of it. Cloudflare, in front of mody.cloud for now, sees the request.

= Support =

Report a problem: https://github.com/phycticio/mody.cloud/issues

Made by Mody Cloud in Spain: https://mody.cloud/plugins/analytics/

== Installation ==

1. Download the zip from https://mody.cloud/plugins/analytics/.
2. In wp-admin, Plugins → Add New → Upload Plugin, choose the zip, then Activate. Or with WP-CLI: `wp plugin install mody-analytics-1.0.0.zip --activate`.

== Changelog ==

= 1.0.0 =
* First release.
