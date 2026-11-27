<?php
/**
 * The signup in the page's language (tickets/signup-chat-languages.md).
 *
 * @package Mody\Members
 */

namespace Mody\Members;

defined( 'ABSPATH' ) || exit;

use WP_HTTP_Response;
use WP_REST_Request;
use WP_Translation_File;

/**
 * What the signup says (the chat, its REST answers, the form without
 * JavaScript and the emails) in the language of the page the visitor signs up
 * on: Spanish, French, German, Italian, Portuguese, Dutch or Catalan, the
 * languages Mody Languages offers; English otherwise.
 *
 * While a language is in use, WordPress's gettext filters answer from this
 * plugin's own files, in WordPress's PHP format:
 * `languages/mody_cloud-<locale>.l10n.php` for our words, and
 * `languages/core-<locale>.l10n.php` for the few of core's words the signup
 * shows (why an address is refused, the time to wait). WordPress itself is
 * switched too (switch_to_locale()): to the language when it is installed
 * (bin/languages: Spanish and Catalan, tickets/spanish-by-default.md), else to
 * English, so a word without a translation is English, never the site's
 * default language (Spanish on mody.cloud since 2026-11-27).
 *
 * Where the language comes from:
 * - the page: Mody Languages' language of the address (`/en/`, or the site's
 *   default language without a prefix), when it is there (class_exists),
 *   else the site's language; the block's render and the form's post;
 * - the chat's REST requests: their `lang`, sent by register.js;
 * - an email to a member: their WordPress language (user meta `locale`), else
 *   the request's; work done after the response takes the request's along.
 */
final class SignupLanguage {

	/** The languages translated: every language Mody Languages offers but English. */
	public const LOCALES = array( 'es_ES', 'fr_FR', 'de_DE', 'it_IT', 'pt_PT', 'nl_NL', 'ca' );

	/** Text domain => the file's prefix in languages/. */
	private const DOMAINS = array(
		'mody_cloud' => 'mody_cloud',
		'default'    => 'core',
	);

	/** The language in use: one of LOCALES, or '' for English. */
	private static string $active = '';

	/** Whether a language was chosen (use()), rather than none (the site's own, reset()). */
	private static bool $chosen = false;

	/** Whether WordPress's locale was switched for the language in use. */
	private static bool $switched = false;

	/** Whether the gettext filters are added (on the first language used). */
	private static bool $hooked = false;

	/**
	 * The files read so far, by "domain locale".
	 *
	 * @var array<string, WP_Translation_File|false>
	 */
	private static array $files = array();

	/**
	 * `plugins_loaded`: the chat's REST requests use their `lang`.
	 */
	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', array( self::class, 'rest_before' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'rest_after' ), 10, 3 );
	}

	/**
	 * A locale if it is translated, else ''.
	 *
	 * @param string $locale A locale, such as es_ES.
	 */
	public static function supported( string $locale ): string {
		return in_array( $locale, self::LOCALES, true ) ? $locale : '';
	}

	/**
	 * The page's language, when it is translated: Mody Languages' language of
	 * the address. '' for English, a language without translations, or
	 * without Mody Languages.
	 */
	public static function of_page(): string {
		$locale = class_exists( \Mody\Languages\Router::class ) ? \Mody\Languages\Router::current() : get_locale();
		return self::supported( $locale );
	}

	/**
	 * The language in use ('' for English).
	 */
	public static function active(): string {
		return self::$active;
	}

	/**
	 * Uses a language from now on: '' or one without translations is English.
	 *
	 * @param string $locale A locale.
	 * @return string The language used before, to put back.
	 */
	public static function use( string $locale ): string {
		$before       = self::$active;
		self::$active = self::supported( $locale );
		self::$chosen = true;
		self::switch_wordpress();
		if ( '' !== self::$active && ! self::$hooked ) {
			self::$hooked = true;
			foreach ( array_keys( self::DOMAINS ) as $domain ) {
				add_filter( 'gettext_' . $domain, array( self::class, 'gettext' ), 10, 3 );
				add_filter( 'gettext_with_context_' . $domain, array( self::class, 'gettext_with_context' ), 10, 4 );
				add_filter( 'ngettext_' . $domain, array( self::class, 'ngettext' ), 10, 5 );
			}
		}
		return $before;
	}

	/**
	 * Runs some work in a language, then puts the one before back.
	 *
	 * @template T
	 * @param string          $locale A locale ('' for English).
	 * @param callable(): T $work   The work.
	 * @return T What the work returns.
	 */
	public static function with( string $locale, callable $work ) {
		$chosen = self::$chosen;
		$before = self::use( $locale );
		try {
			return $work();
		} finally {
			if ( $chosen ) {
				self::use( $before );
			} else {
				self::reset();
			}
		}
	}

	/**
	 * No language chosen any more: WordPress back in the site's own language.
	 */
	public static function reset(): void {
		self::$active = '';
		self::$chosen = false;
		self::switch_wordpress();
	}

	/**
	 * Puts WordPress's own locale in step with the language in use: the
	 * language when WordPress has it installed, else English; the site's own
	 * when none is chosen.
	 */
	private static function switch_wordpress(): void {
		if ( self::$switched ) {
			restore_previous_locale();
			self::$switched = false;
		}
		if ( ! self::$chosen ) {
			return;
		}
		$wanted         = '' !== self::$active && in_array( self::$active, get_available_languages(), true ) ? self::$active : 'en_US';
		self::$switched = switch_to_locale( $wanted );
	}

	/**
	 * The language for an email to a member: theirs (WordPress's user
	 * language), else the one in use.
	 *
	 * @param int $user_id The member.
	 */
	public static function of_user( int $user_id ): string {
		$meta = (string) get_user_meta( $user_id, 'locale', true );
		if ( 'en_US' === $meta ) {
			return ''; // English, chosen: not the request's language.
		}
		$own = self::supported( $meta );
		return '' !== $own ? $own : self::$active;
	}

	/**
	 * The language to keep as a new account's WordPress language (user meta
	 * `locale`): the one the person signs up in (decided by the maintainer on
	 * 2026-11-27, tickets/spanish-by-default.md, Q4). English is kept as
	 * `en_US`, since the site's default may be another language. A language
	 * WordPress does not have installed gives '' (the site's default), because
	 * wp-admin would say it speaks that language (`<html lang>`) while showing
	 * another. Mody Sites gives the account's first site the same language.
	 */
	public static function for_account(): string {
		if ( ! self::$chosen ) {
			return '';
		}
		if ( '' === self::$active ) {
			return 'en_US';
		}
		return in_array( self::$active, get_available_languages(), true ) ? self::$active : '';
	}

	/**
	 * The translation file of a domain in the language in use.
	 *
	 * @param string $domain A text domain.
	 */
	private static function file( string $domain ): ?WP_Translation_File {
		if ( '' === self::$active || ! isset( self::DOMAINS[ $domain ] ) ) {
			return null;
		}
		$key = $domain . ' ' . self::$active;
		if ( ! array_key_exists( $key, self::$files ) ) {
			self::$files[ $key ] = WP_Translation_File::create( self::path( $domain, self::$active ) );
		}
		return self::$files[ $key ] ? self::$files[ $key ] : null;
	}

	/**
	 * Where a domain's translations in a language are.
	 *
	 * @param string $domain A text domain of DOMAINS.
	 * @param string $locale One of LOCALES.
	 */
	public static function path( string $domain, string $locale ): string {
		return dirname( __DIR__ ) . '/languages/' . ( self::DOMAINS[ $domain ] ?? $domain ) . '-' . $locale . '.l10n.php';
	}

	/**
	 * A translation from the file, or false.
	 *
	 * @param string $domain A text domain.
	 * @param string $key    The original, with its context when it has one.
	 * @return string|false
	 */
	private static function find( string $domain, string $key ) {
		$file = self::file( $domain );
		if ( ! $file ) {
			return false;
		}
		$found = $file->translate( $key );
		return is_string( $found ) && '' !== $found ? $found : false;
	}

	/**
	 * `gettext_<domain>`.
	 *
	 * @param string $translation WordPress's translation.
	 * @param string $text        The original.
	 * @param string $domain      The text domain.
	 */
	public static function gettext( $translation, $text, $domain ) {
		$found = self::find( (string) $domain, (string) $text );
		return false === $found ? $translation : $found;
	}

	/**
	 * `gettext_with_context_<domain>`.
	 *
	 * @param string $translation WordPress's translation.
	 * @param string $text        The original.
	 * @param string $context     Its context.
	 * @param string $domain      The text domain.
	 */
	public static function gettext_with_context( $translation, $text, $context, $domain ) {
		$found = self::find( (string) $domain, $context . "\4" . $text );
		return false === $found ? $translation : $found;
	}

	/**
	 * `ngettext_<domain>`: the plural form of the file's language.
	 *
	 * @param string $translation WordPress's translation.
	 * @param string $single      The singular original.
	 * @param string $plural      The plural original.
	 * @param int    $number      The number.
	 * @param string $domain      The text domain.
	 */
	public static function ngettext( $translation, $single, $plural, $number, $domain ) {
		$file = self::file( (string) $domain );
		if ( ! $file ) {
			return $translation;
		}
		$found = $file->translate( $single . "\0" . $plural );
		$found = false === $found ? $file->translate( (string) $single ) : $found;
		if ( ! is_string( $found ) || '' === $found ) {
			return $translation;
		}
		$forms = explode( "\0", $found );
		return $forms[ $file->get_plural_form( (int) $number ) ] ?? $forms[0];
	}

	/**
	 * Whether a REST request is the chat's.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	private static function is_chat( WP_REST_Request $request ): bool {
		return str_starts_with( $request->get_route(), '/' . Registration::REST_NAMESPACE . '/register' );
	}

	/**
	 * `rest_request_before_callbacks`: the chat's requests, from the
	 * permission check on, in their `lang`.
	 *
	 * @param mixed           $response The response so far.
	 * @param array           $handler  The route's handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed The response, unchanged.
	 */
	public static function rest_before( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request && self::is_chat( $request ) ) {
			self::use( is_string( $request['lang'] ) ? $request['lang'] : '' );
		}
		return $response;
	}

	/**
	 * `rest_request_after_callbacks`: the site's own language again. Work done after the
	 * response (Registration::later()) takes its language along.
	 *
	 * @param WP_HTTP_Response|\WP_Error|mixed $response The response.
	 * @param array                            $handler  The route's handler.
	 * @param WP_REST_Request                  $request  The request.
	 * @return mixed The response, unchanged.
	 */
	public static function rest_after( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request && self::is_chat( $request ) ) {
			self::reset();
		}
		return $response;
	}
}
