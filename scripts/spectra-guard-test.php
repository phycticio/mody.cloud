<?php
/**
 * Checks that Spectra makes no connection to a non-European service (rule 1).
 * Run it after every Spectra update (documentation/mvp-spec.md section 10):
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/spectra-guard-test.php
 *
 * Offline: it sends no request (the denied-host check is answered by the guard).
 */

use Mody\Platform\Spectra\SpectraGuard;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-40s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$admins = get_super_admins();
wp_set_current_user( get_user_by( 'login', $admins[0] )->ID );

$active = defined( 'SPECTRA_BLOCKS_VER' );
$check( 'Spectra is active', $active, $active ? SPECTRA_BLOCKS_VER . ( '1.0.13' === SPECTRA_BLOCKS_VER ? '' : ' (guard checked against 1.0.13: review it)' ) : 'activate spectra-blocks first' );
if ( ! $active ) {
	exit( 1 );
}

// Blocks.
$registry = WP_Block_Type_Registry::get_instance();
$spectra  = array_filter( array_keys( $registry->get_all_registered() ), static fn( $name ) => str_starts_with( $name, 'spectra/' ) );
foreach ( SpectraGuard::DENIED_BLOCKS as $name ) {
	$check( "block $name unregistered", ! $registry->is_registered( $name ) );
}
$check( 'other Spectra blocks still registered', count( $spectra ) > 30, count( $spectra ) . ' spectra/* blocks' );
$map = '<!-- wp:spectra/google-map {"address":"Zurich"} --><div class="wp-block-spectra-google-map"><iframe src="https://maps.google.com/maps?q=Zurich&output=embed"></iframe></div><!-- /wp:spectra/google-map -->';
$check( 'saved google-map renders nothing', '' === do_blocks( $map ), 'markup with a Google Maps iframe' );

// Libraries that never load.
$check( 'design library not loaded', ! defined( 'AST_BLOCK_TEMPLATES_VER' ), 'websitedemos.net, ZipWP AI' );
$check( 'ZipWP images not loaded', ! defined( 'ZIPWP_IMAGES_FILE' ) );
$check( 'NPS survey not loaded', ! defined( 'NPS_SURVEY_FILE' ) );
$check( 'BSF analytics off', ! apply_filters( 'bsf_usage_tracking_enabled', true ) );
$check( 'deactivation survey off', array() === apply_filters( 'uds_survey_allowed_screens', array( 'plugins' ) ) );

// Pinned settings.
$settings = array(
	'enable_templates_button'   => 'no',
	'load_select_font_globally' => 'disabled',
	'load_gfonts_locally'       => 'disabled',
	'usage_optin'               => 'no',
);
foreach ( $settings as $key => $expected ) {
	$value = Spectra_Blocks_Settings::get( $key );
	$check( "setting $key", $expected === $value, wp_json_encode( $value ) );
}
$fonts = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
$urls  = array();
array_walk_recursive(
	$fonts,
	static function ( $value ) use ( &$urls ): void {
		if ( is_string( $value ) && preg_match( '#^(https?:)?//#', $value ) ) {
			$urls[] = $value;
		}
	}
);
$remote = array_filter( $urls, array( SpectraGuard::class, 'is_denied_url' ) );
$check( 'no font served from Google', array() === $remote, count( $urls ) . ' font file URL(s), ' . count( $remote ) . ' on denied hosts' );

// Abilities: Spectra's own abilities and MCP server are pinned off (mvp-spec section 10).
foreach ( SpectraGuard::DENIED_ABILITIES as $name ) {
	$check( "ability $name unregistered", ! wp_has_ability( $name ) );
}
foreach ( array( 'spectra_blocks_enable_abilities', 'spectra_blocks_enable_mcp_server' ) as $option ) {
	$check( "option $option", 'disabled' === get_option( $option ) && 'disabled' === SpectraGuard::PINNED_OPTIONS[ $option ], wp_json_encode( get_option( $option ) ) );
}
$spectra_abilities = array_filter(
	array_map( static fn( $ability ) => $ability->get_name(), wp_get_abilities() ),
	static fn( $name ) => str_starts_with( $name, 'spectra' )
);
$check( 'no Spectra abilities registered', array() === $spectra_abilities, count( $spectra_abilities ) . ' registered' );

// Editor assets.
set_current_screen( 'post' );
do_action( 'enqueue_block_editor_assets' );
$check( 'editor guard script enqueued', wp_script_is( 'mody-spectra-guard', 'enqueued' ) );
foreach ( SpectraGuard::DENIED_EDITOR_EXTENSIONS as $nudge ) {
	$styles = array_filter( wp_styles()->queue, static fn( $handle ) => str_contains( $handle, "-extension-{$nudge}-" ) );
	$check( "editor nudge $nudge removed", ! wp_script_is( "spectra-blocks-extension-{$nudge}-editor", 'enqueued' ) && ! $styles );
}

// Safety net for server-side requests.
foreach ( array( 'https://api.zipwp.com/api/', 'https://metrics.brainstormforce.com/', 'https://fonts.gstatic.com/s/inter.woff2' ) as $url ) {
	$response = wp_remote_get( $url );
	$check( 'refused: ' . wp_parse_url( $url, PHP_URL_HOST ), is_wp_error( $response ) && 'mody_denied_host' === $response->get_error_code() );
}
$check( 'Infomaniak and WordPress.org not denied', ! SpectraGuard::is_denied_url( 'https://api.infomaniak.com/1/ai/models' ) && ! SpectraGuard::is_denied_url( 'https://api.wordpress.org/plugins/' ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
