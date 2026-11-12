<?php
/**
 * Signed, expiring links to a site's download files, and serving them.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Export;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The only way a download file leaves the server
 * (tickets/take-your-site-with-you.md, decision 6).
 *
 * A link is `https://<site>/mody-export/<token>`, with no cookie and no login:
 * `wget -c` and another server can pull it. The token is
 * `base64url(<job>|<file>|<expires>)` and an HMAC-SHA256 of that, made with a
 * random key of its own (the network option `mody_export_link_key`, created
 * the first time a link is made; never the wp-config salts, which a checked-in
 * default or a leaked backup would give away), so nothing in it can be
 * changed and the file is one of Jobs::FILES (a whitelist, never a path). The job's own site
 * must be the site the link is used on, so a link never works on another
 * member's host. The dashboard makes a fresh link every time it renders, so a
 * link in an old email or tab is just a 302 back to the export section.
 *
 * serve() runs when the MU plugin loads, before the rest of WordPress, like
 * Design\Patterns::serve_placeholder(). Behind Nginx it checks the link and
 * answers with `X-Accel-Redirect` to the internal location in
 * config/nginx/app.conf.template: Nginx streams the file itself, with Range
 * (resumable) and no PHP worker held. Nginx says it has that location with
 * `fastcgi_param MODY_EXPORT_ACCEL 1;` in the same template, so a deploy that
 * reaches PHP before Nginx was restarted (it renders its template at start)
 * sends no X-Accel-Redirect that the old Nginx would answer with a 404 page.
 * Anywhere else (or when the filter `mody_export_x_accel` says no) PHP streams
 * it in chunks and honours a single `Range: bytes=a-b`. plan() decides everything and touches nothing,
 * so it is what the tests call.
 */
final class Downloads {

	/** The path links live at, on the site's host. */
	public const PATH = '/mody-export/';

	/** The query argument a redirect adds, for the dashboard's notice. */
	public const EXPIRED_ARG = 'export_link';

	/** The network option holding the key links are signed with. */
	public const KEY_OPTION = 'mody_export_link_key';

	/** Bytes read and sent at a time when PHP streams. */
	private const CHUNK = 1048576;

	/**
	 * A link to one of a job's files.
	 *
	 * @param array  $job   A job.
	 * @param string $which One of Jobs::FILES.
	 * @param int    $ttl   Seconds the link works for.
	 * @return string The URL on the job's site; empty for a file that is not one of the three.
	 */
	public static function url( array $job, string $which, int $ttl = 6 * HOUR_IN_SECONDS ): string {
		$token = self::token( $job, $which, time() + max( 1, $ttl ) );
		return '' === $token ? '' : get_home_url( (int) $job['site_id'], self::PATH . $token );
	}

	/**
	 * A signed token.
	 *
	 * @param array  $job     A job.
	 * @param string $which   One of Jobs::FILES.
	 * @param int    $expires Unix time the token stops working.
	 * @return string Empty for a file that is not one of the three, or when no key could be made.
	 */
	public static function token( array $job, string $which, int $expires ): string {
		if ( ! in_array( $which, Jobs::FILES, true ) ) {
			return '';
		}
		$payload = self::encode( (int) $job['id'] . '|' . $which . '|' . $expires );
		$sig     = self::sign( $payload, true );
		return '' === $sig ? '' : $payload . '.' . $sig;
	}

	/**
	 * Checks a token's signature and expiry.
	 *
	 * @param string   $token A token from a link.
	 * @param int|null $now   Unix time; now when null.
	 * @return array{job_id: int, which: string, expires: int}|WP_Error
	 */
	public static function parse( string $token, ?int $now = null ) {
		$parts = explode( '.', $token );
		$sig   = 2 === count( $parts ) ? self::sign( $parts[0] ) : '';
		if ( '' === $sig || ! hash_equals( $sig, $parts[1] ) ) {
			return new WP_Error( 'mody_export_link_bad', 'The link is not valid.' );
		}
		$data = explode( '|', (string) self::decode( $parts[0] ) );
		if ( 3 !== count( $data ) || ! ctype_digit( $data[0] ) || ! in_array( $data[1], Jobs::FILES, true ) || ! ctype_digit( $data[2] ) ) {
			return new WP_Error( 'mody_export_link_bad', 'The link is not valid.' );
		}
		if ( (int) $data[2] < ( $now ?? time() ) ) {
			return new WP_Error( 'mody_export_link_expired', 'The link has expired.' );
		}
		return array(
			'job_id'  => (int) $data[0],
			'which'   => $data[1],
			'expires' => (int) $data[2],
		);
	}

	/**
	 * The file a valid link names, if it can still be downloaded on this site.
	 *
	 * @param array{job_id: int, which: string, expires: int} $link    A checked link (parse()).
	 * @param int                                             $site_id The site the request came to.
	 * @return array{job: array, which: string, path: string, size: int, name: string, sha256: string}|WP_Error
	 */
	public static function resolve( array $link, int $site_id ) {
		$gone = new WP_Error( 'mody_export_gone', 'The files are not available.' );
		$job  = Jobs::get( $link['job_id'] );
		if ( ! $job || 'done' !== $job['status'] || $site_id !== $job['site_id'] || ! isset( $job['files'][ $link['which'] ], Exporter::PATHS[ $link['which'] ] ) ) {
			return $gone;
		}
		if ( (int) strtotime( $job['finished_at'] . ' UTC' ) + Jobs::KEEP_FOR < time() ) {
			return $gone;
		}
		$path = Jobs::dir( $job ) . '/' . Exporter::PATHS[ $link['which'] ];
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return $gone;
		}
		$entry = $job['files'][ $link['which'] ];
		return array(
			'job'    => $job,
			'which'  => $link['which'],
			'path'   => $path,
			'size'   => (int) filesize( $path ),
			'name'   => isset( $entry['name'] ) && '' !== $entry['name'] ? (string) $entry['name'] : Jobs::file_name( $job, $link['which'] ),
			'sha256' => (string) ( $entry['sha256'] ?? '' ),
		);
	}

	/**
	 * Decides the response to a download request, and changes nothing.
	 *
	 * @param string               $method  GET or HEAD.
	 * @param string               $token   The token from the path.
	 * @param array<string,string> $headers The request headers that matter, lower-case: `range` and `if-range`.
	 * @param bool                 $accel   Whether to hand the file to Nginx (X-Accel-Redirect) instead of streaming it; serve() passes accel_ready().
	 * @param int|null             $site_id The site the request came to; the current site when null.
	 * @return array{status: int, headers: array<string,string>, file: string, offset: int, length: int, job_id: int, count: bool}
	 *         `file`, `offset` and `length` are the bytes PHP sends (none for a redirect or when Nginx sends them);
	 *         `count` is whether this is a download to count (a GET that starts the file, not a resume or a HEAD).
	 */
	public static function plan( string $method, string $token, array $headers = array(), bool $accel = false, ?int $site_id = null ): array {
		$method  = strtoupper( $method );
		$site_id = $site_id ?? get_current_blog_id();
		$link    = self::parse( $token );
		$file    = is_wp_error( $link ) ? $link : self::resolve( $link, $site_id );
		if ( is_wp_error( $file ) ) {
			return self::refuse();
		}

		$name = str_replace( array( '"', '\\', "\r", "\n" ), '', $file['name'] );
		$size = $file['size'];
		$plan = array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'           => 'database' === $file['which'] ? 'application/gzip' : 'application/zip',
				'Content-Disposition'    => 'attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode( $name ),
				'Content-Length'         => (string) $size,
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
				'X-Robots-Tag'           => 'noindex',
			),
			'file'    => $file['path'],
			'offset'  => 0,
			'length'  => $size,
			'job_id'  => $file['job']['id'],
			'count'   => 'GET' === $method,
		);
		$range = self::range( (string) ( $headers['range'] ?? '' ), $size );

		/**
		 * Filters whether the file is handed to Nginx with X-Accel-Redirect.
		 * On by default when Nginx has said it has the internal location
		 * (MODY_EXPORT_ACCEL) and the files are in the folder it serves; off
		 * elsewhere, where PHP streams the file.
		 *
		 * @param bool  $accel Whether to use X-Accel-Redirect.
		 * @param array $file  The file (resolve()).
		 */
		$accel    = (bool) apply_filters( 'mody_export_x_accel', $accel, $file );
		$internal = $accel ? self::internal_uri( $file['path'] ) : '';
		if ( '' !== $internal ) {
			// Nginx answers: it adds Accept-Ranges, the real length, ETag and the 206 itself.
			$plan['headers']['X-Accel-Redirect'] = $internal;
			$plan['file']                        = '';
			$plan['length']                      = 0;
			$plan['count']                       = 'GET' === $method && false !== $range && ( ! is_array( $range ) || 0 === $range[0] );
			return $plan;
		}

		$mtime = (int) filemtime( $file['path'] );
		$etag  = '"' . ( '' !== $file['sha256'] ? $file['sha256'] : $size . '-' . $mtime ) . '"';
		$last  = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';

		$plan['headers']['Accept-Ranges'] = 'bytes';
		$plan['headers']['ETag']          = $etag;
		$plan['headers']['Last-Modified'] = $last;

		// A resume is only a resume of the same file: If-Range names the ETag or date it was started from.
		$if_range = trim( (string) ( $headers['if-range'] ?? '' ) );
		if ( '' !== $if_range && $if_range !== $etag && $if_range !== $last ) {
			$range = null;
		}
		if ( false === $range ) {
			$plan['status']                    = 416;
			$plan['headers']['Content-Range']  = 'bytes */' . $size;
			$plan['headers']['Content-Length'] = '0';
			$plan['file']                      = '';
			$plan['length']                    = 0;
			$plan['count']                     = false;
		} elseif ( is_array( $range ) ) {
			$plan['status']                    = 206;
			$plan['headers']['Content-Range']  = 'bytes ' . $range[0] . '-' . $range[1] . '/' . $size;
			$plan['headers']['Content-Length'] = (string) ( $range[1] - $range[0] + 1 );
			$plan['offset']                    = $range[0];
			$plan['length']                    = $range[1] - $range[0] + 1;
			$plan['count']                     = 'GET' === $method && 0 === $range[0];
		}
		return $plan;
	}

	/**
	 * Serves a download, when this request is for one, and stops. Called when
	 * the MU plugin loads, before the rest of WordPress runs.
	 */
	public static function serve(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! str_starts_with( $path, self::PATH ) ) {
			return;
		}
		$plan = self::plan(
			$method,
			substr( $path, strlen( self::PATH ) ),
			array(
				'range'    => isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '',
				'if-range' => isset( $_SERVER['HTTP_IF_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_RANGE'] ) ) : '',
			),
			self::accel_ready()
		);
		if ( $plan['count'] ) {
			Jobs::count_download( $plan['job_id'] );
		}
		http_response_code( $plan['status'] );
		foreach ( $plan['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( 'HEAD' !== $method && '' !== $plan['file'] && $plan['length'] > 0 ) {
			if ( function_exists( 'ini_set' ) ) {
				ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- The file is already compressed, and its length is sent.
			}
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A slow connection takes longer than max_execution_time.
			}
			self::stream( $plan['file'], $plan['offset'], $plan['length'] );
		}
		exit;
	}

	/**
	 * Sends part of a file, in chunks.
	 *
	 * @param string        $path   The file.
	 * @param int           $offset First byte.
	 * @param int           $length Bytes to send.
	 * @param callable|null $write  Receives each chunk; echoes and flushes when null.
	 */
	public static function stream( string $path, int $offset, int $length, ?callable $write = null ): void {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A large file, read in chunks.
		if ( ! $handle ) {
			return;
		}
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}
		while ( $length > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, min( self::CHUNK, $length ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- As above.
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$length -= strlen( $chunk );
			if ( $write ) {
				$write( $chunk );
				continue;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file bytes.
			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();
			if ( connection_aborted() ) {
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- As above.
	}

	/**
	 * The bytes a Range header asks for.
	 *
	 * Only a single range is honoured (`bytes=a-b`, `bytes=a-`, `bytes=-n`);
	 * anything else is ignored and the whole file is sent, which HTTP allows.
	 *
	 * @param string $header The Range header.
	 * @param int    $size   The file's size.
	 * @return array{0: int, 1: int}|false|null First and last byte; false when nothing in the file is asked for (416); null for no usable range.
	 */
	public static function range( string $header, int $size ) {
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/i', trim( $header ), $match ) || ( '' === $match[1] && '' === $match[2] ) ) {
			return null;
		}
		if ( '' === $match[1] ) {
			$last = (int) $match[2]; // The last N bytes.
			return $last < 1 || $size < 1 ? false : array( max( 0, $size - $last ), $size - 1 );
		}
		$first = (int) $match[1];
		$end   = '' === $match[2] ? $size - 1 : min( (int) $match[2], $size - 1 );
		if ( $first >= $size ) {
			return false;
		}
		return $end < $first ? null : array( $first, $end );
	}

	/**
	 * A link that does not work: back to the export section of the site's
	 * dashboard, with a note for it to show.
	 *
	 * @return array{status: int, headers: array<string,string>, file: string, offset: int, length: int, job_id: int, count: bool}
	 */
	private static function refuse(): array {
		return array(
			'status'  => 302,
			'headers' => array(
				'Location'      => add_query_arg( self::EXPIRED_ARG, 'expired', admin_url( 'admin.php?page=mody-dashboard&section=export' ) ),
				'Cache-Control' => 'private, no-store',
				'X-Robots-Tag'  => 'noindex',
			),
			'file'    => '',
			'offset'  => 0,
			'length'  => 0,
			'job_id'  => 0,
			'count'   => false,
		);
	}

	/**
	 * Where Nginx's internal location finds a file: `/_mody_exports/<site>/<token>/<file>`.
	 *
	 * @param string $path The file's path.
	 * @return string Empty when the file is not under the exports folder (then PHP streams it).
	 */
	private static function internal_uri( string $path ): string {
		$root = Jobs::root() . '/';
		if ( ! str_starts_with( $path, $root ) ) {
			return '';
		}
		return '/_mody_exports/' . substr( $path, strlen( $root ) );
	}

	/**
	 * Whether Nginx has the internal location: the PHP location in
	 * config/nginx/app.conf.template sets `MODY_EXPORT_ACCEL 1`, which reaches PHP as a server variable.
	 * Nginx's name in SERVER_SOFTWARE says nothing about that, and an Nginx that was not restarted
	 * after the deploy has the name and not the location.
	 */
	private static function accel_ready(): bool {
		return isset( $_SERVER['MODY_EXPORT_ACCEL'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['MODY_EXPORT_ACCEL'] ) );
	}

	/**
	 * A token's signature; empty when there is no key to sign with.
	 *
	 * @param string $payload The encoded payload.
	 * @param bool   $create  Make the key when there is none. Only making a link does:
	 *                        checking one (any visitor can) never writes to the database.
	 */
	private static function sign( string $payload, bool $create = false ): string {
		$key = self::key( $create );
		return '' === $key ? '' : hash_hmac( 'sha256', $payload, $key );
	}

	/**
	 * The signing key: 32 random bytes in a network option, made the first time
	 * a link is, and kept (a link made before the next request must still
	 * verify). Read with get_site_option(), which works when serve() runs at MU
	 * plugin load, unlike wp_salt() (a pluggable function, not loaded yet).
	 *
	 * @param bool $create Make it when there is none.
	 * @return string Hex; empty when there is none and none was made.
	 */
	private static function key( bool $create ): string {
		$key = get_site_option( self::KEY_OPTION, '' );
		if ( ( ! is_string( $key ) || strlen( $key ) < 32 ) && $create ) {
			add_site_option( self::KEY_OPTION, bin2hex( random_bytes( 32 ) ) ); // Does nothing if another request made one first.
			$key = get_site_option( self::KEY_OPTION, '' );
		}
		return is_string( $key ) && strlen( $key ) >= 32 ? $key : '';
	}

	/**
	 * Base64 that is safe in a path.
	 *
	 * @param string $data Bytes.
	 */
	private static function encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A URL-safe token, not obfuscation.
	}

	/**
	 * The reverse of encode().
	 *
	 * @param string $data Encoded.
	 * @return string|false
	 */
	private static function decode( string $data ) {
		return base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- As above.
	}
}
