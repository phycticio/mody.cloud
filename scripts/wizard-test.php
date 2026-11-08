<?php
/**
 * Tests the brand wizard (ticket M2-03, mvp-spec section 6).
 *
 * Run from the repository root, with INFOMANIAK_* set in .env:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/wizard-test.php
 *
 * 1. The page: its three steps, fields, tone and preset cards, quota and the
 *    script's settings; who may open it (offline).
 * 2. The REST endpoint mody-platform/v1/sites, with fake model answers:
 *    permissions, field errors, a full creation (here it runs at once:
 *    WP-CLI has no PHP-FPM), another user's job, a creation in progress, the
 *    site limit, Retry generation, Try again and a stopped job (offline).
 * 3. Over HTTP, with a login cookie: the page in Network Admin, then a real
 *    creation: POST answers 202 at once, and the job is followed until the
 *    site is ready. Add `-e MODY_TEST_OFFLINE=1` after `docker exec` to skip
 *    the creation (the page check still runs).
 *
 * Sites, jobs and the user created by the test are removed
 * at the end. Emails are captured, not sent. Never prints the token.
 */

use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Brand\BrandSettings;
use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;
use Mody\Platform\Provisioning\Jobs;
use Mody\Platform\Provisioning\Pipeline;
use Mody\Platform\Wizard\WizardController;
use Mody\Platform\Wizard\WizardPage;
use Mody\Platform\Wizard\WizardPreview;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-46s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
$admins = get_super_admins();
$admin  = get_user_by( 'login', $admins[0] )->ID;
wp_set_current_user( $admin );
Jobs::install();
$suffix = strtolower( wp_generate_password( 6, false ) );
$slug   = static fn( string $name ): string => 'modywiz' . $name . $suffix;
$mark   = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Jobs::table() ); // phpcs:ignore WordPress.DB

// Everything the test creates is removed at the end: the jobs after $mark and their sites.
$extra_sites = array();
$member      = wp_insert_user(
	array(
		'user_login' => 'modywiz' . $suffix,
		'user_email' => 'modywiz' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
$cleanup = static function () use ( $mark, &$extra_sites ): void {
	global $wpdb;
	$sites = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM ' . Jobs::table() . ' WHERE id > %d AND site_id IS NOT NULL', $mark ) ) ); // phpcs:ignore WordPress.DB
	foreach ( array_unique( array_merge( $sites, $extra_sites ) ) as $site_id ) {
		if ( get_site( $site_id ) ) {
			wp_delete_site( $site_id );
		}
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Jobs::table() . ' WHERE id > %d', $mark ) ); // phpcs:ignore WordPress.DB
};
register_shutdown_function(
	static function () use ( $cleanup, $member ): void {
		$cleanup();
		wpmu_delete_user( $member );
	}
);

// Model requests answered with fakes (null: real requests); emails captured.
$fakes = null;
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$fakes ) {
		if ( null === $fakes || ! str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/chat/completions' ) ) {
			return $preempt;
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id'      => 'fake',
					'object'  => 'chat.completion',
					'created' => time(),
					'model'   => 'fake',
					'choices' => array(
						array(
							'index'         => 0,
							'message'       => array(
								'role'    => 'assistant',
								'content' => (string) array_shift( $fakes ),
							),
							'finish_reason' => 'stop',
						),
					),
					'usage'   => array(
						'prompt_tokens'     => 1,
						'completion_tokens' => 1,
						'total_tokens'      => 2,
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
add_filter( 'pre_wp_mail', '__return_true' );
// The super admin may already administer sites: room for the test's.
$roomy = static fn( $limit, $user_id ) => $admin === $user_id ? 50 : $limit;
add_filter( 'mody_site_limit', $roomy, 10, 2 );

$answers = static fn( string $name, array $more = array() ): array => array_merge(
	array(
		'slug'    => $slug( $name ),
		'title'   => 'Ogia Bakery',
		'mission' => 'We bake sourdough bread for offices in Zurich.',
		'tone'    => 'friendly',
		'preset'  => 'plum',
		'prompt'  => 'A home page for our bakery.',
	),
	$more
);
$rest = static function ( string $method, string $path, array $body = array(), array $files = array() ) {
	$request = new WP_REST_Request( $method, '/mody-platform/v1/sites' . $path );
	foreach ( $body as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$request->set_file_params( $files );
	$response = rest_do_request( $request );
	return array(
		'code' => $response->get_status(),
		'data' => (array) $response->get_data(),
	);
};

// 1. The page -------------------------------------------------------------------------

echo "Page\n";
$check( 'super admins may use it', user_can( $admin, WizardPage::CAPABILITY ) );
$check( 'other users may not (until M1)', ! user_can( $member, WizardPage::CAPABILITY ) );
$check( 'tones are the brand settings\'', array_keys( WizardPage::tones() ) === BrandSettings::TONES );
ob_start();
WizardPage::render();
$html = (string) ob_get_clean();
preg_match_all( '/name="preset" value="([a-z-]+)"/', $html, $presets );
preg_match_all( '/name="tone" value="([a-z]+)"/', $html, $tones );
$fields = array_filter( array( 'title', 'slug', 'mission', 'prompt' ), static fn( $f ) => str_contains( $html, 'name="' . $f . '"' ) );
$check( 'three steps', 3 === substr_count( $html, 'class="mody-wizard__step"' ) && 2 === substr_count( $html, 'data-step="2" hidden' ) + substr_count( $html, 'data-step="3" hidden' ) );
$check( 'every field, and no brand name (the site name is the brand, M2-08)', 4 === count( $fields ) && ! str_contains( $html, 'name="brand_name"' ), implode( ', ', $fields ) );
$check( 'six preset cards, the default checked', Presets::slugs() === $presets[1] && preg_match( '/value="' . Presets::DEFAULT . '" +checked/', $html ), implode( ', ', $presets[1] ) );
$check( 'three tone cards, friendly checked', BrandSettings::TONES === $tones[1] && preg_match( '/value="friendly" +checked/', $html ) );
$check( 'the address ends with the network domain', str_contains( $html, '<span>.' . get_network()->domain . '</span>' ) );
$check( 'the quota', (bool) preg_match( '/\d+ of 50 sites used/', $html ) );
WizardPage::enqueue();
$settings = (string) implode( '', (array) wp_scripts()->get_data( 'mody-wizard', 'before' ) );
$check( 'script settings', str_contains( $settings, 'window.modyWizard' ) && str_contains( $settings, wp_json_encode( rest_url( 'mody-platform/v1/sites' ) ) ) && str_contains( $settings, '"pending":null' ) );
$check( '...the preview\'s address', str_contains( $settings, wp_json_encode( WizardPreview::url() ) ) );
$check( 'step 2 has the preview', str_contains( $html, 'id="mody-wizard-preview"' ) );

// Step 2's live preview (M2-04) ---------------------------------------------------------

echo "\nLive preview\n";
$preview = WizardPreview::html( 'Ogia <script>x</script> Bakery', 'We bake & deliver.', 'plum' );
// The preset is the theme's style (M2-06): its colors come from WordPress's own global styles.
$color_of = static fn( string $html, string $slug ): string => preg_match( '/--wp--preset--color--' . $slug . ':\s*(#[0-9a-f]{6})/i', $html, $m ) ? strtolower( $m[1] ) : '';
preg_match_all( '/wp-block-navigation-item__label">([^<]+)/', $preview, $nav );
preg_match_all( '#\s(?:src|href)=["\'](https?:)?//([^/"\']+)#', $preview, $hosts );
$foreign = array_unique( array_filter( $hosts[2], static fn( $host ) => $host !== get_network()->domain && ! str_ends_with( $host, '.' . get_network()->domain ) ) );
$check( 'the theme\'s header with the brand name', str_contains( $preview, 'wp-block-site-title' ) && str_contains( $preview, 'Ogia &lt;script&gt;x&lt;/script&gt; Bakery' ) && ! str_contains( $preview, '<script>x</script>' ) );
$check( '...a hero with the sentence', str_contains( $preview, '<h1 class="wp-block-heading">Ogia' ) && str_contains( $preview, 'We bake &amp; deliver.' ) );
$check( '...the preset\'s style (Plum)', Presets::colors( 'plum' )['primary'] === $color_of( $preview, 'primary' ) && Presets::colors( 'plum' )['accent'] === $color_of( $preview, 'accent' ), $color_of( $preview, 'primary' ) . ' ' . $color_of( $preview, 'accent' ) );
$check( '...three fixed links, not the main site\'s pages', array( 'Home', 'About', 'Contact' ) === $nav[1], implode( ', ', $nav[1] ) );
$check( '...nothing from outside the network', ! $foreign, implode( ', ', $foreign ) );
$check( '...no admin bar, the main site\'s title untouched', ! str_contains( $preview, 'id="wpadminbar"' ) && 'Ogia' !== substr( get_option( 'blogname' ), 0, 4 ) );
// WordPress prints its global styles once per request; a real preview is one request.
$GLOBALS['wp_styles'] = null;
$site_colors          = SiteDesign::colors();
$check( 'unknown preset: the default style', Presets::colors( Presets::DEFAULT )['primary'] === $color_of( WizardPreview::html( '', '', 'no-such-preset' ), 'primary' ) );
$check( '...and the main site\'s own styles are back after a preview', $site_colors === SiteDesign::colors(), wp_json_encode( SiteDesign::colors() ) );

// 2. The REST endpoint -----------------------------------------------------------------

echo "\nREST endpoint\n";
wp_set_current_user( 0 );
$out = $rest( 'POST', '', $answers( 'anon' ) );
$check( 'logged out: 401', 401 === $out['code'], (string) $out['code'] );
wp_set_current_user( $member );
$out = $rest( 'POST', '', $answers( 'member' ) );
$check( 'without the capability: 403', 403 === $out['code'], (string) $out['code'] );
wp_set_current_user( $admin );

$out    = $rest( 'POST', '', $answers( 'bad', array( 'slug' => 'Ab', 'mission' => '' ) ) );
$fields = array_keys( (array) ( $out['data']['data']['fields'] ?? array() ) );
sort( $fields );
$check( 'invalid answers: 400 with the fields', 400 === $out['code'] && array( 'mission', 'slug' ) === $fields, $out['code'] . ' ' . implode( ', ', $fields ) );

$fakes = array( SystemPrompt::EXAMPLE );
$out   = $rest( 'POST', '', $answers( 'full' ) );
$fakes = null;
$view  = $out['data'];
$check( 'a creation runs (at once here)', 200 === $out['code'] && 'done' === ( $view['status'] ?? '' ) && 'done' === ( $view['step'] ?? '' ), $out['code'] . ' ' . ( $view['status'] ?? '' ) . ' ' . ( $view['error'] ?? '' ) );
$check(
	'...the site\'s links',
	'https://' . Pipeline::domain( $slug( 'full' ) ) . '/' === ( $view['site']['url'] ?? '' ) && str_contains( (string) ( $view['site']['editor'] ?? '' ), '/wp-admin/site-editor.php?p=%2Fpage%2F' ) && str_contains( (string) ( $view['site']['editor'] ?? '' ), 'mody-chat=open' )
		&& str_ends_with( (string) ( $view['site']['admin'] ?? '' ), '/wp/wp-admin/' ) && 'Ogia Bakery' === ( $view['site']['title'] ?? '' ),
	(string) ( $view['site']['url'] ?? 'no site' )
);
$check( '...no fallback', false === ( $view['fallback'] ?? null ) && false === ( $view['retrying'] ?? null ) );
$made = (int) ( Jobs::get( (int) $view['job'] )['site_id'] ?? 0 );
switch_to_blog( $made );
wp_clean_theme_json_cache();
$made_colors = SiteDesign::colors();
$made_brand  = BrandSettings::brand();
restore_current_blog();
$check( '...the Plum preset, in its Global Styles', Presets::colors( 'plum' ) == $made_colors, wp_json_encode( $made_colors ) ); // phpcs:ignore Universal.Operators.StrictComparisons
$check( '...its brand in its own settings', '' !== $made_brand['name'] && '' !== $made_brand['mission'] && $made_brand['mission'] === $made_brand['tagline'], wp_json_encode( $made_brand ) );
$job_id = (int) ( $view['job'] ?? 0 );
$out    = $rest( 'GET', '/jobs/' . $job_id );
$check( 'GET the job', 200 === $out['code'] && $out['data'] == $view, (string) $out['code'] ); // phpcs:ignore Universal.Operators.StrictComparisons
$theirs = Pipeline::start( $member, $answers( 'theirs' ) );
$out    = $rest( 'GET', '/jobs/' . $theirs );
$check( '...another user\'s job: 404', 404 === $out['code'], (string) $out['code'] );
$out = $rest( 'GET', '/jobs/999999999' );
$check( '...unknown job: 404', 404 === $out['code'], (string) $out['code'] );
$out = $rest( 'POST', '/jobs/' . $job_id . '/run' );
$check( 'Try again on a done job: 409', 409 === $out['code'], (string) $out['code'] );

// A creation in progress blocks another; the limit is enforced.
$queued = Pipeline::start( $admin, $answers( 'queued' ) );
$out    = $rest( 'POST', '', $answers( 'second' ) );
$check( 'while one is in progress: 409 with its job', 409 === $out['code'] && $queued === (int) ( $out['data']['data']['job'] ?? 0 ), (string) $out['code'] );
$wpdb->delete( Jobs::table(), array( 'id' => $queued ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
remove_filter( 'mody_site_limit', $roomy, 10 );
add_filter( 'mody_site_limit', '__return_zero' );
$out = $rest( 'POST', '', $answers( 'limit' ) );
remove_filter( 'mody_site_limit', '__return_zero' );
add_filter( 'mody_site_limit', $roomy, 10, 2 );
$check( 'at the site limit: 403', 403 === $out['code'] && 'mody_site_limit_reached' === ( $out['data']['code'] ?? '' ), $out['code'] . ' ' . ( $out['data']['message'] ?? '' ) );

// The fallback page, and Retry generation.
$fakes = array( 'not blocks', 'not blocks', 'not blocks' );
$out   = $rest( 'POST', '', $answers( 'fallback' ) );
$fakes = null;
$fall  = (int) ( $out['data']['job'] ?? 0 );
$check( 'generation fails: done, with the fallback page', 'done' === ( $out['data']['status'] ?? '' ) && true === ( $out['data']['fallback'] ?? null ), (string) ( $out['data']['status'] ?? '' ) );
$fakes = array( SystemPrompt::EXAMPLE );
$out   = $rest( 'POST', '/jobs/' . $fall . '/retry-generation' );
$fakes = null;
$check( 'Retry generation replaces it', 200 === $out['code'] && false === ( $out['data']['fallback'] ?? null ) && false === ( $out['data']['retrying'] ?? null ), $out['code'] . ' ' . wp_json_encode( $out['data']['fallback'] ?? null ) );
$out = $rest( 'POST', '/jobs/' . $fall . '/retry-generation' );
$check( '...then there is nothing to retry: 409', 409 === $out['code'], (string) $out['code'] );

// A failed job: its message without the step and code; Try again finishes it.
$failed = Pipeline::start( $admin, $answers( 'failed' ) );
Jobs::update(
	$failed,
	array(
		'status'     => 'failed',
		'last_error' => 'quota: mody_step_exception: Database went away: try later.',
	)
);
$out = $rest( 'GET', '/jobs/' . $failed );
$check( 'a failed job shows its message', 'failed' === ( $out['data']['status'] ?? '' ) && 'Database went away: try later.' === ( $out['data']['error'] ?? '' ), (string) ( $out['data']['error'] ?? '' ) );
$fakes = array( SystemPrompt::EXAMPLE );
$out   = $rest( 'POST', '/jobs/' . $failed . '/run' );
$fakes = null;
$check( '...Try again finishes it', 'done' === ( $out['data']['status'] ?? '' ) && null !== ( $out['data']['site'] ?? null ), (string) ( $out['data']['status'] ?? '' ) );

// A stopped job (its run was killed): shown as failed, and Try again resumes it.
$stopped = Pipeline::start( $admin, $answers( 'stopped' ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Jobs::table() . " SET status = 'running', updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id = %d", $stopped ) ); // phpcs:ignore WordPress.DB
$out = $rest( 'GET', '/jobs/' . $stopped );
$check( 'a stopped job is shown as failed', 'failed' === ( $out['data']['status'] ?? '' ) && str_contains( (string) ( $out['data']['error'] ?? '' ), 'stopped' ), (string) ( $out['data']['error'] ?? '' ) );
WizardPage::enqueue();
$settings = (string) implode( '', (array) wp_scripts()->get_data( 'mody-wizard', 'before' ) );
$check( '...and picked up by the page', str_contains( $settings, '"job":' . $stopped . ',' ) );
$fakes = array( SystemPrompt::EXAMPLE );
$out   = $rest( 'POST', '/jobs/' . $stopped . '/run' );
$fakes = null;
$check( '...Try again resumes it', 'done' === ( $out['data']['status'] ?? '' ), (string) ( $out['data']['status'] ?? '' ) . ' ' . ( $out['data']['error'] ?? '' ) );

// The logo (M2-05) ---------------------------------------------------------------------

echo "\nLogo\n";
// A file for the upload: a real PNG, or other bytes under a .png name.
$png = static function ( ?string $bytes = null ): array {
	$tmp = wp_tempnam( 'logo.png' );
	if ( null === $bytes ) {
		$img = imagecreatetruecolor( 64, 64 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 47, 93, 58 ) );
		imagepng( $img, $tmp );
	} else {
		file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	return array(
		'logo' => array(
			'name'     => 'logo.png',
			'type'     => 'image/png',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp ),
		),
	);
};
$main_attachments = static function (): int {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->get_blog_prefix( get_main_site_id() ) . "posts WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB
};

$before = $main_attachments();
$fakes  = array( SystemPrompt::EXAMPLE );
$out    = $rest( 'POST', '', $answers( 'logo' ), $png() );
$fakes  = null;
$site   = (int) ( Jobs::get( (int) ( $out['data']['job'] ?? 0 ) )['site_id'] ?? 0 );
switch_to_blog( $site );
$logo = (int) get_option( 'site_logo' );
$mime = (string) get_post_mime_type( $logo );
restore_current_blog();
$check( 'a PNG: the site gets it as its logo', 'done' === ( $out['data']['status'] ?? '' ) && $logo > 0 && 'image/png' === $mime, $out['code'] . ' ' . $mime );
$check( '...and the upload on the main site is removed', $before === $main_attachments(), ( $main_attachments() - $before ) . ' left' );

$out = $rest( 'POST', '', $answers( 'logotext' ), $png( 'This is not an image.' ) );
$check( 'text named .png: 400 on the logo field', 400 === $out['code'] && isset( $out['data']['data']['fields']['logo'] ) && $before === $main_attachments(), $out['code'] . ' ' . ( $out['data']['message'] ?? '' ) );
$out = $rest( 'POST', '', $answers( 'logobig' ), $png( str_repeat( 'x', WizardController::MAX_LOGO_BYTES + 1 ) ) );
$check( 'over the size limit: 400 on the logo field', 400 === $out['code'] && isset( $out['data']['data']['fields']['logo'] ) && $before === $main_attachments(), (string) $out['code'] );
$out = $rest( 'POST', '', $answers( 'logobad', array( 'mission' => '' ) ), $png() );
$check( 'another field wrong: nothing stored', 400 === $out['code'] && isset( $out['data']['data']['fields']['mission'] ) && $before === $main_attachments(), (string) $out['code'] );
remove_filter( 'mody_site_limit', $roomy, 10 );
add_filter( 'mody_site_limit', '__return_zero' );
$out = $rest( 'POST', '', $answers( 'logolimit' ), $png() );
remove_filter( 'mody_site_limit', '__return_zero' );
add_filter( 'mody_site_limit', $roomy, 10, 2 );
$check( 'at the site limit: the stored logo is deleted', 403 === $out['code'] && $before === $main_attachments(), $out['code'] . ', ' . ( $main_attachments() - $before ) . ' left' );

// 3. Over HTTP --------------------------------------------------------------------------

echo "\nOver HTTP\n";
$expiration = time() + 600;
$token      = WP_Session_Tokens::get_instance( $admin )->create( $expiration );
$cookies    = array(
	SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $admin, $expiration, 'secure_auth', $token ),
	LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $admin, $expiration, 'logged_in', $token ),
);
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookies[ LOGGED_IN_COOKIE ]; // The REST nonce is tied to this session.
register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $admin )->destroy( $token ) );
$http = static function ( string $method, string $url, ?array $body = null ) use ( $cookies ): array {
	$response = wp_remote_request(
		$url,
		array(
			'method'    => $method,
			'cookies'   => $cookies,
			'sslverify' => false, // This site's own certificate.
			'timeout'   => 30,
			'headers'   => array(
				'X-WP-Nonce'   => wp_create_nonce( 'wp_rest' ),
				'Content-Type' => 'application/json',
			),
			'body'      => $body ? wp_json_encode( $body ) : null,
		)
	);
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => (string) wp_remote_retrieve_body( $response ),
	);
};

$page = $http( 'GET', network_admin_url( 'admin.php?page=' . WizardPage::SLUG ) );
$check( 'the page in Network Admin', 200 === $page['code'] && str_contains( $page['body'], 'id="mody-wizard-form"' ) && str_contains( $page['body'], 'window.modyWizard' ) && str_contains( $page['body'], 'assets/wizard.js' ), (string) $page['code'] );
$preview_url = add_query_arg(
	array(
		'brand'   => rawurlencode( 'Ogia Bakery' ),
		'mission' => rawurlencode( 'We bake bread.' ),
		'preset'  => 'forest',
	),
	WizardPreview::url()
);
$shown = $http( 'GET', $preview_url );
$check( 'the preview, same origin as the wizard', 200 === $shown['code'] && '#2f5d3a' === $color_of( $shown['body'], 'primary' ) && str_contains( $shown['body'], 'Ogia Bakery' ) && wp_parse_url( $preview_url, PHP_URL_HOST ) === wp_parse_url( network_admin_url(), PHP_URL_HOST ), (string) $shown['code'] );
$anon = wp_remote_get( $preview_url, array( 'sslverify' => false ) );
$check( '...refused when logged out', 403 === (int) wp_remote_retrieve_response_code( $anon ), (string) wp_remote_retrieve_response_code( $anon ) );

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo "--   real creation skipped: MODY_TEST_OFFLINE is set\n";
} else {
	// The request runs in PHP-FPM, with the real quota: the sites made above go first.
	$cleanup();
	// As the browser sends it: multipart, with a logo.
	$boundary = 'mody' . wp_generate_password( 20, false );
	$body     = '';
	foreach ( $answers( 'http', array( 'preset' => 'sand' ) ) as $name => $value ) {
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
	}
	$body    .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"logo\"; filename=\"logo.png\"\r\nContent-Type: image/png\r\n\r\n"
		. file_get_contents( $png()['logo']['tmp_name'] ) . "\r\n--{$boundary}--\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$start    = microtime( true );
	$response = wp_remote_post(
		rest_url( 'mody-platform/v1/sites' ),
		array(
			'cookies'   => $cookies,
			'sslverify' => false,
			'timeout'   => 30,
			'headers'   => array(
				'X-WP-Nonce'   => wp_create_nonce( 'wp_rest' ),
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'      => $body,
		)
	);
	$posted = array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => (string) wp_remote_retrieve_body( $response ),
	);
	$secs   = microtime( true ) - $start;
	$view   = (array) json_decode( $posted['body'], true );
	$check( 'POST answers 202 at once', 202 === $posted['code'] && in_array( $view['status'] ?? '', array( 'queued', 'running' ), true ) && $secs < 5, sprintf( '%d %s in %.1fs', $posted['code'], $view['status'] ?? $posted['body'], $secs ) );
	$polls = 0;
	while ( in_array( $view['status'] ?? '', array( 'queued', 'running' ), true ) && microtime( true ) - $start < 150 ) {
		sleep( 2 );
		++$polls;
		$view = (array) json_decode( $http( 'GET', rest_url( 'mody-platform/v1/sites/jobs/' . (int) ( $view['job'] ?? 0 ) ) )['body'], true );
	}
	$check( '...followed until the site is ready', 'done' === ( $view['status'] ?? '' ) && ! empty( $view['site']['url'] ), sprintf( '%s after %d polls, %.1fs, %s', $view['status'] ?? '?', $polls, microtime( true ) - $start, ( $view['fallback'] ?? false ) ? 'fallback page' : 'generated page' ) );
	$site = (int) ( Jobs::get( (int) ( $view['job'] ?? 0 ) )['site_id'] ?? 0 );
	$check( '...with the logo sent along, as its site logo', $site && (int) get_blog_option( $site, 'site_logo' ) > 0, (string) get_blog_option( $site, 'site_logo' ) );
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
