<?php
/**
 * Two-step login on the profile: set up an authenticator app, add security
 * keys, make recovery codes; a super admin can turn someone's off.
 *
 * @package Mody\Security
 */

namespace Mody\Security;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * The profile lives on the main site (Mody Profiles): the section shows on
 * one's own profile, and on the network's Edit User screen for a super admin
 * (status and Turn off only). Changes go through REST routes under
 * `mody-security/v1/two-factor`, with WordPress's cookie and nonce, each for
 * the current user only; the reset route is for super admins. The script is
 * plain (assets/two-factor-profile.js, no build step).
 */
final class TwoFactorProfile {

	/** The REST namespace. */
	public const NAMESPACE = 'mody-security/v1';

	/**
	 * Hooked from mody-security.php.
	 */
	public static function register(): void {
		add_action( 'show_user_profile', array( self::class, 'render_own' ), 1 );
		add_action( 'edit_user_profile', array( self::class, 'render_other' ), 1 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( is_multisite() ? 'user_admin_notices' : 'admin_notices', array( self::class, 'required_notice' ) );
	}

	/**
	 * `admin_enqueue_scripts`: the section's script and style, on the
	 * profile screens.
	 *
	 * @param string $hook The screen's hook.
	 */
	public static function enqueue( $hook ): void {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'mody-two-factor-profile', TwoFactorLogin::asset_url( 'two-factor-profile.css' ), array(), TwoFactorLogin::asset_version( 'two-factor-profile.css' ) );
		wp_enqueue_script( 'mody-two-factor-profile', TwoFactorLogin::asset_url( 'two-factor-profile.js' ), array(), TwoFactorLogin::asset_version( 'two-factor-profile.js' ), true );
		wp_localize_script(
			'mody-two-factor-profile',
			'modyTwoFactor',
			array(
				'root'  => esc_url_raw( rest_url( self::NAMESPACE . '/two-factor' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'confirmRemoveApp' => __( 'Remove the authenticator app from your account?', 'mody_cloud' ),
					'confirmRemoveKey' => __( 'Remove this security key from your account?', 'mody_cloud' ),
					'confirmCodes'     => __( 'Make new recovery codes? The ones you have stop working.', 'mody_cloud' ),
					'confirmReset'     => __( 'Turn off two-step login for this account? Its app, keys and recovery codes are removed, and the person is told by email.', 'mody_cloud' ),
					'touchKey'         => __( 'Touch your security key, or follow your browser\'s steps…', 'mody_cloud' ),
					'noWebAuthn'       => __( 'This browser cannot use security keys.', 'mody_cloud' ),
					'cancelled'        => __( 'Cancelled, or the key did not answer. Try again.', 'mody_cloud' ),
					'failed'           => __( 'That did not work. Please try again.', 'mody_cloud' ),
					'codesTitle'       => __( 'Your recovery codes', 'mody_cloud' ),
					'codesHelp'        => __( 'Each code logs you in once if you lose your phone or key. Save them somewhere safe now: they are not shown again.', 'mody_cloud' ),
					'codesDone'        => __( 'I saved them', 'mody_cloud' ),
					'download'         => __( 'Download', 'mody_cloud' ),
				),
			)
		);
	}

	/**
	 * `user_admin_notices`: why someone was sent here.
	 */
	public static function required_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which notice to show.
		if ( 'required' !== ( $_GET['mody_two_factor'] ?? '' ) || TwoFactor::enabled( get_current_user_id() ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Your account must use two-step login. Set up an authenticator app or a security key below to continue.', 'mody_cloud' ) . ' <a href="#mody-two-factor">' . esc_html__( 'Set it up', 'mody_cloud' ) . '</a></p></div>';
	}

	/**
	 * `show_user_profile`: one's own section.
	 *
	 * @param WP_User $user The user.
	 */
	public static function render_own( $user ): void {
		if ( ! $user instanceof WP_User || get_current_user_id() !== $user->ID ) {
			return;
		}
		$id       = $user->ID;
		$app      = TwoFactor::has_totp( $id );
		$keys     = TwoFactor::keys( $id );
		$enabled  = $app || $keys;
		$required = TwoFactor::required( $user );
		?>
		<div class="mody-two-factor" id="mody-two-factor">
			<h2><?php esc_html_e( 'Two-step login', 'mody_cloud' ); ?></h2>
			<p class="mody-two-factor__state">
				<?php if ( $enabled ) : ?>
					<span class="mody-two-factor__badge is-on"><?php esc_html_e( 'On', 'mody_cloud' ); ?></span>
					<?php esc_html_e( 'After your password, logging in asks for a code from your app or your security key.', 'mody_cloud' ); ?>
				<?php else : ?>
					<span class="mody-two-factor__badge"><?php esc_html_e( 'Off', 'mody_cloud' ); ?></span>
					<?php echo esc_html( $required ? __( 'Your account must use it: set up an app or a key below.', 'mody_cloud' ) : __( 'Add a second step to your password: a code from an app on your phone, or a security key.', 'mody_cloud' ) ); ?>
				<?php endif; ?>
			</p>
			<p class="mody-two-factor__message" role="status" aria-live="polite" hidden></p>

			<h3><?php esc_html_e( 'Authenticator app', 'mody_cloud' ); ?></h3>
			<?php if ( $app ) : ?>
				<p>
					<?php
					/* translators: %s: a date. */
					echo esc_html( sprintf( __( 'Added on %s.', 'mody_cloud' ), wp_date( get_option( 'date_format' ), TwoFactor::totp_added( $id ) ) ) );
					?>
					<button type="button" class="button button-link-delete" data-mody-2fa="remove-app"><?php esc_html_e( 'Remove', 'mody_cloud' ); ?></button>
				</p>
			<?php else : ?>
				<?php
				$secret  = TwoFactor::setup_secret( $id );
				$network = is_multisite() ? wp_specialchars_decode( (string) get_network()->site_name, ENT_QUOTES ) : get_bloginfo( 'name' );
				$uri     = Totp::uri( $secret, $user->user_email, $network );
				?>
				<details class="mody-two-factor__setup" <?php echo $required && ! $enabled ? 'open' : ''; ?>>
					<summary class="button"><?php esc_html_e( 'Set up an authenticator app', 'mody_cloud' ); ?></summary>
					<div class="mody-two-factor__setup-body">
						<div class="mody-two-factor__qr"><?php echo Qr::svg( $uri, __( 'QR code for your authenticator app', 'mody_cloud' ), 184 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from numbers and an escaped label. ?></div>
						<ol>
							<li><?php esc_html_e( 'Open an authenticator app on your phone (any app that reads these codes: Aegis, 2FAS, Proton Pass, your password manager…) and scan the code.', 'mody_cloud' ); ?></li>
							<li>
								<?php esc_html_e( 'Cannot scan it? Type this key in the app instead:', 'mody_cloud' ); ?>
								<code class="mody-two-factor__secret"><?php echo esc_html( Totp::grouped( $secret ) ); ?></code>
							</li>
							<li>
								<label for="mody-2fa-confirm"><?php esc_html_e( 'Type the six-digit code the app shows:', 'mody_cloud' ); ?></label><br />
								<input type="text" id="mody-2fa-confirm" class="regular-text mody-two-factor__code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" spellcheck="false" />
								<button type="button" class="button button-primary" data-mody-2fa="confirm-app"><?php esc_html_e( 'Turn on', 'mody_cloud' ); ?></button>
							</li>
						</ol>
					</div>
				</details>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Security keys and passkeys', 'mody_cloud' ); ?></h3>
			<?php if ( $keys ) : ?>
				<table class="widefat striped mody-two-factor__keys">
					<thead><tr><th><?php esc_html_e( 'Name', 'mody_cloud' ); ?></th><th><?php esc_html_e( 'Added', 'mody_cloud' ); ?></th><th><?php esc_html_e( 'Last used', 'mody_cloud' ); ?></th><th><?php esc_html_e( 'Works on', 'mody_cloud' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mody_cloud' ); ?></span></th></tr></thead>
					<tbody>
						<?php foreach ( $keys as $key ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $key['name'] ); ?></td>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $key['added'] ) ); ?></td>
								<td><?php echo esc_html( $key['used'] ? wp_date( get_option( 'date_format' ), (int) $key['used'] ) : __( 'Not yet', 'mody_cloud' ) ); ?></td>
								<td><?php echo esc_html( (string) $key['rp'] ); ?></td>
								<td><button type="button" class="button button-link-delete" data-mody-2fa="remove-key" data-id="<?php echo esc_attr( (string) $key['id'] ); ?>"><?php esc_html_e( 'Remove', 'mody_cloud' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( count( $keys ) < TwoFactor::MAX_KEYS ) : ?>
				<p class="mody-two-factor__add-key">
					<label for="mody-2fa-key-name"><?php esc_html_e( 'Name', 'mody_cloud' ); ?></label>
					<input type="text" id="mody-2fa-key-name" class="regular-text" maxlength="60" placeholder="<?php esc_attr_e( 'For example: blue key, laptop', 'mody_cloud' ); ?>" />
					<button type="button" class="button" data-mody-2fa="add-key"><?php esc_html_e( 'Add a security key', 'mody_cloud' ); ?></button>
				</p>
				<p class="description">
					<?php
					/* translators: %s: an address, such as mody.cloud. */
					echo esc_html( sprintf( __( 'A key works on %s and its sites. On a site with its own domain, log in with your app or a recovery code.', 'mody_cloud' ), WebAuthn::rp_id() ) );
					?>
				</p>
			<?php endif; ?>

			<?php if ( $enabled ) : ?>
				<h3><?php esc_html_e( 'Recovery codes', 'mody_cloud' ); ?></h3>
				<p>
					<?php
					$left = TwoFactor::codes_left( $id );
					/* translators: %d: a number of codes. */
					echo esc_html( sprintf( _n( '%d code left.', '%d codes left.', $left, 'mody_cloud' ), $left ) );
					?>
					<button type="button" class="button" data-mody-2fa="new-codes"><?php esc_html_e( 'Make new codes', 'mody_cloud' ); ?></button>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * `edit_user_profile`: someone else's status, and Turn off for super
	 * admins.
	 *
	 * @param WP_User $user The user.
	 */
	public static function render_other( $user ): void {
		if ( ! $user instanceof WP_User || ! current_user_can( self::manage_others() ) ) {
			return;
		}
		$enabled = TwoFactor::enabled( $user );
		?>
		<div class="mody-two-factor" id="mody-two-factor">
			<h2><?php esc_html_e( 'Two-step login', 'mody_cloud' ); ?></h2>
			<p class="mody-two-factor__message" role="status" aria-live="polite" hidden></p>
			<p>
				<?php if ( $enabled ) : ?>
					<span class="mody-two-factor__badge is-on"><?php esc_html_e( 'On', 'mody_cloud' ); ?></span>
					<?php
					$methods = array();
					if ( TwoFactor::has_totp( $user->ID ) ) {
						$methods[] = __( 'an authenticator app', 'mody_cloud' );
					}
					$count = count( TwoFactor::keys( $user->ID ) );
					if ( $count ) {
						/* translators: %d: a number of keys. */
						$methods[] = sprintf( _n( '%d security key', '%d security keys', $count, 'mody_cloud' ), $count );
					}
					echo esc_html( implode( ', ', $methods ) . '.' );
					?>
					<button type="button" class="button button-link-delete" data-mody-2fa="reset" data-user="<?php echo esc_attr( (string) $user->ID ); ?>"><?php esc_html_e( 'Turn off two-step login', 'mody_cloud' ); ?></button>
				<?php else : ?>
					<span class="mody-two-factor__badge"><?php esc_html_e( 'Off', 'mody_cloud' ); ?></span>
					<?php echo esc_html( TwoFactor::required( $user ) ? __( 'This account must set it up at its next visit to wp-admin.', 'mody_cloud' ) : __( 'Not set up.', 'mody_cloud' ) ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * `rest_api_init`.
	 */
	public static function register_routes(): void {
		$own = array( self::class, 'can_edit_own' );
		register_rest_route(
			self::NAMESPACE,
			'/two-factor/app',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'rest_confirm_app' ),
					'permission_callback' => $own,
					'args'                => array(
						'code' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'rest_remove_app' ),
					'permission_callback' => $own,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/two-factor/keys/options',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_key_options' ),
				'permission_callback' => $own,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/two-factor/keys',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_add_key' ),
				'permission_callback' => $own,
				'args'                => array(
					'name'              => array( 'type' => 'string' ),
					'clientDataJSON'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'attestationObject' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/two-factor/keys/(?P<id>[A-Za-z0-9_-]{16,1400})',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'rest_remove_key' ),
				'permission_callback' => $own,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/two-factor/codes',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_new_codes' ),
				'permission_callback' => $own,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/two-factor/users/(?P<user>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'rest_reset' ),
				'permission_callback' => static fn() => current_user_can( self::manage_others() ),
			)
		);
	}

	/**
	 * Who may see and turn off other people's two-step login: the network's
	 * user managers, or a single site's.
	 */
	public static function manage_others(): string {
		return is_multisite() ? 'manage_network_users' : 'edit_users';
	}

	/**
	 * Logged in (through the cookie and its nonce): one's own setup.
	 */
	public static function can_edit_own(): bool {
		return is_user_logged_in();
	}

	/**
	 * POST app: turns the app on with a code.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_confirm_app( WP_REST_Request $request ) {
		$id = get_current_user_id();
		if ( TwoFactor::has_totp( $id ) ) {
			return new WP_Error( 'mody_two_factor_exists', __( 'An authenticator app is already set up.', 'mody_cloud' ), array( 'status' => 409 ) );
		}
		$wait = RateLimit::hit( 'two_factor', 'setup:' . $id );
		if ( $wait ) {
			return RateLimit::error( $wait );
		}
		if ( ! TwoFactor::confirm_totp( $id, (string) $request['code'] ) ) {
			return new WP_Error( 'mody_two_factor_code', __( 'That code is not right. Check the time on your phone, and type the code the app shows now.', 'mody_cloud' ), array( 'status' => 400 ) );
		}
		RateLimit::reset( 'two_factor', 'setup:' . $id );
		Events::record( 'two_factor_on', $id, 'authenticator app added' );
		return self::changed( $id );
	}

	/**
	 * DELETE app.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_remove_app() {
		$id = get_current_user_id();
		TwoFactor::remove_totp( $id );
		Events::record( TwoFactor::enabled( $id ) ? 'two_factor_on' : 'two_factor_off', $id, 'authenticator app removed' );
		self::tell( $id, __( 'The authenticator app was removed from your account.', 'mody_cloud' ) );
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * POST keys/options: the options for `navigator.credentials.create()`;
	 * the challenge is kept on the user for five minutes.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_key_options() {
		$user = wp_get_current_user();
		$keys = TwoFactor::keys( $user->ID );
		if ( count( $keys ) >= TwoFactor::MAX_KEYS ) {
			return new WP_Error( 'mody_two_factor_keys', __( 'You have as many keys as an account can have. Remove one first.', 'mody_cloud' ), array( 'status' => 409 ) );
		}
		$challenge = random_bytes( 32 );
		$rp_id     = WebAuthn::rp_id();
		update_user_meta(
			$user->ID,
			'mody_webauthn_challenge',
			array(
				'challenge' => base64_encode( $challenge ),
				'rp'        => $rp_id,
				'expires'   => time() + 5 * MINUTE_IN_SECONDS,
			)
		);
		return new WP_REST_Response( WebAuthn::creation_options( $user, $challenge, $rp_id, $keys ) );
	}

	/**
	 * POST keys: checks and stores the new key.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_add_key( WP_REST_Request $request ) {
		$id    = get_current_user_id();
		$saved = get_user_meta( $id, 'mody_webauthn_challenge', true );
		delete_user_meta( $id, 'mody_webauthn_challenge' );
		if ( ! is_array( $saved ) || (int) ( $saved['expires'] ?? 0 ) < time() ) {
			return new WP_Error( 'mody_two_factor_expired', __( 'That took too long. Please try again.', 'mody_cloud' ), array( 'status' => 400 ) );
		}
		$credential = WebAuthn::verify_registration(
			WebAuthn::from_b64url( (string) $request['clientDataJSON'] ),
			WebAuthn::from_b64url( (string) $request['attestationObject'] ),
			(string) base64_decode( (string) $saved['challenge'], true ),
			(string) $saved['rp']
		);
		if ( is_wp_error( $credential ) ) {
			$credential->add_data( array( 'status' => 400 ) );
			return $credential;
		}
		if ( ! TwoFactor::add_key( $id, $credential, (string) $request['name'] ) ) {
			return new WP_Error( 'mody_two_factor_keys', __( 'That key is already on your account, or you have as many as an account can have.', 'mody_cloud' ), array( 'status' => 409 ) );
		}
		Events::record( 'two_factor_on', $id, 'security key added' );
		return self::changed( $id );
	}

	/**
	 * DELETE keys/<id>.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_remove_key( WP_REST_Request $request ) {
		$id = get_current_user_id();
		if ( ! TwoFactor::remove_key( $id, (string) $request['id'] ) ) {
			return new WP_Error( 'mody_two_factor_key', __( 'That key is not on your account.', 'mody_cloud' ), array( 'status' => 404 ) );
		}
		Events::record( TwoFactor::enabled( $id ) ? 'two_factor_on' : 'two_factor_off', $id, 'security key removed' );
		self::tell( $id, __( 'A security key was removed from your account.', 'mody_cloud' ) );
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * POST codes: a new set of recovery codes.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_new_codes() {
		$id = get_current_user_id();
		if ( ! TwoFactor::enabled( $id ) ) {
			return new WP_Error( 'mody_two_factor_off', __( 'Set up an app or a key first.', 'mody_cloud' ), array( 'status' => 409 ) );
		}
		Events::record( 'two_factor_on', $id, 'new recovery codes' );
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'codes' => TwoFactor::new_codes( $id ),
			)
		);
	}

	/**
	 * DELETE users/<id>: a super admin turns someone's two-step login off.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_reset( WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['user'] );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'mody_two_factor_user', __( 'No such user.', 'mody_cloud' ), array( 'status' => 404 ) );
		}
		TwoFactor::reset( $user->ID );
		Events::record( 'two_factor_off', $user->ID, 'turned off by ' . wp_get_current_user()->user_login );
		Alerts::two_factor_changed( $user, __( 'A super admin turned off two-step login on your account.', 'mody_cloud' ) );
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * After a method was added: the first one also makes the recovery codes,
	 * returned to be shown once.
	 *
	 * @param int $user_id The user.
	 */
	private static function changed( int $user_id ): WP_REST_Response {
		$data = array( 'ok' => true );
		if ( 0 === TwoFactor::codes_left( $user_id ) ) {
			$data['codes'] = TwoFactor::new_codes( $user_id );
		}
		return new WP_REST_Response( $data );
	}

	/**
	 * Emails the account about a change it made, so a stolen session cannot
	 * remove a method unnoticed.
	 *
	 * @param int    $user_id The user.
	 * @param string $what    What happened.
	 */
	private static function tell( int $user_id, string $what ): void {
		$user = get_userdata( $user_id );
		if ( $user instanceof WP_User ) {
			Alerts::two_factor_changed( $user, $what );
		}
	}
}
