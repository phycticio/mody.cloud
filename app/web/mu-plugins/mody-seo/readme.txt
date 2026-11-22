=== Mody SEO ===
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An SEO title and an SEO description for every page and post, in the editor's sidebar.

== Description ==

Mody SEO decides how each page and post shows in search results:
* an **SEO title** and an **SEO description**, in the editor's sidebar (Search engines), in the post editor and the Site Editor;
* printed in the page's head as the title and meta description;
* empty fields fall back to the page's own title and its excerpt.

It does one thing: no sitemaps, scores or redirects.

It runs on a single site or a multisite: activate it per site, or for the whole network.

= What it calls =

It calls nothing outside your site while it works. Once or twice a day, WordPress asks mody.cloud whether a newer version exists: one request for every Mody plugin on the site, which sends nothing about the site (no address, no WordPress version). mody.cloud keeps no log of it. Cloudflare, in front of mody.cloud for now, sees the request.

= Support =

Report a problem: https://github.com/phycticio/mody.cloud/issues

Made by Mody Cloud in Spain: https://mody.cloud/plugins/seo/

== Installation ==

1. Download the zip from https://mody.cloud/plugins/seo/.
2. In wp-admin, Plugins → Add New → Upload Plugin, choose the zip, then Activate. Or with WP-CLI: `wp plugin install mody-seo-1.0.0.zip --activate`.

== Changelog ==

= 1.0.0 =
* First release.
