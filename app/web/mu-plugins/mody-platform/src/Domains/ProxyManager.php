<?php
/**
 * Nginx Proxy Manager's API: a certificate and a proxy host per custom domain.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Domains;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-13: Nginx Proxy Manager (NPM) on the
 * production host stays the one front door, holding every certificate. For a
 * verified domain the platform asks NPM for a Let's Encrypt certificate
 * (HTTP-01: the domain already points at the host's ports 80 and 443, which
 * go to NPM) and a proxy host that forwards like the network's own
 * (`mody.cloud`'s proxy host: same scheme, host and port), so the request
 * reaches this stack with its own Host header.
 *
 * Configuration, only from the environment (never stored):
 * - `NPM_API_URL`: NPM's API as the app container reaches it, such as
 *   `http://host.docker.internal:81/api`.
 * - `NPM_API_EMAIL` and `NPM_API_PASSWORD`: an NPM user that may manage
 *   proxy hosts and certificates.
 * - `NPM_LETSENCRYPT_EMAIL` (optional): Let's Encrypt's contact; else the
 *   network option `mody_seller_email`.
 */
final class ProxyManager {

	/** Seconds a request may take (a certificate takes a while). */
	private const TIMEOUT = 120;

	/** The token for this request. */
	private static string $token = '';

	/**
	 * Whether NPM is set up.
	 */
	public static function configured(): bool {
		return '' !== self::url() && '' !== (string) getenv( 'NPM_API_EMAIL' ) && '' !== (string) getenv( 'NPM_API_PASSWORD' );
	}

	/**
	 * The API's base address.
	 */
	private static function url(): string {
		return rtrim( (string) getenv( 'NPM_API_URL' ), '/' );
	}

	/**
	 * Gives a domain its certificate and proxy host.
	 *
	 * @param string $domain A verified domain.
	 * @return array{host: int, certificate: int}|WP_Error
	 */
	public static function add( string $domain ): array|WP_Error {
		$forward = self::forward();
		if ( is_wp_error( $forward ) ) {
			return $forward;
		}
		// A host or certificate left from an earlier try is reused, never doubled.
		$certificate = self::find( '/nginx/certificates', $domain );
		if ( is_wp_error( $certificate ) ) {
			return $certificate;
		}
		if ( ! $certificate ) {
			$email = (string) ( getenv( 'NPM_LETSENCRYPT_EMAIL' ) ?: get_site_option( 'mody_seller_email', '' ) );
			$made  = self::request(
				'POST',
				'/nginx/certificates',
				array(
					'provider'     => 'letsencrypt',
					'domain_names' => array( $domain ),
					'meta'         => array(
						'letsencrypt_email' => $email,
						'letsencrypt_agree' => true,
						'dns_challenge'     => false,
					),
				)
			);
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$certificate = (int) ( $made['id'] ?? 0 );
		}
		$host = self::find( '/nginx/proxy-hosts', $domain );
		if ( is_wp_error( $host ) ) {
			return $host;
		}
		if ( ! $host ) {
			$made = self::request(
				'POST',
				'/nginx/proxy-hosts',
				array(
					'domain_names'            => array( $domain ),
					'forward_scheme'          => $forward['scheme'],
					'forward_host'            => $forward['host'],
					'forward_port'            => $forward['port'],
					'access_list_id'          => 0,
					'certificate_id'          => $certificate,
					'ssl_forced'              => true,
					'http2_support'           => true,
					'block_exploits'          => true,
					'caching_enabled'         => false,
					'allow_websocket_upgrade' => false,
					'hsts_enabled'            => false,
					'hsts_subdomains'         => false,
					'advanced_config'         => '',
					'locations'               => array(),
					'meta'                    => array(
						'letsencrypt_agree' => false,
						'dns_challenge'     => false,
					),
				)
			);
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$host = (int) ( $made['id'] ?? 0 );
		}
		return $host && $certificate
			? array(
				'host'        => $host,
				'certificate' => $certificate,
			)
			: new WP_Error( 'mody_npm', 'NPM did not return a host or a certificate.' );
	}

	/**
	 * Removes a domain's proxy host and certificate.
	 *
	 * @param int $host        The proxy host's ID, or 0.
	 * @param int $certificate The certificate's ID, or 0.
	 * @return true|WP_Error
	 */
	public static function remove( int $host, int $certificate ): bool|WP_Error {
		foreach ( array( '/nginx/proxy-hosts/' => $host, '/nginx/certificates/' => $certificate ) as $path => $id ) {
			if ( $id > 0 ) {
				$done = self::request( 'DELETE', $path . $id );
				if ( is_wp_error( $done ) && 404 !== (int) ( $done->get_error_data()['status'] ?? 0 ) ) {
					return $done;
				}
			}
		}
		return true;
	}

	/**
	 * Where the network's own proxy host forwards to, so a domain's goes there too.
	 *
	 * @return array{scheme: string, host: string, port: int}|WP_Error
	 */
	private static function forward(): array|WP_Error {
		$hosts = self::request( 'GET', '/nginx/proxy-hosts' );
		if ( is_wp_error( $hosts ) ) {
			return $hosts;
		}
		$network = Mapping::network_domain();
		foreach ( $hosts as $host ) {
			if ( in_array( $network, (array) ( $host['domain_names'] ?? array() ), true ) ) {
				return array(
					'scheme' => (string) ( $host['forward_scheme'] ?? 'http' ),
					'host'   => (string) $host['forward_host'],
					'port'   => (int) $host['forward_port'],
				);
			}
		}
		return new WP_Error( 'mody_npm_network', sprintf( 'NPM has no proxy host for %s to copy.', $network ) );
	}

	/**
	 * The ID of the certificate or proxy host that holds a domain, or 0.
	 *
	 * @param string $path   `/nginx/certificates` or `/nginx/proxy-hosts`.
	 * @param string $domain The domain.
	 * @return int|WP_Error
	 */
	private static function find( string $path, string $domain ): int|WP_Error {
		$items = self::request( 'GET', $path );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		foreach ( $items as $item ) {
			if ( in_array( $domain, (array) ( $item['domain_names'] ?? array() ), true ) ) {
				return (int) $item['id'];
			}
		}
		return 0;
	}

	/**
	 * One API request, logged in first.
	 *
	 * @param string                    $method GET, POST or DELETE.
	 * @param string                    $path   After the base.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array<mixed>|WP_Error
	 */
	private static function request( string $method, string $path, ?array $body = null ): array|WP_Error {
		if ( ! self::configured() ) {
			return new WP_Error( 'mody_npm_off', __( 'Certificates are not set up on this server yet.', 'mody_cloud' ) );
		}
		if ( '' === self::$token ) {
			$login = wp_remote_post(
				self::url() . '/tokens',
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'identity' => (string) getenv( 'NPM_API_EMAIL' ),
							'secret'   => (string) getenv( 'NPM_API_PASSWORD' ),
						)
					),
					'timeout' => 15,
				)
			);
			$data = json_decode( (string) wp_remote_retrieve_body( $login ), true );
			if ( is_wp_error( $login ) || empty( $data['token'] ) ) {
				return new WP_Error( 'mody_npm_login', 'Could not log in to NPM.' );
			}
			self::$token = (string) $data['token'];
		}
		$response = wp_remote_request(
			self::url() . $path,
			array(
				'method'  => $method,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::$token,
					'Content-Type'  => 'application/json',
				),
				'body'    => null !== $body ? wp_json_encode( $body ) : null,
				'timeout' => self::TIMEOUT,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mody_npm_down', 'NPM did not answer: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$detail = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			return new WP_Error( 'mody_npm', '' !== $detail ? $detail : 'NPM answered ' . $code, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Forgets the token (tests).
	 */
	public static function reset(): void {
		self::$token = '';
	}
}
