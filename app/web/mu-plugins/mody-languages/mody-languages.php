<?php
/**
 * Plugin Name:       Mody Languages
 * Description:       A multilingual site: translations as ordinary pages, language prefixes in addresses (/es/), a language switcher and the translation matrix (Settings → Languages).
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * On the platform, mu-plugins/mody-languages.php loads this file; a site
 * downloaded from Mody Cloud (tickets/take-your-site-with-you.md) runs it
 * as a normal plugin, so it carries its own header.
 *
 * Mody Languages: a multilingual site on every site of the network
 * (decided by the maintainer on 2026-11-10, tickets/site-languages.md).
 *
 * - A translation is an ordinary page with two post meta fields: its language
 *   and its original (Translations). Pages without them are in the site's
 *   default language, core's Site Language.
 * - Addresses of the other languages carry a prefix, /es/... (Router); the
 *   page's language sets <html lang>, hreflang links and WordPress's own
 *   words (Head).
 * - The mody/language-switcher block, placed after every navigation block
 *   (Switcher); menu links follow the language (Menus); header and footer
 *   copies per language (Parts).
 * - Settings → Languages: the site's languages and the translation matrix
 *   (SettingsPage).
 * - The working language in wp-admin and the editors: the content lists
 *   show one language at a time, chosen from the toolbar or the editors'
 *   menu (AdminLanguage).
 *
 * A site with one language behaves exactly as without this plugin. It stands
 * alone: mody-platform adds Mody's translation through the
 * mody_languages_cell_actions filter.
 *
 * @package Mody\Languages
 */

namespace Mody\Languages;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Languages\Router is src/Router.php.
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

Translations::register();
Router::register();
Head::register();
Switcher::register();
Menus::register();
Parts::register();
SettingsPage::register();
AdminLanguage::register();
