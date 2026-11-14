<?php
/**
 * Custom domains for member sites: adding one, checking its DNS, its
 * certificate, switching the site's address, and taking it off.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Domains;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-13 (tickets/custom-domains.md):
 *
 * - **One domain per site**, entered on the site's Dashboard (Your domain)
 *   by its administrators. The member adds a CNAME at their registrar from
 *   their domain to TARGET (`sites.mody.cloud`, a DNS-only record kept on
 *   the host's changing address); a bare domain (`shop.com`) works only at
 *   registrars that allow a CNAME-like record there, so `www.shop.com` is
 *   what the page suggests.
 * - **The steps:** `pending` until the DNS points here (checked every five
 *   minutes, or with Check now), then `issuing` while Nginx Proxy Manager
 *   gets the certificate and the proxy host (ProxyManager), then `active`:
 *   the site's `home` becomes `https://<domain>` (the old one is kept in
 *   the row). `failed` after a day of failed tries; Try again restarts.
 * - **The admin stays on `*.mody.cloud`:** `siteurl` is not changed, so
 *   wp-admin, the editors, logins and REST calls from the admin keep the
 *   network's login cookie. On the custom domain, wp-login.php and wp-admin
 *   redirect there; previews use it too; REST addresses printed in the admin
 *   use it (rest_url()). Visitors of the public site at the old address are
 *   sent to the domain (301; WordPress's own canonical redirect only changes
 *   www against no www); logged-in users and previews stay, so editing works.
 * - **Taking it off** restores `home` and removes NPM's host and certificate.
 *   A deleted site's domain goes with it.
 */
final class Domains {

	/** admin-post actions. */
	public const ADD_ACTION    = 'mody_domain_add';
	public const CHECK_ACTION  = 'mody_domain_check';
	public const REMOVE_ACTION = 'mody_domain_remove';

	/** The cron event, on the main site. */
	public const EVENT = 'mody_domains_run';

	/** Minutes between DNS checks of a pending domain. */
	public const CHECK_MINUTES = 5;

	/** Hours of tries before a domain is `failed`. */
	public const GIVE_UP_HOURS = 24;

	/** Bump when the table definition changes. */
	private const DB_VERSION = '1';

	/** Network option with the installed version. */
	private const DB_VERSION_OPTION = 'mody_domains_db_version';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'install' ), 1 );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( self::EVENT, array( self::class, 'run' ) );
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval -- Five minutes, for DNS checks.
		add_action( 'admin_post_' . self::ADD_ACTION, array( self::class, 'handle_add' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( self::class, 'handle_check' ) );
		add_action( 'admin_post_' . self::REMOVE_ACTION, array( self::class, 'handle_remove' ) );
		add_action( 'init', array( self::class, 'admin_on_network' ), 0 );
		add_action( 'template_redirect', array( self::class, 'send_to_domain' ), 1 );
		add_filter( 'rest_url', array( self::class, 'rest_url' ), 10, 2 );
		add_filter( 'preview_post_link', array( self::class, 'on_admin_host' ) );
		add_filter( 'allowed_redirect_hosts', array( self::class, 'allowed_redirect_hosts' ) );
		add_action( 'wp_delete_site', array( self::class, 'site_deleted' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mody-domains', Command::class );
		}
	}

	/**
	 * Creates the table when its version changed.
	 */
	public static function install(): void {
		if ( self::DB_VERSION === get_site_option( self::DB_VERSION_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = Mapping::table();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL,
  domain varchar(253) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  old_home varchar(255) DEFAULT NULL,
  npm_host_id int(10) unsigned NOT NULL DEFAULT 0,
  npm_cert_id int(10) unsigned NOT NULL DEFAULT 0,
  checked_at datetime DEFAULT NULL,
  error text,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY domain (domain),
  UNIQUE KEY site_id (site_id),
  KEY status (status)
) {$wpdb->get_charset_collate()};"
		);
		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * `cron_schedules`: every five minutes.
	 *
	 * @param array<string, array<string, mixed>> $schedules The schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function cron_schedules( array $schedules ): array {
		$schedules['mody_five_minutes'] = array(
			'interval' => self::CHECK_MINUTES * MINUTE_IN_SECONDS,
			'display'  => 'Every five minutes',
		);
		return $schedules;
	}

	/**
	 * `init`: the run, scheduled on the main site.
	 */
	public static function schedule(): void {
		if ( is_main_site() && ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'mody_five_minutes', self::EVENT );
		}
	}

	/**
	 * Where members point their domain: TARGET (`MODY_DOMAINS_TARGET`, else
	 * `sites.<network domain>`).
	 */
	public static function target(): string {
		$target = strtolower( trim( (string) getenv( 'MODY_DOMAINS_TARGET' ) ) );
		return '' !== $target ? $target : 'sites.' . Mapping::network_domain();
	}

	/**
	 * A domain as typed, made plain: lowercase, no scheme, path, port or final dot.
	 *
	 * @param string $typed What the member typed.
	 * @return string|WP_Error
	 */
	public static function normalize( string $typed ): string|WP_Error {
		$domain = strtolower( trim( $typed ) );
		$domain = (string) preg_replace( '#^[a-z]+://#', '', $domain );
		$domain = (string) preg_replace( '#[/?\#].*$#', '', $domain );
		$domain = rtrim( (string) preg_replace( '/:\d+$/', '', $domain ), '.' );
		if ( function_exists( 'idn_to_ascii' ) && preg_match( '/[^\x00-\x7f]/', $domain ) ) {
			$ascii  = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$domain = is_string( $ascii ) ? $ascii : $domain;
		}
		if ( strlen( $domain ) > 253 || ! preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $domain ) ) {
			return new WP_Error( 'mody_domain_invalid', __( 'That is not a domain name. Type it like www.yourshop.com.', 'mody_cloud' ) );
		}
		if ( Mapping::is_network_host( $domain ) || $domain === self::target() ) {
			return new WP_Error( 'mody_domain_ours', __( 'That address is already part of Mody Cloud. Type your own domain.', 'mody_cloud' ) );
		}
		return $domain;
	}

	/**
	 * A site's domain row.
	 *
	 * @param int $site_id Site.
	 * @return array<string, mixed>|null
	 */
	public static function of_site( int $site_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Mapping::table() . ' WHERE site_id = %d', $site_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Adds a domain to a site (one per site).
	 *
	 * @param int    $site_id A member site.
	 * @param string $typed   The domain, as typed.
	 * @return array<string, mixed>|WP_Error The new row.
	 */
	public static function add( int $site_id, string $typed ): array|WP_Error {
		if ( is_main_site( $site_id ) || ! get_site( $site_id ) ) {
			return new WP_Error( 'mody_domain_site', __( 'This site cannot have its own domain.', 'mody_cloud' ) );
		}
		$domain = self::normalize( $typed );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}
		if ( self::of_site( $site_id ) ) {
			return new WP_Error( 'mody_domain_one', __( 'This site already has a domain. Take it off first.', 'mody_cloud' ) );
		}
		global $wpdb;
		$taken = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Mapping::table() . ' WHERE domain = %s', $domain ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		if ( $taken ) {
			return new WP_Error( 'mody_domain_taken', __( 'That domain is already used by another site.', 'mody_cloud' ) );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			Mapping::table(),
			array(
				'site_id' => $site_id,
				'domain'  => $domain,
				'status'  => 'pending',
			)
		);
		return self::of_site( $site_id ) ?? new WP_Error( 'mody_domain_db', __( 'That did not work. Please try again.', 'mody_cloud' ) );
	}

	/**
	 * Whether a domain points here: a CNAME to TARGET, or the same addresses as TARGET.
	 *
	 * @param string $domain The domain.
	 * @return true|WP_Error `mody_domain_dns` with what was found.
	 */
	public static function points_here( string $domain ): bool|WP_Error {
		$target = self::target();
		$cname  = self::records( $domain, DNS_CNAME );
		foreach ( $cname as $record ) {
			if ( rtrim( strtolower( (string) ( $record['target'] ?? '' ) ), '.' ) === $target ) {
				return true;
			}
		}
		$ours  = array_column( self::records( $target, DNS_A ), 'ip' );
		$found = array_column( self::records( $domain, DNS_A ), 'ip' );
		if ( $ours && $found && ! array_diff( $found, $ours ) ) {
			return true;
		}
		return new WP_Error(
			'mody_domain_dns',
			$found || $cname
				/* translators: %s: the record the member must add. */
				? sprintf( __( 'Your domain does not point to us yet: it points somewhere else. Change its record to a CNAME to %s.', 'mody_cloud' ), $target )
				: __( 'We cannot find your domain\'s record yet. DNS changes can take a few hours to show; we keep checking.', 'mody_cloud' )
		);
	}

	/**
	 * DNS records, through a filter so tests can answer.
	 *
	 * @param string $name A host.
	 * @param int    $type DNS_CNAME or DNS_A.
	 * @return array<int, array<string, mixed>>
	 */
	private static function records( string $name, int $type ): array {
		/**
		 * Filters DNS answers (tests).
		 *
		 * @param array<int, array<string, mixed>>|null $records Null: ask DNS.
		 * @param string                                $name    The host.
		 * @param int                                   $type    DNS_CNAME or DNS_A.
		 */
		$records = apply_filters( 'mody_domains_dns', null, $name, $type );
		if ( null === $records ) {
			$records = @dns_get_record( $name, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- A missing name is an answer, not an error.
		}
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Moves a domain on as far as it can: DNS, then the certificate, then live.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return array<string, mixed> The row after.
	 */
	public static function advance( array $row ): array {
		global $wpdb;
		$fields = array( 'checked_at' => gmdate( 'Y-m-d H:i:s' ) );
		if ( 'pending' === $row['status'] ) {
			$here = self::points_here( (string) $row['domain'] );
			if ( is_wp_error( $here ) ) {
				$fields['error'] = $here->get_error_message();
			} else {
				$fields['status'] = 'issuing';
				$fields['error']  = null;
				$row              = array_merge( $row, $fields );
			}
		}
		if ( 'issuing' === $row['status'] ) {
			$made = ProxyManager::add( (string) $row['domain'] );
			if ( is_wp_error( $made ) ) {
				$fields['error'] = $made->get_error_message();
				if ( strtotime( $row['created_at'] . ' UTC' ) < time() - self::GIVE_UP_HOURS * HOUR_IN_SECONDS ) {
					$fields['status'] = 'failed';
				}
			} else {
				$fields = array_merge( $fields, self::activate( $row, $made ) );
			}
		}
		$wpdb->update( Mapping::table(), $fields, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		Mapping::forget( (string) $row['domain'] );
		return array_merge( $row, $fields );
	}

	/**
	 * The certificate is there: the site's public address becomes the domain.
	 *
	 * @param array<string, mixed>          $row  The row.
	 * @param array{host: int, certificate: int} $made NPM's IDs.
	 * @return array<string, mixed> The row's new fields.
	 */
	private static function activate( array $row, array $made ): array {
		$site_id = (int) $row['site_id'];
		$old     = (string) get_blog_option( $site_id, 'home' );
		update_blog_option( $site_id, 'home', 'https://' . $row['domain'] );
		clean_blog_cache( $site_id );
		return array(
			'status'      => 'active',
			'error'       => null,
			'old_home'    => '' !== (string) ( $row['old_home'] ?? '' ) ? $row['old_home'] : $old,
			'npm_host_id' => $made['host'],
			'npm_cert_id' => $made['certificate'],
		);
	}

	/**
	 * Takes a site's domain off: the old address back, NPM's host and
	 * certificate removed, the row deleted.
	 *
	 * @param int $site_id Site.
	 * @return true|WP_Error
	 */
	public static function remove( int $site_id ): bool|WP_Error {
		$row = self::of_site( $site_id );
		if ( ! $row ) {
			return true;
		}
		if ( 'active' === $row['status'] && ! empty( $row['old_home'] ) && get_site( $site_id ) ) {
			update_blog_option( $site_id, 'home', (string) $row['old_home'] );
			clean_blog_cache( $site_id );
		}
		if ( $row['npm_host_id'] || $row['npm_cert_id'] ) {
			$gone = ProxyManager::remove( (int) $row['npm_host_id'], (int) $row['npm_cert_id'] );
			if ( is_wp_error( $gone ) ) {
				return $gone;
			}
		}
		global $wpdb;
		$wpdb->delete( Mapping::table(), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		Mapping::forget( (string) $row['domain'] );
		return true;
	}

	/**
	 * The cron run: every pending domain whose last check is old enough, and
	 * every domain getting its certificate.
	 *
	 * @return array{checked: int, active: int}
	 */
	public static function run(): array {
		global $wpdb;
		$done = array(
			'checked' => 0,
			'active'  => 0,
		);
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Mapping::table() . " WHERE status = 'issuing' OR ( status = 'pending' AND ( checked_at IS NULL OR checked_at < %s ) ) ORDER BY id", gmdate( 'Y-m-d H:i:s', time() - ( self::CHECK_MINUTES * MINUTE_IN_SECONDS - 30 ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		foreach ( (array) $rows as $row ) {
			$after = self::advance( $row );
			++$done['checked'];
			$done['active'] += 'active' === $after['status'] ? 1 : 0;
		}
		return $done;
	}

	/**
	 * `init` (early): on a custom domain, the login and the admin go to the
	 * site's `*.mody.cloud` address, where the network's login cookie works.
	 */
	public static function admin_on_network(): void {
		$host = Mapping::request_host();
		if ( '' === $host || Mapping::is_network_host( $host ) || ! Mapping::active( $host ) ) {
			return;
		}
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared, then passed to site_url().
		if ( preg_match( '#^/(wp-login\.php|wp-admin(/|$)|wp-signup\.php|wp-activate\.php)#', $path ) ) {
			$query = isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ? '?' . (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Kept as is for the redirect.
			wp_redirect( site_url( ltrim( $path, '/' ) ) . $query, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The site's own admin address.
			exit;
		}
	}

	/**
	 * `template_redirect`: the public site, opened at its old address, moves
	 * to its custom domain (same path and query).
	 */
	public static function send_to_domain(): void {
		$host = Mapping::request_host();
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $host || $host === $home || ! Mapping::is_network_host( $host ) || ! Mapping::active( $home ) ) {
			return;
		}
		if ( is_user_logged_in() || is_preview() || is_customize_preview() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Appended to the site's own domain.
		wp_redirect( 'https://' . $home . '/' . ltrim( $uri, '/' ), 301 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The site's own custom domain.
		exit;
	}

	/**
	 * A URL on the site's admin host (`siteurl`) instead of its custom domain.
	 *
	 * @param string $url A URL.
	 */
	public static function on_admin_host( string $url ): string {
		$home  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$admin = (string) wp_parse_url( site_url(), PHP_URL_HOST );
		if ( '' === $home || $home === $admin || (string) wp_parse_url( $url, PHP_URL_HOST ) !== $home ) {
			return $url;
		}
		return (string) preg_replace( '#^(https?://)' . preg_quote( $home, '#' ) . '#i', '$1' . $admin, $url );
	}

	/**
	 * `rest_url`: in the admin (the editors call the REST API with the login
	 * cookie), the admin host.
	 *
	 * @param string $url  The REST URL.
	 * @param string $path The route.
	 */
	public static function rest_url( string $url, string $path ): string {
		unset( $path );
		return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST && is_user_logged_in() ) ? self::on_admin_host( $url ) : $url;
	}

	/**
	 * `allowed_redirect_hosts`: the site's admin host too. WordPress allows
	 * only the home host, so once `home` is the custom domain every
	 * wp_safe_redirect() to the admin (`siteurl`) fell back to wp-admin, which
	 * redirected again: a loop on the first real domain (2026-11-14).
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public static function allowed_redirect_hosts( array $hosts ): array {
		$admin = (string) wp_parse_url( site_url(), PHP_URL_HOST );
		if ( '' !== $admin && ! in_array( $admin, $hosts, true ) ) {
			$hosts[] = $admin;
		}
		return $hosts;
	}

	/**
	 * `wp_delete_site`: a deleted site's domain goes too.
	 *
	 * @param \WP_Site $site The site.
	 */
	public static function site_deleted( \WP_Site $site ): void {
		self::remove( (int) $site->blog_id );
	}

	/* The Dashboard's forms -------------------------------------------------------------- */

	/**
	 * Where the forms go back to: the site's Your domain section.
	 *
	 * @param array<string, string> $extra Query arguments.
	 */
	private static function back( array $extra = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page'    => 'mody-dashboard',
					'section' => 'domain',
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Checks a form: its nonce, and that the user runs this site.
	 *
	 * @param string $action The form's action.
	 */
	private static function guard( string $action ): void {
		check_admin_referer( $action );
		if ( is_main_site() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change this site\'s domain.', 'mody_cloud' ), 403 );
		}
	}

	/**
	 * admin-post: Add the domain.
	 */
	public static function handle_add(): void {
		self::guard( self::ADD_ACTION );
		$typed = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$row   = self::add( get_current_blog_id(), $typed );
		if ( is_wp_error( $row ) ) {
			set_transient( 'mody_domain_error_' . get_current_user_id(), $row->get_error_message(), 60 );
			wp_safe_redirect( self::back( array( 'domain-result' => 'error' ) ) );
			exit;
		}
		self::advance( $row ); // It may already point here.
		wp_safe_redirect( self::back( array( 'domain-result' => 'added' ) ) );
		exit;
	}

	/**
	 * admin-post: Check now (and Try again after a failure).
	 */
	public static function handle_check(): void {
		self::guard( self::CHECK_ACTION );
		$row = self::of_site( get_current_blog_id() );
		if ( $row ) {
			if ( 'failed' === $row['status'] ) {
				global $wpdb;
				$wpdb->update( Mapping::table(), array( 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
				$row = self::of_site( get_current_blog_id() );
			}
			self::advance( $row );
		}
		wp_safe_redirect( self::back( array( 'domain-result' => 'checked' ) ) );
		exit;
	}

	/**
	 * admin-post: Take the domain off.
	 */
	public static function handle_remove(): void {
		self::guard( self::REMOVE_ACTION );
		$done = self::remove( get_current_blog_id() );
		wp_safe_redirect( self::back( array( 'domain-result' => true === $done ? 'removed' : 'error' ) ) );
		exit;
	}
}
