<?php
/**
 * Tests the brand as each site's own WordPress data (ticket M2-08).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/brand-settings-test.php
 *
 * 1. The two site settings (mission, tone): registered, in REST
 *    (`/wp/v2/settings`, the Site Editor's `root/site` entity), sanitized,
 *    and refused to anyone without manage_options.
 * 2. Where they are edited: Settings → General, and the Identity screen's
 *    script with its settings, on the Site Editor only.
 * 3. The brand for prompts: read from WordPress now, per site.
 * 4. The profile table is gone, and the migration (on a throwaway copy of
 *    the old table) moves mission, tone and a missing title, then drops it.
 *
 * Everything changed is put back. No model call.
 */

use Mody\Platform\Brand\BrandSettings;
use Mody\Platform\AI\SystemPrompt;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-62s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
$member_sites = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
$site         = (int) ( $member_sites[0] ?? get_main_site_id() );
$suffix       = strtolower( wp_generate_password( 6, false ) );
$member       = (int) wp_insert_user(
	array(
		'user_login' => 'modybrand' . $suffix,
		'user_email' => 'modybrand' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
add_user_to_blog( $site, $member, 'administrator' );
$editor = (int) wp_insert_user(
	array(
		'user_login' => 'modybrnded' . $suffix,
		'user_email' => 'modybrnded' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
add_user_to_blog( $site, $editor, 'editor' );
switch_to_blog( $site );
$saved = array(
	BrandSettings::MISSION => get_option( BrandSettings::MISSION, '' ),
	BrandSettings::TONE    => get_option( BrandSettings::TONE, '' ),
	'blogname'             => get_option( 'blogname' ),
);
restore_current_blog();
register_shutdown_function(
	static function () use ( $site, $saved, $member, $editor ): void {
		switch_to_blog( $site );
		foreach ( $saved as $option => $value ) {
			update_option( $option, $value );
		}
		restore_current_blog();
		wpmu_delete_user( $member );
		wpmu_delete_user( $editor );
	}
);

// 1. The two settings ---------------------------------------------------------------

echo "The settings (site $site)\n";
switch_to_blog( $site );
wp_set_current_user( $member );
$registered = get_registered_settings();
$check( 'registered, in REST', ! empty( $registered[ BrandSettings::MISSION ]['show_in_rest'] ) && ! empty( $registered[ BrandSettings::TONE ]['show_in_rest'] ) );
$rest = static function ( string $method, array $body = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/wp/v2/settings' );
	foreach ( $body as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};
$response = $rest( 'GET' );
$data     = (array) $response->get_data();
$check( 'in /wp/v2/settings with the title and tagline (the root/site entity)', 200 === $response->get_status() && array_key_exists( BrandSettings::MISSION, $data ) && array_key_exists( BrandSettings::TONE, $data ) && array_key_exists( 'title', $data ), (string) $response->get_status() );
$response = $rest(
	'POST',
	array(
		BrandSettings::MISSION => "  We bake <b>sourdough</b>\nfor schools.  " . str_repeat( 'x', 300 ),
		BrandSettings::TONE    => 'technical',
	)
);
$mission  = (string) get_option( BrandSettings::MISSION );
$check( 'saved through REST, sanitized: plain, one line, 200 characters', 200 === $response->get_status() && str_starts_with( $mission, 'We bake sourdough for schools.' ) && ! str_contains( $mission, '<b>' ) && 200 === mb_strlen( $mission ) && 'technical' === get_option( BrandSettings::TONE ), mb_strlen( $mission ) . ' characters' );
$response = $rest( 'POST', array( BrandSettings::TONE => 'pirate' ) );
$check( 'an unknown tone is refused', 400 === $response->get_status() && 'technical' === get_option( BrandSettings::TONE ), (string) $response->get_status() );
wp_set_current_user( $editor );
$response = $rest( 'POST', array( BrandSettings::MISSION => 'Hijacked' ) );
$check( 'without manage_options: refused', in_array( $response->get_status(), array( 401, 403 ), true ) && 'Hijacked' !== get_option( BrandSettings::MISSION ), (string) $response->get_status() );
wp_set_current_user( $member );

// 2. Where they are edited ----------------------------------------------------------

echo "\nWhere they are edited\n";
global $wp_settings_fields;
$wp_settings_fields = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test; rebuilt below.
BrandSettings::general_fields();
$check( 'Settings → General: Mission and Tone of voice', isset( $wp_settings_fields['general']['default'][ BrandSettings::MISSION ], $wp_settings_fields['general']['default'][ BrandSettings::TONE ] ) );
ob_start();
call_user_func( $wp_settings_fields['general']['default'][ BrandSettings::TONE ]['callback'] );
$select = (string) ob_get_clean();
$check( '...the tone as a choice of three, the current one selected', 3 === substr_count( $select, '<option' ) && (bool) preg_match( "/value=\"technical\" selected='selected'/", $select ) );
global $new_allowed_options; // What register_setting() adds for options.php.
$check( '...allowed by options.php for the General page', in_array( BrandSettings::MISSION, (array) ( $new_allowed_options['general'] ?? array() ), true ) && in_array( BrandSettings::TONE, (array) ( $new_allowed_options['general'] ?? array() ), true ) );
wp_dequeue_script( 'mody-identity-fields' );
wp_deregister_script( 'mody-identity-fields' );
BrandSettings::enqueue_identity_fields( 'index.php' );
$elsewhere = wp_script_is( 'mody-identity-fields', 'enqueued' );
BrandSettings::enqueue_identity_fields( 'site-editor.php' );
$inline = (string) implode( '', (array) wp_scripts()->get_data( 'mody-identity-fields', 'before' ) );
$check( 'the Identity screen\'s script: on the Site Editor only, with its settings', ! $elsewhere && wp_script_is( 'mody-identity-fields', 'enqueued' ) && str_contains( $inline, '"mission":"mody_mission"' ) && str_contains( $inline, '"tones"' ) );
$script = (string) file_get_contents( WPMU_PLUGIN_DIR . '/mody-platform/assets/identity-fields.js' );
$check( '...it edits the root/site entity, so the Site Editor\'s Save saves it', str_contains( $script, "editEntityRecord( 'root', 'site'" ) && str_contains( $script, "'/identity'" ) );

// 3. The brand for prompts -----------------------------------------------------------

echo "\nThe brand for prompts\n";
update_option( 'blogname', 'Alpha & Sons' );
$brand = BrandSettings::brand();
$check( 'read now from WordPress, decoded', 'Alpha & Sons' === $brand['name'] && 'technical' === $brand['tone'] && str_starts_with( $brand['mission'], 'We bake sourdough' ), wp_json_encode( $brand ) );
$check( '...and in the prompt, as data', str_contains( SystemPrompt::brand_context(), "<brand>\nName: Alpha & Sons\n" ) && str_contains( SystemPrompt::brand_context(), 'Tone of voice: Technical' ) );
restore_current_blog();
$check( 'each site its own brand', BrandSettings::brand()['name'] !== 'Alpha & Sons', BrandSettings::brand()['name'] );

// 4. The profile table and the migration --------------------------------------------

echo "\nThe migration\n";
$legacy = $wpdb->base_prefix . BrandSettings::LEGACY_TABLE;
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test; a throwaway table.
$check( 'the profile table is gone', $legacy !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) && '1' === (string) get_site_option( 'mody_brand_settings_version' ) );
$copy = $wpdb->base_prefix . 'mody_brand_profiles_test_' . $suffix;
$wpdb->query( "CREATE TABLE {$copy} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, user_id bigint(20) unsigned NOT NULL, site_id bigint(20) unsigned NOT NULL, brand_name varchar(120) NOT NULL, mission text, tone_of_voice varchar(20), PRIMARY KEY (id) )" );
$wpdb->insert( $copy, array( 'user_id' => 1, 'site_id' => $site, 'brand_name' => 'Old Name', 'mission' => 'An older mission.', 'tone_of_voice' => 'friendly' ) );
$wpdb->insert( $copy, array( 'user_id' => 1, 'site_id' => $site, 'brand_name' => 'Newer Name', 'mission' => 'The newest mission.', 'tone_of_voice' => 'formal' ) );
$wpdb->insert( $copy, array( 'user_id' => 1, 'site_id' => 999999, 'brand_name' => 'Gone', 'mission' => 'A deleted site.', 'tone_of_voice' => 'formal' ) );
switch_to_blog( $site );
update_option( BrandSettings::MISSION, '' );
update_option( 'blogname', '' );
restore_current_blog();
$moved = BrandSettings::migrate_from( $copy );
switch_to_blog( $site );
$after = BrandSettings::brand();
restore_current_blog();
$check( 'the newest row moves: mission, tone, and the empty title', 1 === $moved && 'The newest mission.' === $after['mission'] && 'formal' === $after['tone'] && 'Newer Name' === $after['name'], wp_json_encode( $after ) );
$check( '...a deleted site is skipped, and the table dropped', $copy !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $copy ) ) );
switch_to_blog( $site );
update_option( BrandSettings::MISSION, 'Kept.' );
update_option( 'blogname', 'Kept Title' );
restore_current_blog();
$wpdb->query( "CREATE TABLE {$copy} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, user_id bigint(20) unsigned NOT NULL, site_id bigint(20) unsigned NOT NULL, brand_name varchar(120) NOT NULL, mission text, tone_of_voice varchar(20), PRIMARY KEY (id) )" );
$wpdb->insert( $copy, array( 'user_id' => 1, 'site_id' => $site, 'brand_name' => 'Profile Name', 'mission' => 'Profile mission.', 'tone_of_voice' => 'friendly' ) );
BrandSettings::migrate_from( $copy );
switch_to_blog( $site );
$kept = BrandSettings::brand();
restore_current_blog();
$check( 'settings already there are kept (title, mission)', 'Kept.' === $kept['mission'] && 'Kept Title' === $kept['name'], wp_json_encode( $kept ) );
$check( 'no table: nothing to do', 0 === BrandSettings::migrate_from( $copy ) );
// phpcs:enable

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
