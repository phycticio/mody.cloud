<?php
/**
 * Settings → Brand ("Your brand"): what Mody knows about the business.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Brand;

use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Design\SiteDesign;
use Mody\Platform\Login\LoginPage;
use Mody\Platform\SiteEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Asked for by the maintainer on 2026-11-11 (tickets/your-brand-page.md).
 * The layout follows the screenshot he gave: the sections on the left; on
 * the right a card with the brand's name and basics, a notice after saving,
 * and the section's panel with two tabs, Overview (label and value rows)
 * and Edit (the form).
 *
 * Every value lives in WordPress: the name and tagline are `blogname` and
 * `blogdescription`, the mission and tone BrandSettings', the logo the
 * Identity screen's, the rest BrandBook's one setting. Each section's form
 * posts to admin-post.php (save()), which writes only that section's
 * values; the other sections are never touched.
 */
final class BrandPage {

	/** Page slug under Settings. */
	public const SLUG = 'mody-brand';

	/** The admin-post action. */
	public const ACTION = 'mody_brand_save';

	/** Who may see and change the brand (decided 2026-11-11: administrators). */
	public const CAPABILITY = 'manage_options';

	/**
	 * The page's address, on a section and tab.
	 *
	 * @param string               $section A section.
	 * @param string               $tab     overview or edit.
	 * @param array<string, mixed> $extra   More query arguments.
	 */
	public static function url( string $section = 'overview', string $tab = 'overview', array $extra = array() ): string {
		$args = array( 'page' => self::SLUG );
		if ( 'overview' !== $section ) {
			$args['section'] = $section;
		}
		if ( 'edit' === $tab ) {
			$args['tab'] = 'edit';
		}
		return add_query_arg( array_merge( $args, $extra ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Hooked to `admin_menu`: Settings → Brand.
	 */
	public static function register_menu(): void {
		$hook = add_options_page(
			__( 'Your brand', 'mody_cloud' ),
			__( 'Brand', 'mody_cloud' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'enqueue' ) );
			add_action( 'admin_head-' . $hook, array( LoginPage::class, 'print_fonts' ) );
		}
	}

	/**
	 * `load-<page>`: the page's styles and script, and the site's theme
	 * variables, so the visual style shows the site's own colors and fonts.
	 */
	public static function enqueue(): void {
		$root = dirname( __DIR__, 2 );
		LoginPage::enqueue_asset( 'mody-brand-page', 'brand-page.css' );
		wp_add_inline_style( 'mody-brand-page', LoginPage::variables_css() );
		wp_enqueue_script( 'mody-brand-page', plugins_url( 'assets/brand-page.js', $root . '/mody-platform.php' ), array(), (string) filemtime( $root . '/assets/brand-page.js' ), true );
	}

	/**
	 * The section and tab asked for.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function current(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: which section to show.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'overview';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		// phpcs:enable
		$section = isset( BrandBook::sections()[ $section ] ) ? $section : 'overview';
		$tab     = 'edit' === $tab && in_array( $section, BrandBook::editable_sections(), true ) ? 'edit' : 'overview';
		return array( $section, $tab );
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		list( $section, $tab ) = self::current();
		$sections              = BrandBook::sections();
		$filled                = BrandBook::filled_sections();
		?>
		<div class="wrap mody-brand">
			<h1><?php esc_html_e( 'Your brand', 'mody_cloud' ); ?></h1>
			<p class="mody-brand__intro"><?php esc_html_e( 'Everything here is what Mody knows about your business. Mody reads it with every request, so the pages, blocks and texts it writes fit your brand.', 'mody_cloud' ); ?></p>
			<div class="mody-brand__layout">
				<nav class="mody-brand__sections" aria-label="<?php esc_attr_e( 'Brand sections', 'mody_cloud' ); ?>">
					<ul>
						<?php foreach ( $sections as $slug => $label ) : ?>
							<li>
								<a href="<?php echo esc_url( self::url( $slug ) ); ?>"
									class="mody-brand__section-link<?php echo $slug === $section ? ' is-active' : ''; ?>"
									<?php echo $slug === $section ? 'aria-current="page"' : ''; ?>>
									<span><?php echo esc_html( $label ); ?></span>
									<?php if ( in_array( $slug, $filled, true ) ) : ?>
										<span class="mody-brand__filled" aria-label="<?php esc_attr_e( 'filled in', 'mody_cloud' ); ?>">✓</span>
									<?php endif; ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
				<div class="mody-brand__main">
					<?php self::render_card( $filled ); ?>
					<?php self::render_notice(); ?>
					<section class="mody-brand__panel" aria-labelledby="mody-brand-section-title">
						<div class="mody-brand__panel-head">
							<h2 id="mody-brand-section-title"><?php echo esc_html( $sections[ $section ] ); ?></h2>
							<?php if ( in_array( $section, BrandBook::editable_sections(), true ) ) : ?>
								<nav class="mody-brand__tabs" aria-label="<?php esc_attr_e( 'View or edit', 'mody_cloud' ); ?>">
									<?php
									foreach ( array(
										'overview' => __( 'Overview', 'mody_cloud' ),
										'edit'     => __( 'Edit', 'mody_cloud' ),
									) as $slug => $label ) :
										?>
										<a href="<?php echo esc_url( self::url( $section, $slug ) ); ?>" class="mody-brand__tab<?php echo $slug === $tab ? ' is-active' : ''; ?>" <?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
									<?php endforeach; ?>
								</nav>
							<?php endif; ?>
						</div>
						<div class="mody-brand__panel-body">
							<?php
							if ( 'overview' === $section ) {
								self::render_overview( $filled );
							} elseif ( 'visual' === $section ) {
								self::render_visual();
							} elseif ( 'edit' === $tab ) {
								self::render_form( $section );
							} else {
								self::render_rows( $section );
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
	 * The brand's card: logo, name, tagline, how much is filled in.
	 *
	 * @param string[] $filled The filled-in sections.
	 */
	private static function render_card( array $filled ): void {
		$brand    = BrandSettings::brand();
		$editable = BrandBook::editable_sections();
		$count    = count( array_intersect( $filled, $editable ) );
		?>
		<section class="mody-brand__card" aria-label="<?php esc_attr_e( 'The brand', 'mody_cloud' ); ?>">
			<?php $logo = LoginPage::logo_html( '200px' ); ?>
			<?php if ( ! str_contains( $logo, 'mody-login-name' ) ) : // No logo or icon: the name is already beside it. ?>
				<div class="mody-brand__logo"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in logo_html(). ?></div>
			<?php endif; ?>
			<div class="mody-brand__card-text">
				<p class="mody-brand__name"><?php echo esc_html( '' !== $brand['name'] ? $brand['name'] : __( '(no name yet)', 'mody_cloud' ) ); ?></p>
				<?php if ( '' !== $brand['tagline'] ) : ?>
					<p class="mody-brand__tagline"><?php echo esc_html( $brand['tagline'] ); ?></p>
				<?php endif; ?>
				<p class="mody-brand__progress">
					<?php
					/* translators: 1: sections filled in, 2: all sections. */
					echo esc_html( sprintf( __( '%1$d of %2$d sections filled in', 'mody_cloud' ), $count, count( $editable ) ) );
					?>
					<span class="mody-brand__bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) round( 100 * $count / max( 1, count( $editable ) ) ) ); ?>%"></span></span>
				</p>
			</div>
			<?php if ( current_user_can( 'edit_theme_options' ) ) : ?>
				<a class="button" href="<?php echo esc_url( SiteEditor::url( '/identity' ) ); ?>"><?php esc_html_e( 'Change the logo and icon', 'mody_cloud' ); ?></a>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * The notice after saving, or why nothing was saved.
	 */
	private static function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: the result of the last save.
		$saved = isset( $_GET['saved'] );
		$error = isset( $_GET['brand-error'] ) ? sanitize_key( wp_unslash( $_GET['brand-error'] ) ) : '';
		// phpcs:enable
		if ( 'name' === $error ) {
			echo '<div class="notice notice-error inline mody-brand__notice"><p>' . esc_html__( 'The name cannot be empty. The rest was saved.', 'mody_cloud' ) . '</p></div>';
		} elseif ( $saved ) {
			echo '<div class="notice notice-success inline is-dismissible mody-brand__notice"><p>' . esc_html__( 'Saved. Mody uses it from your next request.', 'mody_cloud' ) . '</p></div>';
		}
	}

	/**
	 * The Overview section: every section and whether it is filled in, and
	 * the exact text Mody receives.
	 *
	 * @param string[] $filled The filled-in sections.
	 */
	private static function render_overview( array $filled ): void {
		$sections = BrandBook::sections();
		$about    = array(
			'identity' => __( 'Name, tagline, mission and vision.', 'mody_cloud' ),
			'voice'    => __( 'Tone, how the brand sounds, how it addresses people, words to use and avoid.', 'mody_cloud' ),
			'audience' => __( 'Who the customers are and what they need.', 'mody_cloud' ),
			'offer'    => __( 'Products and services, and what makes the business different.', 'mody_cloud' ),
			'story'    => __( 'How the business started, and its values.', 'mody_cloud' ),
			'facts'    => __( 'Address, phone, email, opening hours and area served. Mody writes them on pages instead of placeholders.', 'mody_cloud' ),
		);
		?>
		<table class="mody-brand__rows mody-brand__status">
			<tbody>
				<?php foreach ( $about as $slug => $text ) : ?>
					<tr>
						<th scope="row"><a href="<?php echo esc_url( self::url( $slug ) ); ?>"><?php echo esc_html( $sections[ $slug ] ); ?></a></th>
						<td><?php echo esc_html( $text ); ?></td>
						<td class="mody-brand__state">
							<?php if ( in_array( $slug, $filled, true ) ) : ?>
								<span class="mody-brand__badge is-filled"><?php esc_html_e( 'Filled in', 'mody_cloud' ); ?></span>
							<?php else : ?>
								<a class="mody-brand__badge" href="<?php echo esc_url( self::url( $slug, 'edit' ) ); ?>"><?php esc_html_e( 'Fill in', 'mody_cloud' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'You can also tell Mody about your business in the chat, for example "our services are…", and it saves it here.', 'mody_cloud' ); ?></p>
		<details class="mody-brand__prompt">
			<summary><?php esc_html_e( 'What Mody receives', 'mody_cloud' ); ?></summary>
			<pre><?php echo esc_html( SystemPrompt::brand_context() ); ?></pre>
		</details>
		<?php
	}

	/**
	 * A section's values as label and value rows.
	 *
	 * @param string $section A section.
	 */
	private static function render_rows( string $section ): void {
		$rows = array();
		$own  = BrandSettings::brand();
		if ( 'identity' === $section ) {
			$rows[ __( 'Name', 'mody_cloud' ) ]    = $own['name'];
			$rows[ __( 'Tagline', 'mody_cloud' ) ] = $own['tagline'];
			$rows[ __( 'Mission', 'mody_cloud' ) ] = $own['mission'];
		}
		if ( 'voice' === $section ) {
			$rows[ __( 'Tone of voice', 'mody_cloud' ) ] = BrandSettings::tone_labels()[ $own['tone'] ];
		}
		$brand  = BrandBook::get();
		$labels = BrandBook::labels();
		foreach ( BrandBook::FIELDS as $key => $field ) {
			if ( $section !== $field['section'] ) {
				continue;
			}
			$value = $brand[ $key ];
			if ( 'address_form' === $key ) {
				$value = self::address_forms()[ $value ] ?? '';
			}
			$rows[ $labels[ $key ]['label'] ] = $value;
		}
		?>
		<table class="mody-brand__rows">
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td><?php self::render_value( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><a class="button" href="<?php echo esc_url( self::url( $section, 'edit' ) ); ?>"><?php esc_html_e( 'Edit', 'mody_cloud' ); ?></a></p>
		<?php
	}

	/**
	 * One value: text (with its line breaks), words, or items.
	 *
	 * @param mixed $value The value.
	 */
	private static function render_value( $value ): void {
		if ( ! BrandBook::filled( $value ) ) {
			echo '<span class="mody-brand__empty">' . esc_html__( 'Not filled in yet', 'mody_cloud' ) . '</span>';
			return;
		}
		if ( ! is_array( $value ) ) {
			echo nl2br( esc_html( (string) $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped, then line breaks.
			return;
		}
		if ( is_array( $value[0] ?? null ) ) {
			echo '<ul class="mody-brand__items">';
			foreach ( $value as $item ) {
				echo '<li><strong>' . esc_html( $item['name'] ) . '</strong>' . ( '' !== $item['description'] ? ' ' . esc_html( $item['description'] ) : '' ) . '</li>';
			}
			echo '</ul>';
			return;
		}
		echo '<ul class="mody-brand__words">';
		foreach ( $value as $word ) {
			echo '<li>' . esc_html( (string) $word ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * The forms of "you", for the screens.
	 *
	 * @return array<string, string>
	 */
	private static function address_forms(): array {
		return array(
			''         => __( 'Not chosen: Mody follows the tone', 'mody_cloud' ),
			'formal'   => __( 'Formal (usted, Sie, vous)', 'mody_cloud' ),
			'informal' => __( 'Informal (tú, du, tu)', 'mody_cloud' ),
		);
	}

	/**
	 * A section's form.
	 *
	 * @param string $section A section.
	 */
	private static function render_form( string $section ): void {
		$own    = BrandSettings::brand();
		$brand  = BrandBook::get();
		$labels = BrandBook::labels();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mody-brand__form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="section" value="<?php echo esc_attr( $section ); ?>" />
			<?php wp_nonce_field( self::ACTION . '_' . $section ); ?>
			<?php
			if ( 'identity' === $section ) {
				self::text_field( 'mody-brand-name', 'blogname', __( 'Name', 'mody_cloud' ), $own['name'], 120, __( 'The site title.', 'mody_cloud' ), true );
				self::text_field( 'mody-brand-tagline', 'blogdescription', __( 'Tagline', 'mody_cloud' ), $own['tagline'], 200, __( 'A short line under the name.', 'mody_cloud' ) );
				self::textarea_field( 'mody-brand-mission', BrandSettings::MISSION, __( 'Mission', 'mody_cloud' ), $own['mission'], BrandSettings::MAX_MISSION, __( 'What the business does, in one sentence.', 'mody_cloud' ), 2 );
			}
			if ( 'voice' === $section ) {
				self::choice_field( BrandSettings::TONE, __( 'Tone of voice', 'mody_cloud' ), $own['tone'], BrandSettings::tone_labels(), __( 'How Mody writes for this site.', 'mody_cloud' ) );
			}
			foreach ( BrandBook::FIELDS as $key => $field ) {
				if ( $section !== $field['section'] ) {
					continue;
				}
				$id    = 'mody-brand-' . str_replace( '_', '-', $key );
				$name  = BrandBook::OPTION . '[' . $key . ']';
				$label = $labels[ $key ]['label'];
				$help  = $labels[ $key ]['help'];
				switch ( $field['type'] ) {
					case 'text':
						self::text_field( $id, $name, $label, $brand[ $key ], $field['max'], $help );
						break;
					case 'email':
						self::text_field( $id, $name, $label, $brand[ $key ], $field['max'], $help, false, 'email' );
						break;
					case 'textarea':
						self::textarea_field( $id, $name, $label, $brand[ $key ], $field['max'], $help, $field['max'] > 300 ? 4 : 3 );
						break;
					case 'enum':
						self::choice_field( $name, $label, $brand[ $key ], self::address_forms(), $help );
						break;
					case 'words':
						/* translators: %d: how many. */
						self::textarea_field( $id, $name, $label, implode( "\n", $brand[ $key ] ), 0, $help . ' ' . sprintf( __( 'One per line, up to %d.', 'mody_cloud' ), $field['items'] ), 4 );
						break;
					case 'items':
						self::items_field( $key, $name, $label, $brand[ $key ], $field, $help );
						break;
				}
			}
			submit_button( __( 'Save', 'mody_cloud' ) );
			?>
		</form>
		<?php
	}

	/**
	 * A one-line field.
	 *
	 * @param string $id       The input's ID.
	 * @param string $name     The input's name.
	 * @param string $label    Its label.
	 * @param string $value    Its value.
	 * @param int    $max      The longest value.
	 * @param string $help     Help under it.
	 * @param bool   $required Whether it may not be empty.
	 * @param string $type     The input's type.
	 */
	private static function text_field( string $id, string $name, string $label, string $value, int $max, string $help, bool $required = false, string $type = 'text' ): void {
		?>
		<div class="mody-brand__field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" maxlength="<?php echo esc_attr( (string) $max ); ?>" class="large-text" <?php echo $required ? 'required' : ''; ?> <?php echo '' !== $help ? 'aria-describedby="' . esc_attr( $id ) . '-help"' : ''; ?> />
			<?php if ( '' !== $help ) : ?>
				<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $help ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A field of a few lines.
	 *
	 * @param string $id    The textarea's ID.
	 * @param string $name  Its name.
	 * @param string $label Its label.
	 * @param string $value Its value.
	 * @param int    $max   The longest value, or 0.
	 * @param string $help  Help under it.
	 * @param int    $rows  Its height.
	 */
	private static function textarea_field( string $id, string $name, string $label, string $value, int $max, string $help, int $rows ): void {
		?>
		<div class="mody-brand__field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="<?php echo esc_attr( (string) $rows ); ?>" class="large-text" <?php echo $max ? 'maxlength="' . esc_attr( (string) $max ) . '"' : ''; ?> aria-describedby="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_textarea( $value ); ?></textarea>
			<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $help ); ?></p>
		</div>
		<?php
	}

	/**
	 * A choice of a few options, as radio buttons.
	 *
	 * @param string                $name    The inputs' name.
	 * @param string                $label   The legend.
	 * @param string                $value   The chosen option.
	 * @param array<string, string> $options Value => label.
	 * @param string                $help    Help under it.
	 */
	private static function choice_field( string $name, string $label, string $value, array $options, string $help ): void {
		?>
		<fieldset class="mody-brand__field">
			<legend><?php echo esc_html( $label ); ?></legend>
			<?php foreach ( $options as $option => $text ) : ?>
				<label class="mody-brand__choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $option ); ?>" <?php checked( $value, (string) $option ); ?> /> <?php echo esc_html( $text ); ?></label>
			<?php endforeach; ?>
			<p class="description"><?php echo esc_html( $help ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * A list of items, each a name and one line: the saved ones and one
	 * empty row. The script adds and removes rows.
	 *
	 * @param string                                          $key   The field.
	 * @param string                                          $name  The inputs' name.
	 * @param string                                          $label The legend.
	 * @param array<int, array{name: string, description: string}> $items The items.
	 * @param array<string, mixed>                            $field The field's limits.
	 * @param string                                          $help  Help under it.
	 */
	private static function items_field( string $key, string $name, string $label, array $items, array $field, string $help ): void {
		$rows = $items;
		if ( count( $rows ) < $field['items'] ) {
			$rows[] = array(
				'name'        => '',
				'description' => '',
			);
		}
		$row = static function ( int $index, array $item ) use ( $name, $field ): void {
			?>
			<li class="mody-brand__item">
				<label><span><?php esc_html_e( 'Name', 'mody_cloud' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $name . '[' . $index . '][name]' ); ?>" value="<?php echo esc_attr( $item['name'] ); ?>" maxlength="<?php echo esc_attr( (string) $field['name'] ); ?>" /></label>
				<label><span><?php esc_html_e( 'About it, in one line', 'mody_cloud' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $name . '[' . $index . '][description]' ); ?>" value="<?php echo esc_attr( $item['description'] ); ?>" maxlength="<?php echo esc_attr( (string) $field['max'] ); ?>" /></label>
				<button type="button" class="button-link mody-brand__remove" hidden><?php esc_html_e( 'Remove', 'mody_cloud' ); ?></button>
			</li>
			<?php
		};
		?>
		<fieldset class="mody-brand__field mody-brand__list" data-max="<?php echo esc_attr( (string) $field['items'] ); ?>" data-name="<?php echo esc_attr( $name ); ?>">
			<legend><?php echo esc_html( $label ); ?></legend>
			<p class="description"><?php echo esc_html( $help ); ?>
				<?php
				/* translators: %d: how many. */
				echo esc_html( sprintf( __( 'Up to %d.', 'mody_cloud' ), $field['items'] ) );
				?>
			</p>
			<ol class="mody-brand__items-edit" id="<?php echo esc_attr( 'mody-brand-' . $key ); ?>">
				<?php
				foreach ( $rows as $index => $item ) {
					$row( $index, $item );
				}
				?>
			</ol>
			<template><?php $row( 999, array( 'name' => '', 'description' => '' ) ); ?></template>
			<button type="button" class="button mody-brand__add" hidden><?php esc_html_e( 'Add another', 'mody_cloud' ); ?></button>
		</fieldset>
		<?php
	}

	/**
	 * The Visual style section: the site's palette, fonts and gradients,
	 * read from its Global Styles, and where to change them.
	 */
	private static function render_visual(): void {
		$palette   = SiteDesign::palette();
		$families  = array();
		$settings  = (array) wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		foreach ( $settings as $origin => $list ) {
			foreach ( 'default' === $origin ? array() : (array) $list as $font ) {
				if ( is_array( $font ) && ! empty( $font['slug'] ) ) {
					$families[ (string) $font['slug'] ] = (string) ( $font['name'] ?? $font['slug'] );
				}
			}
		}
		$fonts = array();
		foreach ( array(
			__( 'Text', 'mody_cloud' )     => array( 'typography', 'fontFamily' ),
			__( 'Headings', 'mody_cloud' ) => array( 'elements', 'heading', 'typography', 'fontFamily' ),
		) as $label => $path ) {
			$value = wp_get_global_styles( $path );
			$slug  = is_string( $value ) ? LoginPage::font_slug( $value ) : '';
			if ( '' !== $slug ) {
				$fonts[ $label ] = $families[ $slug ] ?? $slug;
			}
		}
		$gradients = array();
		foreach ( (array) wp_get_global_settings( array( 'color', 'gradients' ) ) as $origin => $list ) {
			foreach ( 'default' === $origin ? array() : (array) $list as $gradient ) {
				if ( is_array( $gradient ) && ! empty( $gradient['slug'] ) ) {
					$gradients[ (string) $gradient['slug'] ] = (string) ( $gradient['name'] ?? $gradient['slug'] );
				}
			}
		}
		$can = current_user_can( 'edit_theme_options' );
		?>
		<p><?php esc_html_e( 'The colors, fonts and gradients are the site\'s styles. Mody uses them on every page it writes. Change them in the Site Editor, or ask Mody.', 'mody_cloud' ); ?></p>
		<table class="mody-brand__rows">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Colors', 'mody_cloud' ); ?></th>
					<td>
						<ul class="mody-brand__swatches">
							<?php foreach ( $palette as $slug => $hex ) : ?>
								<li><span class="mody-brand__swatch" style="background:<?php echo esc_attr( $hex ); ?>"></span><?php echo esc_html( ucfirst( $slug ) ); ?> <code><?php echo esc_html( $hex ); ?></code></li>
							<?php endforeach; ?>
						</ul>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fonts', 'mody_cloud' ); ?></th>
					<td>
						<?php if ( $fonts ) : ?>
							<ul class="mody-brand__words">
								<?php foreach ( $fonts as $label => $name ) : ?>
									<li><?php echo esc_html( $label . ': ' . $name ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<span class="mody-brand__empty"><?php esc_html_e( 'The theme\'s fonts', 'mody_cloud' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Gradients', 'mody_cloud' ); ?></th>
					<td>
						<?php if ( $gradients ) : ?>
							<ul class="mody-brand__swatches">
								<?php foreach ( $gradients as $slug => $name ) : ?>
									<li><span class="mody-brand__swatch is-gradient" style="background:var(--wp--preset--gradient--<?php echo esc_attr( $slug ); ?>)"></span><?php echo esc_html( $name ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<span class="mody-brand__empty"><?php esc_html_e( 'None', 'mody_cloud' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php if ( $can ) : ?>
			<p class="mody-brand__links">
				<a class="button" href="<?php echo esc_url( SiteEditor::styles_screen( 'colors' )['url'] ); ?>"><?php esc_html_e( 'Change the colors', 'mody_cloud' ); ?></a>
				<a class="button" href="<?php echo esc_url( SiteEditor::styles_screen( 'typography' )['url'] ); ?>"><?php esc_html_e( 'Change the fonts', 'mody_cloud' ); ?></a>
				<a class="button" href="<?php echo esc_url( SiteEditor::url( '/identity' ) ); ?>"><?php esc_html_e( 'Change the logo and icon', 'mody_cloud' ); ?></a>
			</p>
			<?php
		endif;
	}

	/**
	 * `admin_post_mody_brand_save`: saves one section, then back to its overview.
	 */
	public static function save(): void {
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		if ( ! current_user_can( self::CAPABILITY ) || ! in_array( $section, BrandBook::editable_sections(), true ) ) {
			wp_die( esc_html__( 'You cannot change this site\'s brand.', 'mody_cloud' ), 403 );
		}
		check_admin_referer( self::ACTION . '_' . $section );
		$error = self::save_section( $section, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized in save_section().
		wp_safe_redirect( self::url( $section, 'overview', '' !== $error ? array( 'brand-error' => $error ) : array( 'saved' => 1 ) ) );
		exit;
	}

	/**
	 * Saves one section from a posted form: that section's values only.
	 *
	 * @param string               $section An editable section.
	 * @param array<string, mixed> $posted  The form, unslashed.
	 * @return string An error's key ('name'), or ''.
	 */
	public static function save_section( string $section, array $posted ): string {
		$error = '';
		if ( 'identity' === $section ) {
			$name = sanitize_text_field( is_string( $posted['blogname'] ?? null ) ? $posted['blogname'] : '' );
			if ( '' === $name ) {
				$error = 'name';
			} else {
				update_option( 'blogname', mb_substr( $name, 0, 120 ) );
			}
			update_option( 'blogdescription', mb_substr( sanitize_text_field( is_string( $posted['blogdescription'] ?? null ) ? $posted['blogdescription'] : '' ), 0, 200 ) );
			update_option( BrandSettings::MISSION, BrandSettings::sanitize_mission( is_string( $posted[ BrandSettings::MISSION ] ?? null ) ? str_replace( array( "\r", "\n" ), ' ', $posted[ BrandSettings::MISSION ] ) : '' ) );
		}
		if ( 'voice' === $section && isset( $posted[ BrandSettings::TONE ] ) ) {
			update_option( BrandSettings::TONE, BrandSettings::sanitize_tone( $posted[ BrandSettings::TONE ] ) );
		}
		$values  = is_array( $posted[ BrandBook::OPTION ] ?? null ) ? $posted[ BrandBook::OPTION ] : array();
		$changes = array();
		foreach ( BrandBook::FIELDS as $key => $field ) {
			if ( $section === $field['section'] ) {
				$changes[ $key ] = $values[ $key ] ?? null; // A list with every row removed is not posted: it is emptied.
			}
		}
		BrandBook::update( $changes );
		return $error;
	}
}
