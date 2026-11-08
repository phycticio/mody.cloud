<?php
/**
 * The registration page on the main site (mvp-spec section 4, "Signup flow";
 * ticket M1-01, part 2).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Members;

use Mody\Platform\Wizard\WizardPage;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * `mody.cloud/register/`: full name, email, password and a plan, then
 * **Create account**.
 *
 * Decided by the maintainer on 2026-11-08 (the three proposals in M1-01):
 * - **The page** holds the dynamic block `mody/register`, rendered by PHP
 *   with no JS build (`supports.autoRegister`, so the editor shows it through
 *   ServerSideRender). The theme stays free of platform logic. The block
 *   renders only on the main site and is hidden from the inserter.
 * - **No WordPress role:** the `mody_members` row is the membership. The new
 *   user has no role on the main site and becomes `administrator` of each
 *   site they create.
 * - **Before the card is verified** the account is `pending_payment`: it
 *   logs in and sees the payment step on the Mody dashboard, but creates no
 *   site (Members::can_create_site()).
 *
 * **Closed unless opened:** the network option `mody_registration_open`.
 * While it is off, nothing creates the page and the block says registration
 * opens soon. Production stays closed until the card check (part 3) works.
 * Open it with `wp site option update mody_registration_open 1`.
 *
 * The form posts to the page itself and is handled on `template_redirect`:
 * on success the user is logged in and sent to the dashboard; on failure the
 * page renders again with the errors and the values entered (never the
 * password).
 */
final class Registration {

	/** The block's name. */
	public const BLOCK = 'mody/register';

	/** Network option: registration is open when it is truthy. */
	public const OPEN_OPTION = 'mody_registration_open';

	/** Main-site option: the registration page's ID, once created. */
	public const PAGE_OPTION = 'mody_register_page_id';

	/** The page's slug: `/register/`. */
	public const PAGE_SLUG = 'register';

	/** The form's action and nonce action. */
	public const ACTION = 'mody_register';

	/** The shortest password accepted. */
	public const MIN_PASSWORD = 12;

	/** The longest full name accepted. */
	public const MAX_NAME = 120;

	/**
	 * The errors of the form just posted: field (name, email, password, plan,
	 * form) => message.
	 *
	 * @var array<string, string>
	 */
	private static array $errors = array();

	/**
	 * The values just posted, to fill the form again (never the password).
	 *
	 * @var array<string, string>
	 */
	private static array $values = array();

	/**
	 * Whether registration is open.
	 */
	public static function is_open(): bool {
		return (bool) get_site_option( self::OPEN_OPTION );
	}

	/**
	 * Where a member lands: the Mody dashboard in Network Admin.
	 */
	public static function dashboard_url(): string {
		return network_admin_url( 'admin.php?page=' . WizardPage::SLUG );
	}

	/**
	 * `init`: registers the block.
	 */
	public static function register_block(): void {
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'Mody registration form', 'mody_cloud' ),
				'description'     => __( 'The form that creates a member account. It works only on the main site.', 'mody_cloud' ),
				'category'        => 'widgets',
				'icon'            => 'id',
				'supports'        => array(
					'autoRegister' => true,
					'html'         => false,
					'inserter'     => false,
					'multiple'     => false,
					'reusable'     => false,
				),
				'render_callback' => array( self::class, 'render' ),
			)
		);
	}

	/**
	 * `init`: creates the page on the main site the first time registration
	 * is open. An existing `/register/` page is adopted, never changed.
	 */
	public static function ensure_page(): void {
		if ( ! is_main_site() || ! self::is_open() || wp_installing() ) {
			return;
		}
		$id = (int) get_option( self::PAGE_OPTION );
		if ( $id && get_post( $id ) ) {
			return;
		}
		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing ) {
			update_option( self::PAGE_OPTION, $existing->ID );
			return;
		}
		$admins = get_super_admins();
		$author = $admins ? get_user_by( 'login', $admins[0] ) : false;
		$id     = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Create your account', 'mody_cloud' ),
				'post_name'    => self::PAGE_SLUG,
				'post_content' => '<!-- wp:' . self::BLOCK . ' /-->',
				'post_author'  => $author ? $author->ID : 0,
			),
			true
		);
		if ( ! is_wp_error( $id ) ) {
			update_option( self::PAGE_OPTION, $id );
		}
	}

	/**
	 * Creates an account: the WordPress user and a `pending_payment` trial
	 * row with the chosen plan as `next_plan`.
	 *
	 * @param string $name     Full name, unsanitized.
	 * @param string $email    Email, unsanitized.
	 * @param string $password Password, as typed.
	 * @param string $plan     One of Members::PAID_PLANS.
	 * @return int|WP_Error The user ID, or one error per field (codes: name,
	 *                      email, password, plan, form).
	 */
	public static function register( string $name, string $email, string $password, string $plan ): int|WP_Error {
		$errors = new WP_Error();
		$name   = sanitize_text_field( $name );
		$email  = sanitize_email( $email );

		if ( '' === $name ) {
			$errors->add( 'name', __( 'Please enter your name.', 'mody_cloud' ) );
		} elseif ( mb_strlen( $name ) > self::MAX_NAME ) {
			/* translators: %d: the number of characters allowed. */
			$errors->add( 'name', sprintf( __( 'Please use at most %d characters.', 'mody_cloud' ), self::MAX_NAME ) );
		}
		if ( ! is_email( $email ) ) {
			$errors->add( 'email', __( 'Please enter a valid email address.', 'mody_cloud' ) );
		} elseif ( email_exists( $email ) ) {
			$errors->add( 'email', __( 'An account with this email already exists. Log in instead.', 'mody_cloud' ) );
		}
		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			/* translators: %d: the minimum number of characters. */
			$errors->add( 'password', sprintf( __( 'Please use at least %d characters.', 'mody_cloud' ), self::MIN_PASSWORD ) );
		} elseif ( strlen( $password ) > 200 ) {
			$errors->add( 'password', __( 'Please use at most 200 characters.', 'mody_cloud' ) );
		}
		if ( ! in_array( $plan, Members::PAID_PLANS, true ) ) {
			$errors->add( 'plan', __( 'Please choose a plan.', 'mody_cloud' ) );
		}
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::unique_login( $email ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'nickname'     => $name,
				'role'         => '', // No role on the main site: the row is the membership.
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'form', __( 'Your account could not be created. Please try again.', 'mody_cloud' ) );
		}
		$member = Members::create( $user_id, 'trial', 'pending_payment', $plan );
		if ( is_wp_error( $member ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $user_id ); // No user without a row.
			return new WP_Error( 'form', __( 'Your account could not be created. Please try again.', 'mody_cloud' ) );
		}
		return $user_id;
	}

	/**
	 * `template_redirect`: handles the posted form on the main site.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is checked below, once the request is known to be ours.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || ! isset( $_POST['mody_action'] ) || self::ACTION !== $_POST['mody_action'] || ! is_main_site() ) {
			return;
		}
		$field = static fn( string $key ): string => isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in register(); the password is used as typed.
		// phpcs:enable

		self::$values = array(
			'name'  => sanitize_text_field( $field( 'mody_name' ) ),
			'email' => sanitize_email( $field( 'mody_email' ) ),
			'plan'  => sanitize_key( $field( 'mody_plan' ) ),
		);
		if ( ! wp_verify_nonce( sanitize_key( $field( '_mody_nonce' ) ), self::ACTION ) ) {
			self::$errors = array( 'form' => __( 'The form expired. Please try again.', 'mody_cloud' ) );
			return;
		}
		if ( is_user_logged_in() ) {
			self::$errors = array( 'form' => __( 'You are already logged in.', 'mody_cloud' ) );
			return;
		}
		if ( ! self::is_open() ) {
			self::$errors = array( 'form' => __( 'Registration is not open yet.', 'mody_cloud' ) );
			return;
		}

		$user_id = self::register( $field( 'mody_name' ), $field( 'mody_email' ), $field( 'mody_password' ), self::$values['plan'] );
		if ( is_wp_error( $user_id ) ) {
			foreach ( $user_id->get_error_codes() as $code ) {
				self::$errors[ (string) $code ] = $user_id->get_error_message( $code );
			}
			return;
		}
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );
		wp_safe_redirect( self::dashboard_url() );
		exit;
	}

	/**
	 * `login_redirect`: a member who logs in without asking for a page lands
	 * on the Mody dashboard, not the main site's wp-admin, where they have no
	 * role.
	 *
	 * @param string           $redirect_to The destination so far.
	 * @param string           $requested   The destination the request asked for.
	 * @param WP_User|WP_Error $user        The user logging in.
	 */
	public static function login_redirect( string $redirect_to, string $requested, WP_User|WP_Error $user ): string {
		if ( $user instanceof WP_User && ( '' === $requested || admin_url() === $requested ) && Members::get( $user->ID ) ) {
			return self::dashboard_url();
		}
		return $redirect_to;
	}

	/**
	 * The block: the form, or why there is none.
	 */
	public static function render(): string {
		if ( ! is_main_site() ) {
			return '';
		}
		$root = dirname( __DIR__, 2 );
		wp_enqueue_style( 'mody-register', plugins_url( 'assets/register.css', $root . '/mody-platform.php' ), array(), (string) filemtime( $root . '/assets/register.css' ) );

		if ( is_user_logged_in() ) {
			return sprintf(
				'<div class="mody-register"><p>%s</p><p><a class="wp-element-button" href="%s">%s</a></p></div>',
				esc_html__( 'You already have an account.', 'mody_cloud' ),
				esc_url( self::dashboard_url() ),
				esc_html__( 'Go to your dashboard', 'mody_cloud' )
			);
		}
		if ( ! self::is_open() ) {
			// A form posted while closed says so; otherwise, when it opens.
			return '<div class="mody-register"><p role="status">' . esc_html( self::$errors['form'] ?? __( 'Registration opens soon.', 'mody_cloud' ) ) . '</p></div>';
		}

		$value = static fn( string $key ): string => self::$values[ $key ] ?? '';
		$error = static fn( string $key ): string => isset( self::$errors[ $key ] )
			? sprintf( '<p class="mody-register__error" id="mody-register-%s-error" role="alert">%s</p>', esc_attr( $key ), esc_html( self::$errors[ $key ] ) )
			: '';
		$aria  = static fn( string $key ): string => isset( self::$errors[ $key ] ) ? sprintf( ' aria-invalid="true" aria-describedby="mody-register-%s-error"', esc_attr( $key ) ) : '';
		$plan  = in_array( $value( 'plan' ), Members::PAID_PLANS, true ) ? $value( 'plan' ) : Members::PAID_PLANS[0];

		ob_start();
		?>
		<form class="mody-register" method="post" action="<?php echo esc_url( (string) get_permalink() ); ?>" novalidate>
			<?php echo $error( 'form' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $error. ?>
			<p class="mody-register__field">
				<label for="mody-register-name"><?php esc_html_e( 'Full name', 'mody_cloud' ); ?></label>
				<input id="mody-register-name" name="mody_name" type="text" autocomplete="name" required maxlength="<?php echo (int) self::MAX_NAME; ?>" value="<?php echo esc_attr( $value( 'name' ) ); ?>"<?php echo $aria( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $aria. ?>>
				<?php echo $error( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $error. ?>
			</p>
			<p class="mody-register__field">
				<label for="mody-register-email"><?php esc_html_e( 'Email', 'mody_cloud' ); ?></label>
				<input id="mody-register-email" name="mody_email" type="email" autocomplete="email" required value="<?php echo esc_attr( $value( 'email' ) ); ?>"<?php echo $aria( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $aria. ?>>
				<?php echo $error( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $error. ?>
			</p>
			<p class="mody-register__field">
				<label for="mody-register-password"><?php esc_html_e( 'Password', 'mody_cloud' ); ?></label>
				<input id="mody-register-password" name="mody_password" type="password" autocomplete="new-password" required minlength="<?php echo (int) self::MIN_PASSWORD; ?>"<?php echo $aria( 'password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $aria. ?>>
				<span class="mody-register__hint">
					<?php
					/* translators: %d: the minimum number of characters. */
					echo esc_html( sprintf( __( 'At least %d characters.', 'mody_cloud' ), self::MIN_PASSWORD ) );
					?>
				</span>
				<?php echo $error( 'password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $error. ?>
			</p>
			<fieldset class="mody-register__plans"<?php echo $aria( 'plan' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $aria. ?>>
				<legend><?php esc_html_e( 'Choose your plan', 'mody_cloud' ); ?></legend>
				<?php foreach ( Members::PAID_PLANS as $slug ) : ?>
					<?php $details = Members::PLANS[ $slug ]; ?>
					<label class="mody-register__plan">
						<input type="radio" name="mody_plan" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $plan, $slug ); ?>>
						<span class="mody-register__plan-name"><?php echo esc_html( Members::plan_label( $slug ) ); ?></span>
						<span class="mody-register__plan-price">
							<?php
							/* translators: %d: the monthly fee in euros. */
							echo esc_html( sprintf( __( '%d EUR per month', 'mody_cloud' ), $details['fee'] ) );
							?>
						</span>
						<span class="mody-register__plan-sites">
							<?php
							/* translators: %d: the number of sites. */
							echo esc_html( sprintf( _n( '%d site', '%d sites', $details['sites'], 'mody_cloud' ), $details['sites'] ) );
							?>
						</span>
					</label>
				<?php endforeach; ?>
				<?php echo $error( 'plan' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $error. ?>
			</fieldset>
			<p class="mody-register__note">
				<?php
				/* translators: %d: the number of sites in the trial. */
				echo esc_html( sprintf( __( 'Every plan starts with a 30-day trial with %d sites. We check your card with 0.01 EUR, and your plan\'s fee is charged when the trial ends.', 'mody_cloud' ), Members::PLANS['trial']['sites'] ) );
				?>
			</p>
			<input type="hidden" name="mody_action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="_mody_nonce" value="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>">
			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Create account', 'mody_cloud' ); ?></button></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A free login name from the email's local part: lowercase letters and
	 * digits, at least four characters, not one of the network's illegal
	 * names, with a number added when taken.
	 *
	 * @param string $email A valid email.
	 */
	private static function unique_login( string $email ): string {
		$base = substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) strstr( $email, '@', true ) ) ), 0, 50 );
		if ( strlen( $base ) < 4 ) {
			$base .= 'member';
		}
		$illegal   = (array) get_site_option( 'illegal_names', array() );
		$candidate = $base;
		for ( $n = 2; username_exists( $candidate ) || in_array( $candidate, $illegal, true ); $n++ ) {
			$candidate = $base . $n;
		}
		return $candidate;
	}
}
