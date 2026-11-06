<?php
/**
 * The brand profile of a site, read from the network-wide `mody_brand_profiles` table.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Brand;

use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;

defined( 'ABSPATH' ) || exit;

/**
 * One site's brand identity: the source of truth for every generation (rule 4).
 *
 * The schema is documentation/mvp-spec.md section 11. The brand colors are
 * applied through the site's Global Styles (Design\SiteDesign), and prompts
 * refer to palette slugs, never to the hex values: $colors is for the wizard
 * and the design tools, not for SystemPrompt.
 */
final class BrandProfile {

	/** Allowed values of `tone_of_voice`. */
	public const TONES = array( 'formal', 'friendly', 'technical' );

	/** Bump when the table definition in install() changes. */
	private const DB_VERSION = '1';

	/** Network option that records the installed table version. */
	private const DB_VERSION_OPTION = 'mody_brand_profiles_db_version';

	/**
	 * Builds a profile from already sanitized values. Use for_site() or from_row().
	 *
	 * @param int                   $site_id    Site (blog) ID.
	 * @param string                $brand_name Brand name.
	 * @param string                $mission    What the brand does, in one sentence.
	 * @param string                $tone       One of self::TONES.
	 * @param string                $logo_url   Logo URL, or '' for none.
	 * @param int                   $id         Row ID, or 0 when unknown.
	 * @param array<string, string> $colors     Brand colors: primary, secondary, accent => lowercase hex.
	 */
	private function __construct(
		public readonly int $site_id,
		public readonly string $brand_name,
		public readonly string $mission,
		public readonly string $tone,
		public readonly string $logo_url,
		public readonly int $id,
		public readonly array $colors,
	) {}

	/**
	 * The table name. The table is network-wide, so it uses the base prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_brand_profiles';
	}

	/**
	 * Creates or updates the table when its version changed. Cheap otherwise:
	 * one network option read, which the object cache serves.
	 */
	public static function install(): void {
		if ( self::DB_VERSION === get_site_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta() needs this exact layout: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  site_id bigint(20) unsigned NOT NULL,
  brand_name varchar(120) NOT NULL,
  mission text,
  tone_of_voice enum('formal','friendly','technical') DEFAULT 'friendly',
  color_primary varchar(7) DEFAULT '#005f6b',
  color_secondary varchar(7) DEFAULT '#333333',
  color_accent varchar(7) DEFAULT '#b50ba4',
  logo_url varchar(255),
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY user_site (user_id,site_id),
  KEY site_id (site_id)
) {$charset};"
		);

		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Loads the profile of a site.
	 *
	 * @param int $site_id Site (blog) ID.
	 * @return self|null Null when the site has no usable profile.
	 */
	public static function for_site( int $site_id ): ?self {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; one indexed lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, site_id, brand_name, mission, tone_of_voice, logo_url, color_primary, color_secondary, color_accent FROM {$table} WHERE site_id = %d ORDER BY id DESC LIMIT 1", $site_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? self::from_row( $row ) : null;
	}

	/**
	 * Builds a profile from a table row, sanitizing every field again.
	 *
	 * The wizard validates input (mvp-spec section 6). These values also end up
	 * in a system prompt, so they are cleaned here too: tags stripped, lengths
	 * capped, line breaks collapsed, unknown tones replaced by the default.
	 * A color that is not six-digit hex is replaced by the default preset's.
	 *
	 * @param array<string, mixed> $row Row with site_id, brand_name, mission, tone_of_voice, logo_url,
	 *                                  and optionally id, color_primary, color_secondary, color_accent.
	 * @return self|null Null when the brand name is empty.
	 */
	public static function from_row( array $row ): ?self {
		$name = self::clean( $row['brand_name'] ?? '', 120 );
		if ( '' === $name ) {
			return null;
		}

		$tone   = (string) ( $row['tone_of_voice'] ?? '' );
		$logo   = esc_url_raw( (string) ( $row['logo_url'] ?? '' ), array( 'http', 'https' ) );
		$colors = (array) Presets::colors( Presets::DEFAULT );
		foreach ( SiteDesign::SLUGS as $slug ) {
			$color = $row[ 'color_' . $slug ] ?? '';
			if ( SiteDesign::is_hex( $color ) ) {
				$colors[ $slug ] = strtolower( $color );
			}
		}

		return new self(
			(int) ( $row['site_id'] ?? 0 ),
			$name,
			self::clean( $row['mission'] ?? '', 500 ),
			in_array( $tone, self::TONES, true ) ? $tone : 'friendly',
			$logo,
			(int) ( $row['id'] ?? 0 ),
			$colors
		);
	}

	/**
	 * Saves a site's brand profile (provisioning, M2-01).
	 *
	 * The values are cleaned like from_row() cleans what it reads.
	 *
	 * @param int                   $user_id The member who owns the site.
	 * @param int                   $site_id Site (blog) ID.
	 * @param array<string, string> $fields  brand_name, mission, tone_of_voice, and optionally
	 *                                       logo_url, color_primary, color_secondary, color_accent.
	 * @return self|\WP_Error The saved profile.
	 */
	public static function insert( int $user_id, int $site_id, array $fields ): self|\WP_Error {
		$profile = self::from_row( array_merge( $fields, array( 'site_id' => $site_id ) ) );
		if ( null === $profile || $user_id < 1 || $site_id < 1 ) {
			return new \WP_Error( 'mody_invalid_brand_profile', __( 'The brand profile needs a brand name, a member and a site.', 'mody_cloud' ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; one row.
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'user_id'         => $user_id,
				'site_id'         => $site_id,
				'brand_name'      => $profile->brand_name,
				'mission'         => $profile->mission,
				'tone_of_voice'   => $profile->tone,
				'logo_url'        => $profile->logo_url,
				'color_primary'   => $profile->colors['primary'],
				'color_secondary' => $profile->colors['secondary'],
				'color_accent'    => $profile->colors['accent'],
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'mody_brand_profile_not_saved', __( 'The brand profile could not be saved.', 'mody_cloud' ) );
		}

		return self::for_site( $site_id ) ?? new \WP_Error( 'mody_brand_profile_not_saved', __( 'The brand profile could not be saved.', 'mody_cloud' ) );
	}

	/**
	 * Changes the brand colors of a profile row (mody/update-design with `brand` true).
	 *
	 * @param int                   $profile_id Row ID (BrandProfile::$id).
	 * @param array<string, string> $colors     Slug (primary, secondary, accent) => six-digit hex.
	 * @return bool False for an invalid color or ID, or a database error.
	 */
	public static function update_colors( int $profile_id, array $colors ): bool {
		$columns = array();
		foreach ( array_intersect_key( $colors, array_flip( SiteDesign::SLUGS ) ) as $slug => $hex ) {
			if ( ! SiteDesign::is_hex( $hex ) ) {
				return false;
			}
			$columns[ 'color_' . $slug ] = strtolower( $hex );
		}
		if ( $profile_id < 1 || ! $columns ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; one row by primary key.
		$updated = $wpdb->update( self::table(), $columns, array( 'id' => $profile_id ), '%s', '%d' );
		return false !== $updated;
	}

	/**
	 * Plain single-line text, at most $max characters.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum length.
	 */
	private static function clean( $value, int $max ): string {
		$text = sanitize_text_field( (string) $value ); // Strips tags and line breaks.
		return trim( mb_substr( $text, 0, $max ) );
	}
}
