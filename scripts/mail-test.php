<?php
/**
 * Tests outgoing mail: the mody-mail MU plugin, the platform's only way of
 * sending email (decided by the maintainer on 2026-11-10, replacing WP Mail
 * SMTP; documentation/architecture.md#production).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/mail-test.php
 *
 * 1. The plugin: a MU plugin, loaded; WP Mail SMTP is gone (files, Composer,
 *    network activation, constants).
 * 2. Its settings: MAIL_SENDER_EMAIL, MAIL_SENDER_NAME and BREVO_API_KEY from
 *    the environment, with their defaults.
 * 3. The sender: one address for every message; the name replaces only
 *    WordPress's default.
 * 4. Without a key (as locally): wp_mail() uses PHP's mail(), and Mailpit
 *    receives the message from the sender, with no web request.
 * 5. With a key (as in production; a test key is set in this process and
 *    Brevo's answers are faked, so nothing leaves the machine): one request to
 *    Brevo's API per message, with the key, the sender, every recipient, the
 *    body, headers and attachments; Brevo's refusals and network errors make
 *    wp_mail() fail with wp_mail_failed, never showing the key.
 *
 * Environment variables changed here (putenv) are restored at the end.
 */

use Mody\Mail\BrevoMailer;
use Mody\Mail\Config;
use Mody\Mail\Mail;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-62s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

// Environment variables this test changes, restored when it ends.
$saved_env = array();
foreach ( array( 'BREVO_API_KEY', 'MAIL_SENDER_EMAIL', 'MAIL_SENDER_NAME' ) as $name ) {
	$saved_env[ $name ] = getenv( $name );
}
$set_env = static function ( string $name, $value ): void {
	putenv( false === $value ? $name : "$name=$value" );
};
register_shutdown_function(
	static function () use ( $saved_env, $set_env ): void {
		foreach ( $saved_env as $name => $value ) {
			$set_env( $name, $value );
		}
	}
);

// 1. The plugin ----------------------------------------------------------------

echo "The plugin\n";
$check( 'a MU plugin (always on, nobody can switch it off)', isset( get_mu_plugins()['mody-mail.php'] ) && class_exists( Mail::class ) );
$check( '...hooked into wp_mail()', PHP_INT_MAX === has_filter( 'pre_wp_mail', array( Mail::class, 'use_transport' ) ) && PHP_INT_MAX === has_filter( 'wp_mail_from', array( Mail::class, 'from_email' ) ) && PHP_INT_MAX === has_filter( 'wp_mail_from_name', array( Mail::class, 'from_name' ) ) );
$composer = json_decode( (string) file_get_contents( dirname( ABSPATH ) . '/composer.json' ), true );
$mail_plugins = array_filter( array_keys( $composer['require'] ?? array() ), static fn( $name ) => str_contains( $name, 'mail-smtp' ) );
$check( 'WP Mail SMTP gone: not in app/composer.json', ! $mail_plugins, implode( ', ', $mail_plugins ) );
$check( '...not installed, not loaded', ! is_dir( WP_PLUGIN_DIR . '/wp-mail-smtp' ) && ! is_dir( WP_PLUGIN_DIR . '/wp-mail-smtp-pro' ) && ! function_exists( 'wp_mail_smtp' ) );
$active = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
$check( '...not network-active', ! array_filter( $active, static fn( $file ) => str_starts_with( $file, 'wp-mail-smtp' ) ), implode( ', ', $active ) );
$check( '...none of its WPMS_* constants', ! array_filter( array_keys( get_defined_constants( true )['user'] ?? array() ), static fn( $name ) => str_starts_with( $name, 'WPMS_' ) ) );

// 2. Its settings ----------------------------------------------------------------

echo "\nSettings (environment)\n";
$set_env( 'MAIL_SENDER_EMAIL', 'hello@mody.test' );
$set_env( 'MAIL_SENDER_NAME', "Mody\r\nBcc: x@example.org" );
$check( 'MAIL_SENDER_EMAIL read', 'hello@mody.test' === Config::sender_email(), Config::sender_email() );
$check( 'MAIL_SENDER_NAME read, on one line (no header injection)', 'Mody Bcc: x@example.org' === Config::sender_name(), Config::sender_name() );
$set_env( 'MAIL_SENDER_EMAIL', 'not an address' );
$set_env( 'MAIL_SENDER_NAME', '' );
$check( 'defaults: no-reply@ the network domain, "Mody Cloud"', 'no-reply@' . DOMAIN_CURRENT_SITE === Config::sender_email() && 'Mody Cloud' === Config::sender_name(), Config::sender_email() . ', ' . Config::sender_name() );
$set_env( 'MAIL_SENDER_EMAIL', 'no-reply@mody.test' );
$set_env( 'MAIL_SENDER_NAME', 'Mody Test' );
$set_env( 'BREVO_API_KEY', '' );
$check( 'no key: WordPress\'s own transport (here: ' . wp_get_environment_type() . ')', Config::TRANSPORT_PHP === Config::transport() || 'production' === wp_get_environment_type(), Config::transport() );
$set_env( 'BREVO_API_KEY', 'xkeysib-test' );
$check( 'a key: Brevo', Config::TRANSPORT_BREVO === Config::transport() );
$set_env( 'BREVO_API_KEY', '' );

// 3. The sender ------------------------------------------------------------------

echo "\nThe sender\n";
$check( 'the address, whatever the message says', 'no-reply@mody.test' === apply_filters( 'wp_mail_from', 'someone@example.org' ) );
$check( 'the name, instead of WordPress\'s default', 'Mody Test' === apply_filters( 'wp_mail_from_name', 'WordPress' ) && 'Mody Test' === apply_filters( 'wp_mail_from_name', '' ) );
$check( '...a message\'s own name kept', 'Wordfence' === apply_filters( 'wp_mail_from_name', 'Wordfence' ) );

// HTTP requests from here on: Brevo's answer is faked (or the request is refused).
$requests      = array();
$brevo_answer  = null;
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$requests, &$brevo_answer ) {
		if ( str_starts_with( $url, 'http://mailpit:8025/' ) ) {
			return $preempt; // The test's own look into Mailpit.
		}
		$requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return str_starts_with( $url, 'https://api.brevo.com/' ) && null !== $brevo_answer ? $brevo_answer : new WP_Error( 'mail_test', 'Refused by mail-test.php.' );
	},
	10,
	3
);
$failed = array();
add_action(
	'wp_mail_failed',
	static function ( WP_Error $error ) use ( &$failed ): void {
		$failed[] = $error->get_error_message();
	}
);
$brevo_says = static function ( int $code, array $body ): array {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => (string) wp_json_encode( $body ),
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
	);
};

// 4. Without a key ----------------------------------------------------------------

echo "\nWithout a key (WordPress's own transport, Mailpit locally)\n";
if ( 'production' === wp_get_environment_type() ) {
	$check( 'skipped: production', true );
} else {
	$subject = 'Mody mail test ' . wp_generate_password( 10, false );
	$to      = 'mail-test@example.org';
	$sent    = wp_mail( $to, $subject, "Sent by scripts/mail-test.php.\n" );
	$check( 'wp_mail() succeeds', true === $sent, implode( ' | ', $failed ) );
	$check( '...through PHP\'s mail(), not Brevo', ! ( $GLOBALS['phpmailer'] instanceof BrevoMailer ) && 'mail' === $GLOBALS['phpmailer']->Mailer, get_class( $GLOBALS['phpmailer'] ) . ' ' . $GLOBALS['phpmailer']->Mailer );
	$check( '...no web request', ! $requests, implode( ', ', array_column( $requests, 'url' ) ) );
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
	$check( '...from the sender', 'no-reply@mody.test' === ( $message['From']['Address'] ?? '' ) && 'Mody Test' === ( $message['From']['Name'] ?? '' ), ( $message['From']['Name'] ?? '' ) . ' <' . ( $message['From']['Address'] ?? '' ) . '>' );
	if ( ! empty( $message['ID'] ) ) {
		wp_remote_request(
			'http://mailpit:8025/api/v1/messages',
			array(
				'method'  => 'DELETE',
				'body'    => wp_json_encode( array( 'IDs' => array( $message['ID'] ) ) ),
				'headers' => array( 'Content-Type' => 'application/json' ),
			)
		);
	}
}

// 5. With a key ---------------------------------------------------------------------

echo "\nWith a key (Brevo's API, answers faked)\n";
$set_env( 'BREVO_API_KEY', 'xkeysib-test-123' );
$requests     = array();
$failed       = array();
$succeeded    = 0;
$brevo_answer = $brevo_says( 201, array( 'messageId' => '<test-1@smtp-relay.mailin.fr>' ) );
add_action(
	'wp_mail_succeeded',
	static function () use ( &$succeeded ): void {
		++$succeeded;
	}
);
$file = wp_tempnam( 'mody-mail-test.txt' );
file_put_contents( $file, "attached\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$sent = wp_mail(
	array( 'Ada Lovelace <ada@example.org>', 'grace@example.org' ),
	'Plain test',
	"Line one.\nLine two.\n",
	array( 'From: Someone <someone@example.org>', 'Cc: cc@example.org', 'Bcc: bcc@example.org', 'Reply-To: Support <support@example.org>', 'X-Mody-Test: yes' ),
	array( 'notes.txt' => $file )
);
wp_delete_file( $file );
$brevo = $requests[0] ?? array();
$body  = json_decode( (string) ( $brevo['args']['body'] ?? '' ), true );
$check( 'wp_mail() succeeds, wp_mail_succeeded fired', true === $sent && 1 === $succeeded, implode( ' | ', $failed ) );
$check( '...through a BrevoMailer, Brevo\'s message id kept', $GLOBALS['phpmailer'] instanceof BrevoMailer && '<test-1@smtp-relay.mailin.fr>' === $GLOBALS['phpmailer']->brevo_message_id );
$check( 'one request, a POST to Brevo\'s API, nothing else', 1 === count( $requests ) && BrevoMailer::ENDPOINT === ( $brevo['url'] ?? '' ) && 'POST' === ( $brevo['args']['method'] ?? '' ), implode( ', ', array_column( $requests, 'url' ) ) );
$check( '...with the key, as JSON', 'xkeysib-test-123' === ( $brevo['args']['headers']['api-key'] ?? '' ) && 'application/json' === ( $brevo['args']['headers']['content-type'] ?? '' ) && is_array( $body ) );
$check( '...from the sender (the message\'s From ignored, its name kept)', array( 'email' => 'no-reply@mody.test', 'name' => 'Someone' ) === ( $body['sender'] ?? null ), (string) wp_json_encode( $body['sender'] ?? null ) );
$check( '...To, with names', array( array( 'email' => 'ada@example.org', 'name' => 'Ada Lovelace' ), array( 'email' => 'grace@example.org' ) ) === ( $body['to'] ?? null ), (string) wp_json_encode( $body['to'] ?? null ) );
$check( '...Cc and Bcc', array( array( 'email' => 'cc@example.org' ) ) === ( $body['cc'] ?? null ) && array( array( 'email' => 'bcc@example.org' ) ) === ( $body['bcc'] ?? null ) );
$check( '...Reply-To', array( 'email' => 'support@example.org', 'name' => 'Support' ) === ( $body['replyTo'] ?? null ) );
$check( '...subject, and the text as textContent only', 'Plain test' === ( $body['subject'] ?? '' ) && "Line one.\nLine two.\n" === ( $body['textContent'] ?? '' ) && ! isset( $body['htmlContent'] ) );
$check( '...custom header', 'yes' === ( $body['headers']['X-Mody-Test'] ?? '' ), (string) wp_json_encode( $body['headers'] ?? null ) );
$check( '...attachment, base64', array( array( 'name' => 'notes.txt', 'content' => base64_encode( "attached\n" ) ) ) === ( $body['attachment'] ?? null ), (string) wp_json_encode( $body['attachment'] ?? null ) );

$requests = array();
$sent     = wp_mail( 'ada@example.org', 'HTML test', '<p>Hello</p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
$body     = json_decode( (string) ( $requests[0]['args']['body'] ?? '' ), true );
$check( 'an HTML message: htmlContent, no textContent', true === $sent && '<p>Hello</p>' === ( $body['htmlContent'] ?? '' ) && ! isset( $body['textContent'] ) && ! isset( $body['cc'], $body['replyTo'], $body['attachment'] ), (string) wp_json_encode( array_keys( (array) $body ) ) );
$check( '...WordPress\'s default name replaced', array( 'email' => 'no-reply@mody.test', 'name' => 'Mody Test' ) === ( $body['sender'] ?? null ) );

$requests = array();
$sent     = wp_mail( '', 'Bcc only', 'Hidden.', array( 'Bcc: bcc@example.org' ) );
$body     = json_decode( (string) ( $requests[0]['args']['body'] ?? '' ), true );
$check( 'Bcc only: the sender is the To (Brevo needs one)', true === $sent && array( array( 'email' => 'no-reply@mody.test', 'name' => 'Mody Test' ) ) === ( $body['to'] ?? null ) && array( array( 'email' => 'bcc@example.org' ) ) === ( $body['bcc'] ?? null ) );

$requests = array();
$sent     = wp_mail( array( 'ada@example.org', 'x@bücher.de' ), 'IDN test', 'Hello.', array( 'Reply-To: help@bücher.de' ) );
$body     = json_decode( (string) ( $requests[0]['args']['body'] ?? '' ), true );
$check( 'an international domain: punycoded, not dropped (To, Reply-To)', true === $sent && array( array( 'email' => 'ada@example.org' ), array( 'email' => 'x@xn--bcher-kva.de' ) ) === ( $body['to'] ?? null ) && array( 'email' => 'help@xn--bcher-kva.de' ) === ( $body['replyTo'] ?? null ), (string) wp_json_encode( array( $body['to'] ?? null, $body['replyTo'] ?? null ) ) );

$requests = array();
$html     = static fn() => 'text/html; charset=UTF-8';
add_filter( 'wp_mail_content_type', $html );
$sent = wp_mail( 'ada@example.org', 'HTML by filter', '<p>Hi</p>' );
remove_filter( 'wp_mail_content_type', $html );
$body = json_decode( (string) ( $requests[0]['args']['body'] ?? '' ), true );
$check( '"text/html; charset=UTF-8" from wp_mail_content_type: HTML', true === $sent && '<p>Hi</p>' === ( $body['htmlContent'] ?? '' ) && ! isset( $body['textContent'] ) );

$requests     = array();
$failed       = array();
$log          = wp_tempnam( 'mody-mail-log' );
$previous_log = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- captures the failure log for this check.
$brevo_answer = $brevo_says(
	401,
	array(
		'code'    => 'unauthorized',
		'message' => 'Key not found',
	)
);
$sent = wp_mail( 'ada@example.org', 'Refused', 'No.' );
$check( 'Brevo refuses: wp_mail() fails, wp_mail_failed says why', false === $sent && array( 'Brevo refused the email (HTTP 401: Key not found).' ) === $failed, implode( ' | ', $failed ) );
$check( '...never showing the key', ! str_contains( implode( ' ', $failed ), 'xkeysib' ) );
ini_set( 'error_log', (string) $previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
$logged = (string) file_get_contents( $log );
wp_delete_file( $log );
$check( '...the reason in PHP\'s error log, without the key or the message', str_contains( $logged, '[mody-mail] Email not sent: Brevo refused the email (HTTP 401: Key not found).' ) && ! str_contains( $logged, 'xkeysib' ) && ! str_contains( $logged, 'No.' ), trim( $logged ) );

$failed       = array();
$brevo_answer = null; // The request is refused, as by a network error.
$sent         = wp_mail( 'ada@example.org', 'Unreachable', 'No.' );
$check( 'Brevo unreachable: wp_mail() fails', false === $sent && 1 === count( $failed ) && str_starts_with( $failed[0], 'Brevo could not be reached: ' ), implode( ' | ', $failed ) );

$failed = array();
$sent   = wp_mail( 'not an address', 'Nobody', 'No.' );
$check( 'no valid recipient: wp_mail() fails before any request', false === $sent && 1 === count( $failed ), implode( ' | ', $failed ) );

// Production without a key: the BrevoMailer refuses, it never falls back to mail().
$set_env( 'BREVO_API_KEY', '' );
$requests = array();
$mailer   = new BrevoMailer( true );
$mailer->setFrom( 'no-reply@mody.test', 'Mody Test', false );
$mailer->addAddress( 'ada@example.org' );
$mailer->Subject = 'No key';
$mailer->Body    = 'No.';
try {
	$mailer->send();
	$error = '';
} catch ( PHPMailer\PHPMailer\Exception $e ) {
	$error = $e->getMessage();
}
$check( 'no key (production): sending fails with a clear error, no request', 'No mail transport: BREVO_API_KEY is not set.' === $error && ! $requests, $error );

// The key gone, WordPress's own mailer comes back.
Mail::use_transport( null );
$check( 'the key gone: the BrevoMailer is dropped', 'production' === wp_get_environment_type() || ! ( $GLOBALS['phpmailer'] instanceof BrevoMailer ) );

echo "\n" . ( $failures ? "$failures check(s) failed.\n" : "All checks passed.\n" );
