<?php
/**
 * The ability mody/update-site-settings.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Site;

use Mody\Platform\Brand\BrandSettings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Changes the site's identity settings (documentation/mvp-spec.md section 8,
 * tickets M3-03 and M2-08): the title (the brand name), the tagline, and the
 * mission and tone site settings (BrandSettings). Nothing else: the allowlist
 * is fixed here, and the ability's schema refuses any other property.
 */
final class SiteSettings {

	/** Input property => option, with the longest accepted value in characters. */
	public const FIELDS = array(
		'title'   => array(
			'option' => 'blogname',
			'max'    => 120,
		),
		'tagline' => array(
			'option' => 'blogdescription',
			'max'    => 200,
		),
		'mission' => array(
			'option' => BrandSettings::MISSION,
			'max'    => BrandSettings::MAX_MISSION,
		),
		'tone'    => array(
			'option' => BrandSettings::TONE,
			'max'    => 20,
		),
	);

	/**
	 * Permission callback of mody/update-site-settings.
	 */
	public static function can_update(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * A setting as the member reads it. WordPress stores these options
	 * HTML-escaped (sanitize_option()).
	 *
	 * @param string $field A FIELDS key.
	 */
	public static function get( string $field ): string {
		return wp_specialchars_decode( (string) get_option( self::FIELDS[ $field ]['option'], '' ), ENT_QUOTES );
	}

	/**
	 * Execute callback of mody/update-site-settings. Input already validated
	 * against the ability's schema.
	 *
	 * @param mixed $input `title`, `tagline`, `mission` and/or `tone`.
	 * @return array<string, array{old: string, new: string}>|WP_Error The old and new values.
	 */
	public static function update( $input ): array|WP_Error {
		$input  = is_array( $input ) ? $input : array();
		$values = array();
		foreach ( self::FIELDS as $field => $spec ) {
			if ( isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) ) {
				$values[ $field ] = mb_substr( sanitize_text_field( (string) $input[ $field ] ), 0, $spec['max'] );
			}
		}
		if ( ! $values ) {
			return new WP_Error( 'mody_no_settings', __( 'Give a new site title, tagline, mission or tone.', 'mody_cloud' ) );
		}
		if ( isset( $values['tone'] ) && ! in_array( $values['tone'], BrandSettings::TONES, true ) ) {
			/* translators: %s: the tones. */
			return new WP_Error( 'mody_unknown_tone', sprintf( __( 'The tone must be one of: %s.', 'mody_cloud' ), implode( ', ', BrandSettings::TONES ) ) );
		}
		if ( isset( $values['title'] ) && '' === $values['title'] ) {
			return new WP_Error( 'mody_empty_title', __( 'The site title cannot be empty.', 'mody_cloud' ) );
		}

		$changes = array();
		foreach ( $values as $field => $value ) {
			$option = self::FIELDS[ $field ]['option'];
			$old    = self::get( $field );
			update_option( $option, $value );
			// update_option() stores sanitize_option()'s result, and returns false when nothing changed.
			if ( get_option( $option ) !== sanitize_option( $option, $value ) ) {
				return new WP_Error( 'mody_setting_not_saved', __( 'The setting could not be saved. Please try again.', 'mody_cloud' ) );
			}
			$changes[ $field ] = array(
				'old' => $old,
				'new' => self::get( $field ),
			);
		}
		return $changes;
	}
}
