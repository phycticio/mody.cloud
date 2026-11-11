<?php
/**
 * What the owner tells Mody about the business: the site setting `mody_brand`.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Brand;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asked for by the maintainer on 2026-11-11 (tickets/your-brand-page.md).
 * The brand WordPress has no place for (its voice, audience, offer, story,
 * values and facts) is one site setting, an object with a fixed list of
 * fields. The name, tagline, logo, mission and tone stay in their own
 * settings (BrandSettings): this never copies them.
 *
 * Read when a prompt is built (SystemPrompt::brand_context()), changed on
 * Settings → Brand (BrandPage) and by the agent (mody/update-settings, the
 * `brand_*` settings), shown in REST (`/wp/v2/settings`, `manage_options`).
 *
 * Saving the whole option replaces it (sanitize()); update() changes some
 * fields and keeps the others.
 */
final class BrandBook {

	/** The site setting. */
	public const OPTION = 'mody_brand';

	/** The forms of "you" a brand may choose. */
	public const ADDRESS_FORMS = array( 'formal', 'informal' );

	/**
	 * The fields: key => section, type and limits.
	 *
	 * - `text`: one line, at most `max` characters;
	 * - `textarea`: a few lines, at most `max` characters;
	 * - `enum`: one of `values`, or '' (not chosen);
	 * - `email`: an email address, or '';
	 * - `words`: up to `items` words or short phrases of at most `max` characters;
	 * - `items`: up to `items` entries, each a name (at most `name`
	 *   characters) and one line (at most `max`).
	 */
	public const FIELDS = array(
		'vision'         => array(
			'section' => 'identity',
			'type'    => 'text',
			'max'     => 200,
		),
		'voice'          => array(
			'section' => 'voice',
			'type'    => 'textarea',
			'max'     => 400,
		),
		'address_form'   => array(
			'section' => 'voice',
			'type'    => 'enum',
			'values'  => self::ADDRESS_FORMS,
		),
		'words_to_use'   => array(
			'section' => 'voice',
			'type'    => 'words',
			'items'   => 15,
			'max'     => 30,
		),
		'words_to_avoid' => array(
			'section' => 'voice',
			'type'    => 'words',
			'items'   => 15,
			'max'     => 30,
		),
		'audience'       => array(
			'section' => 'audience',
			'type'    => 'textarea',
			'max'     => 400,
		),
		'audience_needs' => array(
			'section' => 'audience',
			'type'    => 'textarea',
			'max'     => 400,
		),
		'offer'          => array(
			'section' => 'offer',
			'type'    => 'items',
			'items'   => 12,
			'name'    => 80,
			'max'     => 120,
		),
		'difference'     => array(
			'section' => 'offer',
			'type'    => 'textarea',
			'max'     => 400,
		),
		'story'          => array(
			'section' => 'story',
			'type'    => 'textarea',
			'max'     => 600,
		),
		'values'         => array(
			'section' => 'story',
			'type'    => 'items',
			'items'   => 6,
			'name'    => 60,
			'max'     => 120,
		),
		'address'        => array(
			'section' => 'facts',
			'type'    => 'textarea',
			'max'     => 200,
		),
		'phone'          => array(
			'section' => 'facts',
			'type'    => 'text',
			'max'     => 40,
		),
		'email'          => array(
			'section' => 'facts',
			'type'    => 'email',
			'max'     => 100,
		),
		'opening_hours'  => array(
			'section' => 'facts',
			'type'    => 'textarea',
			'max'     => 300,
		),
		'area_served'    => array(
			'section' => 'facts',
			'type'    => 'text',
			'max'     => 200,
		),
	);

	/**
	 * `init`: the setting, in REST, for every site. Its schema lists every
	 * field with its limits, so `/wp/v2/settings` refuses anything else.
	 */
	public static function register(): void {
		register_setting(
			'mody_brand',
			self::OPTION,
			array(
				'type'              => 'object',
				'label'             => __( 'Your brand', 'mody_cloud' ),
				'description'       => __( 'What the Mody agent knows about the business: its voice, audience, offer, story, values and facts.', 'mody_cloud' ),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::sanitize( array() ), // Every field, empty.
				'show_in_rest'      => array( 'schema' => self::schema() ),
			)
		);
	}

	/**
	 * The REST schema of the setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$properties = array();
		foreach ( self::FIELDS as $key => $field ) {
			switch ( $field['type'] ) {
				case 'enum':
					$properties[ $key ] = array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), $field['values'] ),
					);
					break;
				case 'words':
					$properties[ $key ] = array(
						'type'     => 'array',
						'maxItems' => $field['items'],
						'items'    => array(
							'type'      => 'string',
							'maxLength' => $field['max'],
						),
					);
					break;
				case 'items':
					$properties[ $key ] = array(
						'type'     => 'array',
						'maxItems' => $field['items'],
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'name'        => array(
									'type'      => 'string',
									'maxLength' => $field['name'],
								),
								'description' => array(
									'type'      => 'string',
									'maxLength' => $field['max'],
								),
							),
							'additionalProperties' => false,
						),
					);
					break;
				default:
					$properties[ $key ] = array(
						'type'      => 'string',
						'maxLength' => $field['max'],
					);
			}
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * The sections, in order: slug => label. Overview and Visual style hold
	 * no field of their own.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array(
			'overview' => __( 'Overview', 'mody_cloud' ),
			'identity' => __( 'Identity', 'mody_cloud' ),
			'voice'    => __( 'Voice', 'mody_cloud' ),
			'audience' => __( 'Audience', 'mody_cloud' ),
			'offer'    => __( 'Offer', 'mody_cloud' ),
			'story'    => __( 'Story and values', 'mody_cloud' ),
			'facts'    => __( 'Facts', 'mody_cloud' ),
			'visual'   => __( 'Visual style', 'mody_cloud' ),
		);
	}

	/**
	 * The fields' labels and help, for the page.
	 *
	 * @return array<string, array{label: string, help: string}>
	 */
	public static function labels(): array {
		return array(
			'vision'         => array(
				'label' => __( 'Vision', 'mody_cloud' ),
				'help'  => __( 'Where the business is going, in one sentence.', 'mody_cloud' ),
			),
			'voice'          => array(
				'label' => __( 'How we sound', 'mody_cloud' ),
				'help'  => __( 'For example: short sentences, no jargon, a touch of humor.', 'mody_cloud' ),
			),
			'address_form'   => array(
				'label' => __( 'How we address people', 'mody_cloud' ),
				'help'  => __( 'In languages with a formal and an informal "you" (usted or tú, Sie or du, vous or tu).', 'mody_cloud' ),
			),
			'words_to_use'   => array(
				'label' => __( 'Words we use', 'mody_cloud' ),
				'help'  => __( 'Words and short phrases that sound like the brand.', 'mody_cloud' ),
			),
			'words_to_avoid' => array(
				'label' => __( 'Words we avoid', 'mody_cloud' ),
				'help'  => __( 'Words and short phrases the brand never uses.', 'mody_cloud' ),
			),
			'audience'       => array(
				'label' => __( 'Who our customers are', 'mody_cloud' ),
				'help'  => __( 'For example: families in the neighborhood, small companies, cyclists who commute.', 'mody_cloud' ),
			),
			'audience_needs' => array(
				'label' => __( 'What they need', 'mody_cloud' ),
				'help'  => __( 'The problem the business solves for them, or what they come for.', 'mody_cloud' ),
			),
			'offer'          => array(
				'label' => __( 'Products and services', 'mody_cloud' ),
				'help'  => __( 'Each one with a name and one line about it.', 'mody_cloud' ),
			),
			'difference'     => array(
				'label' => __( 'What makes us different', 'mody_cloud' ),
				'help'  => __( 'Why customers choose this business and not another one.', 'mody_cloud' ),
			),
			'story'          => array(
				'label' => __( 'Our story', 'mody_cloud' ),
				'help'  => __( 'How the business started and where it is today, in a short paragraph.', 'mody_cloud' ),
			),
			'values'         => array(
				'label' => __( 'Our values', 'mody_cloud' ),
				'help'  => __( 'Each one with a name and one line about it.', 'mody_cloud' ),
			),
			'address'        => array(
				'label' => __( 'Address', 'mody_cloud' ),
				'help'  => __( 'Where customers find the business.', 'mody_cloud' ),
			),
			'phone'          => array(
				'label' => __( 'Phone', 'mody_cloud' ),
				'help'  => '',
			),
			'email'          => array(
				'label' => __( 'Email', 'mody_cloud' ),
				'help'  => __( 'The address customers write to.', 'mody_cloud' ),
			),
			'opening_hours'  => array(
				'label' => __( 'Opening hours', 'mody_cloud' ),
				'help'  => __( 'For example: Monday to Friday 9:00 to 18:00, Saturday 10:00 to 14:00.', 'mody_cloud' ),
			),
			'area_served'    => array(
				'label' => __( 'Area served', 'mody_cloud' ),
				'help'  => __( 'For example: Barcelona and its area, or all of Spain.', 'mody_cloud' ),
			),
		);
	}

	/**
	 * The brand of the current site, every field present.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * The setting's sanitize callback: every known field, normalized and cut
	 * to its limits; unknown keys dropped; missing fields empty.
	 *
	 * @param mixed $value The value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$clean = array();
		foreach ( self::FIELDS as $key => $field ) {
			$clean[ $key ] = self::sanitize_field( $key, $value[ $key ] ?? null );
		}
		return $clean;
	}

	/**
	 * Changes some fields and keeps the others.
	 *
	 * @param array<string, mixed> $changes Field => value; unknown fields are ignored.
	 * @return array<string, mixed> The brand after the change.
	 */
	public static function update( array $changes ): array {
		$brand = self::get();
		foreach ( array_intersect_key( $changes, self::FIELDS ) as $key => $value ) {
			$brand[ $key ] = self::sanitize_field( $key, $value );
		}
		update_option( self::OPTION, $brand );
		return self::get();
	}

	/**
	 * One field, normalized and cut to its limits (the page's forms).
	 *
	 * @param string $key   A field.
	 * @param mixed  $value Its value.
	 * @return string|array<int, mixed>
	 */
	public static function sanitize_field( string $key, $value ) {
		$field = self::FIELDS[ $key ];
		switch ( $field['type'] ) {
			case 'textarea':
				return self::cut( self::lines( is_scalar( $value ) ? (string) $value : '' ), $field['max'] );
			case 'enum':
				return is_string( $value ) && in_array( $value, $field['values'], true ) ? $value : '';
			case 'email':
				$email = sanitize_email( is_scalar( $value ) ? (string) $value : '' );
				return is_email( $email ) && mb_strlen( $email ) <= $field['max'] ? $email : '';
			case 'words':
				$words = array();
				foreach ( self::list_of( $value, true ) as $word ) {
					$word = self::cut( self::text( is_scalar( $word ) ? (string) $word : '' ), $field['max'] );
					if ( '' !== $word && ! in_array( mb_strtolower( $word ), array_map( 'mb_strtolower', $words ), true ) ) {
						$words[] = $word;
					}
				}
				return array_slice( $words, 0, $field['items'] );
			case 'items':
				$items = array();
				foreach ( self::list_of( $value, false ) as $item ) {
					$item = self::item( $item );
					$name = self::cut( self::text( $item['name'] ), $field['name'] );
					if ( '' !== $name ) {
						$items[] = array(
							'name'        => $name,
							'description' => self::cut( self::text( $item['description'] ), $field['max'] ),
						);
					}
				}
				return array_slice( $items, 0, $field['items'] );
			default:
				return self::cut( self::text( is_scalar( $value ) ? (string) $value : '' ), $field['max'] );
		}
	}

	/**
	 * One line of plain text: sanitize_text_field(), whose entities (an
	 * unmatched "<" escapes the rest of the line) are decoded again, since
	 * every screen escapes on output and prompts want text.
	 *
	 * @param string $text Text.
	 */
	private static function text( string $text ): string {
		return wp_specialchars_decode( sanitize_text_field( $text ), ENT_QUOTES );
	}

	/**
	 * One field for the agent (mody/update-settings): what to store, or why
	 * not. Unlike the page's forms, nothing is cut: a value over a limit is
	 * refused, so Mody can shorten it itself.
	 *
	 * @param string $key   A field.
	 * @param mixed  $value Its value: text, a list of words, or a list of
	 *                      items ("Name: one line", or {name, description}).
	 * @return string|array<int, mixed>|WP_Error
	 */
	public static function check_field( string $key, $value ) {
		$field   = self::FIELDS[ $key ];
		$setting = 'brand_' . $key;
		/* translators: 1: a setting, 2: what it may be. */
		$wrong = static fn(): WP_Error => new WP_Error( 'mody_setting_invalid', sprintf( __( '%1$s must be %2$s.', 'mody_cloud' ), $setting, self::allowed( $key ) ) );
		if ( null === $value ) {
			$value = in_array( $field['type'], array( 'words', 'items' ), true ) ? array() : '';
		}
		switch ( $field['type'] ) {
			case 'enum':
				$choice = is_string( $value ) ? strtolower( trim( $value ) ) : null;
				$choice = 'none' === $choice ? '' : $choice;
				return null !== $choice && ( '' === $choice || in_array( $choice, $field['values'], true ) ) ? $choice : $wrong();
			case 'email':
				$email = is_string( $value ) ? trim( $value ) : null;
				return null !== $email && ( '' === $email || ( is_email( $email ) && mb_strlen( $email ) <= $field['max'] ) ) ? sanitize_email( $email ) : $wrong();
			case 'words':
				$list = ( is_array( $value ) && array_is_list( $value ) ) || is_string( $value ) ? self::list_of( $value, true ) : null;
				if ( null === $list || count( $list ) > $field['items'] ) {
					return $wrong();
				}
				foreach ( $list as $word ) {
					if ( ! is_scalar( $word ) || mb_strlen( trim( (string) $word ) ) > $field['max'] ) {
						return $wrong();
					}
				}
				return self::sanitize_field( $key, $list );
			case 'items':
				if ( is_array( $value ) && isset( $value['name'] ) && ! array_is_list( $value ) ) {
					$value = array( $value ); // One item sent alone.
				}
				$list = ( is_array( $value ) && array_is_list( $value ) ) || is_string( $value ) ? self::list_of( $value, false ) : null;
				if ( null === $list || count( $list ) > $field['items'] ) {
					return $wrong();
				}
				foreach ( $list as $item ) {
					$item = self::item( $item );
					if ( '' === trim( $item['name'] ) || mb_strlen( trim( $item['name'] ) ) > $field['name'] || mb_strlen( trim( $item['description'] ) ) > $field['max'] ) {
						return $wrong();
					}
				}
				return self::sanitize_field( $key, $list );
			default:
				if ( ! is_scalar( $value ) ) {
					return $wrong();
				}
				$text = 'textarea' === $field['type'] ? self::lines( (string) $value ) : self::text( (string) $value );
				return mb_strlen( $text ) > $field['max'] ? $wrong() : $text;
		}
	}

	/**
	 * What a field may be, in a few words, for the agent's prompt.
	 *
	 * @param string $key A field.
	 */
	public static function allowed( string $key ): string {
		$field = self::FIELDS[ $key ];
		switch ( $field['type'] ) {
			case 'enum':
				return implode( ', ', $field['values'] ) . ' or none';
			case 'email':
				return 'an email address, or empty';
			case 'words':
				return 'the whole list, up to ' . $field['items'] . ' words or short phrases, each at most ' . $field['max'] . ' characters';
			case 'items':
				return 'the whole list, up to ' . $field['items'] . ' items, each {"name": at most ' . $field['name'] . ' characters, "description": one line, at most ' . $field['max'] . ' characters, or ""}';
			default:
				return 'text, at most ' . $field['max'] . ' characters';
		}
	}

	/**
	 * A field as the agent reads and writes it: text, a list of words, or a
	 * list of {name, description} (objects, so a name with a colon in it
	 * survives the round trip).
	 *
	 * @param string $key A field.
	 * @return string|array<int, mixed>
	 */
	public static function display( string $key ) {
		return self::get()[ $key ];
	}

	/**
	 * Whether a field holds something.
	 *
	 * @param mixed $value A field's value.
	 */
	public static function filled( $value ): bool {
		return is_array( $value ) ? array() !== $value : '' !== trim( (string) $value );
	}

	/**
	 * The sections with something filled in. Identity counts its mission
	 * and vision (the name is always there); Voice its own fields (the tone
	 * always has a value).
	 *
	 * @return string[]
	 */
	public static function filled_sections(): array {
		$brand  = self::get();
		$filled = array();
		foreach ( self::FIELDS as $key => $field ) {
			if ( self::filled( $brand[ $key ] ) ) {
				$filled[ $field['section'] ] = true;
			}
		}
		if ( '' !== BrandSettings::brand()['mission'] ) {
			$filled['identity'] = true;
		}
		return array_values( array_intersect( array_keys( self::sections() ), array_keys( $filled ) ) );
	}

	/**
	 * The sections the owner fills in (they count toward "filled in").
	 *
	 * @return string[]
	 */
	public static function editable_sections(): array {
		return array( 'identity', 'voice', 'audience', 'offer', 'story', 'facts' );
	}

	/**
	 * The brand's lines for the prompts' `<brand>` block, filled fields only,
	 * one per line (a list's items on their own lines).
	 *
	 * @return string[]
	 */
	public static function context_lines(): array {
		$brand  = self::get();
		$labels = array(
			'vision'         => 'Vision',
			'voice'          => 'How the brand sounds',
			'words_to_use'   => 'Words to use',
			'words_to_avoid' => 'Words to avoid',
			'audience'       => 'Who the customers are',
			'audience_needs' => 'What the customers need',
			'offer'          => 'Products and services',
			'difference'     => 'What makes the brand different',
			'story'          => 'Story',
			'values'         => 'Values',
			'address'        => 'Address',
			'phone'          => 'Phone',
			'email'          => 'Email',
			'opening_hours'  => 'Opening hours',
			'area_served'    => 'Area served',
		);
		$joins  = array(
			'address'       => ', ',
			'opening_hours' => '; ',
		);
		$lines  = array();
		foreach ( $labels as $key => $label ) {
			$value = $brand[ $key ];
			if ( ! self::filled( $value ) ) {
				continue;
			}
			switch ( self::FIELDS[ $key ]['type'] ) {
				case 'words':
					$lines[] = $label . ': ' . implode( ', ', $value );
					break;
				case 'items':
					$lines[] = $label . ':';
					foreach ( $value as $item ) {
						// The name quoted, so a name with a colon in it reads as one name.
						$lines[] = '- ' . wp_json_encode( $item['name'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ( '' !== $item['description'] ? ': ' . $item['description'] : '' );
					}
					break;
				default:
					$lines[] = $label . ': ' . implode( $joins[ $key ] ?? ' ', explode( "\n", (string) $value ) );
			}
		}
		return $lines;
	}

	/**
	 * Plain text with line breaks: tags gone, at most one empty line in a row.
	 *
	 * @param string $text Text.
	 */
	private static function lines( string $text ): string {
		$text = wp_specialchars_decode( sanitize_textarea_field( str_replace( array( "\r\n", "\r" ), "\n", $text ) ), ENT_QUOTES );
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * Text cut to a length.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 */
	private static function cut( string $text, int $max ): string {
		return trim( mb_substr( $text, 0, $max ) );
	}

	/**
	 * A list from an array, or from text with one entry per line (and, for
	 * words, separated by commas too).
	 *
	 * @param mixed $value  The value.
	 * @param bool  $commas Whether commas separate entries.
	 * @return array<int, mixed>
	 */
	private static function list_of( $value, bool $commas ): array {
		if ( is_array( $value ) ) {
			return array_values( $value );
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', (array) preg_split( $commas ? '/[\n,]+/' : '/\n+/', $value ) ), 'strlen' ) );
	}

	/**
	 * An item as name and description, from {name, description} or from
	 * "Name: one line".
	 *
	 * @param mixed $item The item.
	 * @return array{name: string, description: string}
	 */
	private static function item( $item ): array {
		if ( is_array( $item ) ) {
			return array(
				'name'        => is_scalar( $item['name'] ?? null ) ? (string) $item['name'] : '',
				'description' => is_scalar( $item['description'] ?? null ) ? (string) $item['description'] : '',
			);
		}
		$text  = is_scalar( $item ) ? trim( (string) $item ) : '';
		$parts = preg_split( str_contains( $text, ': ' ) || str_ends_with( $text, ':' ) ? '/\s*:(?:\s+|$)/u' : '/\s+[-–—]\s+/u', $text, 2 ); // "Opening: 9:00" splits at the first ": " only.
		return array(
			'name'        => (string) ( $parts[0] ?? '' ),
			'description' => (string) ( $parts[1] ?? '' ),
		);
	}
}
