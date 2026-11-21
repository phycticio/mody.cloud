<?php
/**
 * The firewall: blocked addresses, and a short set of rules against the
 * attacks scanners try on every WordPress site.
 *
 * @package Mody\Security
 */

namespace Mody\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Our own rules, nothing downloaded (Wordfence's came from its servers):
 *
 * - **Addresses** (on load, before any other plugin runs): an address or
 *   range in the Security page's blocked list gets a 403 for everything.
 * - **Rules** (`plugins_loaded`, first): the address asked for (path and
 *   query) of every request, and the form and JSON data of visitors who are
 *   not logged in, are matched against RULES. Logged-in users' form data is
 *   not: they write pages, code and HTML in the editors, and WordPress's own
 *   permissions guard what they may save. Password fields are never read.
 *
 * Addresses in the allowed list skip both. A block is logged (at most
 * RateLimit's `firewall_log` a minute per address) and can send an alert.
 * The rules are deliberately few, to keep false positives away from members:
 * each is a pattern only an attack has.
 */
final class Firewall {

	/**
	 * The rule groups: group => patterns (case-insensitive, matched against
	 * each value after URL-decoding twice).
	 */
	public const RULES = array(
		'sqli'      => array(
			'union select'    => '/\bunion(?:\s|\+|\/\*.*?\*\/)+(?:all(?:\s|\+|\/\*.*?\*\/)+)?select\b/is',
			'sleep injection' => '/(?:\b(?:and|or)\b|[\'"\)])\s*(?:sleep|benchmark|pg_sleep)\s*\(\s*\d/i',
			'schema probe'    => '/\binformation_schema\b|\bload_file\s*\(|\binto\s+(?:out|dump)file\b|\b(?:extractvalue|updatexml)\s*\(/i',
			'tautology'       => '/[\'"]\s*\b(?:or|and)\b\s*[\'"]?(\d+)[\'"]?\s*=\s*[\'"]?\1\b/i',
			'stacked query'   => '/;\s*(?:drop|truncate|alter)\s+table\b/i',
		),
		'xss'       => array(
			'script tag'      => '/<\s*script\b/i',
			'event handler'   => '/<[^>]*\son[a-z]{3,}\s*=/i',
			'javascript url'  => '/\bjavascript\s*:(?!\s)/i',
			'embedded object' => '/<\s*(?:iframe|object|embed|base)\b/i',
		),
		'traversal' => array(
			'dot-dot path'    => '/(?:\.\.[\/\\\\]){1,}.*?(?:etc\/passwd|wp-config|proc\/self|\.env\b|\.htaccess|win\.ini)/i',
			'system file'     => '/\/etc\/(?:passwd|shadow|hosts)\b|\bproc\/self\/environ\b/i',
			'stream wrapper'  => '/\b(?:php|phar|zip|expect|glob|data):\/\//i',
		),
		'code'      => array(
			'php tag'         => '/<\?php\b/i',
			'log4shell'       => '/\$\{\s*jndi\s*:/i',
			'shell function'  => '/\b(?:eval|assert|system|shell_exec|passthru|popen|proc_open)\s*\(\s*(?:\$|base64_decode|gzinflate|str_rot13|[\'"])/i',
			'decoder'         => '/\bbase64_decode\s*\(/i',
		),
	);

	/** Fields never read: passwords. */
	private const SKIPPED_FIELDS = array( 'pwd', 'pass', 'pass1', 'pass2', 'password', 'post_password', 'user_pass', 'new_password', 'current_password' );

	/** At most this many bytes of a JSON body are read. */
	private const MAX_BODY = 65536;

	/**
	 * On load (mody-security.php): blocked addresses, and the rules once the
	 * login cookie can be checked.
	 */
	public static function boot(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		$address = ClientAddress::get();
		if ( Settings::in_list( $address, (array) Settings::get( 'allowed_addresses' ) ) ) {
			return;
		}
		if ( Settings::in_list( $address, (array) Settings::get( 'blocked_addresses' ) ) ) {
			self::block( 'address_blocked', 'blocked address' );
		}
		if ( Settings::get( 'firewall' ) ) {
			add_action( 'plugins_loaded', array( self::class, 'inspect' ), PHP_INT_MIN );
		}
	}

	/**
	 * `plugins_loaded`, first: the rules against this request.
	 */
	public static function inspect(): void {
		$values = array( 'uri' => self::request_uri() );
		// phpcs:disable WordPress.Security.NonceVerification -- Read to be checked, never used.
		foreach ( self::flatten( $_GET, 'query' ) as $key => $value ) {
			$values[ $key ] = $value;
		}
		if ( ! self::logged_in() ) {
			foreach ( self::flatten( $_POST, 'form' ) as $key => $value ) {
				$values[ $key ] = $value;
			}
			foreach ( self::flatten( self::json_body(), 'json' ) as $key => $value ) {
				$values[ $key ] = $value;
			}
		}
		// phpcs:enable
		foreach ( $values as $value ) {
			$match = self::match( $value );
			if ( null !== $match ) {
				self::block( 'firewall', $match );
			}
		}
	}

	/**
	 * The rule a value matches, as `group: name`, or null.
	 *
	 * @param string $value A value from the request.
	 */
	public static function match( string $value ): ?string {
		if ( '' === $value ) {
			return null;
		}
		$decoded = rawurldecode( rawurldecode( $value ) );
		$decoded = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		foreach ( self::RULES as $group => $patterns ) {
			if ( ! Settings::rule( $group ) ) {
				continue;
			}
			foreach ( $patterns as $name => $pattern ) {
				if ( preg_match( $pattern, $decoded ) ) {
					return $group . ': ' . $name;
				}
			}
		}
		return null;
	}

	/**
	 * Refuses the request: 403, a short page, a log row, maybe an alert.
	 *
	 * @param string $type   'firewall' or 'address_blocked'.
	 * @param string $reason What matched.
	 */
	private static function block( string $type, string $reason ): void {
		$uri       = self::request_uri();
		$reference = strtoupper( substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) );
		if ( ! RateLimit::hit_address( 'firewall_log' ) ) {
			Events::record( $type, 0, $reason . ' · ' . $reference . ' · ' . mb_substr( $uri, 0, 160 ) );
			if ( 'firewall' === $type ) {
				Alerts::firewall_block( $reason, $uri );
			}
		}
		/**
		 * Fires before a request is refused (tests stop here).
		 *
		 * @param string $type   'firewall' or 'address_blocked'.
		 * @param string $reason What matched.
		 */
		do_action( 'mody_security_blocked', $type, $reason );

		if ( ! headers_sent() ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		// English, untranslated: an address is refused before WordPress loads translations.
		$title = 'Request blocked';
		$text  = 'This request was blocked by the site\'s firewall. If you think it is a mistake, tell the site\'s owner and give them this reference:';
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;background:#f6f7f7;color:#1d2327}main{max-width:32rem;padding:2rem}code{font-size:1.1em}</style></head>'
			. '<body><main><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . ' <code>' . esc_html( $reference ) . '</code></p></main></body></html>';
		exit;
	}

	/**
	 * The address asked for: path and query.
	 */
	private static function request_uri(): string {
		return isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Matched against the rules, then escaped where shown.
	}

	/**
	 * Whether the request carries a valid login cookie.
	 */
	private static function logged_in(): bool {
		return function_exists( 'wp_validate_auth_cookie' ) && false !== wp_validate_auth_cookie( '', 'logged_in' );
	}

	/**
	 * A JSON request body, decoded; an empty list otherwise.
	 *
	 * @return array<mixed>
	 */
	private static function json_body(): array {
		$type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) ) : '';
		if ( ! str_contains( $type, 'json' ) ) {
			return array();
		}
		$body = file_get_contents( 'php://input', false, null, 0, self::MAX_BODY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The request body.
		$data = is_string( $body ) ? json_decode( $body, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Every scalar of a nested array as `prefix.key` => text, keys included
	 * (an attack can sit in a field's name), password fields left out.
	 *
	 * @param mixed  $data   The data.
	 * @param string $prefix Where it came from.
	 * @param int    $depth  How deep.
	 * @return array<string, string>
	 */
	private static function flatten( $data, string $prefix, int $depth = 0 ): array {
		$out = array();
		if ( ! is_array( $data ) || $depth > 8 ) {
			return $out;
		}
		foreach ( $data as $key => $value ) {
			$name = $prefix . '.' . $key;
			if ( in_array( strtolower( (string) $key ), self::SKIPPED_FIELDS, true ) ) {
				continue;
			}
			$out[ $name . '#key' ] = (string) $key;
			if ( is_array( $value ) ) {
				$out += self::flatten( $value, $name, $depth + 1 );
			} elseif ( is_scalar( $value ) ) {
				$out[ $name ] = (string) $value;
			}
		}
		return $out;
	}
}
