<?php
/**
 * Tests ALTCHA on the public forms (tickets/antibot-altcha.md). The signup
 * chat's checks are in scripts/registration-test.php.
 *
 * Run from the repository root, after `pnpm build` (the widget's script):
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/antibot-test.php
 *
 * 1. Challenges (in-process): signed, solved, used once; a changed,
 *    expired, foreign or unsolved one fails. The route hands them out,
 *    never cached, on every site. The script is built.
 * 2. Log in (over HTTP, the main site and alpha): the widget on the form;
 *    without a solution, a wrong one or a used one the right password is
 *    refused; with one it logs in. Logging in outside wp-login.php
 *    (wp_authenticate(), as application passwords do) is left alone.
 * 3. Lost password: the widget; refused without a solution, sent with one.
 *    wp-admin's "Send password reset" (retrieve_password()) is left alone.
 * 4. Comments on alpha: the widget in comment_form() for visitors, not for
 *    users (the theme shows no comment form yet, but wp-comments-post.php
 *    takes posts anyway); a visitor's comment without a solution is refused
 *    (403), with one it is saved, though core checks a comment twice.
 *    Comments added by code are left alone.
 * 5. Antispam Bee (a MU plugin on every site): loaded; its three checks
 *    that call outside services stay off whatever is saved, and are hidden
 *    on its screen; it flags BBCode links as spam and lets a plain comment
 *    through. The visitor's comment in 4 goes through its renamed field.
 *
 * Everything created is removed at the end.
 */

use Mody\Platform\Security\Altcha;
use Mody\Platform\Security\AntispamBee;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-66s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

if ( ! is_main_site() ) {
	echo "Run this on the main site (--url=https://<main host>).\n";
	return;
}
$alpha = get_blog_id_from_url( 'alpha.' . get_network()->domain );
if ( ! $alpha ) {
	echo "This test needs the member site alpha.\n";
	return;
}

$suffix   = strtolower( wp_generate_password( 6, false ) );
$password = wp_generate_password( 24, false );
$user_id  = wpmu_create_user( 'antibot' . $suffix, $password, 'antibot' . $suffix . '@mody.local' );
$post_id  = 0;
register_shutdown_function(
	static function () use ( &$user_id, &$post_id, $alpha ): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		if ( $post_id ) {
			switch_to_blog( $alpha );
			wp_delete_post( $post_id, true ); // Its comments too.
			restore_current_blog();
		}
		if ( $user_id ) {
			wpmu_delete_user( $user_id );
		}
	}
);

// alpha.mody.local resolves to the app container itself; send its requests to
// the web server, which answers for mody.local.
$web = gethostbyname( 'mody.local' );
add_action(
	'http_api_curl',
	static function ( $handle, $args, $url ) use ( $web ): void {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( str_ends_with( $host, '.mody.local' ) ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':443:' . $web ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		}
	},
	10,
	3
);
$http = static function ( string $method, string $to, array $body = array(), array $cookies = array() ): array {
	$response = wp_remote_request(
		$to,
		array(
			'method'      => $method,
			'body'        => $body,
			'cookies'     => $cookies,
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 30,
		)
	);
	$names = array();
	foreach ( (array) wp_remote_retrieve_cookies( $response ) as $cookie ) {
		$names[] = $cookie->name;
	}
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'cookies'  => $names,
		'headers'  => wp_remote_retrieve_headers( $response ),
	);
};
$proof     = static fn(): string => Altcha::solve( Altcha::challenge( 3 ) );
$logged_in = static fn( array $out ): bool => (bool) array_filter( $out['cookies'], static fn( $name ) => str_starts_with( $name, 'wordpress_logged_in_' ) );
$failed    = esc_html( Altcha::failed_message() );

// 1. Challenges ---------------------------------------------------------------------

echo "Challenges\n";
$challenge = Altcha::challenge();
$params    = $challenge['parameters'];
$check( 'a challenge: PBKDF2/SHA-256, a 16-byte key prefix, signed, 30 minutes', Altcha::ALGORITHM === $params['algorithm'] && Altcha::COST === $params['cost'] && 32 === strlen( (string) $params['keyPrefix'] ) && 64 === strlen( $challenge['signature'] ) && abs( $params['expiresAt'] - time() - Altcha::LIFETIME ) < 5 );
$check( '... and no secret counter in it', ! isset( $params['counter'] ) && ! isset( $params['number'] ) );
$payload = $proof();
$check( 'a solution is accepted once', Altcha::verify( $payload ) && ! Altcha::verify( $payload ) );
$tamper = static function ( callable $change ): string {
	$data = json_decode( base64_decode( Altcha::solve( Altcha::challenge( 3 ) ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$change( $data );
	return base64_encode( (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
};
$check( 'a wrong key fails', ! Altcha::verify( $tamper( static function ( &$d ) { $d['solution']['derivedKey'] = str_repeat( 'ab', 32 ); } ) ) );
$check( 'a changed challenge fails (a cheaper cost)', ! Altcha::verify( $tamper( static function ( &$d ) { $d['challenge']['parameters']['cost'] = 1; } ) ) );
$check( 'a longer expiry fails', ! Altcha::verify( $tamper( static function ( &$d ) { $d['challenge']['parameters']['expiresAt'] += DAY_IN_SECONDS; } ) ) );
$check( 'a foreign signature fails', ! Altcha::verify( $tamper( static function ( &$d ) { $d['challenge']['signature'] = hash_hmac( 'sha256', 'x', 'someone else' ); } ) ) );
$check( 'garbage fails', ! Altcha::verify( '' ) && ! Altcha::verify( 'bm90IGpzb24=' ) && ! Altcha::verify( array( 'x' ) ) && ! Altcha::verify( str_repeat( 'A', 5000 ) ) );
$check( 'an unsolved challenge fails', ! Altcha::verify( base64_encode( (string) wp_json_encode( array( 'challenge' => Altcha::challenge(), 'solution' => array( 'counter' => 0, 'derivedKey' => str_repeat( '00', 32 ) ) ) ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
$started = microtime( true );
$solved  = Altcha::solve( Altcha::challenge( 150 ) );
$elapsed = microtime( true ) - $started;
$check( 'the work is real: 150 attempts take PHP measurable time', '' !== $solved && $elapsed > 0.05, sprintf( '%.2fs, about %.1f ms an attempt', $elapsed, $elapsed / 151 * 1000 ) );

foreach ( array( 'main' => network_home_url( '/' ), 'alpha' => get_home_url( $alpha, '/' ) ) as $name => $home ) {
	$out  = $http( 'GET', $home . 'wp-json/mody-platform/v1/altcha' );
	$json = json_decode( $out['body'], true );
	$check( "the route on $name: a fresh challenge, never cached", 200 === $out['code'] && isset( $json['parameters']['keyPrefix'], $json['signature'] ) && str_contains( (string) $out['headers']['cache-control'], 'no-store' ), (string) $out['code'] );
}
$check( 'the widget\'s script is built (pnpm build)', is_readable( WP_CONTENT_DIR . '/../dist/altcha.js' ) );
$out = $http( 'GET', network_home_url( '/dist/altcha.js' ) );
$check( '... and served', 200 === $out['code'] && str_contains( $out['body'], 'altcha-widget' ), (string) $out['code'] );

// 2. Log in -------------------------------------------------------------------------

echo "\nLog in\n";
$user  = get_userdata( $user_id );
$login = static fn( string $site, string $altcha ) => $http(
	'POST',
	$site . 'wp-login.php',
	array(
		'log'        => $user->user_login,
		'pwd'        => $password,
		'testcookie' => '1',
		'altcha'     => $altcha,
	),
	array( 'wordpress_test_cookie' => 'WP Cookie check' )
);
foreach ( array( 'main' => network_site_url( '/' ), 'alpha' => get_home_url( $alpha, '/' ) ) as $name => $site ) {
	$out = $http( 'GET', $site . 'wp-login.php' );
	$check( "$name: the login form has the widget and its script", str_contains( $out['body'], '<altcha-widget class="mody-altcha" name="altcha"' ) && str_contains( $out['body'], '/dist/altcha.js' ) && str_contains( $out['body'], 'wp-json/mody-platform/v1/altcha' ), (string) $out['code'] );
	$out = $login( $site, '' );
	$check( "$name: the right password without a solution is refused", ! $logged_in( $out ) && str_contains( $out['body'], $failed ), (string) $out['code'] );
	$used = $proof();
	$out  = $login( $site, $used );
	$check( "$name: ... and with one, it logs in", $logged_in( $out ) && 302 === $out['code'], (string) $out['code'] );
	$out = $login( $site, $used );
	$check( "$name: ... and the same solution again is refused", ! $logged_in( $out ) && str_contains( $out['body'], $failed ) );
}
$check( 'logging in outside the login page (wp_authenticate()) is left alone', wp_authenticate( $user->user_login, $password ) instanceof WP_User );

// 3. Lost password ------------------------------------------------------------------

echo "\nLost password\n";
$lost = network_site_url( 'wp-login.php?action=lostpassword' );
$out  = $http( 'GET', $lost );
$check( 'the form has the widget', str_contains( $out['body'], '<altcha-widget class="mody-altcha"' ), (string) $out['code'] );
$out = $http( 'POST', $lost, array( 'user_login' => $user->user_login ) );
$check( 'without a solution: refused, no email', 200 === $out['code'] && str_contains( $out['body'], $failed ) && ! str_contains( $out['location'], 'checkemail=confirm' ), (string) $out['code'] );
$out = $http(
	'POST',
	$lost,
	array(
		'user_login' => $user->user_login,
		'altcha'     => $proof(),
	)
);
$check( 'with one: the email is sent', 302 === $out['code'] && str_contains( $out['location'], 'checkemail=confirm' ), $out['location'] );
add_filter( 'pre_wp_mail', '__return_true' );
$check( 'retrieve_password() (wp-admin\'s "Send password reset") is left alone', true === retrieve_password( $user->user_login ) );
remove_filter( 'pre_wp_mail', '__return_true' );

// 4. Comments -----------------------------------------------------------------------

echo "\nComments (alpha)\n";
switch_to_blog( $alpha );
$post_id   = (int) wp_insert_post(
	array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'post_title'     => 'Antibot test ' . $suffix,
		'post_content'   => '<!-- wp:paragraph --><p>Comments welcome.</p><!-- /wp:paragraph -->',
		'comment_status' => 'open',
	)
);
$post_url  = get_site_url( $alpha, '/wp-comments-post.php' );
$form      = static function () use ( &$post_id ): string {
	ob_start();
	comment_form( array(), $post_id );
	return (string) ob_get_clean();
};
wp_set_current_user( 0 );
$visitor = $form();
wp_set_current_user( $user_id );
$member = $form();
wp_set_current_user( 0 );
restore_current_blog();
$check( 'comment_form(): the widget for visitors, not for logged-in users', str_contains( $visitor, '<altcha-widget class="mody-altcha"' ) && str_contains( $visitor, 'id="commentform"' ) && str_contains( $member, 'id="commentform"' ) && ! str_contains( $member, 'altcha-widget' ) );
// Antispam Bee renames the comment field; `comment` itself is its honeypot.
switch_to_blog( $alpha );
Antispam_Bee::get_options(); // Sets up its salt.
$field = Antispam_Bee::get_secret_name_for_post( $post_id );
restore_current_blog();
$check( '... with Antispam Bee\'s renamed comment field', str_contains( $visitor, 'name="' . $field . '"' ), $field );
$comment = static fn( string $altcha, string $text ) => $http(
	'POST',
	$post_url,
	array(
		'comment_post_ID' => $post_id,
		'author'          => 'Visitor ' . $suffix,
		'email'           => 'visitor' . $suffix . '@example.org',
		$field            => $text,
		'comment'         => '',
		'altcha'          => $altcha,
	)
);
// From the database: comments saved by another request are not in this process's cache.
$count = static function () use ( $alpha, &$post_id ): int {
	global $wpdb;
	switch_to_blog( $alpha );
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	restore_current_blog();
	return $n;
};
$out = $comment( '', 'A comment without a solution ' . $suffix );
$check( 'a visitor\'s comment without a solution: 403, not saved', 403 === $out['code'] && str_contains( $out['body'], $failed ) && 0 === $count(), (string) $out['code'] );
$out = $comment( $proof(), 'A comment with a solution ' . $suffix );
$status = static function () use ( $alpha, &$post_id ): string {
	global $wpdb;
	switch_to_blog( $alpha );
	$value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT comment_approved FROM {$wpdb->comments} WHERE comment_post_ID = %d ORDER BY comment_ID DESC LIMIT 1", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	restore_current_blog();
	return $value;
};
$check( '... and with one: saved, not spam', 302 === $out['code'] && 1 === $count() && 'spam' !== $status(), $out['code'] . ' ' . $count() . ' ' . $status() . ' ' . $out['location'] );
switch_to_blog( $alpha );
$by_code = wp_new_comment(
	array(
		'comment_post_ID'      => $post_id,
		'comment_author'       => 'Code',
		'comment_author_email' => 'code@example.org',
		'comment_author_url'   => '',
		'comment_content'      => 'Added by code ' . $suffix,
	),
	true
);
restore_current_blog();
$check( 'comments added by code are left alone', is_int( $by_code ) && 2 === $count(), is_wp_error( $by_code ) ? $by_code->get_error_message() : '' );

// 5. Antispam Bee --------------------------------------------------------------------

echo "\nAntispam Bee (alpha)\n";
switch_to_blog( $alpha );
$check( 'loaded as a MU plugin', class_exists( 'Antispam_Bee' ) && is_readable( WPMU_PLUGIN_DIR . '/antispam-bee/antispam_bee.php' ) );
$saved = get_option( AntispamBee::OPTION, null );
update_option( AntispamBee::OPTION, array( 'gravatar_check' => 1, 'country_code' => 1, 'translate_api' => 1, 'bbcode_check' => 1 ) );
wp_cache_delete( 'antispam_bee' ); // Its own copy of the option.
$options = Antispam_Bee::get_options();
global $wpdb;
$stored = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", AntispamBee::OPTION ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$check( 'the checks that call outside services stay off, even when saved on', 0 === $options['gravatar_check'] && 0 === $options['country_code'] && 0 === $options['translate_api'] && 1 === $options['bbcode_check'] );
$check( '... and are stored off', is_array( $stored ) && 0 === $stored['gravatar_check'] && 0 === $stored['country_code'] && 0 === $stored['translate_api'] );
null === $saved ? delete_option( AntispamBee::OPTION ) : update_option( AntispamBee::OPTION, $saved );
wp_cache_delete( 'antispam_bee' );
$check( 'without saved settings: no output buffering (its activation default)', null !== $saved || 0 === Antispam_Bee::get_option( 'use_output_buffer' ) );
$check( '... and its screen hides the three checks', (bool) has_action( 'admin_head-settings_page_antispam_bee', array( AntispamBee::class, 'hide_settings' ) ) );
$outside = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$outside ) {
		$outside[] = $url;
		return new WP_Error( 'blocked', 'No outside calls in this test.' );
	},
	10,
	3
);
$verify = new ReflectionMethod( 'Antispam_Bee', '_verify_comment_request' );
$base   = array(
	'comment_author'       => 'Visitor',
	'comment_author_email' => 'visitor' . $suffix . '@example.org',
	'comment_author_url'   => '',
	'comment_author_IP'    => '203.0.113.7',
	'comment_agent'        => 'Mozilla/5.0',
	'comment_post_ID'      => $post_id,
);
$spam = $verify->invoke( null, $base + array( 'comment_content' => 'Cheap pills [url=https://spam.example]here[/url]' ) );
$ham  = $verify->invoke( null, $base + array( 'comment_content' => 'Thank you for this post, it helped me a lot with my garden.' ) );
restore_current_blog();
$check( 'it flags a BBCode link as spam', 'bbcode' === ( $spam['reason'] ?? '' ), wp_json_encode( $spam ) );
$check( '... and lets a plain comment through, without calling anything outside', empty( $ham['reason'] ) && array() === $outside, wp_json_encode( $ham ) . ' ' . implode( ', ', $outside ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
