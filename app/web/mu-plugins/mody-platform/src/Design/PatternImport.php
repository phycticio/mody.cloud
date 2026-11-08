<?php
/**
 * Makes WordPress.org patterns fit a Mody site (the design market, ticket
 * design-market-themes-patterns.md). Used by scripts/import-patterns.php.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Design;

use WP_HTML_Tag_Processor;
use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites a pattern's blocks so that it loads nothing from outside the
 * platform (rule 1) and follows the site's design (rule 4, palette slugs):
 *
 * - **Images** become the platform's own placeholders
 *   (`/mody-placeholder/<w>x<h>.svg`, served by Patterns), at the image's
 *   size. Attachment IDs of the source site, `srcset` and `sizes` go.
 * - **Colors** become the theme's palette slugs, so a pattern follows each
 *   site's preset: core's slugs (`black`, `white`…) and custom hex values
 *   map to the nearest of primary, secondary, accent, base and contrast.
 *   Duotone filters go (they only tinted the photos).
 * - **Fonts:** families the theme does not have go; font sizes map to the
 *   theme's.
 * - **Links** to other sites become `#`.
 *
 * Attributes and the saved HTML change together, so the editor still
 * recognizes each block as valid. Patterns with gradients are refused: a
 * gradient has no palette slug here (the theme defines none).
 */
final class PatternImport {

	/** Where the placeholders are served on every site (Patterns::serve_placeholder()). */
	public const PLACEHOLDER_PATH = '/mody-placeholder/';

	/** Core's default palette (wp-includes/theme.json), for mapping its slugs. */
	private const CORE_SLUGS = array(
		'black'                 => '#000000',
		'white'                 => '#ffffff',
		'cyan-bluish-gray'      => '#abb8c3',
		'pale-pink'             => '#f78da7',
		'vivid-red'             => '#cf2e2e',
		'luminous-vivid-orange' => '#ff6900',
		'luminous-vivid-amber'  => '#fcb900',
		'light-green-cyan'      => '#7bdcb5',
		'vivid-green-cyan'      => '#00d084',
		'pale-cyan-blue'        => '#8ed1fc',
		'vivid-cyan-blue'       => '#0693e3',
		'vivid-purple'          => '#9b51e0',
	);

	/** Font sizes of other themes, mapped to this theme's. */
	private const FONT_SIZES = array(
		'extra-small' => 'small',
		'x-small'     => 'small',
		'normal'      => 'medium',
		'huge'        => 'xx-large',
		'larger'      => 'x-large',
	);

	/** Default placeholder sizes per block, when the block gives none. */
	private const SIZES = array(
		'core/cover'      => array( 1600, 900 ),
		'core/media-text' => array( 1000, 1000 ),
		'default'         => array( 1200, 800 ),
	);

	/**
	 * What the import changed, for its report: counters by kind.
	 *
	 * @var array<string, int>
	 */
	public static array $changes = array();

	/**
	 * The theme's palette: slug => lowercase hex.
	 *
	 * @return array<string, string>
	 */
	public static function palette(): array {
		$palette = WP_Theme_JSON_Resolver::get_theme_data()->get_settings()['color']['palette']['theme'] ?? array();
		$colors  = array();
		foreach ( $palette as $color ) {
			$colors[ (string) $color['slug'] ] = strtolower( (string) $color['color'] );
		}
		return $colors;
	}

	/**
	 * The palette slug closest to a color (weighted RGB distance).
	 *
	 * @param string $hex A hex color, three or six digits.
	 */
	public static function nearest( string $hex ): string {
		$rgb  = self::rgb( $hex );
		$best = 'contrast';
		$min  = PHP_FLOAT_MAX;
		foreach ( self::palette() as $slug => $color ) {
			$other = self::rgb( $color );
			$mean  = ( $rgb[0] + $other[0] ) / 2;
			$dist  = ( 2 + $mean / 256 ) * ( $rgb[0] - $other[0] ) ** 2 + 4 * ( $rgb[1] - $other[1] ) ** 2 + ( 2 + ( 255 - $mean ) / 256 ) * ( $rgb[2] - $other[2] ) ** 2;
			if ( $dist < $min ) {
				$min  = $dist;
				$best = $slug;
			}
		}
		return $best;
	}

	/**
	 * Rewrites a pattern.
	 *
	 * @param string $content Serialized blocks.
	 * @return array{content: string, refused: string} `refused` says why the pattern cannot be used, or ''.
	 */
	public static function transform( string $content ): array {
		$refused = '';
		$blocks  = array_map(
			static function ( array $block ) use ( &$refused ): array {
				return self::block( $block, $refused );
			},
			parse_blocks( $content )
		);
		$out     = serialize_blocks( $blocks );
		if ( '' === $refused && preg_match( '#https?://#i', $out ) ) {
			$refused = 'a link to another site is left';
		}
		if ( '' === $refused && preg_match( '/#[0-9a-f]{3,8}\b|rgba?\(/i', $out ) ) {
			$refused = 'a custom color is left';
		}
		return array(
			'content' => $out,
			'refused' => $refused,
		);
	}

	/**
	 * One block and its inner blocks.
	 *
	 * @param array<string, mixed> $block   A parsed block.
	 * @param string               $refused Set to the reason when the pattern cannot be used.
	 * @return array<string, mixed>
	 */
	private static function block( array $block, string &$refused ): array {
		$name  = (string) $block['blockName'];
		$attrs = (array) $block['attrs'];
		$edits = array(); // Callables run on each piece of the block's own HTML.

		if ( isset( $attrs['gradient'] ) || isset( $attrs['customGradient'] ) || isset( $attrs['style']['color']['gradient'] ) ) {
			$refused = 'a gradient';
		}
		if ( isset( $attrs['style']['color']['duotone'] ) ) {
			unset( $attrs['style']['color']['duotone'] );
			self::count( 'duotone removed' );
		}

		// The separator's older color attribute, and its color class (it also has has-<slug>-color).
		if ( 'core/separator' === $name ) {
			$hex = (string) ( $attrs['customColor'] ?? $attrs['color'] ?? $attrs['style']['color']['background'] ?? '' );
			if ( '' !== $hex || isset( $attrs['backgroundColor'] ) ) {
				$slug = '' !== $hex && preg_match( '/^#[0-9a-f]{3,8}$/i', $hex ) ? self::nearest( $hex ) : self::slug_or_map( (string) ( $attrs['backgroundColor'] ?? 'contrast' ) );
				unset( $attrs['customColor'], $attrs['color'] );
				self::unset_path( $attrs, array( 'style', 'color', 'background' ) );
				$attrs['backgroundColor'] = $slug;
				$edits[]                  = static fn( string $html ): string => self::separator_classes( $html, $slug );
				self::count( 'separator color mapped' );
			}
		}

		// Social icons: the colors are rendered from the …Value attributes, which may hold a CSS variable.
		if ( 'core/social-links' === $name ) {
			foreach ( array( 'iconColor', 'iconBackgroundColor' ) as $key ) {
				$custom = 'custom' . ucfirst( $key );
				$source = isset( $attrs[ $custom ] ) && is_string( $attrs[ $custom ] ) ? self::nearest( $attrs[ $custom ] ) : ( isset( $attrs[ $key ] ) ? self::slug_or_map( (string) $attrs[ $key ] ) : '' );
				if ( '' === $source ) {
					continue;
				}
				unset( $attrs[ $custom ] );
				$attrs[ $key ]           = $source;
				$attrs[ $key . 'Value' ] = 'var(--wp--preset--color--' . $source . ')';
				self::count( 'icon color mapped' );
			}
		}

		// Palette slugs of other themes.
		$suffixes = array(
			'textColor'       => '-color',
			'backgroundColor' => '-background-color',
			'borderColor'     => '-border-color',
			'overlayColor'    => '-background-color',
		);
		foreach ( $suffixes as $key => $suffix ) {
			if ( isset( $attrs[ $key ] ) && ! isset( self::palette()[ $attrs[ $key ] ] ) && ! ( 'core/separator' === $name && 'backgroundColor' === $key ) ) {
				$old           = (string) $attrs[ $key ];
				$new           = self::map_slug( $old );
				$attrs[ $key ] = $new;
				$edits[]       = static fn( string $html ): string => self::rename_class( $html, 'has-' . $old . $suffix, 'has-' . $new . $suffix );
				self::count( 'color slug mapped' );
			}
		}

		// Custom colors.
		$custom = array(
			array( array( 'style', 'color', 'background' ), 'background-color', 'backgroundColor', '-background-color' ),
			array( array( 'style', 'color', 'text' ), 'color', 'textColor', '-color' ),
			array( array( 'customOverlayColor' ), 'background-color', 'overlayColor', '-background-color' ),
		);
		foreach ( $custom as list( $path, $property, $key, $suffix ) ) {
			$value = self::get( $attrs, $path );
			if ( ! is_string( $value ) ) {
				continue;
			}
			$css = $value; // As the saved HTML has it.
			if ( str_starts_with( $value, 'var:preset|color|' ) ) {
				$css   = 'var(--wp--preset--color--' . substr( $value, 17 ) . ')';
				$value = isset( self::palette()[ substr( $value, 17 ) ] ) ? '' : ( self::CORE_SLUGS[ substr( $value, 17 ) ] ?? '#000000' );
			}
			if ( ! preg_match( '/^#[0-9a-f]{3,8}$/i', $value ) ) {
				continue;
			}
			$slug = self::nearest( $value );
			self::unset_path( $attrs, $path );
			$attrs[ $key ] = $slug;
			$edits[]       = static fn( string $html ): string => self::style_to_class( $html, $property, $css, 'has-' . $slug . $suffix );
			self::count( 'custom color mapped' );
		}

		// Preset references in styles (links, borders): hex or other themes' slugs.
		if ( isset( $attrs['style'] ) && is_array( $attrs['style'] ) ) {
			array_walk_recursive(
				$attrs['style'],
				static function ( &$value, $key ) use ( &$edits ): void {
					if ( ! is_string( $value ) || ! in_array( $key, array( 'color', 'text', 'background', 'link' ), true ) ) {
						return;
					}
					$hex = preg_match( '/^#[0-9a-f]{3,8}$/i', $value ) ? $value : ( str_starts_with( $value, 'var:preset|color|' ) && ! isset( self::palette()[ substr( $value, 17 ) ] ) ? ( self::CORE_SLUGS[ substr( $value, 17 ) ] ?? '#000000' ) : '' );
					if ( '' === $hex ) {
						return;
					}
					$old     = $value;
					$slug    = self::nearest( $hex );
					$value   = 'var:preset|color|' . $slug;
					$css_old = str_starts_with( $old, '#' ) ? $old : 'var(--wp--preset--color--' . substr( $old, 17 ) . ')';
					$edits[] = static fn( string $html ): string => str_ireplace( $css_old, 'var(--wp--preset--color--' . $slug . ')', $html );
					self::count( 'style color mapped' );
				}
			);
		}

		// Color classes an author typed into "Additional CSS classes", for slugs this palette lacks.
		if ( isset( $attrs['className'] ) && is_string( $attrs['className'] ) ) {
			$kept = array();
			foreach ( preg_split( '/\s+/', trim( $attrs['className'] ) ) as $class ) {
				if ( preg_match( '/^has-([a-z0-9-]+?)-(?:background-|border-)?color$/', $class, $match ) && ! isset( self::palette()[ $match[1] ] ) && ! in_array( $match[1], array( 'text', 'link', 'icon', 'icon-background' ), true ) ) {
					$edits[] = static fn( string $html ): string => self::rename_class( $html, $class, '' );
					self::count( 'stray color class removed' );
					continue;
				}
				$kept[] = $class;
			}
			if ( $kept ) {
				$attrs['className'] = implode( ' ', $kept );
			} else {
				unset( $attrs['className'] );
			}
		}

		// Fonts.
		if ( isset( $attrs['fontFamily'] ) && ! in_array( $attrs['fontFamily'], self::font_families(), true ) ) {
			$old = (string) $attrs['fontFamily'];
			unset( $attrs['fontFamily'] );
			$edits[] = static fn( string $html ): string => self::rename_class( $html, 'has-' . $old . '-font-family', '' );
			self::count( 'font family removed' );
		}
		if ( isset( $attrs['fontSize'] ) && ! in_array( $attrs['fontSize'], self::font_sizes(), true ) ) {
			$old               = (string) $attrs['fontSize'];
			$attrs['fontSize'] = self::FONT_SIZES[ $old ] ?? 'medium';
			$new               = $attrs['fontSize'];
			$edits[]           = static fn( string $html ): string => self::rename_class( $html, 'has-' . $old . '-font-size', 'has-' . $new . '-font-size' );
			self::count( 'font size mapped' );
		}

		// Images.
		$size        = self::size( $name, $attrs );
		$placeholder = self::PLACEHOLDER_PATH . $size[0] . 'x' . $size[1] . '.svg';
		foreach ( array( 'id', 'mediaId', 'mediaLink', 'ids' ) as $key ) {
			unset( $attrs[ $key ] );
		}
		if ( isset( $attrs['url'] ) && is_string( $attrs['url'] ) && preg_match( '#^https?://#i', $attrs['url'] ) ) {
			$attrs['url'] = 'core/cover' === $name ? $placeholder : '#';
		}
		$edits[] = static fn( string $html ): string => self::images( $html, $placeholder, 'core/media-text' === $name );

		$block['attrs']        = $attrs;
		$block['innerContent'] = array_map(
			static function ( $piece ) use ( $edits ) {
				if ( ! is_string( $piece ) ) {
					return $piece;
				}
				foreach ( $edits as $edit ) {
					$piece = $edit( $piece );
				}
				return $piece;
			},
			$block['innerContent']
		);
		$block['innerHTML']    = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
		$block['innerBlocks']  = array_map(
			static function ( array $inner ) use ( &$refused ): array {
				return self::block( $inner, $refused );
			},
			$block['innerBlocks']
		);
		return $block;
	}

	/**
	 * Images, backgrounds and links in a piece of a block's HTML.
	 *
	 * @param string $html        HTML.
	 * @param string $placeholder The placeholder for this block.
	 * @param bool   $media_text  A Media & Text block: its image's `size-*` class
	 *                            comes with the attachment ID, so it goes too.
	 */
	private static function images( string $html, string $placeholder, bool $media_text = false ): string {
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$src = $tags->get_attribute( 'src' );
			if ( is_string( $src ) && 'IMG' === $tags->get_tag() ) {
				$tags->set_attribute( 'src', $placeholder );
				$tags->remove_attribute( 'srcset' );
				$tags->remove_attribute( 'sizes' );
				foreach ( $media_text ? iterator_to_array( $tags->class_list(), false ) : array() as $class ) {
					if ( str_starts_with( $class, 'size-' ) ) {
						$tags->remove_class( $class );
					}
				}
				if ( '' === trim( (string) $tags->get_attribute( 'class' ) ) ) {
					$tags->remove_attribute( 'class' );
				}
				self::count( 'image replaced' );
			}
			$class = $tags->get_attribute( 'class' );
			if ( is_string( $class ) && preg_match( '/\bwp-image-\d+\b/', $class, $match ) ) {
				$tags->remove_class( $match[0] );
			}
			$style = $tags->get_attribute( 'style' );
			if ( is_string( $style ) && preg_match( '#url\(\s*[\'"]?https?://#i', $style ) ) {
				$tags->set_attribute( 'style', (string) preg_replace( '#url\(\s*[\'"]?https?://[^)\'"]+[\'"]?\s*\)#i', 'url(' . $placeholder . ')', $style ) );
				self::count( 'background image replaced' );
			}
			// Text colors inside rich text (the text-color format): palette classes.
			if ( is_string( $style ) && in_array( $tags->get_tag(), array( 'MARK', 'SPAN' ), true ) ) {
				$style = (string) $tags->get_attribute( 'style' );
				$style = (string) preg_replace( '/rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/i', 'transparent', $style );
				if ( preg_match( '/(?<![-\w])color\s*:\s*(#[0-9a-f]{3,8})\s*;?/i', $style, $color ) ) {
					$style = trim( (string) str_replace( $color[0], '', $style ) );
					$tags->add_class( 'has-' . self::nearest( $color[1] ) . '-color' );
					self::count( 'inline text color mapped' );
				}
				'' === $style ? $tags->remove_attribute( 'style' ) : $tags->set_attribute( 'style', $style );
			}
			$href = $tags->get_attribute( 'href' );
			if ( is_string( $href ) && preg_match( '#^https?://#i', $href ) ) {
				$tags->set_attribute( 'href', '#' );
				self::count( 'link replaced' );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * Moves a custom color from an inline style to a palette class, on the
	 * element that carries it.
	 *
	 * @param string $html     HTML.
	 * @param string $property `color` or `background-color`.
	 * @param string $value    The hex value.
	 * @param string $class    The class to add.
	 */
	private static function style_to_class( string $html, string $property, string $value, string $class ): string {
		$tags    = new WP_HTML_Tag_Processor( $html );
		$pattern = '/(?<![-\w])' . preg_quote( $property, '/' ) . '\s*:\s*' . preg_quote( $value, '/' ) . '\s*;?/i';
		while ( $tags->next_tag() ) {
			$style = $tags->get_attribute( 'style' );
			if ( ! is_string( $style ) || ! preg_match( $pattern, $style ) ) {
				continue;
			}
			$style = trim( (string) preg_replace( $pattern, '', $style ) );
			'' === $style ? $tags->remove_attribute( 'style' ) : $tags->set_attribute( 'style', $style );
			$tags->add_class( $class );
		}
		return $tags->get_updated_html();
	}

	/**
	 * Renames (or removes, with '') a class on every element that has it.
	 *
	 * @param string $html HTML.
	 * @param string $old  Class to find.
	 * @param string $new  Its replacement, or ''.
	 */
	private static function rename_class( string $html, string $old, string $new ): string {
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( $tags->has_class( $old ) ) {
				$tags->remove_class( $old );
				if ( '' !== $new ) {
					$tags->add_class( $new );
				}
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * The placeholder size for a block: its width and height, or a default.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return array{0: int, 1: int}
	 */
	private static function size( string $name, array $attrs ): array {
		$default = self::SIZES[ $name ] ?? self::SIZES['default'];
		$width   = (int) ( $attrs['width'] ?? 0 );
		$height  = (int) ( $attrs['height'] ?? 0 );
		if ( $width >= 16 && $height >= 16 ) {
			return array( min( $width, 4000 ), min( $height, 4000 ) );
		}
		if ( $width >= 16 ) {
			return array( min( $width, 4000 ), max( 16, (int) round( $width * $default[1] / $default[0] ) ) );
		}
		return $default;
	}

	/**
	 * The separator's HTML for a palette slug, as its save() writes it: the
	 * slug's text and background classes, and no inline colors.
	 *
	 * @param string $html HTML.
	 * @param string $slug Palette slug.
	 */
	private static function separator_classes( string $html, string $slug ): string {
		$tags = new WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( 'hr' ) ) {
			return $html;
		}
		foreach ( iterator_to_array( $tags->class_list(), false ) as $class ) {
			if ( preg_match( '/^has-(?!text-color$|background$)[a-z0-9-]+-(background-)?color$/', $class ) ) {
				$tags->remove_class( $class );
			}
		}
		foreach ( array( 'has-text-color', 'has-' . $slug . '-color', 'has-' . $slug . '-background-color', 'has-background' ) as $class ) {
			$tags->add_class( $class );
		}
		$style = (string) $tags->get_attribute( 'style' );
		$style = trim( (string) preg_replace( '/(?<![-\w])(background-)?color\s*:\s*[^;]+;?/i', '', $style ) );
		'' === $style ? $tags->remove_attribute( 'style' ) : $tags->set_attribute( 'style', $style );
		return $tags->get_updated_html();
	}

	/**
	 * A slug of this palette, or another theme's slug mapped to one.
	 *
	 * @param string $slug A color slug.
	 */
	private static function slug_or_map( string $slug ): string {
		return isset( self::palette()[ $slug ] ) ? $slug : self::map_slug( $slug );
	}

	/**
	 * Another theme's color slug as one of this palette's.
	 *
	 * @param string $slug A color slug.
	 */
	private static function map_slug( string $slug ): string {
		return isset( self::CORE_SLUGS[ $slug ] ) ? self::nearest( self::CORE_SLUGS[ $slug ] ) : 'contrast';
	}

	/**
	 * The theme's font family slugs.
	 *
	 * @return string[]
	 */
	private static function font_families(): array {
		return array_column( WP_Theme_JSON_Resolver::get_theme_data()->get_settings()['typography']['fontFamilies']['theme'] ?? array(), 'slug' );
	}

	/**
	 * The theme's font size slugs.
	 *
	 * @return string[]
	 */
	private static function font_sizes(): array {
		return array_column( WP_Theme_JSON_Resolver::get_theme_data()->get_settings()['typography']['fontSizes']['theme'] ?? array(), 'slug' );
	}

	/**
	 * Red, green and blue of a hex color.
	 *
	 * @param string $hex Three or six digits, with `#`.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$hex = str_pad( substr( $hex, 0, 6 ), 6, '0' );
		return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * A nested value.
	 *
	 * @param array<string, mixed> $array The array.
	 * @param string[]             $path  Keys.
	 * @return mixed Null when missing.
	 */
	private static function get( array $array, array $path ) {
		foreach ( $path as $key ) {
			if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
				return null;
			}
			$array = $array[ $key ];
		}
		return $array;
	}

	/**
	 * Removes a nested value, and the arrays it leaves empty.
	 *
	 * @param array<string, mixed> $array The array.
	 * @param string[]             $path  Keys.
	 */
	private static function unset_path( array &$array, array $path ): void {
		$key = array_shift( $path );
		if ( ! isset( $array[ $key ] ) ) {
			return;
		}
		if ( ! $path ) {
			unset( $array[ $key ] );
			return;
		}
		if ( is_array( $array[ $key ] ) ) {
			self::unset_path( $array[ $key ], $path );
			if ( ! $array[ $key ] ) {
				unset( $array[ $key ] );
			}
		}
	}

	/**
	 * Counts a change for the report.
	 *
	 * @param string $kind What changed.
	 */
	private static function count( string $kind ): void {
		self::$changes[ $kind ] = ( self::$changes[ $kind ] ?? 0 ) + 1;
	}
}
