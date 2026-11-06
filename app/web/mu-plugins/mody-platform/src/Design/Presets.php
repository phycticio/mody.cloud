<?php
/**
 * The six Mody design presets.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Design;

defined( 'ABSPATH' ) || exit;

/**
 * The curated color presets of documentation/mvp-spec.md section 6.
 *
 * The single source for the brand wizard (M2-03), provisioning (M2-01) and the agent's
 * `mody/update-design` (M3-02). Each preset sets the three brand palette
 * slugs; `base` and `contrast` stay the theme's. The values are the spec's
 * candidate table, still to be curated by the maintainer before M2 ships.
 */
final class Presets {

	/** The preset a new site starts with. Its colors are the theme's and the brand profile table's defaults. */
	public const DEFAULT = 'mody-teal';

	/** Slug => name and colors. The names are proper names, so they are not translated. */
	private const PRESETS = array(
		'mody-teal' => array(
			'name'      => 'Mody Teal',
			'primary'   => '#005f6b',
			'secondary' => '#333333',
			'accent'    => '#b50ba4',
		),
		'forest'    => array(
			'name'      => 'Forest',
			'primary'   => '#2f5d3a',
			'secondary' => '#333333',
			'accent'    => '#c77d2e',
		),
		'ocean'     => array(
			'name'      => 'Ocean',
			'primary'   => '#0b4f6c',
			'secondary' => '#22333b',
			'accent'    => '#4ea8de',
		),
		'plum'      => array(
			'name'      => 'Plum',
			'primary'   => '#5b2a86',
			'secondary' => '#333333',
			'accent'    => '#d94f8f',
		),
		'graphite'  => array(
			'name'      => 'Graphite',
			'primary'   => '#374151',
			'secondary' => '#1f2937',
			'accent'    => '#005f6b',
		),
		'sand'      => array(
			'name'      => 'Sand',
			'primary'   => '#9a6b32',
			'secondary' => '#333333',
			'accent'    => '#0b4f6c',
		),
	);

	/**
	 * Every preset, in display order.
	 *
	 * @return array<string, array{name: string, primary: string, secondary: string, accent: string}> Keyed by slug.
	 */
	public static function all(): array {
		return self::PRESETS;
	}

	/**
	 * The preset slugs, in display order.
	 *
	 * @return list<string>
	 */
	public static function slugs(): array {
		return array_keys( self::PRESETS );
	}

	/**
	 * The three colors of a preset.
	 *
	 * @param string $slug Preset slug.
	 * @return array{primary: string, secondary: string, accent: string}|null Null for an unknown slug.
	 */
	public static function colors( string $slug ): ?array {
		if ( ! isset( self::PRESETS[ $slug ] ) ) {
			return null;
		}
		return array_intersect_key( self::PRESETS[ $slug ], array_flip( SiteDesign::SLUGS ) );
	}

	/**
	 * The preset names, for the agent: "Mody Teal (mody-teal), Forest (forest), …".
	 */
	public static function names(): string {
		$names = array();
		foreach ( self::PRESETS as $slug => $preset ) {
			$names[] = sprintf( '%s (%s)', $preset['name'], $slug );
		}
		return implode( ', ', $names );
	}
}
