<?php
/**
 * The wizard's live preview: the site's header and a hero, rendered by the theme.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Wizard;

use Mody\Platform\Design\Presets;
use Mody\Platform\Provisioning\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * A page on the main site, shown in an iframe in step 2 of the wizard
 * (mvp-spec section 6, ticket M2-04): the theme's header part and a hero
 * with the brand name, the "what do you do?" sentence and a button, in the
 * chosen preset's style.
 *
 * The URL is `/?mody_wizard_preview=1&brand=…&mission=…&preset=…` on the
 * main site. The preset is one of the theme's style variations (M2-06): for
 * the preview, it replaces the main site's own Global Styles, so the page
 * shows what the new site will hold (palette, typography, spacing, element
 * styles). The wizard reloads it when another preset is chosen, and adds the
 * logo itself (same origin, M2-05). Nothing is saved. Only users who may use
 * the wizard see it.
 *
 * What differs from the new site: the navigation shows three fixed links
 * (the main site's pages would be wrong).
 */
final class WizardPreview {

	/** The query variable that asks for the preview. */
	public const QUERY_VAR = 'mody_wizard_preview';

	/**
	 * The preview's address, without the brand and preset.
	 */
	public static function url(): string {
		return add_query_arg( self::QUERY_VAR, '1', get_home_url( get_main_site_id(), '/' ) );
	}

	/**
	 * Hooked to `template_redirect`: answers a preview request and stops.
	 */
	public static function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only page, nothing saved.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || ! is_main_site() ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( WizardPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You may not see this preview.', 'mody_cloud' ), '', array( 'response' => 403 ) );
		}

		$brand   = sanitize_text_field( wp_unslash( (string) ( $_GET['brand'] ?? '' ) ) );
		$mission = sanitize_text_field( wp_unslash( (string) ( $_GET['mission'] ?? '' ) ) );
		$preset  = sanitize_key( wp_unslash( (string) ( $_GET['preset'] ?? '' ) ) );
		// phpcs:enable

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		echo self::html( $brand, $mission, $preset ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered blocks; every value escaped in html().
		exit;
	}

	/**
	 * The preview page.
	 *
	 * @param string $brand   Brand name (plain text).
	 * @param string $mission The "what do you do?" sentence (plain text).
	 * @param string $preset  Preset slug; unknown ones fall back to the default.
	 */
	public static function html( string $brand, string $mission, string $preset ): string {
		$brand   = mb_substr( '' !== $brand ? $brand : __( 'Your brand', 'mody_cloud' ), 0, Pipeline::MAX_NAME_LENGTH );
		$mission = mb_substr( '' !== $mission ? $mission : __( 'What you do, in one sentence.', 'mody_cloud' ), 0, Pipeline::MAX_MISSION_LENGTH );
		$style   = Presets::variation( $preset ) ?? Presets::variation( Presets::DEFAULT );

		$text    = static fn(): string => $brand;
		$tagline = static fn(): string => $mission;
		$none    = static fn(): int => 0;
		$nav     = array( self::class, 'navigation' );
		add_filter( 'pre_option_blogname', $text );
		add_filter( 'pre_option_blogdescription', $tagline );
		add_filter( 'pre_option_site_logo', $none );
		add_filter( 'pre_render_block', $nav, 10, 2 );
		add_filter( 'show_admin_bar', '__return_false' );

		// The chosen style in place of the main site's own Global Styles: what the new site will hold (M2-06).
		$styles = static fn(): \WP_Theme_JSON_Data => new \WP_Theme_JSON_Data(
			array(
				'version'                     => \WP_Theme_JSON::LATEST_SCHEMA,
				'isGlobalStylesUserThemeJSON' => true,
			) + array_filter( $style ),
			'custom'
		);
		add_filter( 'wp_theme_json_data_user', $styles );
		wp_clean_theme_json_cache();

		// Rendered before wp_head(), as block templates are, so their styles are enqueued.
		$blocks = do_blocks( '<!-- wp:template-part {"slug":"header","theme":"' . Pipeline::THEME . '","area":"header","tagName":"header"} /-->' )
			. do_blocks( self::hero( $brand, $mission ) );

		$css = 'html,body{overflow:hidden}';

		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="robots" content="noindex, nofollow">
<?php wp_head(); ?>
<style id="mody-preview"><?php echo esc_html( $css ); ?></style>
</head>
<body <?php body_class( 'mody-wizard-preview' ); ?>>
<div class="wp-site-blocks"><?php echo $blocks; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered blocks. ?></div>
<?php wp_footer(); ?>
</body>
</html>
		<?php
		$html = (string) ob_get_clean();

		remove_filter( 'pre_option_blogname', $text );
		remove_filter( 'pre_option_blogdescription', $tagline );
		remove_filter( 'pre_option_site_logo', $none );
		remove_filter( 'pre_render_block', $nav, 10 );
		remove_filter( 'show_admin_bar', '__return_false' );
		remove_filter( 'wp_theme_json_data_user', $styles );
		wp_clean_theme_json_cache();
		return $html;
	}

	/**
	 * The hero: the brand name, the sentence and a button, as generated pages start.
	 *
	 * @param string $brand   Brand name.
	 * @param string $mission The sentence.
	 */
	private static function hero( string $brand, string $mission ): string {
		return '<!-- wp:group {"backgroundColor":"primary","textColor":"base","layout":{"type":"constrained"}} -->'
			. '<div class="wp-block-group has-base-color has-primary-background-color has-text-color has-background">'
			. '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">' . esc_html( $brand ) . '</h1><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>' . esc_html( $mission ) . '</p><!-- /wp:paragraph -->'
			. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"accent","textColor":"base"} -->'
			. '<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-accent-background-color has-text-color has-background wp-element-button">' . esc_html__( 'Get in touch', 'mody_cloud' ) . '</a></div>'
			. '<!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->'
			. '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
			. '<!-- wp:heading {"textColor":"primary"} --><h2 class="wp-block-heading has-primary-color has-text-color">' . esc_html__( 'What we do', 'mody_cloud' ) . '</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph {"textColor":"secondary"} --><p class="has-secondary-color has-text-color">' . esc_html__( 'Your first page goes here, written in your brand\'s voice.', 'mody_cloud' ) . '</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
	}

	/**
	 * `pre_render_block` during the preview: the header's navigation gets
	 * three fixed links instead of the main site's pages.
	 *
	 * @param string|null          $content The content, or null to render normally.
	 * @param array<string, mixed> $block   The parsed block.
	 * @return string|null
	 */
	public static function navigation( $content, array $block ) {
		if ( 'core/navigation' !== ( $block['blockName'] ?? '' ) || ! empty( $block['attrs']['mody_preview'] ) ) {
			return $content;
		}
		$links = array();
		foreach ( array( __( 'Home', 'mody_cloud' ), __( 'About', 'mody_cloud' ), __( 'Contact', 'mody_cloud' ) ) as $label ) {
			$links[] = array(
				'blockName'    => 'core/navigation-link',
				'attrs'        => array(
					'label' => $label,
					'url'   => '#',
					'kind'  => 'custom',
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		}
		$block['attrs']['mody_preview'] = true;
		unset( $block['attrs']['ref'] );
		$block['innerBlocks']  = $links;
		$block['innerContent'] = array_fill( 0, count( $links ), null );
		return render_block( $block );
	}
}
