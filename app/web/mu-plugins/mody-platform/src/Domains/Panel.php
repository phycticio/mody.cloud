<?php
/**
 * The Dashboard's Your domain section.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Your domain (tickets/custom-domains.md): type a domain, see the record to
 * add at the registrar, follow its steps, Check now, Take it off. For the
 * site's administrators, on member sites.
 */
final class Panel {

	/** The section's key on the Dashboard. */
	public const SECTION = 'domain';

	/**
	 * Whether the section shows: a member site, run by the current user.
	 */
	public static function available(): bool {
		return ! is_main_site() && current_user_can( 'manage_options' );
	}

	/**
	 * The section.
	 */
	public static function render(): void {
		$row    = Domains::of_site( get_current_blog_id() );
		$target = Domains::target();
		self::notice();
		if ( ! $row ) {
			?>
			<p><?php esc_html_e( 'Your site can answer at your own domain, such as www.yourshop.com. You buy the domain from any registrar, then point it to us here. Your editor and wp-admin stay at this site\'s mody.cloud address.', 'mody_cloud' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mody-domain__form">
				<?php wp_nonce_field( Domains::ADD_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Domains::ADD_ACTION ); ?>" />
				<p>
					<label for="mody-domain"><?php esc_html_e( 'Your domain', 'mody_cloud' ); ?></label><br />
					<input type="text" id="mody-domain" name="domain" class="regular-text" placeholder="www.yourshop.com" autocomplete="off" spellcheck="false" required />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Use this domain', 'mody_cloud' ); ?></button>
				</p>
			</form>
			<?php
			return;
		}
		$domain = (string) $row['domain'];
		$name   = self::record_name( $domain );
		$steps  = array(
			'pending' => __( 'Waiting for your domain to point to us', 'mody_cloud' ),
			'issuing' => __( 'Your domain points to us: setting up its secure certificate', 'mody_cloud' ),
			'active'  => __( 'Live', 'mody_cloud' ),
			'failed'  => __( 'Stopped: see the reason below', 'mody_cloud' ),
		);
		?>
		<table class="mody-brand__rows">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Domain', 'mody_cloud' ); ?></th>
					<td>
						<?php if ( 'active' === $row['status'] ) : ?>
							<a href="<?php echo esc_url( 'https://' . $domain . '/' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $domain ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $domain ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'mody_cloud' ); ?></th>
					<td>
						<span class="mody-brand__badge<?php echo 'active' === $row['status'] ? ' is-filled' : ''; ?>"><?php echo esc_html( $steps[ $row['status'] ] ?? $row['status'] ); ?></span>
						<?php if ( ! empty( $row['checked_at'] ) && 'active' !== $row['status'] ) : ?>
							<span class="mody-dashboard__note">
								<?php
								/* translators: %s: a time, such as "3 minutes". */
								echo esc_html( sprintf( __( 'Last checked %s ago.', 'mody_cloud' ), human_time_diff( (int) strtotime( $row['checked_at'] . ' UTC' ) ) ) );
								?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( ! empty( $row['error'] ) && 'active' !== $row['status'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Why', 'mody_cloud' ); ?></th>
						<td><?php echo esc_html( (string) $row['error'] ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( in_array( $row['status'], array( 'pending', 'failed' ), true ) ) : ?>
			<h3><?php esc_html_e( 'Point your domain to us', 'mody_cloud' ); ?></h3>
			<p><?php esc_html_e( 'At the company where you bought the domain, open its DNS settings and add this record (or change the one with the same name):', 'mody_cloud' ); ?></p>
			<table class="widefat striped mody-domain__record">
				<thead><tr><th><?php esc_html_e( 'Type', 'mody_cloud' ); ?></th><th><?php esc_html_e( 'Name', 'mody_cloud' ); ?></th><th><?php esc_html_e( 'Value', 'mody_cloud' ); ?></th></tr></thead>
				<tbody><tr><td><code>CNAME</code></td><td><code><?php echo esc_html( $name ); ?></code></td><td><code><?php echo esc_html( $target ); ?></code></td></tr></tbody>
			</table>
			<p class="mody-dashboard__note">
				<?php
				esc_html_e( 'We check every few minutes; changes can take a few hours to show everywhere. Once it points to us, we set up the secure certificate and your site goes live at your domain by itself.', 'mody_cloud' );
				if ( '@' === $name ) {
					echo ' ' . esc_html__( 'This is a bare domain: many registrars allow a CNAME there only as "ALIAS", "ANAME" or "CNAME flattening". If yours does not, use the www address instead, and set your registrar to forward the bare domain to it.', 'mody_cloud' );
				}
				?>
			</p>
		<?php elseif ( 'active' === $row['status'] ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: the custom domain, 2: the site's mody.cloud address. */
						__( 'Your site answers at %1$s, with a secure certificate. Visitors who open %2$s are sent there. You keep logging in and editing at %2$s.', 'mody_cloud' ),
						$domain,
						(string) wp_parse_url( site_url(), PHP_URL_HOST )
					)
				);
				?>
			</p>
		<?php endif; ?>

		<div class="mody-domain__actions">
			<?php if ( 'active' !== $row['status'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( Domains::CHECK_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( Domains::CHECK_ACTION ); ?>" />
					<button type="submit" class="button button-primary"><?php echo esc_html( 'failed' === $row['status'] ? __( 'Try again', 'mody_cloud' ) : __( 'Check now', 'mody_cloud' ) ); ?></button>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Take this domain off? Your site goes back to its mody.cloud address.', 'mody_cloud' ) ) ); ?>);">
				<?php wp_nonce_field( Domains::REMOVE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Domains::REMOVE_ACTION ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Take this domain off', 'mody_cloud' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * The record's name at the registrar: `www` for www.shop.com, `@` for a
	 * bare domain, the part before the last two labels otherwise.
	 *
	 * @param string $domain The domain.
	 */
	public static function record_name( string $domain ): string {
		$labels = explode( '.', $domain );
		// Common two-part endings (shop.co.uk is a bare domain); not the whole public suffix list.
		$suffix = in_array( implode( '.', array_slice( $labels, -2 ) ), array( 'co.uk', 'org.uk', 'me.uk', 'com.es', 'org.es', 'nom.es', 'com.au', 'com.br', 'com.mx', 'com.ar', 'co.jp', 'co.nz', 'com.pt', 'co.za' ), true ) ? 3 : 2;
		return count( $labels ) <= $suffix ? '@' : implode( '.', array_slice( $labels, 0, count( $labels ) - $suffix ) );
	}

	/**
	 * What the last form did.
	 */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the result of the last form.
		$result = isset( $_GET['domain-result'] ) ? sanitize_key( wp_unslash( $_GET['domain-result'] ) ) : '';
		if ( 'error' === $result ) {
			$message = (string) get_transient( 'mody_domain_error_' . get_current_user_id() );
			delete_transient( 'mody_domain_error_' . get_current_user_id() );
			printf( '<div class="notice notice-error inline mody-brand__notice"><p>%s</p></div>', esc_html( '' !== $message ? $message : __( 'That did not work. Please try again.', 'mody_cloud' ) ) );
		} elseif ( 'removed' === $result ) {
			printf( '<div class="notice notice-success inline mody-brand__notice"><p>%s</p></div>', esc_html__( 'The domain is off. Your site answers at its mody.cloud address again.', 'mody_cloud' ) );
		} elseif ( 'added' === $result ) {
			printf( '<div class="notice notice-success inline mody-brand__notice"><p>%s</p></div>', esc_html__( 'Domain saved. Now point it to us as shown below.', 'mody_cloud' ) );
		}
	}
}
