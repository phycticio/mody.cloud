<?php
/**
 * The languages: those the platform offers, the site's default language and
 * the site's other languages.
 *
 * - **Offered** (decided 2026-11-10): English, Spanish, French, German,
 *   Italian, Portuguese, Dutch and Catalan. Each has its address prefix, its
 *   own name and its hreflang code.
 * - **Default:** core's Site Language, read as get_locale() reads it but
 *   without its filters (this plugin switches the locale on the front end):
 *   the WPLANG constant, then the site's WPLANG option, then the network's;
 *   en_US when empty. It may be a language outside the offered list.
 * - **Other languages:** the site option mody_languages, a list of offered
 *   locales, never the default one.
 *
 * @package Mody\Languages
 */

namespace Mody\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the languages of the network and of the current site.
 */
final class Languages {

	/** The site option holding the site's other languages. */
	public const OPTION = 'mody_languages';

	/**
	 * The offered languages: locale => [address prefix, own name, hreflang].
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	public const OFFERED = array(
		'en_US' => array( 'en', 'English', 'en-US' ),
		'es_ES' => array( 'es', 'Español', 'es-ES' ),
		'fr_FR' => array( 'fr', 'Français', 'fr-FR' ),
		'de_DE' => array( 'de', 'Deutsch', 'de-DE' ),
		'it_IT' => array( 'it', 'Italiano', 'it-IT' ),
		'pt_PT' => array( 'pt', 'Português', 'pt-PT' ),
		'nl_NL' => array( 'nl', 'Nederlands', 'nl-NL' ),
		'ca'    => array( 'ca', 'Català', 'ca' ),
	);

	/**
	 * The site's default language: core's Site Language.
	 */
	public static function default_locale(): string {
		$locale = defined( 'WPLANG' ) ? (string) WPLANG : '';
		if ( is_multisite() ) {
			$site = get_option( 'WPLANG' );
			if ( false === $site ) {
				$site = get_site_option( 'WPLANG' );
			}
			if ( false !== $site ) {
				$locale = (string) $site;
			}
		} else {
			$locale = (string) get_option( 'WPLANG', $locale );
		}
		return '' === $locale ? 'en_US' : $locale;
	}

	/**
	 * The site's other languages, in the offered order, never the default.
	 *
	 * @return string[]
	 */
	public static function others(): array {
		$saved   = get_option( self::OPTION, array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$default = self::default_locale();
		$slug    = self::slug( $default );
		return array_values(
			array_filter(
				array_keys( self::OFFERED ),
				static fn( string $locale ): bool => in_array( $locale, $saved, true ) && $locale !== $default && self::OFFERED[ $locale ][0] !== $slug
			)
		);
	}

	/**
	 * Every language of the site, the default first.
	 *
	 * @return string[]
	 */
	public static function site_locales(): array {
		return array_merge( array( self::default_locale() ), self::others() );
	}

	/**
	 * Whether the site has more than one language.
	 */
	public static function is_multilingual(): bool {
		return array() !== self::others();
	}

	/**
	 * Whether a locale is one of the site's other languages.
	 *
	 * @param string $locale A locale.
	 */
	public static function is_other( string $locale ): bool {
		return in_array( $locale, self::others(), true );
	}

	/**
	 * A language's address prefix: es for es_ES; the first part of a locale
	 * outside the offered list.
	 *
	 * @param string $locale A locale.
	 */
	public static function slug( string $locale ): string {
		return self::OFFERED[ $locale ][0] ?? strtolower( (string) strtok( $locale, '_' ) );
	}

	/**
	 * A language's own name: Español for es_ES; the locale itself outside the
	 * offered list.
	 *
	 * @param string $locale A locale.
	 */
	public static function label( string $locale ): string {
		return self::OFFERED[ $locale ][1] ?? $locale;
	}

	/**
	 * A language's code for lang and hreflang attributes: es-ES for es_ES.
	 *
	 * @param string $locale A locale.
	 */
	public static function code( string $locale ): string {
		return self::OFFERED[ $locale ][2] ?? str_replace( '_', '-', $locale );
	}

	/**
	 * The site's other language whose address prefix this is, or ''.
	 *
	 * @param string $slug An address prefix, such as es.
	 */
	public static function from_slug( string $slug ): string {
		foreach ( self::others() as $locale ) {
			if ( self::slug( $locale ) === $slug ) {
				return $locale;
			}
		}
		return '';
	}

	/**
	 * Saves the site's other languages, keeping only offered ones.
	 *
	 * @param string[] $locales Locales.
	 */
	public static function save( array $locales ): void {
		$locales = array_values( array_intersect( array_keys( self::OFFERED ), array_map( 'strval', $locales ) ) );
		update_option( self::OPTION, $locales );
	}
}
