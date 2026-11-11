<?php
/**
 * The ability mody/update-settings: the site's settings, by name, in tiers.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Site;

use Mody\Platform\Brand\BrandBook;
use Mody\Platform\Brand\BrandSettings;
use Mody\Platform\Login\Schemes;
use Mody\Platform\SiteEditor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-11 (tickets/agent-knows-gutenberg-spectra-settings.md):
 * Mody changes the site's settings in tiers.
 *
 * - `direct`: changed when asked (formats, timezone, comments, image sizes…).
 * - `confirm`: changed only after the owner confirms in the chat (search
 *   engines, home page, permalinks, languages, comment blocklists). The
 *   agent holds such a call and shows Apply and Cancel (Agent::hold()); the
 *   ability itself does not ask, so a person calling it directly (REST, MCP)
 *   is not asked twice.
 * - Everything else is refused: the admin email, users and roles, plugins
 *   and themes, API keys, the mail server, the default language (no
 *   language packs can be installed by members).
 *
 * The allowlist is ours, typed, not read from register_setting(): the
 * registered list holds API keys, and misses most of the Settings screens.
 * Each value is checked here first (all or nothing), then saved with
 * update_option() and read back, so WordPress's own sanitizing applies.
 */
final class Settings {

	public const DIRECT  = 'direct';
	public const CONFIRM = 'confirm';

	/** The word for "no page" and "the latest posts". */
	public const NONE   = 'none';
	public const LATEST = 'latest posts';

	/** The longest list of words (moderation, blocklist), in lines. */
	public const MAX_LINES = 200;

	/**
	 * Setting name => spec: `option`, `type`, `tier`, and per type `max`,
	 * `min`, `values` (name => stored) or `on`/`off` (stored).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function specs(): array {
		$bool     = static fn( string $option, string $tier = self::DIRECT, $on = '1', $off = '0' ): array => array(
			'option' => $option,
			'type'   => 'bool',
			'tier'   => $tier,
			'on'     => $on,
			'off'    => $off,
		);
		$int      = static fn( string $option, int $min, int $max ): array => array(
			'option' => $option,
			'type'   => 'int',
			'tier'   => self::DIRECT,
			'min'    => $min,
			'max'    => $max,
		);
		$text     = static fn( string $option, int $max ): array => array(
			'option' => $option,
			'type'   => 'text',
			'tier'   => self::DIRECT,
			'max'    => $max,
		);
		$enum     = static fn( string $option, array $values, string $tier = self::DIRECT ): array => array(
			'option' => $option,
			'type'   => 'enum',
			'tier'   => $tier,
			'values' => $values,
		);
		$formats  = array( 'standard' => '0' );
		foreach ( array( 'aside', 'chat', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio' ) as $format ) {
			$formats[ $format ] = $format;
		}
		$brand = array();
		foreach ( array_keys( BrandBook::FIELDS ) as $field ) {
			$brand[ 'brand_' . $field ] = array(
				'option' => BrandBook::OPTION,
				'type'   => 'brand',
				'tier'   => self::DIRECT, // Decided 2026-11-11: the owner is telling Mody about their own business.
				'field'  => $field,
			);
		}
		return array_merge(
			array(
				// General.
				'title'                        => $text( 'blogname', 120 ),
				'tagline'                      => $text( 'blogdescription', 200 ),
				'mission'                      => $text( BrandSettings::MISSION, BrandSettings::MAX_MISSION ),
				'tone'                         => $enum( BrandSettings::TONE, array_combine( BrandSettings::TONES, BrandSettings::TONES ) ),
				'timezone'                     => array(
					'option' => 'timezone_string',
					'type'   => 'timezone',
					'tier'   => self::DIRECT,
				),
				'date_format'                  => $text( 'date_format', 40 ),
				'time_format'                  => $text( 'time_format', 40 ),
				'week_starts_on'               => $enum( 'start_of_week', array_flip( array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ) ) ),
				// Writing and reading.
				'default_category'             => array(
					'option' => 'default_category',
					'type'   => 'category',
					'tier'   => self::DIRECT,
				),
				'default_post_format'          => $enum( 'default_post_format', $formats ),
				'emoticons_as_images'          => $bool( 'use_smilies' ),
				'posts_per_page'               => $int( 'posts_per_page', 1, 100 ),
				'feed_items'                   => $int( 'posts_per_rss', 1, 100 ),
				'feed_content'                 => $enum(
					'rss_use_excerpt',
					array(
						'full text' => '0',
						'excerpt'   => '1',
					)
				),
				'homepage'                     => array(
					'option' => 'page_on_front',
					'type'   => 'homepage',
					'tier'   => self::CONFIRM,
				),
				'posts_page'                   => array(
					'option' => 'page_for_posts',
					'type'   => 'page',
					'tier'   => self::CONFIRM,
				),
				'search_engines_visible'       => $bool( 'blog_public', self::CONFIRM ),
				// Discussion.
				'comments_on_new_posts'        => $bool( 'default_comment_status', self::DIRECT, 'open', 'closed' ),
				'pingbacks_on_new_posts'       => $bool( 'default_ping_status', self::DIRECT, 'open', 'closed' ),
				'comments_need_name_and_email' => $bool( 'require_name_email' ),
				'comments_only_registered'     => $bool( 'comment_registration', self::CONFIRM ),
				'comments_held_for_approval'   => $bool( 'comment_moderation' ),
				'comments_known_authors_approved' => $bool( 'comment_previously_approved' ),
				'email_on_new_comment'         => $bool( 'comments_notify' ),
				'email_on_held_comment'        => $bool( 'moderation_notify' ),
				'threaded_comments'            => $bool( 'thread_comments' ),
				'thread_depth'                 => $int( 'thread_comments_depth', 2, 10 ),
				'comments_in_pages'            => $bool( 'page_comments' ),
				'comments_per_page'            => $int( 'comments_per_page', 1, 200 ),
				'comment_order'                => $enum(
					'comment_order',
					array(
						'oldest first' => 'asc',
						'newest first' => 'desc',
					)
				),
				'comments_first_page'          => $enum(
					'default_comments_page',
					array(
						'newest' => 'newest',
						'oldest' => 'oldest',
					)
				),
				'close_old_comments'           => $bool( 'close_comments_for_old_posts' ),
				'close_comments_after_days'    => $int( 'close_comments_days_old', 1, 3650 ),
				'show_avatars'                 => $bool( 'show_avatars' ),
				'comment_moderation_words'     => array(
					'option' => 'moderation_keys',
					'type'   => 'lines',
					'tier'   => self::CONFIRM,
				),
				'comment_blocklist_words'      => array(
					'option' => 'disallowed_keys',
					'type'   => 'lines',
					'tier'   => self::CONFIRM,
				),
				// Media.
				'thumbnail_width'              => $int( 'thumbnail_size_w', 0, 4096 ),
				'thumbnail_height'             => $int( 'thumbnail_size_h', 0, 4096 ),
				'thumbnail_crop'               => $bool( 'thumbnail_crop' ),
				'medium_width'                 => $int( 'medium_size_w', 0, 4096 ),
				'medium_height'                => $int( 'medium_size_h', 0, 4096 ),
				'large_width'                  => $int( 'large_size_w', 0, 4096 ),
				'large_height'                 => $int( 'large_size_h', 0, 4096 ),
				// Permalinks.
				'permalinks'                   => $enum(
					'permalink_structure',
					array(
						'post name'      => '/%postname%/',
						'day and name'   => '/%year%/%monthnum%/%day%/%postname%/',
						'month and name' => '/%year%/%monthnum%/%postname%/',
						'numeric'        => '/archives/%post_id%',
					),
					self::CONFIRM
				),
				// Mody's own screens.
				'login_color_scheme'           => array(
					'option' => Schemes::OPTION,
					'type'   => 'scheme',
					'tier'   => self::DIRECT,
				),
				'other_languages'              => array(
					'option' => 'mody_languages',
					'type'   => 'locales',
					'tier'   => self::CONFIRM,
				),
			),
			$brand // Your brand (2026-11-11): brand_vision, brand_offer…
		);
	}

	/**
	 * Settings asked for that are refused, with why: so the agent can say it.
	 *
	 * @return array<string, string>
	 */
	public static function refused(): array {
		return array(
			'admin_email'   => __( 'The administration email is changed by the owner in Settings → General, with a confirmation email.', 'mody_cloud' ),
			'language'      => __( 'The site\'s default language is set when the site is created. Other languages can be added (other_languages).', 'mody_cloud' ),
			'site_address'  => __( 'The site\'s address cannot be changed here.', 'mody_cloud' ),
			'users'         => __( 'Users and roles are managed on the Users screen, not by Mody.', 'mody_cloud' ),
			'plugins'       => __( 'Plugins are managed by the platform.', 'mody_cloud' ),
			'mail_server'   => __( 'Posting by email is not available.', 'mody_cloud' ),
			'logo'          => __( 'The logo and the site icon are set on the Identity screen.', 'mody_cloud' ),
		);
	}

	/**
	 * Permission callback of mody/update-settings.
	 */
	public static function can_update(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * The settings in `changes` that wait for the owner's confirmation.
	 *
	 * @param mixed $input The ability's input.
	 * @return string[]
	 */
	public static function to_confirm( $input ): array {
		$changes = is_array( $input ) && is_array( $input['changes'] ?? null ) ? $input['changes'] : array();
		$specs   = self::specs();
		return array_values( array_filter( array_keys( $changes ), static fn( $name ): bool => self::CONFIRM === ( $specs[ $name ]['tier'] ?? '' ) ) );
	}

	/**
	 * A setting as the owner reads it: the name of its value, not what is stored.
	 *
	 * @param string $name A setting.
	 * @return mixed
	 */
	public static function current( string $name ) {
		$spec   = self::specs()[ $name ];
		$stored = get_option( $spec['option'] );
		switch ( $spec['type'] ) {
			case 'bool':
				return (string) $stored === (string) $spec['on'];
			case 'int':
				return (int) $stored;
			case 'enum':
				$found = array_search( (string) $stored, array_map( 'strval', $spec['values'] ), true );
				return false === $found ? (string) $stored : (string) $found;
			case 'category':
				$term = get_term( (int) $stored, 'category' );
				return $term instanceof \WP_Term ? $term->name : '';
			case 'homepage':
				return 'page' === get_option( 'show_on_front' ) && $stored ? get_the_title( (int) $stored ) : self::LATEST;
			case 'page':
				return $stored ? get_the_title( (int) $stored ) : self::NONE;
			case 'lines':
				return array_values( array_filter( array_map( 'trim', explode( "\n", (string) $stored ) ), 'strlen' ) );
			case 'scheme':
				return Schemes::chosen();
			case 'locales':
				return array_values( array_map( 'strval', (array) $stored ) );
			case 'brand':
				return BrandBook::display( $spec['field'] );
			default:
				return wp_specialchars_decode( (string) $stored, ENT_QUOTES );
		}
	}

	/**
	 * What a value may be, in a few words, for the agent's prompt.
	 *
	 * @param string $name A setting.
	 */
	public static function allowed( string $name ): string {
		$spec = self::specs()[ $name ];
		switch ( $spec['type'] ) {
			case 'bool':
				return 'true or false';
			case 'int':
				return $spec['min'] . ' to ' . $spec['max'];
			case 'enum':
				return implode( ', ', array_keys( $spec['values'] ) );
			case 'text':
				return 'text, at most ' . $spec['max'] . ' characters';
			case 'timezone':
				return 'a timezone such as Europe/Madrid';
			case 'category':
				return 'an existing category\'s name';
			case 'homepage':
				return '"' . self::LATEST . '" or a published page\'s title';
			case 'page':
				return '"' . self::NONE . '" or a published page\'s title';
			case 'lines':
				return 'a list of words or phrases, one per item; an empty list clears it';
			case 'scheme':
				return implode( ', ', array_keys( Schemes::for_palette( Schemes::palette() ) ) );
			case 'locales':
				$offered = class_exists( \Mody\Languages\Languages::class ) ? array_keys( \Mody\Languages\Languages::OFFERED ) : array();
				return 'a list of locales from ' . implode( ', ', $offered ) . ', besides the default language';
			case 'brand':
				return BrandBook::allowed( $spec['field'] );
		}
		return '';
	}

	/**
	 * Checks one value and gives what to store, or why not.
	 *
	 * @param string $name  A setting.
	 * @param mixed  $value The value asked for.
	 * @return mixed|WP_Error The value to store.
	 */
	public static function check( string $name, $value ) {
		$spec = self::specs()[ $name ];
		/* translators: 1: a setting, 2: what it may be. */
		$wrong = static fn(): WP_Error => new WP_Error( 'mody_setting_invalid', sprintf( __( '%1$s must be %2$s.', 'mody_cloud' ), $name, self::allowed( $name ) ) );
		switch ( $spec['type'] ) {
			case 'bool':
				// filter_var() reads '' as false: refuse it, and anything not a word or a number.
				$bool = is_bool( $value ) ? $value : ( is_scalar( $value ) && '' !== trim( (string) $value ) ? filter_var( (string) $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null );
				return null === $bool ? $wrong() : ( $bool ? $spec['on'] : $spec['off'] );
			case 'int':
				$int = filter_var( $value, FILTER_VALIDATE_INT );
				return false === $int || $int < $spec['min'] || $int > $spec['max'] ? $wrong() : $int;
			case 'enum':
				$key = is_scalar( $value ) ? strtolower( trim( str_replace( '_', ' ', (string) $value ) ) ) : '';
				foreach ( $spec['values'] as $label => $stored ) {
					if ( strtolower( (string) $label ) === $key ) {
						return $stored;
					}
				}
				return $wrong();
			case 'text':
				$text = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
				if ( 'title' === $name && '' === $text ) {
					return new WP_Error( 'mody_empty_title', __( 'The site title cannot be empty.', 'mody_cloud' ) );
				}
				return mb_strlen( $text ) > $spec['max'] ? $wrong() : $text;
			case 'timezone':
				return is_string( $value ) && in_array( $value, timezone_identifiers_list(), true ) ? $value : $wrong();
			case 'category':
				$term = is_scalar( $value ) ? get_term_by( 'name', (string) $value, 'category' ) : false;
				return $term instanceof \WP_Term ? (int) $term->term_id : $wrong();
			case 'homepage':
			case 'page':
				$title = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( strtolower( $title ) === ( 'homepage' === $spec['type'] ? self::LATEST : self::NONE ) ) {
					return 0;
				}
				$page = '' !== $title ? SiteEditor::match_page( 0, $title ) : new WP_Error( 'mody_page_not_found', '' );
				if ( is_wp_error( $page ) || 'publish' !== $page->post_status ) {
					/* translators: 1: a setting, 2: a page title. */
					return new WP_Error( 'mody_setting_page', sprintf( __( '%1$s must be a published page; "%2$s" is not one.', 'mody_cloud' ), $name, $title ) );
				}
				return (int) $page->ID;
			case 'lines':
				$items = is_array( $value ) ? $value : ( is_string( $value ) ? explode( "\n", $value ) : null );
				if ( null === $items || count( $items ) > self::MAX_LINES ) {
					return $wrong();
				}
				return implode( "\n", array_values( array_filter( array_map( static fn( $line ): string => sanitize_text_field( is_scalar( $line ) ? (string) $line : '' ), $items ), 'strlen' ) ) );
			case 'scheme':
				return is_string( $value ) && isset( Schemes::for_palette( Schemes::palette() )[ strtolower( $value ) ] ) ? strtolower( $value ) : $wrong();
			case 'locales':
				if ( ! is_array( $value ) || ! class_exists( \Mody\Languages\Languages::class ) ) {
					return $wrong();
				}
				$offered = array_keys( \Mody\Languages\Languages::OFFERED );
				$locales = array_map( static fn( $locale ): string => is_scalar( $locale ) ? (string) $locale : '', $value );
				return array_diff( $locales, $offered ) ? $wrong() : array_values( array_unique( $locales ) );
			case 'brand':
				return BrandBook::check_field( $spec['field'], $value );
		}
		return $wrong();
	}

	/**
	 * Execute callback of mody/update-settings.
	 *
	 * @param mixed $input `changes`: setting => value.
	 * @return array<string, mixed>|WP_Error `changed` (setting => old, new) and `screen`.
	 */
	public static function update( $input ): array|WP_Error {
		$changes = is_array( $input ) && is_array( $input['changes'] ?? null ) ? $input['changes'] : array();
		if ( ! $changes ) {
			return new WP_Error( 'mody_no_settings', __( 'Give the settings to change and their new values.', 'mody_cloud' ) );
		}
		$specs   = self::specs();
		$refused = self::refused();
		$values  = array();
		$errors  = array();
		foreach ( $changes as $name => $value ) {
			$name = (string) $name;
			if ( ! isset( $specs[ $name ] ) ) {
				/* translators: %s: a setting's name. */
				$errors[] = $refused[ $name ] ?? sprintf( __( 'There is no setting "%s" Mody can change.', 'mody_cloud' ), $name );
				continue;
			}
			$checked = self::check( $name, $value );
			if ( is_wp_error( $checked ) ) {
				$errors[] = $checked->get_error_message();
				continue;
			}
			$values[ $name ] = $checked;
		}
		if ( $errors ) {
			return new WP_Error( 'mody_settings_refused', __( 'Nothing was changed.', 'mody_cloud' ) . ' ' . implode( ' ', $errors ) );
		}

		$changed = array();
		foreach ( $values as $name => $value ) {
			$old = self::current( $name );
			$ok  = self::save( $name, $value );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$changed[ $name ] = array(
				'old' => $old,
				'new' => self::current( $name ),
			);
		}
		if ( isset( $values['permalinks'] ) ) {
			flush_rewrite_rules( false );
		}
		return array( 'changed' => $changed );
	}

	/**
	 * Saves one checked value, and reads it back.
	 *
	 * @param string $name  A setting.
	 * @param mixed  $value What check() gave.
	 * @return true|WP_Error
	 */
	private static function save( string $name, $value ): bool|WP_Error {
		$spec = self::specs()[ $name ];
		switch ( $spec['type'] ) {
			case 'homepage':
				update_option( 'show_on_front', $value ? 'page' : 'posts' );
				update_option( 'page_on_front', $value );
				$ok = (int) get_option( 'page_on_front' ) === (int) $value;
				break;
			case 'locales':
				\Mody\Languages\Languages::save( $value );
				$ok = array_values( (array) get_option( $spec['option'] ) ) === array_values( array_intersect( array_keys( \Mody\Languages\Languages::OFFERED ), $value ) );
				break;
			case 'scheme':
				update_option( $spec['option'], $value );
				$ok = Schemes::chosen() === $value;
				break;
			case 'brand':
				$ok = BrandBook::update( array( $spec['field'] => $value ) )[ $spec['field'] ] === $value;
				break;
			case 'enum':
				if ( 'permalink_structure' === $spec['option'] ) {
					// As Settings → Permalinks does: the rewrite object holds the structure in memory.
					global $wp_rewrite;
					$wp_rewrite->set_permalink_structure( $value );
					$ok = get_option( 'permalink_structure' ) === $value;
					break;
				}
				update_option( $spec['option'], $value );
				$ok = (string) get_option( $spec['option'] ) === (string) sanitize_option( $spec['option'], $value );
				break;
			default:
				update_option( $spec['option'], $value );
				// update_option() stores sanitize_option()'s result, and returns false when nothing changed.
				$ok = (string) get_option( $spec['option'] ) === (string) sanitize_option( $spec['option'], $value );
		}
		/* translators: %s: a setting's name. */
		return $ok ? true : new WP_Error( 'mody_setting_not_saved', sprintf( __( '%s could not be saved. Please try again.', 'mody_cloud' ), $name ) );
	}

	/**
	 * The settings and their current values, for the agent's system prompt:
	 * one line each, the ones that need the owner's confirmation marked.
	 */
	public static function catalog(): string {
		$lines = array();
		foreach ( array_keys( self::specs() ) as $name ) {
			if ( 'brand' === self::specs()[ $name ]['type'] ) {
				$lines[] = '- ' . $name . ' (' . self::allowed( $name ) . ')'; // Its value is in <brand>, when filled in.
				continue;
			}
			$value   = self::current( $name );
			$shown   = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : ( is_array( $value ) ? '[' . implode( ', ', $value ) . ']' : '"' . mb_strimwidth( (string) $value, 0, 80, '…' ) . '"' );
			$lines[] = '- ' . $name . ' = ' . $shown . ' (' . self::allowed( $name ) . ')' . ( self::CONFIRM === self::specs()[ $name ]['tier'] ? ' [confirm]' : '' );
		}
		return implode( "\n", $lines );
	}
}
