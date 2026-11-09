<?php
/**
 * Tests outgoing mail: WP Mail SMTP, the platform's only way of sending email
 * (decided by the maintainer on 2026-11-09, documentation/architecture.md#production).
 *
 * Run from the repository root, locally (SMTP to Mailpit):
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/mail-test.php
 *
 * And with production's mail settings, as the server's compose.prod.yml sets
 * them (Brevo's API; the request is caught here, nothing leaves the machine):
 *
 *   docker exec -i -u www-data -e WORDPRESS_SMTP_MAILER=sendinblue \
 *     -e WORDPRESS_SMTP_BREVO_API_KEY=xkeysib-test -e WORDPRESS_SMTP_FROM=no-reply@mody.cloud \
 *     mody_cloud_app wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/mail-test.php
 *
 * 1. The plugin: required, network-active, hidden from members' Plugins screen.
 * 2. Its settings: the constants from wp-config.php, locked (its screen cannot change them).
 * 3. Who sees it: super admins only; a member site's administrator does not.
 * 4. Rule 1: its notifications feed, usage tracking, summary email, error banner and
 *    setup wizard are off.
 * 5. Sending: locally a wp_mail() reaches Mailpit through WP Mail SMTP, from the one
 *    sender; with production's settings it goes to Brevo's API, with the key, and
 *    nowhere else.
 *
 * A test user (administrator of a member site) is created and removed.
 */

use Mody\Platform\Gating\MemberPlugins;
use Mody\Platform\Mail\WpMailSmtp;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-58s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$production = defined( 'WPMS_MAILER' ) && 'sendinblue' === WPMS_MAILER;
echo $production ? "Production settings (Brevo's API, caught)\n" : "Local settings (SMTP to Mailpit)\n";

// 1. The plugin ----------------------------------------------------------------

echo "\nThe plugin\n";
$composer = json_decode( (string) file_get_contents( dirname( ABSPATH ) . '/composer.json' ), true );
$check( 'required in app/composer.json, pinned', isset( $composer['require']['wpackagist-plugin/wp-mail-smtp'] ) && preg_match( '/^\d+\.\d+\.\d+$/', (string) $composer['require']['wpackagist-plugin/wp-mail-smtp'] ), (string) ( $composer['require']['wpackagist-plugin/wp-mail-smtp'] ?? 'missing' ) );
$check( 'network-active', is_plugin_active_for_network( WpMailSmtp::PLUGIN ), WpMailSmtp::PLUGIN );
$check( 'loaded', function_exists( 'wp_mail_smtp' ) );
$check( 'a platform plugin: never on members\' Plugins screen', in_array( 'wp-mail-smtp', MemberPlugins::PLATFORM_PLUGINS, true ) && MemberPlugins::is_hidden( WpMailSmtp::PLUGIN ) );

// 2. Its settings ------------------------------------------------------------------

echo "\nSettings (constants)\n";
$options = \WPMailSMTP\Options::init();
$check( 'constants on (WPMS_ON): its screen cannot change them', true === WPMS_ON && $options->is_const_enabled() );
foreach ( array( array( 'mail', 'mailer' ), array( 'mail', 'from_email' ), array( 'mail', 'from_email_force' ), array( 'mail', 'from_name' ) ) as $setting ) {
	$check( '...' . implode( '.', $setting ) . ' locked', $options->is_const_defined( $setting[0], $setting[1] ), (string) wp_json_encode( $options->get( $setting[0], $setting[1] ) ) );
}
$check( '...one sender for every message', true === (bool) $options->get( 'mail', 'from_email_force' ) && WPMS_MAIL_FROM === $options->get( 'mail', 'from_email' ) && is_email( WPMS_MAIL_FROM ), WPMS_MAIL_FROM );
if ( $production ) {
	$check( 'mailer: Brevo\'s API, with the key from the environment', 'sendinblue' === $options->get( 'mail', 'mailer' ) && '' !== (string) $options->get( 'sendinblue', 'api_key' ) && $options->is_const_defined( 'sendinblue', 'api_key' ) );
} else {
	$check( 'mailer: SMTP to Mailpit, locked', 'smtp' === $options->get( 'mail', 'mailer' ) && 'mailpit' === $options->get( 'smtp', 'host' ) && 1025 === (int) $options->get( 'smtp', 'port' ) && $options->is_const_defined( 'smtp', 'host' ), $options->get( 'smtp', 'host' ) . ':' . $options->get( 'smtp', 'port' ) );
	$check( '...no Brevo key outside production', '' === (string) $options->get( 'sendinblue', 'api_key' ) );
}

// 3. Who sees it -------------------------------------------------------------------

echo "\nWho sees it\n";
$sites  = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
$site   = (int) ( $sites[0] ?? 0 );
$member = wp_insert_user(
	array(
		'user_login' => 'modymail' . strtolower( wp_generate_password( 6, false ) ),
		'user_email' => 'modymail' . wp_generate_password( 6, false ) . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
$admin  = get_user_by( 'login', get_super_admins()[0] )->ID;
register_shutdown_function(
	static function () use ( $member ): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		if ( is_int( $member ) ) {
			wpmu_delete_user( $member );
		}
	}
);
$cap = wp_mail_smtp()->get_capability_manage_options();
$check( 'its capability: super admins only', WpMailSmtp::CAPABILITY === $cap && WpMailSmtp::CAPABILITY === wp_mail_smtp()->get_capability_manage_options(), $cap );
if ( $site && is_int( $member ) ) {
	add_user_to_blog( $site, $member, 'administrator' );
	switch_to_blog( $site );
	$check( 'a member site\'s administrator: no screens, test tool or notices', user_can( $member, 'manage_options' ) && ! user_can( $member, $cap ) );
	$check( '...a super admin: all of them', user_can( $admin, $cap ) );
	restore_current_blog();
}

// 4. Rule 1 --------------------------------------------------------------------

echo "\nRule 1: nothing to its maker on its own\n";
$check( 'notifications feed off', false === apply_filters( 'wp_mail_smtp_admin_notifications_has_access', true ) );
$check( 'usage tracking off', false === apply_filters( 'wp_mail_smtp_usage_tracking_load_allowed', true ) );
$check( 'weekly summary email off', true === apply_filters( 'wp_mail_smtp_reports_emails_summary_is_disabled', false ) && true === WPMS_SUMMARY_REPORT_EMAIL_DISABLED );
$check( 'error banner (help links from wpmailsmtpapi.com) off', false === apply_filters( 'wp_mail_smtp_admin_is_error_delivery_notice_enabled', true ) );
$check( 'setup wizard off (the constants set everything)', false === apply_filters( 'wp_mail_smtp_admin_setup_wizard_load_wizard', true ) );

// 5. Sending ---------------------------------------------------------------------

echo "\nSending\n";
$subject  = 'Mody mail test ' . wp_generate_password( 10, false );
$to       = 'mail-test@example.org';
$requests = array();
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$requests ) {
		$requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( str_starts_with( $url, 'https://api.brevo.com/' ) ) {
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => '{"messageId":"<test@smtp-relay.mailin.fr>"}',
				'response' => array(
					'code'    => 201,
					'message' => 'Created',
				),
				'cookies'  => array(),
			);
		}
		return $preempt;
	},
	10,
	3
);
$sent = wp_mail( $to, $subject, "Sent by scripts/mail-test.php.\n" );
$check( 'wp_mail() succeeds', true === $sent );
$check( '...through WP Mail SMTP', $GLOBALS['phpmailer'] instanceof \WPMailSMTP\MailCatcherInterface, is_object( $GLOBALS['phpmailer'] ) ? get_class( $GLOBALS['phpmailer'] ) : 'none' );

if ( $production ) {
	$brevo = array_values( array_filter( $requests, static fn( $request ) => str_starts_with( $request['url'], 'https://api.brevo.com/' ) ) );
	$body  = json_decode( (string) ( $brevo[0]['args']['body'] ?? '' ), true );
	$check( 'one request to Brevo\'s API, nothing else', 1 === count( $brevo ) && 1 === count( $requests ) && 'https://api.brevo.com/v3/smtp/email' === $brevo[0]['url'], count( $requests ) . ' request(s): ' . implode( ', ', array_column( $requests, 'url' ) ) );
	$check( '...with the key from the environment', WPMS_SENDINBLUE_API_KEY === ( $brevo[0]['args']['headers']['api-key'] ?? '' ) );
	$check( '...from the one sender, to the recipient', WPMS_MAIL_FROM === ( $body['sender']['email'] ?? '' ) && $to === ( $body['to'][0]['email'] ?? '' ) && $subject === ( $body['subject'] ?? '' ), wp_json_encode( $body['sender'] ?? null ) );
} else {
	$check( '...over SMTP to Mailpit', 'smtp' === $GLOBALS['phpmailer']->Mailer && 'mailpit' === $GLOBALS['phpmailer']->Host, $GLOBALS['phpmailer']->Mailer . ' ' . $GLOBALS['phpmailer']->Host );
	$check( '...no web request (no API, nothing to its maker)', ! $requests, implode( ', ', array_column( $requests, 'url' ) ) );
	$found = array();
	for ( $i = 0; $i < 10 && ! $found; $i++ ) {
		$response = wp_remote_get( 'http://mailpit:8025/api/v1/search?query=' . rawurlencode( 'subject:"' . $subject . '"' ) );
		$found    = json_decode( (string) wp_remote_retrieve_body( $response ), true )['messages'] ?? array();
		if ( ! $found ) {
			usleep( 300000 );
		}
	}
	$message = $found[0] ?? array();
	$check( 'Mailpit received it', 1 === count( $found ) && $to === ( $message['To'][0]['Address'] ?? '' ), (string) ( $message['ID'] ?? 'not found' ) );
	$check( '...from the one sender', WPMS_MAIL_FROM === ( $message['From']['Address'] ?? '' ) && WPMS_MAIL_FROM_NAME === ( $message['From']['Name'] ?? '' ), ( $message['From']['Name'] ?? '' ) . ' <' . ( $message['From']['Address'] ?? '' ) . '>' );
	if ( ! empty( $message['ID'] ) ) {
		wp_remote_request( 'http://mailpit:8025/api/v1/messages', array( 'method' => 'DELETE', 'body' => wp_json_encode( array( 'IDs' => array( $message['ID'] ) ) ), 'headers' => array( 'Content-Type' => 'application/json' ) ) );
	}
}

echo "\n" . ( $failures ? "$failures check(s) failed.\n" : "All checks passed.\n" );
