<?php
/**
 * Network Admin → Mody → Security: every setting of Mody Security, the file
 * check and the security log, in the Mody dashboard's layout.
 *
 * @package Mody\Security
 */

namespace Mody\Security;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Asked for by the maintainer on 2026-11-21: "The plugin should have a
 * settings page where we change all settings that can be managed. This
 * settings page should have the same template as the mody-dashboard page."
 * Super admins only. Sections on the left (SECTIONS); each settings section
 * is one form posted to admin-post.php, checked with a nonce, sanitized by
 * Settings and logged.
 */
final class SecurityPage {

	/** The page's slug. */
	public const SLUG = 'mody-security';

	/** Who may use it. */
	public const CAPABILITY = 'manage_network_options';

	/** admin-post actions. */
	public const SAVE_ACTION  = 'mody_security_save';
	public const CHECK_ACTION = 'mody_security_check_files';
	public const BLOCK_ACTION = 'mody_security_block_address';

	/**
	 * Hooked from mody-security.php.
	 */
	public static function register(): void {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( self::class, 'register_menu' ), 20 );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( self::class, 'save' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( self::class, 'check_files' ) );
		add_action( 'admin_post_' . self::BLOCK_ACTION, array( self::class, 'block_address' ) );
	}

	/**
	 * The sections: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array(
			'overview'   => __( 'Overview', 'mody_cloud' ),
			'two-factor' => __( 'Two-step login', 'mody_cloud' ),
			'login'      => __( 'Login protection', 'mody_cloud' ),
			'firewall'   => __( 'Firewall', 'mody_cloud' ),
			'alerts'     => __( 'Alerts', 'mody_cloud' ),
			'files'      => __( 'File check', 'mody_cloud' ),
			'log'        => __( 'Security log', 'mody_cloud' ),
		);
	}

	/**
	 * The page's address, on a section.
	 *
	 * @param string               $section A section.
	 * @param array<string, mixed> $extra   More query arguments.
	 */
	public static function url( string $section = 'overview', array $extra = array() ): string {
		$args = array( 'page' => self::SLUG );
		if ( 'overview' !== $section ) {
			$args['section'] = $section;
		}
		return add_query_arg( array_merge( $args, $extra ), network_admin_url( 'admin.php' ) );
	}

	/**
	 * Who may open the page: network options on a multisite, the site's
	 * options on a single site (a plain WordPress, where nobody has the
	 * network capabilities).
	 */
	public static function capability(): string {
		return is_multisite() ? self::CAPABILITY : 'manage_options';
	}

	/**
	 * `network_admin_menu`: under the network's Mody menu (Mody Sites), else
	 * under Settings. `admin_menu` on a single site: under Settings.
	 */
	public static function register_menu(): void {
		if ( ! is_multisite() ) {
			$parent = 'options-general.php';
		} else {
			$parent = class_exists( \Mody\Sites\WizardPage::class ) ? \Mody\Sites\WizardPage::SLUG : 'settings.php';
		}
		$hook = add_submenu_page( $parent, __( 'Security', 'mody_cloud' ), __( 'Security', 'mody_cloud' ), self::capability(), self::SLUG, array( self::class, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * `load-<page>`: the layout's stylesheet, the page's own, and the main
	 * site's theme variables.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( 'mody-security-admin-page', TwoFactorLogin::asset_url( 'admin-page.css' ), array(), TwoFactorLogin::asset_version( 'admin-page.css' ) );
		wp_enqueue_style( 'mody-security-page', TwoFactorLogin::asset_url( 'security-page.css' ), array( 'mody-security-admin-page' ), TwoFactorLogin::asset_version( 'security-page.css' ) );
		wp_add_inline_style( 'mody-security-admin-page', (string) wp_get_global_stylesheet( array( 'variables' ) ) );
	}

	/**
	 * The section asked for.
	 */
	private static function current(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which section to show.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'overview';
		return array_key_exists( $section, self::sections() ) ? $section : 'overview';
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$sections = self::sections();
		$section  = self::current();
		?>
		<div class="wrap mody-brand mody-security">
			<h1><?php esc_html_e( 'Security', 'mody_cloud' ); ?></h1>
			<div class="mody-brand__layout">
				<nav class="mody-brand__sections" aria-label="<?php esc_attr_e( 'Security sections', 'mody_cloud' ); ?>">
					<ul>
						<?php foreach ( $sections as $slug => $label ) : ?>
							<li>
								<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="mody-brand__section-link<?php echo $slug === $section ? ' is-active' : ''; ?>" <?php echo $slug === $section ? 'aria-current="page"' : ''; ?>>
									<span><?php echo esc_html( $label ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
				<div class="mody-brand__main">
					<?php self::render_card(); ?>
					<?php self::render_notice(); ?>
					<section class="mody-brand__panel" aria-labelledby="mody-security-section-title">
						<div class="mody-brand__panel-head">
							<h2 id="mody-security-section-title"><?php echo esc_html( $sections[ $section ] ); ?></h2>
						</div>
						<div class="mody-brand__panel-body">
							<?php
							switch ( $section ) {
								case 'two-factor':
									self::render_two_factor();
									break;
								case 'login':
									self::render_login();
									break;
								case 'firewall':
									self::render_firewall();
									break;
								case 'alerts':
									self::render_alerts();
									break;
								case 'files':
									self::render_files();
									break;
								case 'log':
									self::render_log();
									break;
								default:
									self::render_overview();
							}
							?>
						</div>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The network's card: its name and what protects it.
	 */
	private static function render_card(): void {
		$name     = wp_specialchars_decode( is_multisite() ? (string) get_network()->site_name : (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$file     = FileCheck::last();
		$badges   = array(
			array( Settings::get( 'firewall' ), __( 'Firewall on', 'mody_cloud' ), __( 'Firewall off', 'mody_cloud' ) ),
			array( 'none' !== Settings::get( 'two_factor_required' ), __( 'Two-step login required', 'mody_cloud' ), __( 'Two-step login optional', 'mody_cloud' ) ),
			array( $file && $file['manifest'] && ! FileCheck::found( $file ), __( 'Files as deployed', 'mody_cloud' ), ! $file ? __( 'Files not checked', 'mody_cloud' ) : ( FileCheck::found( $file ) ? __( 'Files changed', 'mody_cloud' ) : __( 'No deploy list', 'mody_cloud' ) ) ),
		);
		?>
		<section class="mody-brand__card" aria-label="<?php esc_attr_e( 'The network', 'mody_cloud' ); ?>">
			<div class="mody-brand__card-text">
				<p class="mody-brand__name"><?php echo esc_html( $name ); ?></p>
				<p class="mody-brand__tagline"><?php esc_html_e( 'Mody Security protects every site of the network: logins, two-step login, the firewall and the files. Nothing here calls a service outside our servers.', 'mody_cloud' ); ?></p>
				<ul class="mody-security__badges">
					<?php foreach ( $badges as list( $ok, $yes, $no ) ) : ?>
						<li class="mody-brand__badge<?php echo $ok ? ' is-filled' : ' is-warning'; ?>"><?php echo esc_html( $ok ? $yes : $no ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
		<?php
	}

	/**
	 * The notice after a save or an action.
	 */
	private static function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: which notice to show.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		// phpcs:enable
		$notices = array(
			'saved'       => array( 'success', __( 'Saved.', 'mody_cloud' ) ),
			'own-address' => array( 'warning', __( 'Saved, without your own address: blocking it would lock you out.', 'mody_cloud' ) ),
			'checked'     => array( 'success', __( 'The file check ran.', 'mody_cloud' ) ),
			'blocked'     => array( 'success', __( 'The address is blocked.', 'mody_cloud' ) ),
			'not-blocked' => array( 'error', __( 'That address cannot be blocked (it is yours, allowed, or not an address).', 'mody_cloud' ) ),
		);
		if ( isset( $notices[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s inline mody-brand__notice"><p>%2$s</p></div>', esc_attr( $notices[ $notice ][0] ), esc_html( $notices[ $notice ][1] ) );
		}
	}

	/**
	 * Overview: the last week at a glance.
	 */
	private static function render_overview(): void {
		$day    = Events::counts( time() - DAY_IN_SECONDS );
		$week   = Events::counts( time() - WEEK_IN_SECONDS );
		$admins = self::super_admins();
		$on     = count( array_filter( $admins, array( TwoFactor::class, 'enabled' ) ) );
		$file   = FileCheck::last();
		$rows   = array(
			__( 'Super admins with two-step login', 'mody_cloud' ) => sprintf( '%d / %d', $on, count( $admins ) ),
			__( 'Logins, last 24 hours', 'mody_cloud' )            => number_format_i18n( $day['login'] ),
			__( 'Failed logins, last 24 hours', 'mody_cloud' )     => number_format_i18n( $day['login_failed'] ),
			__( 'Addresses paused, last 7 days', 'mody_cloud' )    => number_format_i18n( $week['paused'] + $week['username_blocked'] ),
			__( 'Firewall blocks, last 7 days', 'mody_cloud' )     => number_format_i18n( $week['firewall'] + $week['address_blocked'] ),
			__( 'Wrong second steps, last 7 days', 'mody_cloud' )  => number_format_i18n( $week['two_factor_failed'] ),
			__( 'Last file check', 'mody_cloud' )                  => $file ? self::file_summary( $file ) : __( 'Not run yet', 'mody_cloud' ),
		);
		self::rows( $rows );
		echo '<p class="description">' . esc_html__( 'Counted from the security log, which keeps 30 days.', 'mody_cloud' ) . '</p>';
	}

	/**
	 * Two-step login: who must use it, and the super admins' state.
	 */
	private static function render_two_factor(): void {
		self::form_start( 'two-factor' );
		self::radios(
			'two_factor_required',
			__( 'Who must use two-step login', 'mody_cloud' ),
			array(
				'none'           => __( 'Nobody: everyone may turn it on in their profile', 'mody_cloud' ),
				'super_admins'   => __( 'Super admins', 'mody_cloud' ),
				'administrators' => __( 'Super admins and every site\'s administrators (members too)', 'mody_cloud' ),
			),
			(string) Settings::get( 'two_factor_required' )
		);
		echo '<p class="description">' . esc_html__( 'Someone who must and has not set it up can log in, but wp-admin sends them to their profile until they add an authenticator app or a security key.', 'mody_cloud' ) . '</p>';
		self::form_end();

		echo '<h3>' . esc_html__( 'Super admins', 'mody_cloud' ) . '</h3><table class="mody-brand__rows">';
		foreach ( self::super_admins() as $admin ) {
			$methods = array();
			if ( TwoFactor::has_totp( $admin->ID ) ) {
				$methods[] = __( 'app', 'mody_cloud' );
			}
			$keys = count( TwoFactor::keys( $admin->ID ) );
			if ( $keys ) {
				/* translators: %d: a number of keys. */
				$methods[] = sprintf( _n( '%d key', '%d keys', $keys, 'mody_cloud' ), $keys );
			}
			printf(
				'<tr><th scope="row"><a href="%1$s">%2$s</a></th><td>%3$s</td></tr>',
				esc_url( network_admin_url( 'user-edit.php?user_id=' . $admin->ID ) . '#mody-two-factor' ),
				esc_html( $admin->user_login ),
				esc_html( $methods ? implode( ', ', $methods ) : __( 'Off', 'mody_cloud' ) )
			);
		}
		echo '</table><p class="description">' . esc_html__( 'Anyone sets up their own on their profile. A super admin can turn someone\'s off on the network\'s Edit User screen, for an account that lost its phone and codes.', 'mody_cloud' ) . '</p>';
	}

	/**
	 * Login protection: the limits and blocked usernames.
	 */
	private static function render_login(): void {
		self::form_start( 'login' );
		self::number( 'login_address', __( 'Failed logins from one address before a pause', 'mody_cloud' ), (int) Settings::get( 'login_address' ) );
		self::number( 'login_pair', __( 'Failed logins for one account from one address before a pause', 'mody_cloud' ), (int) Settings::get( 'login_pair' ) );
		self::number( 'login_account', __( 'Failed logins for one account from anywhere before a pause', 'mody_cloud' ), (int) Settings::get( 'login_account' ), __( 'A last resort against guesses from many addresses. It never pauses a browser the account has logged in from before.', 'mody_cloud' ) );
		self::number( 'login_minutes', __( 'Pause, in minutes', 'mody_cloud' ), (int) Settings::get( 'login_minutes' ), __( 'Failures are counted over this time, and a pause lasts until it is over.', 'mody_cloud' ) );
		$names = (array) Settings::get( 'blocked_usernames' );
		self::textarea( 'blocked_usernames', __( 'Blocked usernames', 'mody_cloud' ), implode( "\n", $names ), __( 'One per line. Trying to log in with one pauses the address at once. A name that is a real account is never blocked.', 'mody_cloud' ) );
		$real = array_filter( $names, static fn( $name ) => (bool) get_user_by( 'login', $name ) );
		if ( $real ) {
			/* translators: %s: login names. */
			echo '<p class="description">' . esc_html( sprintf( __( 'These are real accounts, so they are not blocked: %s.', 'mody_cloud' ), implode( ', ', $real ) ) ) . '</p>';
		}
		self::form_end();
		echo '<p class="description">' . esc_html__( 'Logins also need the ALTCHA puzzle, solved in the browser on our own servers.', 'mody_cloud' ) . '</p>';
	}

	/**
	 * Firewall: on or off, the rule groups, the address lists.
	 */
	private static function render_firewall(): void {
		self::form_start( 'firewall' );
		self::checkbox( 'firewall', __( 'Firewall on', 'mody_cloud' ), (bool) Settings::get( 'firewall' ), __( 'Checks every request\'s address, and visitors\' form data, against the rules below. Blocked addresses are refused even when it is off.', 'mody_cloud' ) );
		$groups = array(
			'sqli'      => __( 'SQL injection', 'mody_cloud' ),
			'xss'       => __( 'Script injection (cross-site scripting)', 'mody_cloud' ),
			'traversal' => __( 'Reading the server\'s files (path traversal, file inclusion)', 'mody_cloud' ),
			'code'      => __( 'Running code (PHP, shell, Log4Shell)', 'mody_cloud' ),
		);
		echo '<fieldset class="mody-brand__field"><legend>' . esc_html__( 'Rules', 'mody_cloud' ) . '</legend>';
		foreach ( $groups as $group => $label ) {
			printf( '<label class="mody-brand__choice"><input type="checkbox" name="rules[%1$s]" value="1" %2$s /> %3$s</label>', esc_attr( $group ), checked( Settings::rule( $group ), true, false ), esc_html( $label ) );
		}
		echo '</fieldset>';
		$own = ClientAddress::get();
		self::textarea( 'blocked_addresses', __( 'Blocked addresses', 'mody_cloud' ), implode( "\n", (array) Settings::get( 'blocked_addresses' ) ), __( 'One address or range per line (203.0.113.7, 203.0.113.0/24, 2001:db8::/32). They get "Request blocked" on every site.', 'mody_cloud' ) );
		/* translators: %s: an IP address. */
		self::textarea( 'allowed_addresses', __( 'Allowed addresses', 'mody_cloud' ), implode( "\n", (array) Settings::get( 'allowed_addresses' ) ), sprintf( __( 'Never blocked, and the rules skip them. Your address now: %s', 'mody_cloud' ), '' !== $own ? $own : __( 'unknown', 'mody_cloud' ) ) );
		self::form_end();
	}

	/**
	 * Alerts: which emails the super admins get.
	 */
	private static function render_alerts(): void {
		self::form_start( 'alerts' );
		$alerts = array(
			'admin_login'    => __( 'A super admin logs in from a browser the account has not used before', 'mody_cloud' ),
			'address_paused' => __( 'An address is paused after too many failed logins', 'mody_cloud' ),
			'file_changes'   => __( 'The file check finds changed, added or missing files', 'mody_cloud' ),
			'firewall_block' => __( 'The firewall blocks a request (can be many)', 'mody_cloud' ),
		);
		echo '<fieldset class="mody-brand__field"><legend>' . esc_html__( 'Email the super admins when', 'mody_cloud' ) . '</legend>';
		foreach ( $alerts as $alert => $label ) {
			printf( '<label class="mody-brand__choice"><input type="checkbox" name="alerts[%1$s]" value="1" %2$s /> %3$s</label>', esc_attr( $alert ), checked( Settings::alert( $alert ), true, false ), esc_html( $label ) );
		}
		echo '</fieldset>';
		/* translators: %s: email addresses. */
		echo '<p class="description">' . esc_html( sprintf( __( 'They go to: %s. At most ten of each kind an hour.', 'mody_cloud' ), implode( ', ', Alerts::recipients() ) ) ) . '</p>';
		self::form_end();
		echo '<p class="description">' . esc_html__( 'Whatever these say, people always hear about their own account: when two-step login is turned off or a recovery code is used.', 'mody_cloud' ) . '</p>';
	}

	/**
	 * File check: the last result and Check now.
	 */
	private static function render_files(): void {
		$file = FileCheck::last();
		echo '<p>' . esc_html__( 'Every code file (WordPress, plugins, themes, libraries) is compared with the list the last deploy wrote, and uploads are searched for scripts.', 'mody_cloud' ) . '</p>';
		if ( $file ) {
			self::rows(
				array(
					__( 'Checked', 'mody_cloud' )            => wp_date( 'Y-m-d H:i', (int) $file['time'] ),
					__( 'Result', 'mody_cloud' )             => self::file_summary( $file ),
					__( 'Deploy list from', 'mody_cloud' )   => $file['manifest'] ? wp_date( 'Y-m-d H:i', (int) $file['built'] ) : ( FileCheck::platform() ? __( 'No list: app/files.sha256 is written by the deploy', 'mody_cloud' ) : __( 'No list: run wp mody-security files-manifest once the code is as it should be', 'mody_cloud' ) ),
					__( 'Files compared', 'mody_cloud' )     => number_format_i18n( (int) $file['checked'] ),
				)
			);
			foreach ( array(
				'changed' => __( 'Changed', 'mody_cloud' ),
				'added'   => __( 'Added', 'mody_cloud' ),
				'missing' => __( 'Missing', 'mody_cloud' ),
				'uploads' => __( 'Scripts in uploads', 'mody_cloud' ),
			) as $key => $label ) {
				if ( ! empty( $file[ $key ] ) ) {
					echo '<h3>' . esc_html( $label ) . ' (' . esc_html( number_format_i18n( (int) ( $file[ $key . '_count' ] ?? count( $file[ $key ] ) ) ) ) . ')</h3><ul class="mody-security__paths">';
					foreach ( $file[ $key ] as $path ) {
						echo '<li><code>' . esc_html( $path ) . '</code></li>';
					}
					echo '</ul>';
				}
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( network_admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CHECK_ACTION ); ?>" />
			<?php wp_nonce_field( self::CHECK_ACTION ); ?>
			<?php submit_button( __( 'Check now', 'mody_cloud' ), 'secondary' ); ?>
		</form>
		<p class="description"><?php esc_html_e( 'From the server: wp mody-security check-files --alert (exits with 1 when something differs).', 'mody_cloud' ); ?></p>
		<?php
	}

	/**
	 * The security log, newest first.
	 */
	private static function render_log(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: a filter.
		$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$labels = Events::labels();
		$events = Events::recent( 300, $type );
		?>
		<form method="get" action="<?php echo esc_url( network_admin_url( 'admin.php' ) ); ?>" class="mody-security__filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="section" value="log" />
			<label for="mody-security-type"><?php esc_html_e( 'Show', 'mody_cloud' ); ?></label>
			<select id="mody-security-type" name="type">
				<option value=""><?php esc_html_e( 'Everything', 'mody_cloud' ); ?></option>
				<?php foreach ( $labels as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'mody_cloud' ), 'secondary', '', false ); ?>
		</form>
		<?php
		if ( ! $events ) {
			echo '<p class="mody-brand__empty">' . esc_html__( 'Nothing yet.', 'mody_cloud' ) . '</p>';
			return;
		}
		$blocked = (array) Settings::get( 'blocked_addresses' );
		echo '<div class="mody-security__log-wrap"><table class="widefat striped mody-security__log"><thead><tr><th>' . esc_html__( 'When', 'mody_cloud' ) . '</th><th>' . esc_html__( 'What', 'mody_cloud' ) . '</th><th>' . esc_html__( 'Account', 'mody_cloud' ) . '</th><th>' . esc_html__( 'Address', 'mody_cloud' ) . '</th><th>' . esc_html__( 'Detail', 'mody_cloud' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			$user    = (int) $event['user_id'] ? get_userdata( (int) $event['user_id'] ) : null;
			$address = (string) $event['address'];
			echo '<tr>';
			echo '<td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $event['created'] ) ) . '</td>';
			echo '<td>' . esc_html( $labels[ $event['type'] ] ?? $event['type'] ) . '</td>';
			echo '<td>' . ( $user instanceof WP_User ? esc_html( $user->user_login ) : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $address ? $address : '—' );
			if ( '' !== $address && ClientAddress::is_public( $address ) && ! Settings::in_list( $address, $blocked ) ) {
				printf(
					' <a class="mody-security__block" href="%1$s">%2$s</a>',
					esc_url( wp_nonce_url( add_query_arg( array( 'action' => self::BLOCK_ACTION, 'address' => rawurlencode( $address ) ), network_admin_url( 'admin-post.php' ) ), self::BLOCK_ACTION ) ),
					esc_html__( 'Block', 'mody_cloud' )
				);
			}
			echo '</td>';
			echo '<td>' . esc_html( (string) $event['detail'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="description">' . esc_html__( 'Kept 30 days, then deleted. Addresses are kept to keep the network safe (they let you block an attacker).', 'mody_cloud' ) . '</p>';
	}

	/**
	 * `admin_post_mody_security_save`: one section's settings.
	 */
	public static function save(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'mody_cloud' ), 403 );
		}
		check_admin_referer( self::SAVE_ACTION );
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		$post    = wp_unslash( $_POST );
		$changes = array();
		$notice  = 'saved';
		switch ( $section ) {
			case 'two-factor':
				$changes['two_factor_required'] = sanitize_key( (string) ( $post['two_factor_required'] ?? '' ) );
				break;
			case 'login':
				foreach ( array( 'login_address', 'login_pair', 'login_account', 'login_minutes' ) as $key ) {
					$changes[ $key ] = absint( $post[ $key ] ?? 0 );
				}
				$changes['blocked_usernames'] = sanitize_textarea_field( (string) ( $post['blocked_usernames'] ?? '' ) );
				break;
			case 'firewall':
				$changes['firewall'] = ! empty( $post['firewall'] );
				$changes['rules']    = array_map( 'boolval', (array) ( $post['rules'] ?? array() ) );
				$blocked             = Settings::addresses( sanitize_textarea_field( (string) ( $post['blocked_addresses'] ?? '' ) ) );
				$own                 = ClientAddress::get();
				$kept                = array_values( array_filter( $blocked, static fn( $entry ) => ! Settings::in_list( $own, array( $entry ) ) ) );
				if ( count( $kept ) !== count( $blocked ) ) {
					$notice = 'own-address';
				}
				$changes['blocked_addresses'] = $kept;
				$changes['allowed_addresses'] = sanitize_textarea_field( (string) ( $post['allowed_addresses'] ?? '' ) );
				break;
			case 'alerts':
				$changes['alerts'] = array_map( 'boolval', (array) ( $post['alerts'] ?? array() ) );
				break;
			default:
				wp_safe_redirect( self::url() );
				exit;
		}
		Settings::save( $changes );
		Events::record( 'settings', get_current_user_id(), $section );
		wp_safe_redirect( self::url( $section, array( 'notice' => $notice ) ) );
		exit;
	}

	/**
	 * `admin_post_mody_security_check_files`.
	 */
	public static function check_files(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'mody_cloud' ), 403 );
		}
		check_admin_referer( self::CHECK_ACTION );
		FileCheck::run();
		wp_safe_redirect( self::url( 'files', array( 'notice' => 'checked' ) ) );
		exit;
	}

	/**
	 * `admin_post_mody_security_block_address`: Block, from the log.
	 */
	public static function block_address(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'mody_cloud' ), 403 );
		}
		check_admin_referer( self::BLOCK_ACTION );
		$address = isset( $_GET['address'] ) ? Settings::range( sanitize_text_field( rawurldecode( wp_unslash( $_GET['address'] ) ) ) ) : null;
		$own     = ClientAddress::get();
		if ( null === $address || Settings::in_list( $own, array( $address ) ) || Settings::in_list( $address, (array) Settings::get( 'allowed_addresses' ) ) ) {
			wp_safe_redirect( self::url( 'log', array( 'notice' => 'not-blocked' ) ) );
			exit;
		}
		Settings::save( array( 'blocked_addresses' => array_merge( (array) Settings::get( 'blocked_addresses' ), array( $address ) ) ) );
		Events::record( 'settings', get_current_user_id(), 'blocked ' . $address );
		wp_safe_redirect( self::url( 'log', array( 'notice' => 'blocked' ) ) );
		exit;
	}

	/**
	 * The network's super admins.
	 *
	 * @return WP_User[]
	 */
	private static function super_admins(): array {
		$users = array();
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof WP_User ) {
				$users[] = $user;
			}
		}
		return $users;
	}

	/**
	 * A file check's result in a few words.
	 *
	 * @param array<string, mixed> $file FileCheck::run()'s answer.
	 */
	private static function file_summary( array $file ): string {
		if ( ! $file['manifest'] ) {
			/* translators: %d: a number of files. */
			return sprintf( __( 'No deploy list here; %d scripts in uploads', 'mody_cloud' ), (int) ( $file['uploads_count'] ?? 0 ) );
		}
		if ( ! FileCheck::found( $file ) ) {
			return __( 'Every file is as deployed', 'mody_cloud' );
		}
		/* translators: 1: changed, 2: added, 3: missing, 4: scripts in uploads. */
		return sprintf( __( '%1$d changed, %2$d added, %3$d missing, %4$d scripts in uploads', 'mody_cloud' ), (int) $file['changed_count'], (int) $file['added_count'], (int) $file['missing_count'], (int) $file['uploads_count'] );
	}

	/**
	 * Label and value rows.
	 *
	 * @param array<string, string> $rows Label => value (plain text).
	 */
	private static function rows( array $rows ): void {
		echo '<table class="mody-brand__rows">';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</table>';
	}

	/**
	 * Opens a section's form.
	 *
	 * @param string $section The section.
	 */
	private static function form_start( string $section ): void {
		echo '<form method="post" action="' . esc_url( network_admin_url( 'admin-post.php' ) ) . '" class="mody-brand__form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		echo '<input type="hidden" name="section" value="' . esc_attr( $section ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );
	}

	/**
	 * Closes a section's form.
	 */
	private static function form_end(): void {
		submit_button( __( 'Save', 'mody_cloud' ) );
		echo '</form>';
	}

	/**
	 * A number field.
	 *
	 * @param string $name  The field.
	 * @param string $label Its label.
	 * @param int    $value Its value.
	 * @param string $help  A line under it.
	 */
	private static function number( string $name, string $label, int $value, string $help = '' ): void {
		printf(
			'<div class="mody-brand__field"><label for="mody-security-%1$s">%2$s</label><input type="number" min="1" max="%5$d" class="small-text" id="mody-security-%1$s" name="%1$s" value="%3$d" />%4$s</div>',
			esc_attr( $name ),
			esc_html( $label ),
			(int) $value,
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '',
			'login_minutes' === $name ? 1440 : 1000
		);
	}

	/**
	 * A text area.
	 *
	 * @param string $name  The field.
	 * @param string $label Its label.
	 * @param string $value Its value.
	 * @param string $help  A line under it.
	 */
	private static function textarea( string $name, string $label, string $value, string $help = '' ): void {
		printf(
			'<div class="mody-brand__field"><label for="mody-security-%1$s">%2$s</label><textarea class="large-text code" rows="5" id="mody-security-%1$s" name="%1$s" spellcheck="false">%3$s</textarea>%4$s</div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( $value ),
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	/**
	 * A checkbox.
	 *
	 * @param string $name    The field.
	 * @param string $label   Its label.
	 * @param bool   $checked Whether it is on.
	 * @param string $help    A line under it.
	 */
	private static function checkbox( string $name, string $label, bool $checked, string $help = '' ): void {
		printf(
			'<div class="mody-brand__field"><label class="mody-brand__choice"><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>%4$s</div>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label ),
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	/**
	 * Radio buttons.
	 *
	 * @param string                $name    The field.
	 * @param string                $legend  The question.
	 * @param array<string, string> $options Value => label.
	 * @param string                $value   The value now.
	 */
	private static function radios( string $name, string $legend, array $options, string $value ): void {
		echo '<fieldset class="mody-brand__field"><legend>' . esc_html( $legend ) . '</legend>';
		foreach ( $options as $option => $text ) {
			printf( '<label class="mody-brand__choice"><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label>', esc_attr( $name ), esc_attr( $option ), checked( $value, $option, false ), esc_html( $text ) );
		}
		echo '</fieldset>';
	}
}
