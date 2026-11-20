<?php
/**
 * Tests the signup in the page's language (tickets/signup-chat-languages.md).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/signup-languages-test.php
 *
 * 1. The files: every language has both files, in WordPress's PHP format,
 *    and every translation keeps its original's placeholders.
 * 2. Nothing left in English: every text the signup asks WordPress to
 *    translate (the block, the chat's words, each answer's errors, the
 *    waits, the emails) has a translation in every language.
 * 3. The page's language: on /es/ the block and the chat speak Spanish and
 *    send `es_ES`; the default language and languages without files stay
 *    English.
 * 4. The chat's requests: their `lang` gives the answer's language (ours and
 *    core's words, plurals included), English afterwards; an unknown one is
 *    English.
 * 5. The emails: the welcome email in the language in use, the member's own
 *    language first; work done after the response keeps the visitor's; a new
 *    account keeps its language only once WordPress has it installed.
 *
 * Registration is opened for the run with a filter. A throwaway member is
 * made and removed; no site is created, no model call.
 */

use Mody\Members\Registration;
use Mody\Members\SignupLanguage;
use Mody\Security\RateLimit;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-74s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$open = static fn() => 1;
add_filter( 'pre_site_option_mody_registration_open', $open );
if ( class_exists( RateLimit::class ) ) {
	RateLimit::clear();
}
$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, array $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);

// 1. The files ------------------------------------------------------------------------------------

echo "\nThe files\n";
$placeholders = static function ( string $text ): array {
	preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $found );
	$found = $found[0];
	sort( $found );
	return $found;
};
$files = array();
foreach ( SignupLanguage::LOCALES as $locale ) {
	foreach ( array( 'mody_cloud', 'default' ) as $domain ) {
		$path = SignupLanguage::path( $domain, $locale );
		$data = is_readable( $path ) ? include $path : null;
		$ok   = is_array( $data ) && $domain === ( $data['domain'] ?? '' ) && $locale === ( $data['language'] ?? '' ) && ! empty( $data['messages'] ) && str_contains( (string) ( $data['plural-forms'] ?? '' ), 'nplurals=2' );
		$bad  = array();
		foreach ( (array) ( $data['messages'] ?? array() ) as $original => $translation ) {
			$forms = explode( "\0", (string) $translation );
			foreach ( $forms as $form ) {
				if ( '' === trim( $form ) || $placeholders( $form ) !== $placeholders( (string) $original ) ) {
					$bad[] = $original;
				}
			}
		}
		$files[ $domain ][ $locale ] = (array) ( $data['messages'] ?? array() );
		$check( basename( $path ) . ': read, its placeholders kept', $ok && ! $bad, $bad ? 'wrong: ' . implode( ' | ', array_slice( $bad, 0, 3 ) ) : count( (array) $data['messages'] ) . ' texts' );
	}
}

// 2. Nothing left in English -------------------------------------------------------------------------

echo "\nNothing left in English\n";
$suffix = strtolower( wp_generate_password( 6, false ) );
$member = (int) wpmu_create_user( 'modylang' . $suffix, wp_generate_password( 24 ), "modylang{$suffix}@mody.local" );
register_shutdown_function(
	static function () use ( $member ): void {
		if ( get_userdata( $member ) ) {
			wpmu_delete_user( $member );
		}
	}
);
$asked = array();
$ask   = static function ( $translation, $text, $domain ) use ( &$asked ) {
	$asked[ $domain ][ $text ] = true;
	return $translation;
};
$ask_n = static function ( $translation, $single, $plural, $number, $domain ) use ( &$asked ) {
	$asked[ $domain ][ $single ] = true;
	return $translation;
};
foreach ( array( 'mody_cloud', 'default' ) as $domain ) {
	add_filter( "gettext_{$domain}", $ask, 1, 3 );
	add_filter( "ngettext_{$domain}", $ask_n, 1, 5 );
}
Registration::render();
foreach ( array( 'name' => '', 'email' => 'not an email', 'title' => '', 'nope' => '' ) as $field => $value ) {
	Registration::check( $field, $value );
}
Registration::check( 'name', str_repeat( 'a', 300 ) );
Registration::check( 'title', 'Visit www.example.com' );
foreach ( array( '', 'ab', 'Ab_c', '12345', 'www' ) as $slug ) {
	Registration::check( 'slug', $slug );
}
Registration::done_message( 'a@example.com', 'a.mody.local' );
Registration::spain_only_message();
\Mody\Members\Members::free_closed_message();
foreach ( array( 30, 120, 7200, 200000 ) as $wait ) {
	RateLimit::message( $wait );
}
\Mody\Security\Altcha::failed_message();
Registration::send_welcome( get_userdata( $member ), 'Lina\'s Bakery', 'https://linabakery.mody.local/' );
foreach ( array( 'mody_cloud', 'default' ) as $domain ) {
	remove_filter( "gettext_{$domain}", $ask, 1 );
	remove_filter( "ngettext_{$domain}", $ask_n, 1 );
}
// Core's own words a block's styles ask for, not the signup's.
$not_ours = array( 'Small', 'Medium', 'Large', '%sX-Small', '%sX-Large' );
foreach ( SignupLanguage::LOCALES as $locale ) {
	$missing = array();
	foreach ( $asked as $domain => $texts ) {
		foreach ( array_keys( $texts ) as $text ) {
			if ( ! in_array( $text, $not_ours, true ) && ! isset( $files[ $domain ][ $locale ][ $text ] ) ) {
				$missing[] = $text;
			}
		}
	}
	$check( "{$locale}: every text the signup asked for is translated", ! $missing, $missing ? count( $missing ) . ' missing: ' . implode( ' | ', array_slice( $missing, 0, 3 ) ) : count( $asked['mody_cloud'] ?? array() ) . ' + ' . count( $asked['default'] ?? array() ) . ' texts' );
}
$mails = array();

// 3. The page's language ----------------------------------------------------------------------------

echo "\nThe page's language\n";
$languages = class_exists( \Mody\Languages\Router::class ) && \Mody\Languages\Languages::is_other( 'es_ES' );
if ( ! $languages ) {
	$check( 'the main site has Spanish (Mody Languages; scripts/home-page-translations.php)', false );
} else {
	\Mody\Languages\Router::set_current( 'es_ES' );
	$check( 'on /es/ the page is Spanish', 'es_ES' === SignupLanguage::of_page() );
	$html = Registration::render();
	$check( '... the form speaks Spanish', str_contains( $html, 'Responde cuatro preguntas' ) && str_contains( $html, 'Vivo en España.' ) && ! str_contains( $html, 'Answer four quick questions' ) );
	$data = implode( '', (array) wp_scripts()->get_data( 'mody-register', 'before' ) );
	$check( '... the chat\'s words too, and it sends es_ES', str_contains( $data, '"lang":"es_ES"' ) && str_contains( $data, wp_json_encode( '¿Cómo te llamas?' ) ) );
	$check( '... and English again after the block', '' === SignupLanguage::active() && 'Please enter your name.' === Registration::check( 'name', '' ) );
	\Mody\Languages\Router::set_current( '' );
	$check( 'the default language: English', '' === SignupLanguage::of_page() && str_contains( Registration::render(), 'Answer four quick questions' ) );
}
$check( 'a language without files is English', '' === SignupLanguage::supported( 'en_US' ) && '' === SignupLanguage::supported( 'xx_XX' ) && 'ca' === SignupLanguage::supported( 'ca' ) );

// 4. The chat's requests ------------------------------------------------------------------------------

echo "\nThe chat's requests\n";
$ask_route = static function ( array $body ): array {
	$request = new WP_REST_Request( 'POST', '/' . Registration::REST_NAMESPACE . '/register/check' );
	foreach ( array_merge( array( 'nonce' => wp_create_nonce( Registration::ACTION ) ), $body ) as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), (array) $response->get_data() );
};
wp_set_current_user( 0 );
[ $status, $data ] = $ask_route( array( 'field' => 'name', 'value' => '', 'lang' => 'de_DE' ) );
$check( 'a German request: the answer in German', 200 === $status && 'Bitte gib deinen Namen ein.' === ( $data['message'] ?? '' ), $data['message'] ?? (string) $status );
$check( '... English again after it', '' === SignupLanguage::active() );
[ $status, $data ] = $ask_route( array( 'field' => 'slug', 'value' => 'ab', 'lang' => 'it_IT' ) );
$check( 'an Italian request: core\'s words too, with their plural', str_contains( (string) ( $data['message'] ?? '' ), 'almeno 4 caratteri' ), (string) ( $data['message'] ?? $status ) );
[ $status, $data ] = $ask_route( array( 'field' => 'name', 'value' => '', 'lang' => 'xx_XX' ) );
$check( 'an unknown language: English', 'Please enter your name.' === ( $data['message'] ?? '' ) );
[ $status, $data ] = $ask_route( array( 'field' => 'name', 'value' => 'Lina', 'lang' => 'fr_FR', 'nonce' => 'expired' ) );
$check( 'the permission check speaks it too (an expired page, in French)', 403 === $status && str_starts_with( (string) ( $data['message'] ?? '' ), 'Cette page a expiré' ), (string) ( $data['message'] ?? '' ) );
$wait = SignupLanguage::with( 'de_DE', static fn() => RateLimit::message( 120 ) );
$check( 'a wait in German: the plural', str_contains( $wait, 'in 2 Minuten' ), $wait );
$wait = SignupLanguage::with( 'fr_FR', static fn() => RateLimit::message( 60 ) );
$check( '... in French: one minute, singular', str_contains( $wait, 'dans 1 minute' ), $wait );

// 5. The emails ------------------------------------------------------------------------------------------

echo "\nThe emails\n";
$mails = array();
SignupLanguage::with( 'es_ES', static fn() => Registration::send_welcome( get_userdata( $member ), 'Panadería Lina', 'https://linabakery.mody.local/' ) );
$mail = $mails[0] ?? array();
$check( 'the welcome email in the language in use', str_contains( (string) ( $mail['subject'] ?? '' ), 'Te damos la bienvenida' ) && str_contains( (string) ( $mail['message'] ?? '' ), 'Elige tu contraseña' ) && str_contains( (string) ( $mail['message'] ?? '' ), 'action=rp' ), (string) ( $mail['subject'] ?? 'no email' ) );
update_user_meta( $member, 'locale', 'ca' );
$mails = array();
SignupLanguage::with( 'es_ES', static fn() => Registration::send_welcome( get_userdata( $member ), 'Forn Lina', 'https://linabakery.mody.local/' ) );
$check( '... the member\'s own language first (Catalan)', str_contains( (string) ( $mails[0]['subject'] ?? '' ), 'Et donem la benvinguda' ), (string) ( $mails[0]['subject'] ?? '' ) );
delete_user_meta( $member, 'locale' );
$mails = array();
Registration::send_welcome( get_userdata( $member ), 'Lina', 'https://linabakery.mody.local/' );
$check( '... and English without one', 'Welcome to Mody Cloud: choose your password' === ( $mails[0]['subject'] ?? '' ) );

// After the response: an email that already has an account gets its note in the visitor's language.
RateLimit::clear();
$defer = static fn() => true;
add_filter( 'mody_registration_defer', $defer );
$mails  = array();
$answer = SignupLanguage::with( 'it_IT', static fn() => Registration::register( 'Lina', "modylang{$suffix}@mody.local", 'Forno Lina', 'fornolina' . $suffix, true ) );
remove_filter( 'mody_registration_defer', $defer );
$sent_before = count( $mails );
Registration::run_later();
$check( 'work done after the response keeps the visitor\'s language (Italian)', is_array( $answer ) && 0 === $sent_before && str_contains( (string) ( $mails[0]['subject'] ?? '' ), 'hai già un account' ), (string) ( $mails[0]['subject'] ?? 'no email' ) );
$check( '... and nothing is left in Italian after it', '' === SignupLanguage::active() );

$installed = in_array( 'es_ES', get_available_languages(), true );
$keep      = SignupLanguage::with( 'es_ES', static fn() => SignupLanguage::for_account() );
$check( 'a new account keeps the language only once WordPress has it installed', $installed ? 'es_ES' === $keep : '' === $keep, $installed ? 'es_ES installed' : 'es_ES not installed: nothing kept' );

RateLimit::clear();
remove_filter( 'pre_site_option_mody_registration_open', $open );
echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
