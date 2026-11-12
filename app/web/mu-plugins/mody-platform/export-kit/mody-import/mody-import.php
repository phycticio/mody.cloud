<?php
/**
 * Plugin Name:       Mody Import
 * Description:       Brings a site you downloaded from Mody Cloud back to life on any WordPress: its pages, design, languages, menus, media and users. This first version explains the three files and the steps; the one-click restore comes in a later version.
 * Version:           0.1.0
 * Author:            Mody Cloud
 * Author URI:        https://mody.cloud
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody-import
 *
 * Made by Mody Cloud's Export\Archives and zipped into the code file
 * (tickets/take-your-site-with-you.md). It never runs on Mody Cloud itself.
 * It makes no network calls and stores nothing: its only screen is
 * Tools → Mody Import.
 *
 * The code file is this plugin plus a payload/ folder: the theme and the
 * plugins the site ran, ready to copy into wp-content. README-restore.txt
 * lists, step by step, how to put the three files back by hand.
 *
 * Nothing in this folder is meant for the web: .htaccess denies the manifest
 * and the text files, payload/ has its own, and the screen shows the README
 * itself. The README's last step deletes the plugin once the site is back.
 *
 * @package Mody\Import
 */

namespace Mody\Import;

defined( 'ABSPATH' ) || exit;

const SCREEN = 'mody-import';

add_action( 'admin_menu', __NAMESPACE__ . '\\add_screen' );

/**
 * Adds Tools → Mody Import.
 */
function add_screen(): void {
	add_management_page(
		__( 'Mody Import', 'mody-import' ),
		__( 'Mody Import', 'mody-import' ),
		'manage_options',
		SCREEN,
		__NAMESPACE__ . '\\render_screen'
	);
}

/**
 * What the download says about itself: the manifest that travels in this
 * plugin's folder.
 *
 * @return array<string, mixed> Empty when it is missing or unreadable.
 */
function manifest(): array {
	$file = __DIR__ . '/mody-export.json';
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$manifest = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in this plugin.
	return is_array( $manifest ) ? $manifest : array();
}

/**
 * The Tools → Mody Import screen.
 */
function render_screen(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$manifest = manifest();
	$name     = (string) ( $manifest['site']['name'] ?? '' );
	$home     = (string) ( $manifest['site']['home'] ?? '' );
	$created  = (string) ( $manifest['created_at'] ?? '' );
	$readme   = is_readable( __DIR__ . '/README-restore.txt' ) ? (string) file_get_contents( __DIR__ . '/README-restore.txt' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in this plugin.
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Mody Import', 'mody-import' ); ?></h1>

		<?php if ( '' !== $name || '' !== $home ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: site name, 2: site address, 3: date. */
					esc_html__( 'This download was made from %1$s (%2$s) on %3$s.', 'mody-import' ),
					'<strong>' . esc_html( '' !== $name ? $name : $home ) . '</strong>',
					esc_html( $home ),
					esc_html( '' !== $created ? mysql2date( get_option( 'date_format' ), $created ) : '' )
				);
				?>
			</p>
		<?php endif; ?>

		<p><?php esc_html_e( 'The site was downloaded as three files. All three are needed to bring it back:', 'mody-import' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'The code file: this plugin, with the site\'s theme and plugins in its payload folder.', 'mody-import' ); ?></li>
			<li><?php esc_html_e( 'The media file: every image, document and font in the site\'s media library.', 'mody-import' ); ?></li>
			<li><?php esc_html_e( 'The database file: the pages, design, languages, menus, settings and users.', 'mody-import' ); ?></li>
		</ol>

		<p>
			<strong><?php esc_html_e( 'The one-click restore arrives in a later version of Mody Import.', 'mody-import' ); ?></strong>
			<?php esc_html_e( 'Until then, the steps to restore the site by hand are in README-restore.txt, shown below and in this plugin\'s folder.', 'mody-import' ); ?>
		</p>

		<?php if ( '' !== $readme ) : ?>
			<h2><?php esc_html_e( 'README-restore.txt', 'mody-import' ); ?></h2>
			<pre style="max-width: 100%; max-height: 32em; overflow: auto; padding: 1em; background: #fff; border: 1px solid #c3c4c7;"><?php echo esc_html( $readme ); ?></pre>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'Mody Import makes no network calls and keeps nothing in your database. It is free software (GPL), and you can delete it once your site is back.', 'mody-import' ); ?></p>
	</div>
	<?php
}
