<?php
/**
 * Plugin Name:       Mody SEO
 * Description:       How each page and post shows in search results: an SEO title and an SEO description in the editor's sidebar, printed in the page's head. Without them, the page's title and excerpt are used.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        https://mody.cloud/
 * Text Domain:       mody_cloud
 *
 * On the platform, mu-plugins/mody-seo.php loads this file; a site
 * downloaded from Mody Cloud (tickets/take-your-site-with-you.md) runs it
 * as a normal plugin, so it carries its own header.
 *
 * Mody SEO (its own MU plugin, decided by the maintainer on 2026-11-10 and
 * 2026-11-16, tickets/seo.md): one job, a page's search appearance.
 *
 * - **The fields** (Fields): every public post type (pages and posts) has
 *   two post meta fields, `mody_seo_title` and `mody_seo_description`.
 * - **The sidebar** (Panel): the "Search engines" panel of the editor's
 *   document sidebar edits them, in the post editor and in the Site Editor.
 * - **The head** (Head): the SEO title is the whole `<title>`; without one,
 *   WordPress's own ("Page title – Site"). The SEO description is the
 *   `<meta name="description">`; without one, the page's excerpt; with
 *   neither, no tag (search engines pick a snippet).
 *
 * It names no other Mody plugin's class except, when present, Mody
 * Languages' Translations. Mody's agent changes the fields through
 * `mody/update-seo`, in Mody Agent.
 *
 * @package Mody\Seo
 */

namespace Mody\Seo;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Seo\Fields is src/Fields.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

Fields::register();
Head::register();
Panel::register();

// WordPress's update notice from mody.cloud, when installed as a normal plugin (never on Mody Cloud's network).
UpdateCheck::register( __FILE__ );
