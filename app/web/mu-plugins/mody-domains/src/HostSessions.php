<?php
/**
 * Login sessions bound to where they were made: the network's own addresses,
 * or one member's domain.
 *
 * @package Mody\Domains
 */

namespace Mody\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Since 2026-11-20 a member's site is edited at its own domain (#19), so
 * people log in there, and a member controls where their domain's DNS points.
 * WordPress's login cookie names a session token, and a token works on any
 * host of the network: a member could capture the session of someone who
 * logged in on their domain (an editor they invited) and use it where that
 * person has other roles. Decided by the agent on 2026-11-20 (#27, the open
 * question left by #19; in the night report):
 *
 * - Each new session records its **scope** (`mody_scope`): `network` when it
 *   is made on `mody.cloud` or a `*.mody.cloud` address (and in WP-CLI), or
 *   the member's domain it is made on.
 * - A session counts only in its own scope: a login on `www.shop.com` works
 *   on `www.shop.com` alone; a login on the network works on every network
 *   address (the shared login of the access model), never on a member's
 *   domain.
 * - A session from before this has no scope and counts as `network`, so
 *   nobody on the network is logged out; a session made on a member's domain
 *   before it asks for the login there again.
 *
 * Super admins never log in on a member's domain at all (Domains).
 */
final class HostSessions extends \WP_User_Meta_Session_Tokens {

	/** The session's key for its scope. */
	public const KEY = 'mody_scope';

	/** The scope of the network's own addresses. */
	public const NETWORK = 'network';

	/**
	 * Hooks: the session manager, and the scope stored with each new session.
	 */
	public static function register(): void {
		add_filter( 'session_token_manager', static fn(): string => self::class );
		add_filter( 'attach_session_information', array( self::class, 'attach' ) );
	}

	/**
	 * The scope of the request running now.
	 */
	public static function scope(): string {
		$host = Mapping::request_host();
		return '' === $host || Mapping::is_network_host( $host ) ? self::NETWORK : $host;
	}

	/**
	 * `attach_session_information`: the new session's scope.
	 *
	 * @param array<string, mixed> $session What WordPress stores with the session.
	 * @return array<string, mixed>
	 */
	public static function attach( array $session ): array {
		$session[ self::KEY ] = self::scope();
		return $session;
	}

	/**
	 * A session, only when it was made in this request's scope.
	 *
	 * @param string $verifier The hashed token.
	 * @return array<string, mixed>|null
	 */
	protected function get_session( $verifier ) {
		$session = parent::get_session( $verifier );
		if ( ! is_array( $session ) ) {
			return $session;
		}
		$scope = isset( $session[ self::KEY ] ) && is_string( $session[ self::KEY ] ) ? $session[ self::KEY ] : self::NETWORK;
		return $scope === self::scope() ? $session : null;
	}
}
