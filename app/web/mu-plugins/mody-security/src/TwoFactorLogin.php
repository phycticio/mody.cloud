<?php
/**
 * Two-step login on wp-login.php: the second screen after the password,
 * and the push to set it up for those who must.
 *
 * @package Mody\Security
 */

namespace Mody\Security;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * **The second screen.** When the password, ALTCHA and the limits pass for
 * an account with two-step login (`authenticate`, after FormGuard), no
 * cookie is set: the login waits, for ten minutes, under a random token
 * (a site transient keyed by its hash), and the same request shows the
 * second screen. It posts to `wp-login.php?action=mody_two_factor` with
 * the token and either a code (from the app, or a recovery code) or a
 * security key's signed answer. Wrong answers count in the `two_factor`
 * limit; reaching it ends the login. A right one sets the cookie, fires
 * `wp_login` as core does (FormGuard's after_login, the log, alerts) and
 * redirects as core would. wp-admin's "session expired" window works the
 * same way (`interim-login`).
 *
 * **Not asked:** application passwords (REST, MCP) and WP-CLI, which never
 * use the form; the member's first password, which logs in from the reset
 * screen before any method can exist (Mody Onboarding).
 *
 * **Must set it up:** someone TwoFactor::required() and without a method
 * gets wp-admin's screens sent to their profile's Two-step login.
 */
final class TwoFactorLogin {

	/** wp-login.php's action for the second screen. */
	public const ACTION = 'mody_two_factor';

	/** Seconds a waiting login lasts. */
	public const TTL = 600;

	/**
	 * Hooked from mody-security.php.
	 */
	public static function register(): void {
		add_filter( 'authenticate', array( self::class, 'intercept' ), 200 );
		add_action( 'login_form_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_init', array( self::class, 'enforce' ), 1 );
	}

	/**
	 * Whether this request is a POST from wp-login.php's form.
	 */
	private static function from_login_form(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login.php's own form has no nonce; only its presence is read.
		return 'POST' === $method && 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) && ! empty( $_POST['log'] );
	}

	/**
	 * `authenticate`, last: a right password for an account with two-step
	 * login shows the second screen instead of logging in.
	 *
	 * @param WP_User|WP_Error|null $user The result so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function intercept( $user ) {
		if ( ! $user instanceof WP_User || ! self::from_login_form() || ! TwoFactor::enabled( $user ) ) {
			return $user;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- wp-login.php's form has no nonce; the token below is the check from here on.
		$redirect = isset( $_REQUEST['redirect_to'] ) ? sanitize_url( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		$pending  = array(
			'user'      => $user->ID,
			'remember'  => ! empty( $_POST['rememberme'] ),
			'redirect'  => $redirect,
			'challenge' => random_bytes( 32 ),
			'expires'   => time() + self::TTL,
		);
		// phpcs:enable
		$token = bin2hex( random_bytes( 32 ) );
		self::save_pending( $token, $pending );
		self::render( $user, $token, $pending );
		exit;
	}

	/**
	 * `login_form_mody_two_factor`: checks the second step.
	 */
	public static function handle(): void {
		global $interim_login;
		// wp-login.php sets this only after `login_form_<action>`: set it as it would.
		$interim_login = isset( $_REQUEST['interim-login'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.NonceVerification.Recommended -- wp-login.php's own flag.
		$method        =isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The token is the check: random, single use, ten minutes.
		$token   = isset( $_POST['mody_token'] ) ? sanitize_key( wp_unslash( $_POST['mody_token'] ) ) : '';
		$pending = 'POST' === $method ? self::pending( $token ) : null;
		if ( null === $pending ) {
			self::render_message( __( 'That login has expired. Please log in again.', 'mody_cloud' ) );
			exit;
		}
		$user = get_userdata( (int) $pending['user'] );
		if ( ! $user instanceof WP_User || ! TwoFactor::enabled( $user ) ) {
			self::delete_pending( $token );
			self::render_message( __( 'That login has expired. Please log in again.', 'mody_cloud' ) );
			exit;
		}
		$subject = 'user:' . $user->ID;
		$wait    = RateLimit::wait( 'two_factor', $subject );
		if ( $wait ) {
			self::delete_pending( $token );
			self::render_message( RateLimit::message( $wait, __( 'Too many wrong codes for this account. Please try again in %s.', 'mody_cloud' ) ) );
			exit;
		}

		$answer = isset( $_POST['mody_webauthn'] ) ? sanitize_text_field( wp_unslash( $_POST['mody_webauthn'] ) ) : '';
		$typed  = isset( $_POST['mody_code'] ) ? sanitize_text_field( wp_unslash( $_POST['mody_code'] ) ) : '';
		// phpcs:enable
		$how = '' !== $answer ? self::check_key( $user, $answer, $pending['challenge'] ) : TwoFactor::verify_typed( $user->ID, $typed );

		if ( '' === $how ) {
			Events::record( 'two_factor_failed', $user->ID, '' !== $answer ? 'security key' : 'code' );
			if ( RateLimit::hit( 'two_factor', $subject ) || RateLimit::wait( 'two_factor', $subject ) ) {
				self::delete_pending( $token );
				self::render_message( RateLimit::message( RateLimit::wait( 'two_factor', $subject ), __( 'Too many wrong codes for this account. Please try again in %s.', 'mody_cloud' ) ) );
				exit;
			}
			$pending['challenge'] = random_bytes( 32 );
			self::save_pending( $token, $pending );
			$error = '' !== $answer ? __( 'That security key did not work. Try again, or use a code.', 'mody_cloud' ) : __( 'That code is not right. Check it and try again.', 'mody_cloud' );
			self::render( $user, $token, $pending, new WP_Error( 'mody_two_factor', $error ) );
			exit;
		}

		self::delete_pending( $token );
		RateLimit::reset( 'two_factor', $subject );
		self::finish( $user, $pending, $how );
	}

	/**
	 * Checks a security key's answer.
	 *
	 * @param WP_User $user      The account.
	 * @param string  $answer    JSON: id, clientDataJSON, authenticatorData, signature (base64url).
	 * @param string  $challenge The challenge, raw.
	 * @return string 'key', or '' when it is wrong.
	 */
	private static function check_key( WP_User $user, string $answer, string $challenge ): string {
		$data = json_decode( $answer, true );
		if ( ! is_array( $data ) ) {
			return '';
		}
		$credential = TwoFactor::find_key( $user->ID, (string) ( $data['id'] ?? '' ) );
		if ( null === $credential ) {
			return '';
		}
		$count = WebAuthn::verify_assertion(
			$credential,
			WebAuthn::from_b64url( (string) ( $data['clientDataJSON'] ?? '' ) ),
			WebAuthn::from_b64url( (string) ( $data['authenticatorData'] ?? '' ) ),
			WebAuthn::from_b64url( (string) ( $data['signature'] ?? '' ) ),
			$challenge,
			WebAuthn::rp_id()
		);
		if ( is_wp_error( $count ) ) {
			return '';
		}
		TwoFactor::used_key( $user->ID, (string) $credential['id'], $count );
		return 'key';
	}

	/**
	 * Logs in and redirects, as wp-login.php does after a password.
	 *
	 * @param WP_User              $user    The account.
	 * @param array<string, mixed> $pending The waiting login.
	 * @param string               $how     'totp', 'key' or 'recovery'.
	 */
	private static function finish( WP_User $user, array $pending, string $how ): void {
		global $interim_login;

		wp_set_auth_cookie( $user->ID, (bool) $pending['remember'] );
		wp_set_current_user( $user->ID );
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user );

		if ( 'recovery' === $how ) {
			Alerts::recovery_code_used( $user );
		}

		if ( $interim_login ) {
			$interim_login = 'success'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp-login.php's own flag: login_header() reads it.
			login_header( '', '<p class="message">' . esc_html__( 'You have logged in successfully.' ) . '</p>' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's words.
			echo '</div>';
			/** This action is documented in wp-login.php */
			do_action( 'login_footer' );
			echo '</body></html>';
			exit;
		}

		$requested = (string) $pending['redirect'];
		$default   = '' !== $requested ? $requested : admin_url();
		/** This filter is documented in wp-login.php */
		$redirect = apply_filters( 'login_redirect', $default, $requested, $user );
		if ( empty( $redirect ) || 'wp-admin/' === $redirect || admin_url() === $redirect ) {
			if ( is_multisite() && ! get_active_blog_for_user( $user->ID ) && ! is_super_admin( $user->ID ) ) {
				$redirect = user_admin_url();
			} elseif ( is_multisite() && ! $user->has_cap( 'read' ) ) {
				$redirect = get_dashboard_url( $user->ID );
			} elseif ( ! $user->has_cap( 'edit_posts' ) ) {
				$redirect = $user->has_cap( 'read' ) ? admin_url( 'profile.php' ) : home_url();
			}
			wp_redirect( $redirect ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- As wp-login.php: one of WordPress's own addresses.
			exit;
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * The second screen.
	 *
	 * @param WP_User              $user    The account.
	 * @param string               $token   The waiting login's token.
	 * @param array<string, mixed> $pending The waiting login.
	 * @param WP_Error|null        $error   What went wrong last.
	 */
	private static function render( WP_User $user, string $token, array $pending, ?WP_Error $error = null ): void {
		global $interim_login;

		$keys      = TwoFactor::keys_here( $user->ID );
		$all_keys  = TwoFactor::keys( $user->ID );
		$has_app   = TwoFactor::has_totp( $user->ID );
		$has_codes = TwoFactor::codes_left( $user->ID ) > 0;
		$options   = $keys ? WebAuthn::request_options( $pending['challenge'], WebAuthn::rp_id(), $keys ) : null;

		add_action(
			'login_enqueue_scripts',
			static function () use ( $options ): void {
				wp_enqueue_style( 'mody-two-factor-login', self::asset_url( 'two-factor-login.css' ), array( 'login' ), self::asset_version( 'two-factor-login.css' ) );
				if ( $options ) {
					wp_enqueue_script( 'mody-two-factor-login', self::asset_url( 'two-factor-login.js' ), array(), self::asset_version( 'two-factor-login.js' ), true );
				}
			}
		);

		login_header( __( 'Two-step login', 'mody_cloud' ), '', $error ?? new WP_Error() );
		?>
		<form name="mody-two-factor" id="loginform" class="mody-two-factor" action="<?php echo esc_url( add_query_arg( 'action', self::ACTION, site_url( 'wp-login.php', 'login_post' ) ) ); ?>" method="post" autocomplete="off">
			<input type="hidden" name="mody_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php if ( $interim_login ) : ?>
				<input type="hidden" name="interim-login" value="1" />
			<?php endif; ?>
			<p class="mody-two-factor__intro">
				<?php
				/* translators: %s: the person's name. */
				echo esc_html( sprintf( __( 'Hello %s. One more step to log in:', 'mody_cloud' ), $user->display_name ) );
				?>
			</p>
			<?php if ( $options ) : ?>
				<input type="hidden" name="mody_webauthn" id="mody-webauthn" value="" />
				<p class="mody-two-factor__key">
					<button type="button" class="button button-primary button-large" id="mody-two-factor-key" data-options="<?php echo esc_attr( (string) wp_json_encode( $options ) ); ?>" data-touch="<?php esc_attr_e( 'Touch your security key, or follow your browser\'s steps…', 'mody_cloud' ); ?>" data-cancelled="<?php esc_attr_e( 'Cancelled, or the key did not answer. Try again, or use a code.', 'mody_cloud' ); ?>" data-unsupported="<?php esc_attr_e( 'This browser cannot use security keys. Use a code instead.', 'mody_cloud' ); ?>"><?php esc_html_e( 'Use your security key', 'mody_cloud' ); ?></button>
				</p>
				<p class="mody-two-factor__status" id="mody-two-factor-status" role="status" aria-live="polite"></p>
			<?php elseif ( $all_keys ) : ?>
				<p class="mody-two-factor__note">
					<?php
					/* translators: %s: the network's address, such as mody.cloud. */
					echo esc_html( sprintf( __( 'Your security key works on %s, not on this address: log in there, or use a code below.', 'mody_cloud' ), (string) $all_keys[0]['rp'] ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( $has_app || $has_codes ) : ?>
				<p class="<?php echo $options ? 'mody-two-factor__or' : ''; ?>">
					<?php
					if ( $options ) {
						$label = $has_app ? __( 'Or type the code from your authenticator app, or a recovery code', 'mody_cloud' ) : __( 'Or type a recovery code', 'mody_cloud' );
					} else {
						$label = $has_app ? __( 'The code from your authenticator app, or a recovery code', 'mody_cloud' ) : __( 'A recovery code', 'mody_cloud' );
					}
					?>
					<label for="mody-code"><?php echo esc_html( $label ); ?></label>
					<input type="text" name="mody_code" id="mody-code" class="input" value="" size="20" autocomplete="one-time-code" <?php echo $has_app ? 'inputmode="numeric"' : ''; ?> spellcheck="false" autocapitalize="off" <?php echo $options ? '' : 'autofocus'; ?> />
				</p>
				<p class="submit">
					<input type="submit" class="button <?php echo $options ? '' : 'button-primary'; ?> button-large" value="<?php esc_attr_e( 'Continue', 'mody_cloud' ); ?>" />
				</p>
			<?php elseif ( ! $options ) : ?>
				<p class="mody-two-factor__note"><?php esc_html_e( 'You have no way to finish logging in on this address. Ask a super admin to turn off your two-step login.', 'mody_cloud' ); ?></p>
			<?php endif; ?>
		</form>
		<p id="nav"><a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Start over', 'mody_cloud' ); ?></a></p>
		<?php
		login_footer( 'mody-code' );
	}

	/**
	 * A screen with one message and a way back to the form.
	 *
	 * @param string $message What happened.
	 */
	private static function render_message( string $message ): void {
		login_header( __( 'Two-step login', 'mody_cloud' ), '', new WP_Error( 'mody_two_factor', $message ) );
		echo '<p id="nav"><a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Log in', 'mody_cloud' ) . '</a></p>';
		login_footer();
	}

	/**
	 * `admin_init`: someone who must use two-step login and has no method
	 * goes to their profile's Two-step login first.
	 */
	public static function enforce(): void {
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! TwoFactor::required( $user ) || TwoFactor::enabled( $user ) ) {
			return;
		}
		if ( 'profile.php' === ( $GLOBALS['pagenow'] ?? '' ) && ( is_user_admin() || ! is_multisite() ) ) {
			return;
		}
		if ( 'admin-post.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		$profile = is_multisite() ? user_admin_url( 'profile.php' ) : admin_url( 'profile.php' ); // A single site has no user admin.
		wp_safe_redirect( add_query_arg( 'mody_two_factor', 'required', $profile ) . '#mody-two-factor' );
		exit;
	}

	/**
	 * Stores a waiting login.
	 *
	 * @param string               $token   The token.
	 * @param array<string, mixed> $pending The login.
	 */
	private static function save_pending( string $token, array $pending ): void {
		$pending['challenge'] = base64_encode( $pending['challenge'] );
		set_site_transient( self::transient( $token ), $pending, self::TTL );
	}

	/**
	 * A waiting login, or null.
	 *
	 * @param string $token The token.
	 * @return array<string, mixed>|null
	 */
	private static function pending( string $token ): ?array {
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			return null;
		}
		$pending = get_site_transient( self::transient( $token ) );
		if ( ! is_array( $pending ) || (int) ( $pending['expires'] ?? 0 ) < time() ) {
			return null;
		}
		$pending['challenge'] = (string) base64_decode( (string) $pending['challenge'], true );
		return $pending;
	}

	/**
	 * Ends a waiting login.
	 *
	 * @param string $token The token.
	 */
	private static function delete_pending( string $token ): void {
		if ( preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			delete_site_transient( self::transient( $token ) );
		}
	}

	/**
	 * A token's transient name: its hash, so the database never holds the
	 * token itself.
	 *
	 * @param string $token The token.
	 */
	private static function transient( string $token ): string {
		return 'mody_2fa_' . substr( hash( 'sha256', $token ), 0, 32 );
	}

	/**
	 * One of the plugin's assets.
	 *
	 * @param string $file The file in `assets/`.
	 */
	public static function asset_url( string $file ): string {
		return plugins_url( 'assets/' . $file, dirname( __DIR__ ) . '/mody-security.php' );
	}

	/**
	 * An asset's version: its modification time.
	 *
	 * @param string $file The file in `assets/`.
	 */
	public static function asset_version( string $file ): string {
		return (string) @filemtime( dirname( __DIR__ ) . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file only loses the cache-buster.
	}
}
