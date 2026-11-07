<?php
/**
 * Tests the site provisioning pipeline (ticket M2-01, mvp-spec section 7).
 *
 * Run from the repository root, with INFOMANIAK_* set in .env:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/provisioning-test.php
 *
 * 1. The wizard's answers: validation and defaults (offline).
 * 2. The quota: 3 sites per user until M1, jobs on the way counted (offline).
 * 3. A full run with a fake model answer: the site, its administrator, theme,
 *    tagline, profile, Global Styles, front page, template and the welcome
 *    email. Running it again changes nothing (offline).
 * 4. A run killed at each step, right after the step's side effects, then
 *    resumed: one site, one profile, one front page, one email, one model
 *    request (offline).
 * 5. Generation failure: invalid answers and a model error publish the
 *    fallback page; Retry generation replaces it (offline).
 * 6. One real run, with a real page generation. Add `-e MODY_TEST_OFFLINE=1`
 *    after `docker exec` to skip it, or `-e MODY_TEST_KEEP_SITE=1` to keep
 *    the site it creates (it belongs to the first super admin).
 *
 * Sites, users, jobs and brand profiles created by the test are removed at the
 * end. Emails are captured, not sent. Never prints the token.
 */

use Mody\Platform\AI\BlockValidator;
use Mody\Platform\AI\PageGenerator;
use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Brand\BrandProfile;
use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;
use Mody\Platform\Provisioning\Jobs;
use Mody\Platform\Provisioning\Pipeline;
use Mody\Platform\Provisioning\Quota;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-48s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$message  = static fn( $result ): string => is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '';

global $wpdb;
$admins = get_super_admins();
$admin  = get_user_by( 'login', $admins[0] );
wp_set_current_user( $admin->ID );
BrandProfile::install();
Jobs::install();
$suffix = strtolower( wp_generate_password( 6, false ) );
$slugs  = static fn( string $name ): string => 'modytest' . $name . $suffix;

// Everything created here is removed at the end.
$created = array(
	'sites' => array(),
	'users' => array(),
	'jobs'  => array(),
);
register_shutdown_function(
	static function () use ( &$created ): void {
		global $wpdb;
		if ( getenv( 'MODY_TEST_KEEP_SITE' ) && isset( $created['keep'] ) ) {
			$created['sites'] = array_diff( $created['sites'], array( $created['keep'] ) );
		}
		foreach ( array_unique( $created['sites'] ) as $site_id ) {
			$wpdb->delete( BrandProfile::table(), array( 'site_id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( get_site( $site_id ) ) {
				wp_delete_site( $site_id );
			}
		}
		foreach ( $created['jobs'] as $job_id ) {
			$wpdb->delete( Jobs::table(), array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		foreach ( $created['users'] as $user_id ) {
			wpmu_delete_user( $user_id );
		}
	}
);

// A test member, not a super admin.
$member = wp_insert_user(
	array(
		'user_login' => 'modytest' . $suffix,
		'user_email' => 'modytest' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
$created['users'][] = $member;

// Model requests, recorded and answered with fakes (null: real requests). A string
// is a text answer, an int an HTTP error status.
$requests = array();
$fakes    = null;
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$requests, &$fakes ) {
		if ( ! str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/chat/completions' ) ) {
			return $preempt;
		}
		$requests[] = json_decode( (string) $args['body'], true );
		if ( null === $fakes ) {
			return $preempt;
		}
		$fake   = array_shift( $fakes );
		$status = is_int( $fake ) ? $fake : 200;
		$body   = 200 === $status
			? array(
				'id'      => 'fake-' . count( $requests ),
				'object'  => 'chat.completion',
				'created' => time(),
				'model'   => 'fake',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => (string) $fake,
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
			: array( 'error' => array( 'message' => 'Fake server error' ) );
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// Emails, captured instead of sent.
$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $preempt, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);
$mails_to = static function ( string $email ) use ( &$mails ): array {
	return array_values( array_filter( $mails, static fn( $m ) => in_array( $email, (array) $m['to'], true ) ) );
};
$member_email = get_userdata( $member )->user_email;

// Starts a job and records it for cleanup.
$start = static function ( int $user_id, array $input ) use ( &$created ) {
	$job_id = Pipeline::start( $user_id, $input );
	if ( ! is_wp_error( $job_id ) ) {
		$created['jobs'][] = $job_id;
	}
	return $job_id;
};
// Runs a job and records its site for cleanup.
$run = static function ( int $job_id ) use ( &$created ) {
	$job = Pipeline::run( $job_id );
	$row = Jobs::get( $job_id );
	if ( $row && $row['site_id'] ) {
		$created['sites'][] = $row['site_id'];
	}
	return $job;
};
// The sites at a domain, the profile rows of a site, the pages made by a job.
$sites_at = static fn( string $slug ): array => get_sites(
	array(
		'domain' => Pipeline::domain( $slug ),
		'fields' => 'ids',
	)
);
$profiles = static function ( int $site_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BrandProfile::table() . ' WHERE site_id = %d', $site_id ) ); // phpcs:ignore WordPress.DB
};
$job_pages = static function ( int $site_id, int $job_id ): array {
	switch_to_blog( $site_id );
	$ids = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'any',
			'meta_key'    => '_mody_provisioning_job', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => $job_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'      => 'ids',
			'numberposts' => -1,
		)
	);
	restore_current_blog();
	return $ids;
};
$valid = array(
	'title'   => 'Ogia Bakery',
	'mission' => 'We bake sourdough bread for offices in Zurich.',
	'tone'    => 'friendly',
	'preset'  => 'forest',
	'prompt'  => 'A home page for our bakery: what we bake, how delivery works, and how to order.',
);
// Site limit raised for the test member while the kill tests run.
$raise_limit = static fn( $limit, $user_id ) => $user_id === $member ? 50 : $limit;

// 1. Validation ------------------------------------------------------------------

echo "\nValidation\n";
$user  = get_userdata( $member );
$bad   = Pipeline::validate(
	array(
		'slug'    => 'Ab',
		'title'   => '',
		'mission' => str_repeat( 'x', 201 ),
		'tone'    => 'shouty',
		'preset'  => 'neon',
		'prompt'  => '',
	),
	$user
);
$fields = is_wp_error( $bad ) ? array_keys( $bad->get_error_data()['fields'] ?? array() ) : array();
sort( $fields );
$check( 'every bad field reported', array( 'mission', 'preset', 'prompt', 'slug', 'title', 'tone' ) === $fields, implode( ', ', $fields ) );
foreach ( array( 'admin', 'alpha', '1234', 'ogia-bakery' ) as $slug ) {
	$out = Pipeline::validate( array_merge( $valid, array( 'slug' => $slug ) ), $user );
	$check( "slug refused: $slug", is_wp_error( $out ) && isset( $out->get_error_data()['fields']['slug'] ), $out instanceof WP_Error ? $out->get_error_message() : 'accepted' );
}
$out = Pipeline::validate( array_merge( $valid, array( 'mission' => '' ) ), $user );
$check( 'empty mission refused', is_wp_error( $out ) && isset( $out->get_error_data()['fields']['mission'] ) );
$out = Pipeline::validate( array_merge( $valid, array( 'prompt' => str_repeat( 'x', PageGenerator::MAX_PROMPT_LENGTH + 1 ) ) ), $user );
$check( 'too long prompt refused', is_wp_error( $out ) && isset( $out->get_error_data()['fields']['prompt'] ) );
$out = Pipeline::validate(
	array(
		'slug'    => ' OgiaTest ',
		'title'   => '<b>Ogia</b> Bakery',
		'mission' => "We bake\nbread.",
		'prompt'  => 'Home page',
	),
	$user
);
$check(
	'defaults and cleaning',
	! is_wp_error( $out ) && 'ogiatest' === $out['slug'] && 'Ogia Bakery' === $out['title'] && 'Ogia Bakery' === $out['brand_name']
		&& 'friendly' === $out['tone'] && Presets::DEFAULT === $out['preset'] && 'We bake bread.' === $out['mission'],
	$message( $out )
);

// 2. Quota -----------------------------------------------------------------------

echo "\nQuota\n";
$check( 'limit is 3 until M1', 3 === Quota::limit( $member ) );
$check( 'a new member may create a site', true === Quota::can_create_site( $member ), (string) Quota::used( $member ) . ' used' );
add_filter( 'mody_site_limit', '__return_zero' );
$out = Pipeline::start( $member, array_merge( $valid, array( 'slug' => $slugs( 'quota' ) ) ) );
remove_filter( 'mody_site_limit', '__return_zero' );
$check( 'start refused at the limit', is_wp_error( $out ) && 'mody_site_limit_reached' === $out->get_error_code(), $message( $out ) );
$queued = array();
foreach ( array( 'qa', 'qb', 'qc' ) as $name ) {
	$queued[] = $start( $member, array_merge( $valid, array( 'slug' => $slugs( $name ) ) ) );
}
$check( '...queued jobs count', 3 === Quota::used( $member ), (string) Quota::used( $member ) . ' used' );
$out = $start( $member, array_merge( $valid, array( 'slug' => $slugs( 'qd' ) ) ) );
$check( '...a fourth is refused', is_wp_error( $out ) && 'mody_site_limit_reached' === $out->get_error_code(), $message( $out ) );
$wpdb->query( 'DELETE FROM ' . Jobs::table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $queued ) ) . ')' ); // phpcs:ignore WordPress.DB

// 3. A full run -------------------------------------------------------------------

echo "\nA full run (fake page)\n";
$slug     = $slugs( 'full' );
$job_id   = $start( $member, array_merge( $valid, array( 'slug' => $slug ) ) );
$fakes    = array( SystemPrompt::EXAMPLE );
$requests = array();
$mails    = array();
$t0       = microtime( true );
$job      = $run( $job_id );
$secs     = sprintf( '%.1fs', microtime( true ) - $t0 );
$fakes    = null;
$check( 'job done', ! is_wp_error( $job ) && 'done' === $job['status'] && 'done' === $job['step'], is_wp_error( $job ) ? $message( $job ) : $job['status'] . ' ' . $job['last_error'] . " $secs" );
$site_id = is_array( $job ) ? $job['site_id'] : 0;
$site    = get_site( $site_id );
$check( 'site at the subdomain', $site && Pipeline::domain( $slug ) === $site->domain && '1' === (string) $site->public, $site ? $site->domain : 'no site' );
$caps = get_user_meta( $member, $wpdb->get_blog_prefix( $site_id ) . 'capabilities', true );
$check( 'member is its administrator', is_array( $caps ) && ! empty( $caps['administrator'] ) );
$check( '...and uses one site of the quota', 1 === Quota::used( $member ) );
switch_to_blog( $site_id );
wp_clean_theme_json_cache();
$check( 'theme, title and tagline', 'theme' === get_stylesheet() && 'Ogia Bakery' === get_option( 'blogname' ) && $valid['mission'] === get_option( 'blogdescription' ) );
$main_suffix = substr( get_site_url( get_main_site_id() ), strlen( get_home_url( get_main_site_id() ) ) );
$check(
	'addresses shaped like the main site\'s',
	'https://' . Pipeline::domain( $slug ) === get_option( 'home' ) && get_option( 'home' ) . $main_suffix === get_option( 'siteurl' )
		&& str_starts_with( admin_url(), get_option( 'siteurl' ) . '/wp-admin/' ),
	get_option( 'home' ) . ' ' . get_option( 'siteurl' )
);
$admin_base = admin_url();
$check( 'job recorded on the site', $job_id === (int) get_option( 'mody_provisioning_job' ) );
$check( 'sample post and page removed', ! get_page_by_path( 'hello-world', OBJECT, 'post' ) && ! get_page_by_path( 'sample-page', OBJECT, 'page' ) );
$check( 'Global Styles: the Forest preset', array_intersect_key( SiteDesign::colors(), Presets::colors( 'forest' ) ) == Presets::colors( 'forest' ), wp_json_encode( SiteDesign::colors() ) ); // phpcs:ignore Universal.Operators.StrictComparisons
$page_id = (int) ( $job['result']['page_id'] ?? 0 );
$page    = get_post( $page_id );
$check( 'front page published', $page && 'publish' === $page->post_status && 'page' === get_option( 'show_on_front' ) && $page_id === (int) get_option( 'page_on_front' ) );
$check( '...the generated markup, as validated', $page && SystemPrompt::EXAMPLE === $page->post_content );
$check( '...titled with its heading', $page && 'Bikes repaired while you work' === $page->post_title, $page ? $page->post_title : '' );
$check( '...without the template title', PageGenerator::TEMPLATE === get_page_template_slug( $page_id ) );
$check( '...by the member', $page && $member === (int) $page->post_author );
$home = home_url( '/' );
restore_current_blog();
$profile = BrandProfile::for_site( $site_id );
$check( 'brand profile', $profile && 'Ogia Bakery' === $profile->brand_name && 'friendly' === $profile->tone && Presets::colors( 'forest' ) == $profile->colors, $profile ? wp_json_encode( $profile->colors ) : 'none' ); // phpcs:ignore Universal.Operators.StrictComparisons
$check( 'one model request, with the brand', 1 === count( $requests ) && str_contains( (string) wp_json_encode( $requests[0]['messages'][0] ?? array() ), '<brand>' ), count( $requests ) . ' request(s)' );
$welcome = $mails_to( $member_email );
$check( 'one welcome email', 1 === count( $welcome ) && str_contains( $welcome[0]['message'], $home ) && str_contains( $welcome[0]['message'], $admin_base . 'site-editor.php' ) && str_contains( $welcome[0]['message'], 'mody-chat=open' ), count( $welcome ) . ' email(s)' );
$check( 'result', false === ( $job['result']['fallback'] ?? null ) && 'sent' === ( $job['result']['email'] ?? '' ), wp_json_encode( $job['result'] ?? array() ) );

$requests = array();
$mails    = array();
$again    = $run( $job_id );
$check( 'running it again changes nothing', ! is_wp_error( $again ) && 'done' === $again['status'] && ! $requests && ! $mails && 1 === count( $sites_at( $slug ) ) );
$out = $start( $member, array_merge( $valid, array( 'slug' => $slug ) ) );
$check( 'the same address again is refused', is_wp_error( $out ) && isset( $out->get_error_data()['fields']['slug'] ), $message( $out ) );

// 4. Killed and resumed -----------------------------------------------------------------

echo "\nKilled after each step, then resumed\n";
add_filter( 'mody_site_limit', $raise_limit, 10, 2 );
foreach ( array_slice( Jobs::STEPS, 0, -1 ) as $kill_at ) {
	$slug     = $slugs( 'kill' . $kill_at );
	$job_id   = $start( $member, array_merge( $valid, array( 'slug' => $slug ) ) );
	$fakes    = array( SystemPrompt::EXAMPLE, SystemPrompt::EXAMPLE );
	$requests = array();
	$mails    = array();
	$killer   = static function ( string $step ) use ( $kill_at ): void {
		if ( $step === $kill_at ) {
			throw new \Error( 'killed' );
		}
	};
	add_action( 'mody_provisioning_step_done', $killer );
	$killed = false;
	try {
		$run( $job_id );
	} catch ( \Error $e ) {
		$killed = 'killed' === $e->getMessage();
	}
	remove_action( 'mody_provisioning_step_done', $killer );
	$row  = Jobs::get( $job_id );
	$busy = Pipeline::run( $job_id );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Jobs::table() . ' SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id = %d', $job_id ) ); // phpcs:ignore WordPress.DB
	$job   = $run( $job_id );
	$sites = $sites_at( $slug );
	$site  = (int) ( $sites[0] ?? 0 );
	foreach ( $sites as $id ) {
		$created['sites'][] = (int) $id;
	}
	$ok = $killed && 'running' === $row['status'] && $kill_at === $row['step']
		&& is_wp_error( $busy ) && 'mody_job_busy' === $busy->get_error_code()
		&& ! is_wp_error( $job ) && 'done' === $job['status']
		&& 1 === count( $sites ) && 1 === $profiles( $site ) && 1 === count( $job_pages( $site, $job_id ) )
		&& 1 === count( $mails_to( $member_email ) ) && 1 === count( $requests );
	$check(
		"killed at $kill_at",
		$ok,
		sprintf(
			'%s; %d site(s), %d profile(s), %d page(s), %d email(s), %d request(s)%s',
			is_wp_error( $job ) ? $message( $job ) : $job['status'] . ( $job['last_error'] ? ' ' . $job['last_error'] : '' ),
			count( $sites ),
			$profiles( $site ),
			$site ? count( $job_pages( $site, $job_id ) ) : 0,
			count( $mails_to( $member_email ) ),
			count( $requests ),
			// Seen once (2026-11-07, not reproduced in 15 runs): 0 requests. Show where the page came from.
			$ok ? '' : '; site ' . $site . ', result ' . ( is_wp_error( $job ) ? '' : wp_json_encode( $job['result'] ) )
		)
	);
}
$fakes = null;

// A site at the address that this job did not create.
$slug   = $slugs( 'taken' );
$job_id = $start( $member, array_merge( $valid, array( 'slug' => $slug ) ) );
$other  = wpmu_create_blog( Pipeline::domain( $slug ), get_network()->path, 'Someone else', $admin->ID );
wp_installing( false );
$created['sites'][] = $other;
$job                = $run( $job_id );
$check( 'address taken meanwhile: failed', ! is_wp_error( $job ) && 'failed' === $job['status'] && str_contains( $job['last_error'], 'mody_site_exists' ), is_wp_error( $job ) ? $message( $job ) : $job['last_error'] );
remove_filter( 'mody_site_limit', $raise_limit, 10 );

// 5. Generation failure -------------------------------------------------------------

echo "\nGeneration failure\n";
add_filter( 'mody_site_limit', $raise_limit, 10, 2 );
foreach ( array( 'invalid' => array( 'not blocks', 'not blocks', 'not blocks' ), 'error' => array( 500 ) ) as $case => $answers ) {
	$slug     = $slugs( 'fail' . $case );
	$job_id   = $start( $member, array_merge( $valid, array( 'slug' => $slug ) ) );
	$fakes    = $answers;
	$requests = array();
	$job      = $run( $job_id );
	$fakes    = null;
	$site_id  = is_array( $job ) ? $job['site_id'] : 0;
	$page_id  = (int) ( $job['result']['page_id'] ?? 0 );
	switch_to_blog( $site_id );
	$page    = get_post( $page_id );
	$content = $page ? $page->post_content : '';
	$front   = $page_id === (int) get_option( 'page_on_front' );
	restore_current_blog();
	$check(
		"$case answers: fallback page, job done",
		! is_wp_error( $job ) && 'done' === $job['status'] && true === $job['result']['fallback'] && $front && $page && 'publish' === $page->post_status
			&& str_contains( $content, 'Hello, I&#039;m Ogia Bakery' ) && '' !== ( $job['result']['generation_error'] ?? '' ),
		is_wp_error( $job ) ? $message( $job ) : ( $job['result']['generation_error'] ?? '' )
	);
}
$check( 'fallback markup is valid', true === BlockValidator::validate( Pipeline::fallback_markup( 'Ogia <Bakery>', 'We bake & deliver.' ) ) );

$fakes    = array( 'still not blocks', 'still not blocks', 'still not blocks' );
$retry    = Pipeline::retry_generation( $job_id );
$fakes    = null;
$job      = Jobs::get( $job_id );
$check( 'retry that fails again keeps the fallback', is_wp_error( $retry ) && true === $job['result']['fallback'], $message( $retry ) );
$fakes    = array( SystemPrompt::EXAMPLE );
$retry    = Pipeline::retry_generation( $job_id );
$fakes    = null;
switch_to_blog( $job['site_id'] );
$page = get_post( (int) $job['result']['page_id'] );
$meta = get_post_meta( $page->ID, '_mody_fallback', true );
restore_current_blog();
$check( 'retry replaces the fallback page', ! is_wp_error( $retry ) && false === $retry['result']['fallback'] && SystemPrompt::EXAMPLE === $page->post_content && 'Bikes repaired while you work' === $page->post_title && '' === $meta, $message( $retry ) );
$again = Pipeline::retry_generation( $job_id );
$check( '...and only once', is_wp_error( $again ) && 'mody_nothing_to_retry' === $again->get_error_code() );
remove_filter( 'mody_site_limit', $raise_limit, 10 );

// Logo (M2-05) -------------------------------------------------------------------------

echo "\nLogo\n";
add_filter( 'mody_site_limit', $raise_limit, 10, 2 );
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$main_uploads = array();
// A 64 px PNG in the main site's media library, uploaded by $user_id.
$make_logo = static function ( int $user_id ) use ( &$main_uploads ): int {
	$tmp = wp_tempnam( 'logo.png' );
	$img = imagecreatetruecolor( 64, 64 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 91, 42, 134 ) );
	imagepng( $img, $tmp );
	$previous = get_current_user_id();
	wp_set_current_user( $user_id );
	switch_to_blog( get_main_site_id() );
	$id = media_handle_sideload(
		array(
			'name'     => 'logo.png',
			'tmp_name' => $tmp,
		),
		0
	);
	restore_current_blog();
	wp_set_current_user( $previous );
	$main_uploads[] = (int) $id;
	return is_wp_error( $id ) ? 0 : (int) $id;
};
register_shutdown_function(
	static function () use ( &$main_uploads ): void {
		switch_to_blog( get_main_site_id() );
		foreach ( $main_uploads as $id ) {
			if ( $id && get_post( $id ) ) {
				wp_delete_attachment( $id, true );
			}
		}
		restore_current_blog();
	}
);
$site_logos = static function ( int $site_id, int $job_id ): array {
	switch_to_blog( $site_id );
	$ids = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => '_mody_provisioning_job', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => $job_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'      => 'ids',
			'numberposts' => -1,
		)
	);
	restore_current_blog();
	return $ids;
};

$theirs = $make_logo( $admin->ID );
$out    = Pipeline::validate( array_merge( $valid, array( 'slug' => $slugs( 'logotheirs' ), 'logo_id' => $theirs ) ), $user );
$check( 'someone else\'s upload refused', is_wp_error( $out ) && isset( $out->get_error_data()['fields']['logo'] ), $out instanceof WP_Error ? $out->get_error_message() : 'accepted' );
$out = Pipeline::validate( array_merge( $valid, array( 'slug' => $slugs( 'logonone' ), 'logo_id' => 999999999 ) ), $user );
$check( '...an unknown one refused', is_wp_error( $out ) && isset( $out->get_error_data()['fields']['logo'] ) );
switch_to_blog( get_main_site_id() );
$text_id        = wp_insert_attachment(
	array(
		'post_mime_type' => 'text/plain',
		'post_title'     => 'Not an image',
		'post_status'    => 'inherit',
		'post_author'    => $member,
	)
);
$main_uploads[] = $text_id;
restore_current_blog();
$out = Pipeline::validate( array_merge( $valid, array( 'slug' => $slugs( 'logotext' ), 'logo_id' => $text_id ) ), $user );
$check( '...a file that is not an image refused', is_wp_error( $out ) && isset( $out->get_error_data()['fields']['logo'] ) );

$source   = $make_logo( $member );
$slug     = $slugs( 'logo' );
$job_id   = $start( $member, array_merge( $valid, array( 'slug' => $slug, 'logo_id' => $source ) ) );
$fakes    = array( SystemPrompt::EXAMPLE );
$job      = $run( $job_id );
$fakes    = null;
$site_id  = is_array( $job ) ? (int) $job['site_id'] : 0;
$logos    = $site_logos( $site_id, $job_id );
switch_to_blog( $site_id );
$logo_id  = (int) get_option( 'site_logo' );
$logo_url  = (string) wp_get_attachment_url( $logo_id );
$logo_mime = (string) get_post_mime_type( $logo_id );
$on_disk   = is_readable( (string) get_attached_file( $logo_id ) );
$header   = do_blocks( '<!-- wp:template-part {"slug":"header","theme":"theme","area":"header","tagName":"header"} /-->' );
restore_current_blog();
switch_to_blog( get_main_site_id() );
$source_left = (bool) get_post( $source );
restore_current_blog();
// Upload URLs take the request's host here (wp-config.php); the profile keeps the site's own.
$logo_path = (string) wp_parse_url( $logo_url, PHP_URL_PATH );
$site_url  = 'https://' . Pipeline::domain( $slug ) . $logo_path;
$check( 'the site gets the logo', ! is_wp_error( $job ) && 'done' === $job['status'] && 1 === count( $logos ) && $logos[0] === $logo_id && $on_disk, $site_url );
$check( '...in its own media library', str_contains( $logo_path, '/uploads/sites/' . $site_id . '/' ) && 'image/png' === $logo_mime, $logo_mime );
$check( '...shown in the header', str_contains( $header, 'wp-block-site-logo' ) && str_contains( $header, wp_basename( $logo_url ) ) );
$check( '...recorded in the profile, on the site\'s host', $site_url === BrandProfile::for_site( $site_id )?->logo_url && $site_url === ( $job['result']['logo_url'] ?? '' ), (string) BrandProfile::for_site( $site_id )?->logo_url );
$check( '...and the upload on the main site removed', ! $source_left );

// Killed in the profile step, after the copy: one logo.
$source  = $make_logo( $member );
$slug    = $slugs( 'logokill' );
$job_id  = $start( $member, array_merge( $valid, array( 'slug' => $slug, 'logo_id' => $source ) ) );
$fakes   = array( SystemPrompt::EXAMPLE, SystemPrompt::EXAMPLE );
$killer  = static function ( string $step ): void {
	if ( 'profile' === $step ) {
		throw new \Error( 'killed' );
	}
};
add_action( 'mody_provisioning_step_done', $killer );
try {
	$run( $job_id );
} catch ( \Error $e ) {
	unset( $e );
}
remove_action( 'mody_provisioning_step_done', $killer );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Jobs::table() . ' SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id = %d', $job_id ) ); // phpcs:ignore WordPress.DB
$job     = $run( $job_id );
$fakes   = null;
$site_id = is_array( $job ) ? (int) $job['site_id'] : 0;
$check( 'killed after the copy, resumed: one logo', ! is_wp_error( $job ) && 'done' === $job['status'] && 1 === count( $site_logos( $site_id, $job_id ) ) && '' !== (string) BrandProfile::for_site( $site_id )?->logo_url, count( $site_logos( $site_id, $job_id ) ) . ' logo(s)' );

// The upload vanished before the run: the site is created without a logo.
$source = $make_logo( $member );
$slug   = $slugs( 'logogone' );
$job_id = $start( $member, array_merge( $valid, array( 'slug' => $slug, 'logo_id' => $source ) ) );
switch_to_blog( get_main_site_id() );
wp_delete_attachment( $source, true );
restore_current_blog();
$fakes   = array( SystemPrompt::EXAMPLE );
$job     = $run( $job_id );
$fakes   = null;
$site_id = is_array( $job ) ? (int) $job['site_id'] : 0;
switch_to_blog( $site_id );
$no_logo = ! get_option( 'site_logo' );
restore_current_blog();
$check( 'upload gone: the site, without a logo', ! is_wp_error( $job ) && 'done' === $job['status'] && $no_logo && '' !== ( $job['result']['logo_error'] ?? '' ), (string) ( $job['result']['logo_error'] ?? '' ) );
remove_filter( 'mody_site_limit', $raise_limit, 10 );

// The agent's drafts use the same template (no second title).
$draft = PageGenerator::insert_page( SystemPrompt::EXAMPLE );
$check( 'insert_page: a draft without the template title', ! is_wp_error( $draft ) && 'draft' === get_post_status( $draft ) && PageGenerator::TEMPLATE === get_page_template_slug( $draft ) && 'Bikes repaired while you work' === get_the_title( $draft ) );
if ( ! is_wp_error( $draft ) ) {
	wp_delete_post( $draft, true );
}

// 6. A real run ------------------------------------------------------------------------

echo "\nA real run\n";
if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo "--   skipped: MODY_TEST_OFFLINE is set\n";
} else {
	add_filter( 'mody_site_limit', static fn( $limit, $user_id ) => $user_id === $admin->ID ? 50 : $limit, 10, 2 );
	$slug     = $slugs( 'real' );
	$job_id   = $start( $admin->ID, array_merge( $valid, array( 'slug' => $slug, 'preset' => 'ocean' ) ) );
	$requests = array();
	$t0       = microtime( true );
	$job      = $run( $job_id );
	$secs     = microtime( true ) - $t0;
	$created['keep'] = is_array( $job ) ? $job['site_id'] : 0;
	$check( 'done in under 2 minutes', ! is_wp_error( $job ) && 'done' === $job['status'] && $secs < 120, sprintf( '%.1fs, %d model request(s)', $secs, count( $requests ) ) );
	$check( '...with a generated page, not the fallback', ! is_wp_error( $job ) && false === ( $job['result']['fallback'] ?? null ), (string) ( $job['result']['generation_error'] ?? '' ) );
	if ( ! is_wp_error( $job ) ) {
		switch_to_blog( $job['site_id'] );
		$page = get_post( (int) $job['result']['page_id'] );
		$url  = home_url( '/' );
		restore_current_blog();
		$check( '...valid markup on the front page', $page && true === BlockValidator::validate( $page->post_content ), $page ? '"' . $page->post_title . '"' : '' );
		echo getenv( 'MODY_TEST_KEEP_SITE' ) ? "     kept: $url\n" : "     $url (removed at the end; MODY_TEST_KEEP_SITE=1 keeps it)\n";
	}
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
