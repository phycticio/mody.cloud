<?php
/**
 * Tests the registration chat and its form (tickets M1-01 part 2, M1-03).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/registration-test.php
 *
 * 1. Closed (the default): no page, the block says registration opens soon,
 *    both REST routes refuse. The block (M1-04): from block.json, every
 *    style support, in the inserter on the main site only, its styles on
 *    the wrapper, nothing rendered on a member site.
 * 2. The checks (in-process): each question's rules, reserved and taken
 *    addresses, the address suggested for a project's name, and register()
 *    refusing bad answers without creating anything.
 * 3. Open, over HTTP: the page with the chat's script and the form (no
 *    password, no plans, no card); the check route (nonce, each field, the
 *    suggestion); the register route: bad answers (400 with fields), then a
 *    good one (201): the user without a main-site role, a place of the
 *    launch offer (six months free, one site, no card), the onboarding stage, the site with its starter page and no
 *    model call, the welcome email with the set-password link; the same
 *    email again is refused.
 * 4. The form without JavaScript: errors keep the answers, an expired nonce,
 *    and a good post says "Done!". A logged-in visitor is told they have an
 *    account; members log in to the dashboard.
 * 5. The launch offer's places taken: the page says so and the price, and
 *    nothing is created.
 * 6. Closed again: the page says so, and nothing is created.
 *
 * Antibot (tickets/antibot-altcha.md): the page has the ALTCHA widget and
 * its script; creating an account needs a solution (403 without one), and a
 * solution works once. The test solves easy challenges in PHP.
 *
 * The security pass of 2026-11-12 (the limits, the login names, the text of
 * the welcome email and what a repeated email says) has its own checks in
 * scripts/security-signup-test.php; here only what changed in this flow: the
 * email check says nothing about accounts, an email that has one gets the
 * same answer and nothing is created, names and projects carry no links.
 *
 * Everything created is removed at the end. No model call.
 */

use Mody\Platform\Members\Members;
use Mody\Platform\Members\Registration;
use Mody\Platform\Onboarding\Onboarding;
use Mody\Platform\Provisioning\Jobs;
use Mody\Platform\Provisioning\Pipeline;
use Mody\Platform\Security\Altcha;
use Mody\Platform\Security\RateLimit;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-64s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

if ( ! is_main_site() ) {
	echo "Run this on the main site (--url=https://<main host>).\n";
	return;
}
RateLimit::clear(); // Accounts made here count toward the hour's limit; start from zero.
$suffix      = strtolower( wp_generate_password( 6, false ) );
$emails      = array( 'a' => "modyreg{$suffix}@mody.local", 'b' => "modyregb{$suffix}@mody.local", 'c' => "modyregc{$suffix}@mody.local" );
$slugs       = array( 'a' => "regtest{$suffix}", 'b' => "regtestb{$suffix}", 'c' => "regtestc{$suffix}" );
$was_open    = get_site_option( Registration::OPEN_OPTION );
$was_promo   = array(
	Members::PROMO_START_OPTION => get_site_option( Members::PROMO_START_OPTION, null ),
	Members::PROMO_GIVEN_OPTION => get_site_option( Members::PROMO_GIVEN_OPTION, null ),
);
delete_site_option( Members::PROMO_START_OPTION ); // The offer as at launch: 50 places.
delete_site_option( Members::PROMO_GIVEN_OPTION );
$page_before = (int) get_option( Registration::PAGE_OPTION );
$admins      = get_super_admins();
$super       = get_user_by( 'login', $admins[0] );
register_shutdown_function(
	static function () use ( $emails, $was_open, $was_promo, $page_before ): void {
		foreach ( $was_promo as $option => $value ) {
			null === $value ? delete_site_option( $option ) : update_site_option( $option, $value );
		}
		foreach ( $emails as $email ) {
			$user = get_user_by( 'email', $email );
			if ( ! $user ) {
				continue;
			}
			foreach ( get_blogs_of_user( $user->ID ) as $site ) {
				if ( ! is_main_site( (int) $site->userblog_id ) ) {
					wpmu_delete_blog( (int) $site->userblog_id, true );
				}
			}
			Members::delete( $user->ID );
			wpmu_delete_user( $user->ID );
		}
		false === $was_open ? delete_site_option( Registration::OPEN_OPTION ) : update_site_option( Registration::OPEN_OPTION, $was_open );
		$page_now = (int) get_option( Registration::PAGE_OPTION );
		if ( ! $page_before && $page_now ) {
			wp_delete_post( $page_now, true );
			delete_option( Registration::PAGE_OPTION );
		}
	}
);
$block = static fn(): string => render_block( parse_blocks( '<!-- wp:' . Registration::BLOCK . ' /-->' )[0] );
$http  = static function ( string $method, string $to, array $cookies = array(), array $body = array(), bool $json = false ): array {
	$response = wp_remote_request(
		$to,
		array(
			'method'      => $method,
			'cookies'     => $cookies,
			'headers'     => $json ? array( 'Content-Type' => 'application/json' ) : array(),
			'body'        => $json ? wp_json_encode( $body ) : $body,
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 60,
		)
	);
	$text = (string) wp_remote_retrieve_body( $response );
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'body'     => $text,
		'json'     => json_decode( $text, true ),
	);
};
// A solved ALTCHA challenge (an easy one: counter 3).
$proof = static fn(): string => Altcha::solve( Altcha::challenge( 3 ) );
$rest  = static fn( string $route, array $body ): array => $http( 'POST', rest_url( 'mody-platform/v1/' . $route ), array(), $body, true );
// A signup over HTTP answers before the account exists (the work runs after the response, so the
// answer takes the same time for a new email and an existing one): wait for its last step, the
// welcome email's reset key, before looking at what it made.
$settle = static function ( string $email ): bool {
	global $wpdb;
	for ( $i = 0; $i < 120; $i++ ) {
		if ( '' !== (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE user_email = %s", $email ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return true;
		}
		usleep( 500000 );
	}
	return false;
};
// From the database: sites created by another request are not in this process's cache.
$site_exists = static function ( string $slug ): bool {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE domain = %s", Pipeline::domain( $slug ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
};

// 1. Closed ------------------------------------------------------------------------

echo "Closed (the default)\n";
delete_site_option( Registration::OPEN_OPTION );
$check( 'closed unless the network option is set', ! Registration::is_open() );
if ( ! $page_before ) {
	Registration::ensure_page();
	$check( '... and no page is created', ! get_option( Registration::PAGE_OPTION ) && ! get_page_by_path( Registration::PAGE_SLUG ) );
}
$check( 'the block says registration opens soon', str_contains( $block(), 'Registration opens soon.' ) && ! str_contains( $block(), '<form' ) );
$type = WP_Block_Type_Registry::get_instance()->get_registered( Registration::BLOCK );
$check( 'the block: from block.json, with its editor preview and style', $type && 3 === $type->api_version && is_callable( $type->render_callback ) && array( Registration::EDITOR ) === $type->editor_script_handles && array( Registration::STYLE ) === $type->style_handles && false === ( $type->supports['multiple'] ?? null ) );
$supports = array( 'anchor', 'align', 'color.background', 'color.text', 'color.gradients', 'color.link', 'color.button', 'background.backgroundImage', 'background.gradient', 'spacing.margin', 'spacing.padding', 'spacing.blockGap', 'dimensions.minHeight', 'typography.fontSize', 'typography.__experimentalFontFamily', 'typography.__experimentalLetterSpacing', '__experimentalBorder.radius', 'shadow' );
$missing  = array_filter( $supports, static fn( $path ) => ! block_has_support( $type, explode( '.', $path ) ) );
$check( '...every style WordPress offers a block', ! $missing, $missing ? 'missing: ' . implode( ', ', $missing ) : count( $supports ) . ' checked' );
$member_site = get_sites( array( 'number' => 1, 'fields' => 'ids', 'site__not_in' => array( get_main_site_id() ) ) );
$inserter    = true === ( $type->supports['inserter'] ?? null );
if ( $member_site ) {
	switch_to_blog( (int) $member_site[0] );
	$inserter = $inserter && false === Registration::block_metadata( array( 'name' => Registration::BLOCK ) )['supports']['inserter'] && '' === $block();
	restore_current_blog();
}
$check( '...in the inserter on the main site only, rendered nothing elsewhere', $inserter );
$styled = render_block( parse_blocks( '<!-- wp:' . Registration::BLOCK . ' {"align":"wide","anchor":"join","backgroundColor":"contrast","textColor":"base","style":{"spacing":{"blockGap":"var:preset|spacing|50","padding":{"top":"var:preset|spacing|60"}},"elements":{"button":{"color":{"background":"var:preset|color|accent","text":"var:preset|color|contrast"}}},"border":{"radius":"20px"},"typography":{"fontSize":"1.2rem"}}} /-->' )[0] );
$check(
	'...its styles on the front end (classes, inline styles, the gap, the visitor\'s colors)',
	1 === preg_match( '/^\s*<div ([^>]*)>/', $styled, $tag )
		&& str_contains( $tag[1], 'class="mody-register alignwide wp-elements-' ) && str_contains( $tag[1], 'has-contrast-background-color' ) && str_contains( $tag[1], 'has-base-color' ) && str_contains( $tag[1], 'id="join"' )
		&& str_contains( $tag[1], 'padding-top:var(--wp--preset--spacing--60)' ) && str_contains( $tag[1], 'border-radius:20px' ) && preg_match( '/font-size:[^;"]*1\\.2rem/', $tag[1] )
		&& str_contains( $tag[1], '--mody-register-gap:var(--wp--preset--spacing--50)' ) && str_contains( $tag[1], '--mody-register-you-background:var(--wp--preset--color--accent)' ) && str_contains( $tag[1], '--mody-register-you-text:var(--wp--preset--color--contrast)' ),
	$tag[1] ?? 'no wrapper'
);
$hostile = render_block( parse_blocks( '<!-- wp:' . Registration::BLOCK . ' {"style":{"spacing":{"blockGap":"1rem;background:url(https://example.org/x.png)"}}} /-->' )[0] );
$check( '...a style value that could inject CSS is dropped', ! str_contains( $hostile, 'example.org' ) && ! str_contains( $hostile, '--mody-register-gap' ) );
$out = $rest( 'register/check', array( 'field' => 'name', 'value' => 'Ada', 'nonce' => 'x' ) );
$check( 'the check route refuses (403)', 403 === $out['code'] && 'mody_registration_closed' === ( $out['json']['code'] ?? '' ), (string) $out['code'] );

// 2. The checks ---------------------------------------------------------------------

echo "\nThe checks\n";
$check( 'name: required, at most 60 characters, no links', '' !== Registration::check( 'name', '  ' ) && '' !== Registration::check( 'name', str_repeat( 'a', Registration::MAX_NAME + 1 ) ) && '' === Registration::check( 'name', str_repeat( 'a', Registration::MAX_NAME ) ) && '' === Registration::check( 'name', 'Ada Lovelace' ) && '' !== Registration::check( 'name', 'Call +34 600 000 000 or visit bank-secure.example' ) );
$check( 'email: valid, and says nothing about accounts', str_contains( Registration::check( 'email', 'nope' ), 'valid email' ) && '' === Registration::check( 'email', $super->user_email ) && '' === Registration::check( 'email', $emails['a'] ) );
$check( 'title: required, at most 60 characters, no links', '' !== Registration::check( 'title', '' ) && '' !== Registration::check( 'title', str_repeat( 'b', Registration::MAX_TITLE + 1 ) ) && '' !== Registration::check( 'title', 'Log in at evil.example/bank' ) && '' === Registration::check( 'title', 'Lina\'s Bakery' ) );
$taken = get_site( get_main_site_id() )->domain;
$first = get_sites( array( 'number' => 1, 'site__not_in' => array( get_main_site_id() ) ) );
$check(
	'slug: core\'s rules, reserved names, taken addresses',
	str_contains( Registration::check( 'slug', 'ab' ), 'at least 4' )
		&& str_contains( Registration::check( 'slug', 'Big-Shop' ), 'lowercase' )
		&& str_contains( Registration::check( 'slug', 'mail' ), 'not allowed' )
		&& str_contains( Registration::check( 'slug', 'plugins' ), 'not allowed' )
		&& ( ! $first || str_contains( Registration::check( 'slug', strstr( $first[0]->domain, '.', true ) ), 'already exists' ) )
		&& '' === Registration::check( 'slug', $slugs['a'] ),
	$taken
);
// Core keeps an address that matches a login for that user; here a login takes no address.
$login    = "reglogin{$suffix}";
$login_id = (int) wpmu_create_user( $login, wp_generate_password(), "modyreglogin{$suffix}@mody.local" );
$check(
	'slug: someone\'s login does not take the address (a site does)',
	$login_id
		&& str_contains( (string) wpmu_validate_blog_signup( $login, 'X' )['errors']->get_error_message( 'blogname' ), 'reserved' )
		&& '' === Registration::check( 'slug', $login )
		&& ! isset( Pipeline::check_address( $login, 'X', $super )['slug'] )
		&& ( ! $first || isset( Pipeline::check_address( strstr( $first[0]->domain, '.', true ), 'X', $super )['slug'] ) ),
	$login
);
if ( $login_id ) {
	wpmu_delete_user( $login_id );
}
$check( 'the wizard refuses reserved names too', isset( Pipeline::check_address( 'admin', 'X', $super )['slug'] ) && isset( Pipeline::check_address( 'webmail', 'X', $super )['slug'] ) );
$check( 'addresses as typed are cleaned', 'myshop' === Registration::normalize_slug( 'https://My-Shop' . Registration::address_suffix() ) && 'bakery' === Registration::normalize_slug( 'Bäkery!' ) );
$check( 'an address is suggested for the project\'s name', 'linasbakery' === Registration::suggest_slug( 'Lina\'s Bäkery!' ) && 'mailsite' !== Registration::suggest_slug( 'Mail' ) && '' === Registration::check( 'slug', Registration::suggest_slug( 'Mail' ) ), Registration::suggest_slug( 'Mail' ) );
$result = Registration::register( ' ', 'nope', '', 'ab' );
$fields = is_wp_error( $result ) ? array_keys( (array) $result->get_error_data()['fields'] ) : array();
sort( $fields );
$check( 'register() refuses all four bad answers', array( 'email', 'name', 'slug', 'title' ) === $fields, implode( ', ', $fields ) );
$check( '... and creates nothing', ! get_user_by( 'email', 'nope' ) && ! $site_exists( 'ab' ) );

// 3. Open, over HTTP ----------------------------------------------------------------

echo "\nOpen, over HTTP\n";
update_site_option( Registration::OPEN_OPTION, 1 );
Registration::ensure_page();
$page_id = (int) get_option( Registration::PAGE_OPTION );
$page    = get_post( $page_id );
$check( 'the page exists, published at /register/, with the block', $page && 'publish' === $page->post_status && Registration::PAGE_SLUG === $page->post_name && has_block( Registration::BLOCK, $page ), $page_id ? (string) get_permalink( $page_id ) : 'none' );
$url = (string) get_permalink( $page_id );

$out = $http( 'GET', $url );
preg_match( '/name="_mody_nonce" value="([0-9a-f]+)"/', $out['body'], $nonce );
$nonce = $nonce[1] ?? '';
$check( 'the page: the chat\'s script and settings, and the form', 200 === $out['code'] && str_contains( $out['body'], 'assets/register.js' ) && str_contains( $out['body'], 'window.modyRegister' ) && str_contains( $out['body'], 'id="mody-register-chat"' ) && '' !== $nonce, (string) $out['code'] );
$check( '... the antibot widget and its script, in the form', str_contains( $out['body'], '<altcha-widget class="mody-altcha" name="altcha"' ) && str_contains( $out['body'], '/dist/altcha.js' ) && str_contains( $out['body'], 'Signing up needs JavaScript' ) );
$in_container = do_blocks( '<!-- wp:spectra/container {"tagName":"section"} --><div class="wp-block-spectra-container"><!-- wp:' . Registration::BLOCK . ' /--></div><!-- /wp:spectra/container -->' );
$check( '... and inside a Spectra container too (its kses; the home page)', str_contains( $in_container, '<altcha-widget class="mody-altcha" name="altcha" challenge="' ) && str_contains( $in_container, 'auto="onfocus"' ) );
$check( '... the four questions, the address\'s suffix', str_contains( $out['body'], 'name="mody_name"' ) && str_contains( $out['body'], 'name="mody_email"' ) && str_contains( $out['body'], 'name="mody_title"' ) && str_contains( $out['body'], 'name="mody_slug"' ) && str_contains( $out['body'], esc_html( Registration::address_suffix() ) ) );
$check( '... no password; free for 6 months, no card, then the price with VAT, never "unlimited"', ! str_contains( $out['body'], 'type="password"' ) && str_contains( $out['body'], 'free for 6 months' ) && str_contains( $out['body'], 'no card needed' ) && str_contains( $out['body'], '7.99 EUR a month, VAT included' ) && false === stripos( $out['body'], 'unlimited' ) );

$out = $rest( 'register/check', array( 'field' => 'name', 'value' => 'Ada', 'nonce' => 'deadbeef00' ) );
$check( 'check: a wrong nonce is refused (403)', 403 === $out['code'] && 'mody_registration_expired' === ( $out['json']['code'] ?? '' ), (string) $out['code'] );
$ask = static fn( string $field, string $value ): array => (array) $rest( 'register/check', array( 'field' => $field, 'value' => $value, 'nonce' => $nonce ) )['json'];
$out = $ask( 'email', $super->user_email );
$check( 'check: a used email is fine too, nothing says it has an account', true === ( $out['ok'] ?? null ) && ! isset( $out['exists'] ) && ! isset( $out['message'] ), (string) wp_json_encode( $out ) );
$out = $ask( 'title', 'Regtest ' . $suffix );
$check( 'check: a title gives a free address', true === ( $out['ok'] ?? null ) && 'regtest' . $suffix === ( $out['suggestion'] ?? '' ), (string) ( $out['suggestion'] ?? '' ) );
$out = $ask( 'slug', 'Mail' . Registration::address_suffix() );
$check( 'check: a reserved address, cleaned first', false === ( $out['ok'] ?? null ) && 'mail' === ( $out['value'] ?? '' ) && str_contains( (string) ( $out['message'] ?? '' ), 'not allowed' ) );
$out = $ask( 'slug', $slugs['a'] );
$check( 'check: a free address, with the whole domain', true === ( $out['ok'] ?? null ) && Pipeline::domain( $slugs['a'] ) === ( $out['address'] ?? '' ) );

$answers = static fn( string $key, array $over = array() ): array => array_merge(
	array(
		'name'  => 'Ada Lovelace',
		'email' => $emails[ $key ],
		'title' => 'Ada\'s Analytical Bakery',
		'slug'   => $slugs[ $key ],
		'nonce'  => $nonce,
		'altcha' => $proof(),
	),
	$over
);
$used = $proof();
$out  = $rest( 'register', $answers( 'a', array( 'email' => 'nope', 'slug' => 'mail', 'altcha' => $used ) ) );
$check( 'register: bad answers, 400 with the fields', 400 === $out['code'] && isset( $out['json']['data']['fields']['email'], $out['json']['data']['fields']['slug'] ) && ! get_user_by( 'email', $emails['a'] ), (string) $out['code'] );
$out = $rest( 'register', $answers( 'b', array( 'altcha' => '' ) ) );
$check( 'register without an antibot solution: 403, nothing created', 403 === $out['code'] && 'mody_altcha_failed' === ( $out['json']['code'] ?? '' ) && ! get_user_by( 'email', $emails['b'] ), (string) $out['code'] );
$out = $rest( 'register', $answers( 'b', array( 'altcha' => $used ) ) );
$check( '... and with a solution used before: 403', 403 === $out['code'] && 'mody_altcha_failed' === ( $out['json']['code'] ?? '' ) && ! get_user_by( 'email', $emails['b'] ), (string) $out['code'] );
$out = $rest( 'register', $answers( 'b', array( 'altcha' => Altcha::solve( Altcha::challenge( 3 ) ) . 'x' ) ) );
$check( '... and with a changed one: 403', 403 === $out['code'] && ! get_user_by( 'email', $emails['b'] ), (string) $out['code'] );

$mails   = array();
$started = microtime( true );
add_filter(
	'pre_wp_mail',
	static function ( $short, array $mail ) use ( &$mails ) {
		$mails[] = $mail;
		return true;
	},
	10,
	2
);
// In-process, so the welcome email can be read here; the REST route is checked with the next account.
$account = Registration::register( 'Ada Lovelace', $emails['a'], 'Ada\'s Analytical Bakery', $slugs['a'] );
$seconds = microtime( true ) - $started;
$user    = get_user_by( 'email', $emails['a'] );
$row     = $user ? Members::get( $user->ID ) : null;
$check( 'register(): the account, in seconds', is_array( $account ) && $user && $seconds < 20, sprintf( '%.1fs', $seconds ) );
$check( '... the user, with their name and no main-site role', $user && 'Ada Lovelace' === $user->display_name && ! ( new WP_User( $user->ID ) )->roles, $user ? $user->user_login : 'no user' );
$until = $row ? (int) strtotime( $row['free_until'] . ' UTC' ) : 0;
$check( '... a place of the launch offer: free for six months, active', $row && 'free' === $row['plan'] && 'active' === $row['status'] && abs( $until - (int) strtotime( '+6 months' ) ) < 300 && $row['free_until'] === $row['billing_due_at'] && 49 === Members::promo_left(), $row ? $row['free_until'] : '' );
$check( '... the onboarding stage: password', $user && Onboarding::STAGE_PASSWORD === get_user_meta( $user->ID, Onboarding::META, true ) );
$site_id = is_array( $account ) ? (int) $account['site_id'] : 0;
$job     = $site_id ? Jobs::get( (int) get_blog_option( $site_id, 'mody_provisioning_job' ) ) : null;
$check( '... the site, at its address, with the member as administrator', $site_id && Pipeline::domain( $slugs['a'] ) === get_site( $site_id )->domain && is_user_member_of_blog( $user->ID, $site_id ) && user_can_for_site( $user->ID, $site_id, 'administrator' ) );
$check( '... the starter site: done, no model call, no site email', $job && 'done' === $job['status'] && ! empty( $job['input']['starter'] ) && '' === $job['input']['prompt'] && 'signup' === ( $job['result']['email'] ?? '' ) && empty( $job['result']['generation_error'] ) );
switch_to_blog( $site_id );
$front = get_post( (int) get_option( 'page_on_front' ) );
$check( '... the site\'s name, an empty tagline, the starter front page', 'Ada\'s Analytical Bakery' === wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) && '' === get_option( 'blogdescription' ) && $front && 'Home' === $front->post_title && 'publish' === $front->post_status && has_block( 'spectra/container', $front ) );
$check( '... hidden from search engines until it has real pages', '0' === (string) get_site( $site_id )->public && '0' === (string) get_option( 'blog_public' ) && ! empty( wp_robots_no_robots( array() )['noindex'] ) );
restore_current_blog();
// Core also tells the network admin about each new site (the network option `registrationnotification`).
$to_member = array_values( array_filter( $mails, static fn( array $mail ): bool => $emails['a'] === $mail['to'] ) );
$welcome   = $to_member[0] ?? array();
$body      = (string) ( $welcome['message'] ?? '' );
$check( 'one email to the member: the welcome', 1 === count( $to_member ) && str_contains( (string) ( $welcome['subject'] ?? '' ), 'choose your password' ), count( $mails ) . ' sent' );
$check( '... with the site, the free months, no card, then the price', str_contains( $body, 'https://' . Pipeline::domain( $slugs['a'] ) . '/' ) && str_contains( $body, 'Your site is free until' ) && str_contains( $body, 'no card is needed until then' ) && str_contains( $body, '7.99 EUR a month, VAT included' ) );
preg_match( '#(https?://\S+wp-login\.php\?action=rp&key=([^&\s]+)&login=([^\s]+))#', $body, $link );
$check( '... and the set-password link, with a valid key', isset( $link[2] ) && $user && rawurldecode( $link[3] ) === $user->user_login && check_password_reset_key( $link[2], $user->user_login ) instanceof WP_User );

$out = $rest( 'register', $answers( 'b' ) );
$settle( $emails['b'] );
$b = get_user_by( 'email', $emails['b'] );
$check( 'register over REST: 201 with the email and the address', 201 === $out['code'] && $emails['b'] === ( $out['json']['email'] ?? '' ) && Pipeline::domain( $slugs['b'] ) === ( $out['json']['address'] ?? '' ) && $b && Members::get( $b->ID ), (string) $out['code'] );
$users_before = count( get_users( array( 'fields' => 'ID' ) ) );
$out          = $rest( 'register', $answers( 'b', array( 'slug' => $slugs['c'] ) ) );
$check( '... the same email again: the same answer, and nothing is created', 201 === $out['code'] && $emails['b'] === ( $out['json']['email'] ?? '' ) && ! $site_exists( $slugs['c'] ) && count( get_users( array( 'fields' => 'ID' ) ) ) === $users_before, (string) $out['code'] );
$out = $rest( 'register', $answers( 'c', array( 'name' => 'Visit http://phish.example now' ) ) );
$check( '... a name with a link is refused, nothing created', 400 === $out['code'] && isset( $out['json']['data']['fields']['name'] ) && ! get_user_by( 'email', $emails['c'] ), (string) $out['code'] );
$out = $rest( 'register', $answers( 'c', array( 'slug' => $slugs['b'] ) ) );
$check( '... and so is a taken address', 400 === $out['code'] && str_contains( (string) ( $out['json']['data']['fields']['slug'] ?? '' ), 'already exists' ) && ! get_user_by( 'email', $emails['c'] ), (string) $out['code'] );

// 4. The form without JavaScript ----------------------------------------------------

echo "\nThe form without JavaScript\n";
$form = static fn( array $over = array() ): array => array_merge(
	array(
		'mody_action' => Registration::ACTION,
		'_mody_nonce' => $nonce,
		'mody_name'   => 'Charles Babbage',
		'mody_email'  => $emails['c'],
		'mody_title'  => 'Difference Engines',
		'mody_slug'   => $slugs['c'],
		'altcha'      => $proof(),
	),
	$over
);
$out = $http( 'POST', $url, array(), $form( array( 'mody_email' => 'not-an-email' ) ) );
$check( 'a bad post: its error, the answers kept, no account', 200 === $out['code'] && str_contains( $out['body'], 'Please enter a valid email address.' ) && str_contains( $out['body'], 'value="Charles Babbage"' ) && str_contains( $out['body'], 'value="' . $slugs['c'] . '"' ) && ! get_user_by( 'email', $emails['c'] ), (string) $out['code'] );
$out = $http( 'POST', $url, array(), $form( array( 'altcha' => '' ) ) );
$check( 'a post without the antibot solution: refused, no account', str_contains( $out['body'], esc_html( Altcha::failed_message() ) ) && ! get_user_by( 'email', $emails['c'] ) );
$out = $http( 'POST', $url, array(), $form( array( '_mody_nonce' => 'deadbeef00' ) ) );
$check( 'an expired nonce is refused', str_contains( $out['body'], 'The form expired.' ) && ! get_user_by( 'email', $emails['c'] ) );
$out = $http( 'POST', $url, array(), $form() );
$settle( $emails['c'] );
$c = get_user_by( 'email', $emails['c'] );
$check( 'a good post: "Done!", the account and the site', 200 === $out['code'] && str_contains( $out['body'], 'Done! I sent an email to ' . $emails['c'] . '.' ) && $c && $site_exists( $slugs['c'] ), (string) $out['code'] );

$cookie    = static function ( int $user_id ): array {
	$expiration = time() + 300;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user_id )->destroy( $token ) );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};
if ( $user ) {
	$out = $http( 'GET', $url, $cookie( $user->ID ) );
	$check( 'a logged-in visitor: "You already have an account."', str_contains( $out['body'], 'You already have an account.' ) && ! str_contains( $out['body'], 'name="mody_email"' ) );
	$out = $http( 'GET', Registration::dashboard_url(), $cookie( $user->ID ) );
	$check( 'the free member\'s dashboard: 1 of 1 site, no New site', 200 === $out['code'] && str_contains( $out['body'], '1 of 1 site used' ) && str_contains( $out['body'], 'The launch offer includes one site.' ), (string) $out['code'] );
	$home = Registration::login_redirect( admin_url(), '', $user );
	$check( 'members log in to their site\'s Mody dashboard (Plan & billing is there)', Registration::member_home( $user->ID ) === $home && str_contains( $home, Pipeline::domain( $slugs['a'] ) ) && str_contains( $home, 'page=mody-dashboard' ), $home );
	$check( '... unless they asked for a page', 'https://example.test/x' === Registration::login_redirect( 'https://example.test/x', 'https://example.test/x', $user ) );
	$check( '... and others keep the default', admin_url() === Registration::login_redirect( admin_url(), '', $super ) );
}

// 5. The launch offer's places taken ----------------------------------------------------

echo "\nThe places taken\n";
update_site_option( Members::PROMO_GIVEN_OPTION, Members::FREE_PLACES );
$other = 'modyrege' . $suffix . '@mody.local';
$out   = $http( 'GET', $url );
if ( Mody\Platform\Billing\Mollie::configured() ) {
	// With a Mollie key (the web server's environment), signing up means paying (billing-test.php covers it).
	$check( 'with Mollie: the chat asks for the first month by card', str_contains( $out['body'], 'pay the first month by card' ) && str_contains( $out['body'], 'name="mody_email"' ) );
} else {
	$check( 'the page says the 50 places are taken, and the price', str_contains( $out['body'], 'The 50 free places of our launch offer are taken.' ) && str_contains( $out['body'], '7.99 EUR a month per site, VAT included' ) && ! str_contains( $out['body'], 'name="mody_email"' ) );
	$out = $rest( 'register', $answers( 'a', array( 'email' => $other ) ) );
	$check( 'the register route refuses (403)', 403 === $out['code'] && 'mody_promo_closed' === ( $out['json']['code'] ?? '' ) && ! get_user_by( 'email', $other ), (string) $out['code'] );
}
update_site_option( Members::PROMO_GIVEN_OPTION, 1 );

// 6. Closed again -------------------------------------------------------------------

echo "\nClosed again\n";
delete_site_option( Registration::OPEN_OPTION );
$other = 'modyregd' . $suffix . '@mody.local';
$out   = $http( 'GET', $url );
$check( 'the page says registration opens soon', str_contains( $out['body'], 'Registration opens soon.' ) && ! str_contains( $out['body'], 'name="mody_email"' ) );
$out = $http( 'POST', $url, array(), $form( array( 'mody_email' => $other ) ) );
$check( 'a good post creates nothing', str_contains( $out['body'], 'Registration is not open yet.' ) && ! get_user_by( 'email', $other ) );
$out = $rest( 'register', $answers( 'a', array( 'email' => $other ) ) );
$check( 'the register route refuses (403)', 403 === $out['code'] && ! get_user_by( 'email', $other ), (string) $out['code'] );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
