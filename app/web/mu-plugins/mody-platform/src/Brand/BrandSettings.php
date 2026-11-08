<?php
/**
 * A site's brand: its WordPress settings (ticket M2-08).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * The brand of a site is its own WordPress data (decided 2026-11-08): the
 * site title, tagline, logo and icon (Identity), its styles (Global Styles),
 * and two settings WordPress lacks, registered here as site settings:
 *
 * - `mody_mission`: what the business does, in one sentence;
 * - `mody_tone`: the tone of voice (formal, friendly, technical).
 *
 * Both are shown in REST, so they belong to the Site Editor's `root/site`
 * entity like the title and tagline: the Identity screen's two extra fields
 * (assets/identity-fields.js) edit them, and the Site Editor's Save saves
 * them. Settings → General shows them too. Nothing is copied anywhere: a
 * prompt reads the brand when it runs (brand()).
 *
 * Replaces the `mody_brand_profiles` table, whose rows migrate() moves into
 * each site's settings once, then drops.
 */
final class BrandSettings {

	/** What the business does, in one sentence. */
	public const MISSION = 'mody_mission';

	/** The tone of voice. */
	public const TONE = 'mody_tone';

	/** Allowed tones. */
	public const TONES = array( 'formal', 'friendly', 'technical' );

	/** The tone when none was chosen. */
	public const DEFAULT_TONE = 'friendly';

	/** The longest mission. */
	public const MAX_MISSION = 200;

	/** Network option: the profile table was migrated and dropped. */
	private const MIGRATED_OPTION = 'mody_brand_settings_version';

	/** The table this replaces (network-wide, base prefix). */
	public const LEGACY_TABLE = 'mody_brand_profiles';

	/**
	 * `init`: registers the two settings, in REST, for every site.
	 */
	public static function register(): void {
		register_setting(
			'general',
			self::MISSION,
			array(
				'type'              => 'string',
				'label'             => __( 'Mission', 'mody_cloud' ),
				'description'       => __( 'What the business does, in one sentence. The Mody agent writes with it.', 'mody_cloud' ),
				'sanitize_callback' => array( self::class, 'sanitize_mission' ),
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		register_setting(
			'general',
			self::TONE,
			array(
				'type'              => 'string',
				'label'             => __( 'Tone of voice', 'mody_cloud' ),
				'description'       => __( 'How the Mody agent writes for this site.', 'mody_cloud' ),
				'sanitize_callback' => array( self::class, 'sanitize_tone' ),
				'show_in_rest'      => array( 'schema' => array( 'enum' => self::TONES ) ),
				'default'           => self::DEFAULT_TONE,
			)
		);
	}

	/**
	 * The tones' labels, for the screens.
	 *
	 * @return array<string, string>
	 */
	public static function tone_labels(): array {
		return array(
			'formal'    => __( 'Formal', 'mody_cloud' ),
			'friendly'  => __( 'Friendly', 'mody_cloud' ),
			'technical' => __( 'Technical', 'mody_cloud' ),
		);
	}

	/**
	 * The brand of the current site, read now from WordPress.
	 *
	 * @return array{name: string, tagline: string, mission: string, tone: string}
	 */
	public static function brand(): array {
		return array(
			'name'    => self::decode( (string) get_option( 'blogname', '' ) ),
			'tagline' => self::decode( (string) get_option( 'blogdescription', '' ) ),
			'mission' => self::sanitize_mission( get_option( self::MISSION, '' ) ),
			'tone'    => self::sanitize_tone( get_option( self::TONE, self::DEFAULT_TONE ) ),
		);
	}

	/**
	 * Writes the mission and tone of the current site.
	 *
	 * @param string $mission What the business does.
	 * @param string $tone    One of TONES.
	 */
	public static function save( string $mission, string $tone ): void {
		update_option( self::MISSION, self::sanitize_mission( $mission ) );
		update_option( self::TONE, self::sanitize_tone( $tone ) );
	}

	/**
	 * Sanitizes a mission: plain text, one line, at most MAX_MISSION characters.
	 *
	 * @param mixed $value The value.
	 */
	public static function sanitize_mission( $value ): string {
		return mb_substr( sanitize_text_field( is_string( $value ) ? $value : '' ), 0, self::MAX_MISSION );
	}

	/**
	 * Sanitizes a tone: one of TONES, else the default.
	 *
	 * @param mixed $value The value.
	 */
	public static function sanitize_tone( $value ): string {
		return is_string( $value ) && in_array( $value, self::TONES, true ) ? $value : self::DEFAULT_TONE;
	}

	/**
	 * `admin_init`: the two settings in Settings → General, the supported
	 * place that never depends on the Site Editor's markup.
	 */
	public static function general_fields(): void {
		add_settings_field(
			self::MISSION,
			'<label for="' . esc_attr( self::MISSION ) . '">' . esc_html__( 'Mission', 'mody_cloud' ) . '</label>',
			static function (): void {
				printf(
					'<input name="%1$s" type="text" id="%1$s" value="%2$s" class="regular-text" maxlength="%3$d" /><p class="description">%4$s</p>',
					esc_attr( self::MISSION ),
					esc_attr( self::brand()['mission'] ),
					(int) self::MAX_MISSION,
					esc_html__( 'What the business does, in one sentence. The Mody agent writes with it.', 'mody_cloud' )
				);
			},
			'general'
		);
		add_settings_field(
			self::TONE,
			'<label for="' . esc_attr( self::TONE ) . '">' . esc_html__( 'Tone of voice', 'mody_cloud' ) . '</label>',
			static function (): void {
				$current = self::brand()['tone'];
				echo '<select name="' . esc_attr( self::TONE ) . '" id="' . esc_attr( self::TONE ) . '">';
				foreach ( self::tone_labels() as $value => $label ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
				}
				echo '</select>';
			},
			'general'
		);
	}

	/**
	 * `admin_enqueue_scripts`: on the Site Editor, the script that adds the
	 * two settings to the Identity screen (assets/identity-fields.js).
	 *
	 * @param string $hook The admin page.
	 */
	public static function enqueue_identity_fields( string $hook ): void {
		if ( 'site-editor.php' !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$root = dirname( __DIR__, 2 );
		wp_enqueue_script( 'mody-identity-fields', plugins_url( 'assets/identity-fields.js', $root . '/mody-platform.php' ), array( 'wp-element', 'wp-components', 'wp-data', 'wp-core-data' ), (string) filemtime( $root . '/assets/identity-fields.js' ), true );
		wp_add_inline_script(
			'mody-identity-fields',
			'window.modyIdentity = ' . wp_json_encode(
				array(
					'mission'     => self::MISSION,
					'tone'        => self::TONE,
					'maxMission'  => self::MAX_MISSION,
					'defaultTone' => self::DEFAULT_TONE,
					'tones'       => self::tone_labels(),
					'strings'     => array(
						'mission'     => __( 'Mission', 'mody_cloud' ),
						'missionHelp' => __( 'What the business does, in one sentence. The Mody agent writes with it.', 'mody_cloud' ),
						'tone'        => __( 'Tone of voice', 'mody_cloud' ),
						'toneHelp'    => __( 'How the Mody agent writes for this site.', 'mody_cloud' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * `init` (early): once per network, moves the old profile table's mission
	 * and tone into each site's settings, sets an empty site title from the
	 * profile's brand name, and drops the table (decided 2026-11-08). The
	 * rest of a profile (logo, colors) is already in each site's settings
	 * and Global Styles.
	 */
	public static function migrate(): void {
		if ( '1' === (string) get_site_option( self::MIGRATED_OPTION ) ) {
			return;
		}
		global $wpdb;
		self::migrate_from( $wpdb->base_prefix . self::LEGACY_TABLE );
		update_site_option( self::MIGRATED_OPTION, '1' );
	}

	/**
	 * The migration itself, for a given table (tests use a copy).
	 *
	 * @param string $table A table with the old profile's columns.
	 * @return int How many sites got their settings.
	 */
	public static function migrate_from( string $table ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- A one-off migration of Mody's own table.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return 0;
		}
		$rows  = (array) $wpdb->get_results( "SELECT site_id, brand_name, mission, tone_of_voice FROM {$table} ORDER BY id ASC", ARRAY_A );
		$sites = array();
		foreach ( $rows as $row ) {
			$sites[ (int) $row['site_id'] ] = $row; // The newest row wins, as the profile did.
		}
		$moved = 0;
		foreach ( $sites as $site_id => $row ) {
			if ( ! get_site( $site_id ) ) {
				continue;
			}
			switch_to_blog( $site_id );
			if ( '' === (string) get_option( self::MISSION, '' ) && '' !== trim( (string) $row['mission'] ) ) {
				update_option( self::MISSION, self::sanitize_mission( $row['mission'] ) );
			}
			update_option( self::TONE, self::sanitize_tone( $row['tone_of_voice'] ) );
			if ( '' === trim( (string) get_option( 'blogname', '' ) ) && '' !== trim( (string) $row['brand_name'] ) ) {
				update_option( 'blogname', sanitize_text_field( $row['brand_name'] ) );
			}
			restore_current_blog();
			++$moved;
		}
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		// phpcs:enable
		if ( $wpdb->base_prefix . self::LEGACY_TABLE === $table ) {
			delete_site_option( 'mody_brand_profiles_db_version' );
		}
		return $moved;
	}

	/**
	 * WordPress stores the title and tagline HTML-escaped; prompts get text.
	 *
	 * @param string $value A stored value.
	 */
	private static function decode( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
