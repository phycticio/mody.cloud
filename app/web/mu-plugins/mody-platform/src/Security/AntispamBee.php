<?php
/**
 * Antispam Bee, kept inside rule 1 (tickets/antibot-altcha.md).
 *
 * Antispam Bee (pluginkollektiv, Germany) checks comments on our own server:
 * a honeypot, BBCode, known spam patterns, the local spam database. It is a
 * MU plugin on every site (Composer installs it into
 * mu-plugins/antispam-bee/, loaded by mu-plugins/antispam-bee.php), beside
 * ALTCHA, which stops scripts; Antispam Bee catches spam people type.
 *
 * Three of its checks call outside services, and each site's administrator
 * could switch them on in Settings → Antispam Bee. They are pinned off,
 * whatever is saved, and hidden on that screen:
 * - "Trust commenters with a Gravatar": gravatar.com (Automattic, US);
 * - the country check: iplocate.io;
 * - the language check: pluginkollektiv's API.
 *
 * As a MU plugin its activation hook never runs. It only stored
 * `use_output_buffer` = 0 (the comment field is changed through a filter,
 * not by buffering the page), so that is the default here.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Pins Antispam Bee's outside checks off.
 */
final class AntispamBee {

	/** Antispam Bee's option, per site. */
	public const OPTION = 'antispam_bee';

	/** Its settings that call outside services, always 0. */
	public const PINNED_OFF = array( 'gravatar_check', 'country_code', 'translate_api' );

	/**
	 * Hooked on load.
	 */
	public static function register(): void {
		add_filter( 'option_' . self::OPTION, array( self::class, 'pin' ) );
		add_filter( 'default_option_' . self::OPTION, array( self::class, 'pin' ) );
		add_filter( 'pre_update_option_' . self::OPTION, array( self::class, 'pin' ) );
		add_action( 'admin_head-settings_page_antispam_bee', array( self::class, 'hide_settings' ) );
	}

	/**
	 * The option with the outside checks off, and without output buffering
	 * unless a site chose it.
	 *
	 * @param mixed $options The stored options (false when there are none).
	 * @return array<string, mixed>
	 */
	public static function pin( $options ): array {
		$options = is_array( $options ) ? $options : array();
		$options += array( 'use_output_buffer' => 0 );
		foreach ( self::PINNED_OFF as $key ) {
			$options[ $key ] = 0;
		}
		return $options;
	}

	/**
	 * `admin_head-settings_page_antispam_bee`: the three checks are not offered.
	 */
	public static function hide_settings(): void {
		echo '<style>li:has(> #ab_gravatar_check),li:has(> #ab_country_code),li:has(> #ab_translate_api){display:none}</style>';
	}
}
