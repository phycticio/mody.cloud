=== Mody Security ===
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two-step login with an app or a security key, login limits, a firewall, a file check, a security log and alerts.

== Description ==

Mody Security protects logins and the site from our own code, with no outside service:
* **Two-step login:** an authenticator app or security keys and passkeys, with ten recovery codes. Administrators must use it by default; that is a setting.
* **Login protection:** limits per address and per account, and usernames nobody may log in with.
* **ALTCHA:** a proof-of-work check, computed by the visitor's browser and checked on your server, on login, lost password and comments.
* **A firewall** with rules on the address asked for and on form data, and lists of blocked and allowed addresses.
* **A security log** kept 30 days, and **alerts** by email.
* **A file check** that lists PHP files in the uploads folder, and compares the code with a list of its files when one is written (`wp mody-security files-manifest`).

Its settings are on Settings → Security, or Network Admin → Settings → Security on a multisite.

It runs on a single site or a multisite: activate it per site, or for the whole network.

= What it calls =

It calls nothing outside your site while it works. Once or twice a day, WordPress asks mody.cloud whether a newer version exists: one request for every Mody plugin on the site, which sends nothing about the site (no address, no WordPress version). mody.cloud keeps no log of it. Cloudflare, in front of mody.cloud for now, sees the request.

= Support =

Report a problem: https://github.com/phycticio/mody.cloud/issues

Made by Mody Cloud in Spain: https://mody.cloud/plugins/security/

== Installation ==

1. Download the zip from https://mody.cloud/plugins/security/.
2. In wp-admin, Plugins → Add New → Upload Plugin, choose the zip, then Activate. Or with WP-CLI: `wp plugin install mody-security-1.0.0.zip --activate`.

== Changelog ==

= 1.0.0 =
* First release.
