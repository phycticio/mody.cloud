<?php
/**
 * Color pickers offer only palette colors (M1-04 part A).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Design;

defined( 'ABSPATH' ) || exit;

/**
 * On every site, color pickers show only the palette: the theme's five colors
 * and the site's own (Styles → Colors → Palette → Custom). Decided by the
 * maintainer on 2026-11-09.
 *
 * - **theme.json does it** for every block that uses WordPress's color
 *   controls, Spectra's color fields included: `settings.color.custom` and
 *   `customGradient` are off, and the gradients are presets built from the
 *   palette.
 * - **Spectra draws some pickers itself** (spectra-blocks 1.0.13), whatever
 *   the settings say. This style hides their free part in the editors; their
 *   palette stays.
 *   - The Advanced Gradient's Color 1 and Color 2 (container, slider and
 *     slider-child): a free picker above the palette.
 *   - Five color lists with a custom color button: the container's shadow
 *     and border hover colors, the button's border hover and shadow colors,
 *     and the content block's text shadow color.
 * - **The selectors match nothing else that should stay:**
 *   - core never puts a picker directly in that panel;
 *   - core's color lists show the custom color button only when the settings
 *     allow it, except the shadow editor (Styles → Shadows), which has no
 *     palette and keeps it;
 *   - the palette editor, where people add colors, uses another class.
 * - `scripts/spectra-guard-test.php` checks Spectra's markup after every update.
 */
final class PaletteOnly {

	/** The style's handle. */
	public const HANDLE = 'mody-palette-only';

	/** Hides the free pickers that ignore the settings (see the class comment). */
	public const CSS = '.block-editor-color-gradient-control__panel > .components-color-picker, .components-color-palette__custom-color-wrapper:not(.global-styles-ui__shadow-editor-panel *) { display: none; }';

	/**
	 * Adds the style to the editors (post, site and widgets). Hooked to
	 * `enqueue_block_editor_assets`: the pickers open outside the canvas.
	 */
	public static function enqueue(): void {
		wp_register_style( self::HANDLE, false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline only, no file.
		wp_add_inline_style( self::HANDLE, self::CSS );
		wp_enqueue_style( self::HANDLE );
	}
}
