<?php
/**
 * Plugin Name:       Mody Onboarding
 * Description:       A new member's first steps: their first password opens their site's Identity screen, and the editor tour shows them around.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * A member's first steps (M1-03, tickets/M1-03-chat-signup-and-editor-tour.md):
 * the first password logs the member in on their site's Identity screen,
 * where the editor tour shows the agent, asks for two requests, then
 * explains the Site Editor. Their stage is the user meta `mody_onboarding`.
 *
 * Other plugins tell it what it cannot know:
 *
 * - `mody_onboarding_no_site_url` (filter): where a member with no site of
 *   their own goes after their first password (default: wp-admin).
 * - `mody_onboarding_free_until` (filter): when the member's free months end,
 *   as a timestamp, for the tour's last step (default: 0, not shown).
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class. A network-only plugin.
 *
 * @package Mody\Onboarding
 */

namespace Mody\Onboarding;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Onboarding\Example is src/Example.php.
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

if ( is_multisite() ) {
	add_action( 'init', array( Onboarding::class, 'register_meta' ) );
	add_action( 'after_password_reset', array( Onboarding::class, 'after_password_reset' ) );
	add_action( 'enqueue_block_editor_assets', array( EditorTour::class, 'enqueue' ), 20 ); // After the agent's chat bubble.
	add_filter( 'rest_prepare_user', array( Onboarding::class, 'hide_stage' ), 10, 3 ); // The tour's stage is nobody else's business (security pass).
}
