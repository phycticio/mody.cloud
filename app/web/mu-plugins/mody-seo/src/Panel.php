<?php
/**
 * The "Search engines" panel in the editor's document sidebar.
 *
 * @package Mody\Seo
 */

namespace Mody\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Loads assets/seo-panel.js (plain JS, no build step) in the post editor
 * and the Site Editor. The panel shows for pages and posts, never for a
 * template or a part. Its two fields are saved with the page, when the
 * owner saves.
 *
 * In the post editor, the `ai` plugin's own Meta Description panel is
 * removed: it writes a field of its own, which is not printed.
 */
final class Panel {

	/** The script's handle. */
	public const HANDLE = 'mody-seo-panel';

	/** The `ai` plugin's panel (its Asset_Loader prefixes `ai_`). */
	private const AI_PANEL = 'ai_meta_description';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'drop_ai_panel' ), 99 );
	}

	/**
	 * `enqueue_block_editor_assets`: the panel, in the post editor and the
	 * Site Editor (not the widgets editor).
	 */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'site-editor' ), true ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/assets/seo-panel.js';
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/seo-panel.js', dirname( __DIR__ ) . '/mody-seo.php' ),
			array( 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			(string) filemtime( $file ),
			true
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.modySeo = ' . wp_json_encode(
				array(
					'postTypes'      => Fields::post_types(),
					'titleKey'       => Fields::TITLE,
					'descriptionKey' => Fields::DESCRIPTION,
					'titleMax'       => Fields::TITLE_MAX,
					'descriptionMax' => Fields::DESCRIPTION_MAX,
					'site'           => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
					'tagline'        => html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ),
					'frontPage'      => 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0,
				)
			) . ';',
			'before'
		);
		wp_set_script_translations( self::HANDLE, 'mody_cloud' );
	}

	/**
	 * `admin_enqueue_scripts`, late: the `ai` plugin's Meta Description panel
	 * goes, so the sidebar has one description.
	 */
	public static function drop_ai_panel(): void {
		wp_dequeue_script( self::AI_PANEL );
		wp_dequeue_style( self::AI_PANEL );
	}
}
