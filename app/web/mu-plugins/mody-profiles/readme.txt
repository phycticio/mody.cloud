=== Mody Profiles ===
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Profile pictures stored on your own site and initials for everyone else, with no Gravatar.

== Description ==

Mody Profiles replaces Gravatar:
* each person uploads a picture on their profile, stored in your media library;
* everyone else gets their initials, drawn by your site;
* nothing is asked of gravatar.com.

On a multisite, each person has one profile, edited on the main site.

It runs on a single site or a multisite: activate it per site, or for the whole network.

= What it calls =

It calls nothing outside your site while it works. Once or twice a day, WordPress asks mody.cloud whether a newer version exists: one request for every Mody plugin on the site, which sends nothing about the site (no address, no WordPress version). mody.cloud keeps no log of it. Cloudflare, in front of mody.cloud for now, sees the request.

= Support =

Report a problem: https://github.com/phycticio/mody.cloud/issues

Made by Mody Cloud in Spain: https://mody.cloud/plugins/profiles/

== Installation ==

1. Download the zip from https://mody.cloud/plugins/profiles/.
2. In wp-admin, Plugins → Add New → Upload Plugin, choose the zip, then Activate. Or with WP-CLI: `wp plugin install mody-profiles-1.0.1.zip --activate`.

== Changelog ==

= 1.0.1 =
* The profile picture's help says where the picture shows: on this site, or on every site of the network (it said "every Mody Cloud site").

= 1.0.0 =
* First release.
