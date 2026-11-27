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
 * 3. The page's language: the main site is Spanish by default (Mody
 *    Languages, tickets/spanish-by-default.md), so the page without a prefix
 *    speaks Spanish and sends `es_ES`; /en/ gives English and /ca/ Catalan;
 *    languages without files stay English.
 * 4. The chat's requests: their `lang` gives the answer's language (ours and
 *    core's words, plurals included), the site's own afterwards; an unknown
 *    or missing one is English. While a language is in use WordPress's own
 *    words follow it (switch_to_locale()): "Dashboard" is English in English
 *    on the Spanish site, "Escritorio" in Spanish.
 * 5. The emails: the welcome email in the language in use, the member's own
 *    language first (English chosen included); work done after the response
 *    keeps the visitor's; a new account keeps the language it signs up in:
 *    en_US for English, nothing when none is chosen or WordPress lacks it.
 *
 * Registration is opened for the run with filters (the network option and
 * the Offers setting, whatever they are locally). A throwaway member is made
 * and removed; no site is created, no model call.
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
// Sign-ups open to everyone, whatever the Offers setting is on this install (it may be "invited people only").
$everyone = static fn() => array(
	'launch_who' => 'everyone',
	'invites_on' => true,
);
add_filter( 'pre_site_option_' . \Mody\Members\Offers::OPTION, $everyone );
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
// Switching WordPress's language (SignupLanguage does it) builds a WP_Locale (the weekdays, the months,
// AM and PM) and labels the post types and taxonomies again: those words are core's, not the signup's,
// so the texts asked for while the locale is switched are not collected.
$asked = array();
$in_locale = static function (): bool {
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		if ( in_array( $frame['class'] ?? '', array( 'WP_Locale', 'WP_Locale_Switcher' ), true ) ) {
			return true;
		}
	}
	return false;
};
$ask   = static function ( $translation, $text, $domain ) use ( &$asked, $in_locale ) {
	if ( ! isset( $asked[ $domain ][ $text ] ) && ! $in_locale() ) {
		$asked[ $domain ][ $text ] = true;
	}
	return $translation;
};
$ask_n = static function ( $translation, $single, $plural, $number, $domain ) use ( &$asked, $in_locale ) {
	if ( ! isset( $asked[ $domain ][ $single ] ) && ! $in_locale() ) {
		$asked[ $domain ][ $single ] = true;
	}
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
$languages = class_exists( \Mody\Languages\Router::class ) && 'es_ES' === \Mody\Languages\Languages::default_locale() && \Mody\Languages\Languages::is_other( 'en_US' ) && \Mody\Languages\Languages::is_other( 'ca' );
if ( ! $languages ) {
	$check( 'the main site is Spanish, with English and Catalan (Mody Languages; scripts/home-page-translations.php)', false );
} else {
	\Mody\Languages\Router::set_current( '' );
	$check( 'the main site is Spanish by default, with English and Catalan', 'es_ES' === \Mody\Languages\Languages::default_locale() && 'es_ES' === get_locale() );
	$check( 'the default page is Spanish', 'es_ES' === SignupLanguage::of_page() );
	$html = Registration::render();
	$check( '... the form speaks Spanish', str_contains( $html, 'Responde cuatro preguntas' ) && str_contains( $html, 'Vivo en España.' ) && ! str_contains( $html, 'Answer four quick questions' ) );
	$data = implode( '', (array) wp_scripts()->get_data( 'mody-register', 'before' ) );
	$check( '... the chat\'s words too, and it sends es_ES', str_contains( $data, '"lang":"es_ES"' ) && str_contains( $data, wp_json_encode( '¿Cómo te llamas?' ) ) );
	$check( '... and the site\'s own language again after the block (none chosen)', '' === SignupLanguage::active() && '' === SignupLanguage::for_account() && 'es_ES' === get_locale() );

	\Mody\Languages\Router::set_current( 'en_US' );
	$check( 'on /en/ the page is English', '' === SignupLanguage::of_page() );
	$html = Registration::render();
	$check( '... the form speaks English', str_contains( $html, 'Answer four quick questions' ) && ! str_contains( $html, 'Responde cuatro preguntas' ) );
	$data = implode( '', (array) wp_scripts()->get_data( 'mody-register', 'before' ) );
	$check( '... the chat sends no language (English), with its English words', str_contains( $data, '"lang":""' ) && str_contains( $data, wp_json_encode( 'What\'s your name?' ) ) );
	$check( '... and Spanish, the site\'s own, again after the block', '' === SignupLanguage::active() && 'es_ES' === get_locale() );

	\Mody\Languages\Router::set_current( 'ca' );
	$check( 'on /ca/ the page is Catalan', 'ca' === SignupLanguage::of_page() );
	$html = Registration::render();
	$data = implode( '', (array) wp_scripts()->get_data( 'mody-register', 'before' ) );
	$ask_name = SignupLanguage::with( 'ca', static fn() => __( 'What\'s your name?', 'mody_cloud' ) );
	$check( '... the form and the chat speak Catalan, and it sends ca', 'What\'s your name?' !== $ask_name && str_contains( $data, wp_json_encode( $ask_name ) ) && ! str_contains( $html, 'Answer four quick questions' ) && str_contains( $data, '"lang":"ca"' ), $ask_name );
	\Mody\Languages\Router::set_current( '' );
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
$check( '... the site\'s own language again after it (none chosen)', '' === SignupLanguage::active() && '' === SignupLanguage::for_account() && 'es_ES' === get_locale() );
[ $status, $data ] = $ask_route( array( 'field' => 'slug', 'value' => 'ab', 'lang' => 'it_IT' ) );
$check( 'an Italian request: core\'s words too, with their plural', str_contains( (string) ( $data['message'] ?? '' ), 'almeno 4 caratteri' ), (string) ( $data['message'] ?? $status ) );
[ $status, $data ] = $ask_route( array( 'field' => 'name', 'value' => '', 'lang' => 'xx_XX' ) );
$check( 'an unknown language: English', 'Please enter your name.' === ( $data['message'] ?? '' ) );
[ $status, $data ] = $ask_route( array( 'field' => 'name', 'value' => '' ) );
$check( 'no language at all: English too, not the site\'s Spanish', 'Please enter your name.' === ( $data['message'] ?? '' ) );
[ $status, $data ] = $ask_route( array( 'field' => 'name', 'value' => 'Lina', 'lang' => 'fr_FR', 'nonce' => 'expired' ) );
$check( 'the permission check speaks it too (an expired page, in French)', 403 === $status && str_starts_with( (string) ( $data['message'] ?? '' ), 'Cette page a expiré' ), (string) ( $data['message'] ?? '' ) );
$wait = SignupLanguage::with( 'de_DE', static fn() => RateLimit::message( 120 ) );
$check( 'a wait in German: the plural', str_contains( $wait, 'in 2 Minuten' ), $wait );
$wait = SignupLanguage::with( 'fr_FR', static fn() => RateLimit::message( 60 ) );
$check( '... in French: one minute, singular', str_contains( $wait, 'dans 1 minute' ), $wait );

// WordPress's own words follow the language in use (switch_to_locale()), whatever the site's is.
echo "\nWordPress's own words\n";
$is_installed = static fn( string $locale ): bool => in_array( $locale, get_available_languages(), true );
$dashboard    = static fn(): string => __( 'Dashboard' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own word.
$check( 'the site\'s own language (none chosen): Spanish on the main site', 'es_ES' === get_locale() && 'Escritorio' === $dashboard(), get_locale() . ' ' . $dashboard() );
SignupLanguage::use( '' );
$check( 'English in use: "Dashboard" is English, the account keeps en_US', 'en_US' === get_locale() && 'Dashboard' === $dashboard() && 'en_US' === SignupLanguage::for_account(), get_locale() . ' ' . $dashboard() . ' ' . SignupLanguage::for_account() );
SignupLanguage::use( 'es_ES' );
$check( 'Spanish in use: "Dashboard" is "Escritorio", the account keeps es_ES', 'es_ES' === get_locale() && 'Escritorio' === $dashboard() && ( $is_installed( 'es_ES' ) ? 'es_ES' : '' ) === SignupLanguage::for_account(), get_locale() . ' ' . $dashboard() . ' ' . SignupLanguage::for_account() );
SignupLanguage::use( 'ca' );
$catalan = $dashboard();
$check( 'Catalan in use: core speaks Catalan too, the account keeps ca', ( $is_installed( 'ca' ) ? 'ca' === get_locale() && 'Dashboard' !== $catalan && 'Escritorio' !== $catalan && 'ca' === SignupLanguage::for_account() : '' === SignupLanguage::for_account() ), get_locale() . ' ' . $catalan );
SignupLanguage::use( 'fr_FR' );
$check( 'French in use, not installed: core is English, not the site\'s Spanish; the account keeps nothing', ( $is_installed( 'fr_FR' ) || ( 'en_US' === get_locale() && 'Dashboard' === $dashboard() ) ) && ( $is_installed( 'fr_FR' ) ? 'fr_FR' : '' ) === SignupLanguage::for_account(), get_locale() . ' ' . $dashboard() );
SignupLanguage::reset();
$check( 'reset(): the site\'s own language again, nothing chosen', 'es_ES' === get_locale() && 'Escritorio' === $dashboard() && '' === SignupLanguage::for_account() && '' === SignupLanguage::active(), get_locale() . ' ' . $dashboard() );
$nested = SignupLanguage::with( '', static fn() => array( $dashboard(), SignupLanguage::with( 'es_ES', $dashboard ), $dashboard() ) );
$check( 'with(): the language only inside, and restored in order', array( 'Dashboard', 'Escritorio', 'Dashboard' ) === $nested && 'es_ES' === get_locale() && '' === SignupLanguage::for_account(), implode( ' | ', $nested ) );

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
update_user_meta( $member, 'locale', 'en_US' );
$mails = array();
SignupLanguage::with( 'es_ES', static fn() => Registration::send_welcome( get_userdata( $member ), 'Lina', 'https://linabakery.mody.local/' ) );
$check( '... and English when it is theirs, whatever the request\'s language', 'Welcome to Mody Cloud: choose your password' === ( $mails[0]['subject'] ?? '' ) && '' === SignupLanguage::of_user( $member ), (string) ( $mails[0]['subject'] ?? '' ) );
delete_user_meta( $member, 'locale' );
$mails = array();
Registration::send_welcome( get_userdata( $member ), 'Lina', 'https://linabakery.mody.local/' );
$check( '... and English without one (none chosen)', 'Welcome to Mody Cloud: choose your password' === ( $mails[0]['subject'] ?? '' ) );

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

// A new account keeps the language it signs up in (user meta `locale`; Mody Sites gives its first site the same).
$keep = static fn( string $locale ): string => SignupLanguage::with( $locale, static fn() => SignupLanguage::for_account() );
$check( 'a new account keeps the language it signs up in: Spanish and Catalan (installed), English as en_US', ( $is_installed( 'es_ES' ) ? 'es_ES' : '' ) === $keep( 'es_ES' ) && ( $is_installed( 'ca' ) ? 'ca' : '' ) === $keep( 'ca' ) && 'en_US' === $keep( '' ), $keep( 'es_ES' ) . ' ' . $keep( 'ca' ) . ' ' . $keep( '' ) );
$check( '... a language WordPress does not have gives nothing (the site\'s own); an unknown one is English; none chosen, nothing', ( $is_installed( 'fr_FR' ) ? 'fr_FR' : '' ) === $keep( 'fr_FR' ) && 'en_US' === $keep( 'xx_XX' ) && '' === SignupLanguage::for_account(), $keep( 'fr_FR' ) . '|' . $keep( 'xx_XX' ) . '|' . SignupLanguage::for_account() );

RateLimit::clear();
remove_filter( 'pre_site_option_mody_registration_open', $open );
remove_filter( 'pre_site_option_' . \Mody\Members\Offers::OPTION, $everyone );
echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
