=== Mody Dashboard ===
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Dashboard that shows the site at a glance and its visitors on a board of charts.

== Description ==

Mody Dashboard replaces wp-admin's Dashboard with the site at a glance:
* **Overview:** the site, its storage and what is new;
* **Visitors:** a board of charts you arrange yourself, with periods, comparisons, filters and CSV downloads (needs Mody Analytics);
* other plugins can add sections of their own.

The board's script ships inside the plugin; nothing loads from another site.

It runs on a single site or a multisite: activate it per site, or for the whole network.

= What it calls =

It calls nothing outside your site while it works. Once or twice a day, WordPress asks mody.cloud whether a newer version exists: one request for every Mody plugin on the site, which sends nothing about the site (no address, no WordPress version). mody.cloud keeps no log of it. Cloudflare, in front of mody.cloud for now, sees the request.

= Support =

Report a problem: https://github.com/phycticio/mody.cloud/issues

Made by Mody Cloud in Spain: https://mody.cloud/plugins/dashboard/

== Installation ==

1. Download the zip from https://mody.cloud/plugins/dashboard/.
2. In wp-admin, Plugins → Add New → Upload Plugin, choose the zip, then Activate. Or with WP-CLI: `wp plugin install mody-dashboard-1.0.1.zip --activate`.

== Changelog ==

= 1.0.1 =
* The Visitors note says the site counts its visitors itself (it said "Mody Cloud").

= 1.0.0 =
* First release.
