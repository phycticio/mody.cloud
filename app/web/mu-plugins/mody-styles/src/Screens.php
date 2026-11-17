<?php
/**
 * The Site Editor's Styles screens.
 *
 * @package Mody\Styles
 */

namespace Mody\Styles;

use WP_Block_Type_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Where a change to the styles shows: the Styles screen of a section, as a
 * Site Editor URL on the current site and a name the agent can say.
 * WordPress 7.1 routes the Site Editor with `?p=/styles`, and
 * `section=<screen>` picks a screen such as `/colors`.
 */
final class Screens {

	/**
	 * Styles screens: key => `section` path. `stylebook` is the Style Book
	 * preview, not a section.
	 */
	public const SECTIONS = array(
		'overview'   => '/',
		'browse'     => '/variations',
		'colors'     => '/colors',
		'palette'    => '/colors/palette',
		'typography' => '/typography',
		'layout'     => '/layout',
		'blocks'     => '/blocks',
		'revisions'  => '/revisions',
		'stylebook'  => '',
	);

	/**
	 * The Styles screen of a section key.
	 *
	 * @param string $section One of the SECTIONS keys.
	 * @param string $block   A block type for the `blocks` section, such as `core/button`, or ''.
	 * @return array{url: string, place: string}
	 */
	public static function styles( string $section, string $block = '' ): array {
		if ( 'stylebook' === $section ) {
			return array(
				'url'   => self::url( array( 'preview' => 'stylebook' ) ),
				'place' => __( 'the Style Book', 'mody_cloud' ),
			);
		}
		$places = array(
			'overview'   => __( 'Styles', 'mody_cloud' ),
			'browse'     => __( 'Styles → Browse styles', 'mody_cloud' ),
			'colors'     => __( 'Styles → Colors', 'mody_cloud' ),
			'palette'    => __( 'Styles → Colors → Palette', 'mody_cloud' ),
			'typography' => __( 'Styles → Typography', 'mody_cloud' ),
			'layout'     => __( 'Styles → Layout', 'mody_cloud' ),
			'blocks'     => __( 'Styles → Blocks', 'mody_cloud' ),
			'revisions'  => __( 'Styles → Revisions', 'mody_cloud' ),
		);
		$section = isset( $places[ $section ] ) ? $section : 'overview';
		$path    = self::SECTIONS[ $section ];
		$place   = $places[ $section ];
		if ( 'blocks' === $section && '' !== $block ) {
			$type  = WP_Block_Type_Registry::get_instance()->get_registered( $block );
			$path  = '/blocks/' . rawurlencode( $block ); // As the Site Editor builds it.
			$place = $places['blocks'] . ' → ' . ( $type && $type->title ? $type->title : $block );
		}
		return array(
			'url'   => '/' === $path ? self::url() : self::url( array( 'section' => $path ) ),
			'place' => $place,
		);
	}

	/**
	 * The Site Editor's Styles URL on the current site.
	 *
	 * @param array<string, string> $query More query arguments, such as `section`.
	 */
	private static function url( array $query = array() ): string {
		$args = array( 'p' => rawurlencode( '/styles' ) ) + array_map( 'rawurlencode', $query );
		return add_query_arg( $args, admin_url( 'site-editor.php' ) );
	}
}
