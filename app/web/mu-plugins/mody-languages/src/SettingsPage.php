<?php
/**
 * Settings → Languages: the site's languages and the translation matrix.
 *
 * - **Languages** (manage_options): the default language is core's Site
 *   Language (Settings → General); the other languages are checkboxes.
 * - **The matrix:** one row per page in the default language, then the
 *   header and the footer; one column per other language. A cell is missing
 *   (with Create: a draft copy, then the editor), draft, translated or
 *   outdated (the original was saved after the translation).
 * - **More actions:** the filter mody_languages_cell_actions adds links to a
 *   missing page cell (mody-platform's "Translate with Mody").
 *
 * Changes go through admin-post.php with a nonce, then back to this screen.
 *
 * @package Mody\Languages
 */

namespace Mody\Languages;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The Languages screen and its actions.
 */
final class SettingsPage {

	public const SLUG = 'mody-languages';

	/** The most pages the matrix lists. */
	public const MAX_ROWS = 200;

	/**
	 * Hooked on load.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_mody_languages_save', array( self::class, 'save' ) );
		add_action( 'admin_post_mody_languages_create', array( self::class, 'create' ) );
	}

	/**
	 * `admin_menu`: Settings → Languages.
	 */
	public static function menu(): void {
		add_options_page( __( 'Languages', 'mody_cloud' ), __( 'Languages', 'mody_cloud' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * The screen's address, with optional query arguments.
	 *
	 * @param array<string, string> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * The link that creates a translation: a page's (by ID) or a template
	 * part's (by slug).
	 *
	 * @param string $what   A page ID, or header / footer.
	 * @param string $locale The language.
	 */
	public static function create_url( string $what, string $locale ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'mody_languages_create',
					'what'   => $what,
					'lang'   => $locale,
				),
				admin_url( 'admin-post.php' )
			),
			'mody_languages_create_' . $what . '_' . $locale
		);
	}

	/**
	 * The editor of a template part.
	 *
	 * @param string $slug A template part's slug.
	 */
	public static function part_editor_url( string $slug ): string {
		return add_query_arg(
			array(
				'postType' => 'wp_template_part',
				'postId'   => get_stylesheet() . '//' . $slug,
				'canvas'   => 'edit',
			),
			admin_url( 'site-editor.php' )
		);
	}

	/**
	 * The pages in the default language, for the matrix's rows.
	 *
	 * @return WP_Post[]
	 */
	public static function originals(): array {
		return get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => self::MAX_ROWS,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One query per screen.
					array(
						'key'     => Translations::LANG,
						'compare' => 'NOT EXISTS',
					),
				),
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * `admin_post_mody_languages_save`: the site's other languages.
	 */
	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change this site\'s languages.', 'mody_cloud' ), 403 );
		}
		check_admin_referer( 'mody_languages_save' );
		$locales = isset( $_POST['languages'] ) && is_array( $_POST['languages'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['languages'] ) ) : array();
		Languages::save( $locales );
		$args = array( 'saved' => '1' );
		$taken = array_filter( Languages::others(), static fn( string $locale ): bool => null !== get_page_by_path( Languages::slug( $locale ) ) );
		if ( $taken ) {
			$args['taken'] = implode( ',', array_map( array( Languages::class, 'slug' ), $taken ) );
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * `admin_post_mody_languages_create`: a page's or a template part's copy,
	 * then its editor.
	 */
	public static function create(): void {
		$what   = sanitize_key( wp_unslash( $_GET['what'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
		$locale = sanitize_text_field( wp_unslash( $_GET['lang'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
		check_admin_referer( 'mody_languages_create_' . $what . '_' . $locale );

		if ( in_array( $what, Parts::TRANSLATABLE, true ) ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				wp_die( esc_html__( 'You cannot edit this site\'s template parts.', 'mody_cloud' ), 403 );
			}
			$result = Parts::create( $what, $locale );
			$target = is_wp_error( $result ) ? '' : self::part_editor_url( Parts::copy_slug( $what, $locale ) );
		} else {
			$post_id = absint( $what );
			$type    = $post_id ? get_post_type_object( (string) get_post_type( $post_id ) ) : null;
			if ( ! $post_id || ! $type || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( $type->cap->publish_posts ) ) {
				wp_die( esc_html__( 'You cannot translate this page.', 'mody_cloud' ), 403 );
			}
			$result = Translations::create( $post_id, $locale );
			$target = is_wp_error( $result ) ? '' : (string) get_edit_post_link( $result, 'url' );
		}

		if ( is_wp_error( $result ) ) {
			$code = str_replace( 'mody_languages_', '', (string) $result->get_error_code() );
			wp_safe_redirect( self::url( array( 'error' => isset( self::errors()[ $code ] ) ? $code : 'failed' ) ) );
			exit;
		}
		wp_safe_redirect( $target ? $target : self::url() );
		exit;
	}

	/**
	 * The screen's error messages, by the code in its address. Unknown codes
	 * show nothing.
	 *
	 * @return array<string, string>
	 */
	public static function errors(): array {
		return array(
			'no_original'     => __( 'This page cannot be translated.', 'mody_cloud' ),
			'not_a_language'  => __( 'This is not one of the site\'s other languages.', 'mody_cloud' ),
			'exists'          => __( 'That translation already exists.', 'mody_cloud' ),
			'no_part'         => __( 'This template part cannot be translated.', 'mody_cloud' ),
			'failed'          => __( 'The translation could not be created.', 'mody_cloud' ),
		);
	}

	/**
	 * A cell's state label.
	 *
	 * @param string $state missing, draft, translated or outdated.
	 */
	private static function state_label( string $state ): string {
		return array(
			'missing'    => __( 'Missing', 'mody_cloud' ),
			'draft'      => __( 'Draft', 'mody_cloud' ),
			'translated' => __( 'Translated', 'mody_cloud' ),
			'outdated'   => __( 'Outdated', 'mody_cloud' ),
		)[ $state ] ?? $state;
	}

	/**
	 * One cell of a page row.
	 *
	 * @param WP_Post $page   An original.
	 * @param string  $locale A language.
	 */
	private static function page_cell( WP_Post $page, string $locale ): string {
		$id = Translations::in( $page, $locale, false );
		if ( $id ) {
			$state = Translations::state( $id );
			$links = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $id, 'url' ) ), esc_html__( 'Edit', 'mody_cloud' ) );
			if ( 'publish' === get_post_status( $id ) ) {
				$links .= sprintf( ' · <a href="%s">%s</a>', esc_url( (string) get_permalink( $id ) ), esc_html__( 'View', 'mody_cloud' ) );
			}
			return sprintf( '<span class="mody-languages-state is-%1$s">%2$s</span><br>%3$s', esc_attr( $state ), esc_html( self::state_label( $state ) ), $links );
		}

		$actions = array(
			array(
				'label' => __( 'Create', 'mody_cloud' ),
				'url'   => self::create_url( (string) $page->ID, $locale ),
			),
		);
		/**
		 * Actions offered on a missing translation.
		 *
		 * @param array<int, array{label: string, url: string}> $actions Links.
		 * @param int                                          $post_id The original.
		 * @param string                                       $locale  The language.
		 */
		$actions = (array) apply_filters( 'mody_languages_cell_actions', $actions, $page->ID, $locale );
		$links   = array();
		foreach ( $actions as $action ) {
			if ( is_array( $action ) && ! empty( $action['label'] ) && ! empty( $action['url'] ) ) {
				$links[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) $action['url'] ), esc_html( (string) $action['label'] ) );
			}
		}
		return sprintf( '<span class="mody-languages-state is-missing">%s</span><br>%s', esc_html( self::state_label( 'missing' ) ), implode( ' · ', $links ) );
	}

	/**
	 * One cell of a template part row.
	 *
	 * @param string $slug   header or footer.
	 * @param string $locale A language.
	 */
	private static function part_cell( string $slug, string $locale ): string {
		$copy = Parts::copy_slug( $slug, $locale );
		if ( Parts::find( $copy ) ) {
			return sprintf(
				'<span class="mody-languages-state is-translated">%s</span><br><a href="%s">%s</a>',
				esc_html__( 'Copy made', 'mody_cloud' ),
				esc_url( self::part_editor_url( $copy ) ),
				esc_html__( 'Edit', 'mody_cloud' )
			);
		}
		return sprintf(
			'<span class="mody-languages-state is-missing">%s</span><br><a href="%s">%s</a>',
			esc_html__( 'Same as the original', 'mody_cloud' ),
			esc_url( self::create_url( $slug, $locale ) ),
			esc_html__( 'Create a copy', 'mody_cloud' )
		);
	}

	/**
	 * The screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$default = Languages::default_locale();
		$others  = Languages::others();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Notices only.
		$saved = isset( $_GET['saved'] );
		$error = self::errors()[ sanitize_key( wp_unslash( $_GET['error'] ?? '' ) ) ] ?? '';
		$taken = array_intersect(
			explode( ',', sanitize_text_field( wp_unslash( $_GET['taken'] ?? '' ) ) ),
			array_map( array( Languages::class, 'slug' ), Languages::others() )
		);
		// phpcs:enable
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Languages', 'mody_cloud' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Languages saved.', 'mody_cloud' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $taken as $prefix ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					/* translators: %s: an address prefix, such as /es/. */
					printf( esc_html__( 'A page already uses the address %s, so it keeps it: that language\'s home page cannot be reached there. Change that page\'s slug to free the address.', 'mody_cloud' ), '<code>/' . esc_html( $prefix ) . '/</code>' );
					?>
				</p></div>
			<?php endforeach; ?>
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mody_languages_save">
				<?php wp_nonce_field( 'mody_languages_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Default language', 'mody_cloud' ); ?></th>
						<td>
							<strong><?php echo esc_html( Languages::label( $default ) ); ?></strong>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to Settings → General. */
									esc_html__( 'Your pages are in this language, at addresses without a prefix. It is the Site Language in %s.', 'mody_cloud' ),
									'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings → General', 'mody_cloud' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Other languages', 'mody_cloud' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Other languages', 'mody_cloud' ); ?></legend>
								<?php foreach ( array_keys( Languages::OFFERED ) as $locale ) : ?>
									<?php
									if ( $locale === $default || Languages::slug( $locale ) === Languages::slug( $default ) ) {
										continue;
									}
									?>
									<label style="display:inline-block;min-width:10em;margin:0 1em .5em 0">
										<input type="checkbox" name="languages[]" value="<?php echo esc_attr( $locale ); ?>" <?php checked( in_array( $locale, $others, true ) ); ?>>
										<?php echo esc_html( Languages::label( $locale ) ); ?>
										<code>/<?php echo esc_html( Languages::slug( $locale ) ); ?>/</code>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Pages in another language live at addresses starting with its prefix, and a language switcher appears next to your menu.', 'mody_cloud' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save languages', 'mody_cloud' ) ); ?>
			</form>

			<?php if ( array() !== $others ) : ?>
				<h2><?php esc_html_e( 'Translations', 'mody_cloud' ); ?></h2>
				<p><?php esc_html_e( 'Every page in your default language, and its translations. A translation is outdated when its original was changed after it.', 'mody_cloud' ); ?></p>
				<style>
					.mody-languages-matrix td, .mody-languages-matrix th { vertical-align: top; }
					.mody-languages-state { font-weight: 600; }
					.mody-languages-state.is-missing { color: #8a6d00; }
					.mody-languages-state.is-draft { color: #50575e; }
					.mody-languages-state.is-translated { color: #00702a; }
					.mody-languages-state.is-outdated { color: #b32d2e; }
				</style>
				<table class="widefat striped mody-languages-matrix">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html( Languages::label( $default ) ); ?></th>
							<?php foreach ( $others as $locale ) : ?>
								<th scope="col"><?php echo esc_html( Languages::label( $locale ) ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( self::originals() as $page ) : ?>
							<tr>
								<th scope="row">
									<a href="<?php echo esc_url( (string) get_edit_post_link( $page->ID, 'url' ) ); ?>"><?php echo esc_html( get_the_title( $page ) ? get_the_title( $page ) : __( '(no title)', 'mody_cloud' ) ); ?></a>
									<?php if ( 'publish' !== $page->post_status ) : ?>
										<span class="post-state">— <?php echo esc_html( get_post_status_object( $page->post_status )->label ?? $page->post_status ); ?></span>
									<?php endif; ?>
								</th>
								<?php foreach ( $others as $locale ) : ?>
									<td><?php echo self::page_cell( $page, $locale ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built. ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						<?php foreach ( Parts::TRANSLATABLE as $slug ) : ?>
							<?php $part = Parts::find( $slug ); ?>
							<?php if ( $part ) : ?>
								<tr>
									<th scope="row"><a href="<?php echo esc_url( self::part_editor_url( $slug ) ); ?>"><?php echo esc_html( $part->title ); ?></a> <span class="post-state">— <?php esc_html_e( 'template part', 'mody_cloud' ); ?></span></th>
									<?php foreach ( $others as $locale ) : ?>
										<td><?php echo self::part_cell( $slug, $locale ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built. ?></td>
									<?php endforeach; ?>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
