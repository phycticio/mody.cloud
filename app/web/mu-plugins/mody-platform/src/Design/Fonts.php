<?php
/**
 * The Font Library: no font collection from outside the network (rule 1).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Design;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress registers a "Google Fonts" collection in the Site Editor's Font
 * Library (Styles → Typography → Fonts). Opening it loads its list and font
 * previews from wordpress.org, and installing a font downloads it from
 * Google (which SpectraGuard already refuses). Both are outside Europe, so the
 * collection is removed on every site (2026-11-07).
 *
 * The members' own typography comes from a repository of free fonts served
 * by the network (decided 2026-11-07, ticket M2-07), registered here later.
 */
final class Fonts {

	/** Font collections WordPress registers that load from outside the network. */
	public const REMOVED_COLLECTIONS = array( 'google-fonts' );

	/**
	 * Hooked to `init` after core registers its default collections (priority 10).
	 */
	public static function remove_collections(): void {
		if ( ! function_exists( 'wp_unregister_font_collection' ) ) {
			return;
		}
		foreach ( self::REMOVED_COLLECTIONS as $slug ) {
			if ( isset( \WP_Font_Library::get_instance()->get_font_collections()[ $slug ] ) ) {
				wp_unregister_font_collection( $slug );
			}
		}
	}
}
