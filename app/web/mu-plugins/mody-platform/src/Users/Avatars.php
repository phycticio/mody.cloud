<?php
/**
 * Profile pictures stored on the main site, and no Gravatar
 * (tickets/network-profile-local-avatars.md).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Users;

use Mody\Platform\Media\ImageSize;
use WP_Comment;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-11: "Remove gravatar and replace it
 * with a user picture that can be added locally." Gravatar is Automattic's
 * (US, rule 1).
 *
 * - **The picture** is uploaded on the main site's profile screen, stored in
 *   the main site's media library and owned by the user. The user meta
 *   META holds its attachment ID and URLS its URLs per size, so other sites
 *   show it without switching to the main site.
 * - **Every avatar** is answered here (`pre_get_avatar_data`), before core
 *   builds a Gravatar URL: the user's picture, or their initials, drawn as
 *   an SVG by this platform at `/mody-avatar/…` (visitors who comment too).
 *   Nothing is fetched from anywhere else.
 */
final class Avatars {

	/** User meta: the picture's attachment ID, on the main site. */
	public const META = 'mody_avatar';

	/** User meta: the picture's URLs, size name => URL. */
	public const URLS = 'mody_avatar_urls';

	/** The upload field, and the "remove" checkbox. */
	public const FIELD  = 'mody_avatar';
	public const REMOVE = 'mody_avatar_remove';

	/** Accepted images. */
	public const TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'gif'          => 'image/gif',
	);

	/** Largest upload, in bytes. */
	public const MAX_BYTES = 2097152;

	/** The stored picture's side, at most, in pixels. */
	public const SIZE = 512;

	/** Attachment meta: whose picture it is. */
	public const OWNER = '_mody_avatar_of';

	/** Initials backgrounds: muted, all readable with white text (4.5:1 or more). */
	private const BACKGROUNDS = array( '#475569', '#4d5b7c', '#5b5f97', '#6b4f7a', '#7a4f5c', '#7a5a3a', '#4f6b4f', '#3f6b6b' );

	/**
	 * Filters `pre_get_avatar_data`: the user's picture, or their initials.
	 *
	 * @param array<string, mixed> $args        The avatar's arguments.
	 * @param mixed                $id_or_email A user ID, email, WP_User, WP_Post or WP_Comment.
	 * @return array<string, mixed>
	 */
	public static function avatar_data( $args, $id_or_email ): array {
		$args = (array) $args;
		$size = max( 1, (int) ( $args['size'] ?? 96 ) );
		list( $user, $name, $email ) = self::who( $id_or_email );

		$url = $user ? self::picture_url( $user->ID, $size ) : '';
		if ( '' !== $url && empty( $args['force_default'] ) ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
			return $args;
		}
		$args['url']          = self::initials_url( '' !== $name ? $name : $email, '' !== $email ? $email : $name );
		$args['found_avatar'] = false;
		return $args;
	}

	/**
	 * The user, name and email behind what core passes.
	 *
	 * @param mixed $id_or_email A user ID, email, WP_User, WP_Post or WP_Comment.
	 * @return array{0: WP_User|null, 1: string, 2: string}
	 */
	private static function who( $id_or_email ): array {
		$user = null;
		$name = '';
		if ( $id_or_email instanceof WP_Comment ) {
			$user = $id_or_email->user_id ? get_userdata( (int) $id_or_email->user_id ) : null;
			$name = (string) $id_or_email->comment_author;
			$id_or_email = (string) $id_or_email->comment_author_email;
		} elseif ( $id_or_email instanceof WP_Post ) {
			$user = get_userdata( (int) $id_or_email->post_author );
		} elseif ( $id_or_email instanceof WP_User ) {
			$user = $id_or_email;
		} elseif ( is_numeric( $id_or_email ) ) {
			$user = get_userdata( (int) $id_or_email );
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email ) ?: null;
		}
		$user  = $user instanceof WP_User ? $user : null;
		$email = $user ? (string) $user->user_email : ( is_string( $id_or_email ) ? $id_or_email : '' );
		if ( $user ) {
			$name = trim( (string) $user->display_name ) !== '' ? (string) $user->display_name : (string) $user->user_login;
		}
		return array( $user, $name, $email );
	}

	/**
	 * The user's picture at the nearest size, or ''.
	 *
	 * @param int $user_id The user.
	 * @param int $size    Pixels.
	 */
	public static function picture_url( int $user_id, int $size ): string {
		$urls = get_user_meta( $user_id, self::URLS, true );
		if ( ! is_array( $urls ) || ! $urls ) {
			return '';
		}
		$pick = $size <= 150 && isset( $urls['thumbnail'] ) ? 'thumbnail' : ( $size <= 300 && isset( $urls['medium'] ) ? 'medium' : 'full' );
		return (string) ( $urls[ $pick ] ?? reset( $urls ) );
	}

	/** Where initials are served, on every site: `/mody-avatar/<color>/<letters>.svg`. */
	public const PATH = '/mody-avatar/';

	/**
	 * Initials as an image address: two letters, white on a color chosen
	 * from the email (the same person looks the same everywhere). Served by
	 * serve_initials(), drawn here, never fetched from anywhere.
	 *
	 * @param string $name A name, or an email.
	 * @param string $seed What picks the color.
	 */
	public static function initials_url( string $name, string $seed ): string {
		$name    = trim( (string) preg_replace( '/@.*$/', '', wp_specialchars_decode( $name, ENT_QUOTES ) ) );
		$words   = preg_split( '/[\s._-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$letters = '';
		foreach ( array_slice( $words, 0, 2 ) as $word ) {
			$letter   = mb_strtoupper( mb_substr( $word, 0, 1 ) );
			$letters .= preg_match( '/^[\p{L}\p{N}]$/u', $letter ) ? $letter : '';
		}
		$color = abs( crc32( strtolower( trim( $seed ) ) ) ) % count( self::BACKGROUNDS );
		return network_home_url( self::PATH . $color . '/' . rawurlencode( '' !== $letters ? $letters : '-' ) . '.svg' );
	}

	/**
	 * The color and letters an initials path asks for, or null when it is not one.
	 *
	 * @param string $path A request path, such as `/mody-avatar/3/AL.svg`.
	 * @return array{0: int, 1: string}|null
	 */
	public static function initials_request( string $path ): ?array {
		if ( ! preg_match( '#^' . preg_quote( self::PATH, '#' ) . '([0-9])/([^/]{1,40})\.svg$#', $path, $match ) ) {
			return null;
		}
		$letters = rawurldecode( $match[2] );
		if ( (int) $match[1] >= count( self::BACKGROUNDS ) || ( '-' !== $letters && ! preg_match( '/^[\p{L}\p{N}]{1,2}$/u', $letters ) ) ) {
			return null;
		}
		return array( (int) $match[1], '-' === $letters ? '' : $letters );
	}

	/**
	 * The initials drawing.
	 *
	 * @param int    $color   An index of BACKGROUNDS.
	 * @param string $letters One or two letters or digits; '' for a person sign.
	 */
	public static function svg( int $color, string $letters ): string {
		$fill = self::BACKGROUNDS[ $color ] ?? self::BACKGROUNDS[0];
		$mark = '' === $letters
			? '<circle cx="32" cy="25" r="11" fill="#fff"/><path d="M12 56c2-11 10-17 20-17s18 6 20 17z" fill="#fff"/>'
			: '<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif" font-size="' . ( mb_strlen( $letters ) > 1 ? 26 : 30 ) . '" font-weight="600" fill="#fff">' . htmlspecialchars( $letters, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</text>';
		return '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" fill="' . $fill . '"/>' . $mark . '</svg>';
	}

	/**
	 * Serves an initials image, when this request is for one, and stops.
	 * Called when the MU plugin loads, before the rest of WordPress runs
	 * (like Design\Patterns::serve_placeholder()).
	 */
	public static function serve_initials(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		// The path is decoded by initials_request(), which accepts only letters and digits.
		$path    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request = self::initials_request( $path );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || null === $request ) {
			return;
		}
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'HEAD' !== $method ) {
			echo self::svg( $request[0], $request[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Letters escaped in svg().
		}
		exit;
	}

	/**
	 * Filters `avatar_defaults` (Settings → Discussion): one choice, the
	 * initials; core's others are drawn by Gravatar.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array( 'mystery' => __( 'Initials (drawn on this site, no Gravatar)', 'mody_cloud' ) );
	}

	/**
	 * Whether the upload controls belong on this screen: the main site, the
	 * network's user dashboard or Network Admin (all on the main site).
	 */
	private static function on_main_site(): bool {
		return ! is_multisite() || is_main_site();
	}

	/**
	 * Hooked to `user_edit_form_tag`: the profile form takes files.
	 */
	public static function form_tag(): void {
		if ( self::on_main_site() ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/**
	 * Filters `user_profile_picture_description`: the upload controls, in
	 * place of core's "change it on Gravatar".
	 *
	 * @param string  $description Core's text.
	 * @param WP_User $user        The user.
	 */
	public static function description( $description, $user ): string {
		if ( ! $user instanceof WP_User || ! self::on_main_site() ) {
			return (string) $description;
		}
		return self::controls( $user );
	}

	/**
	 * Hooked to `show_user_profile` and `edit_user_profile`: the controls in
	 * their own section when the main site does not show avatars (core then
	 * hides its Profile Picture row).
	 *
	 * @param WP_User $user The user.
	 */
	public static function section( $user ): void {
		if ( ! $user instanceof WP_User || ! self::on_main_site() || get_option( 'show_avatars' ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Profile Picture' ) . '</h2><table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Profile Picture' ) . '</th><td>' // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string.
			. get_avatar( $user->ID ) . '<p class="description">' . self::controls( $user ) . '</p></td></tr></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
	}

	/**
	 * The upload field, and Remove when there is a picture.
	 *
	 * @param WP_User $user The user.
	 */
	private static function controls( WP_User $user ): string {
		$html  = esc_html__( 'Your picture on every Mody Cloud site. A JPEG, PNG, WebP or GIF image of at most 2 MB; a square one looks best.', 'mody_cloud' );
		$html .= '<br><label for="' . esc_attr( self::FIELD ) . '" class="screen-reader-text">' . esc_html__( 'Upload a profile picture', 'mody_cloud' ) . '</label>';
		$html .= '<input type="file" id="' . esc_attr( self::FIELD ) . '" name="' . esc_attr( self::FIELD ) . '" accept="image/jpeg,image/png,image/webp,image/gif" />';
		if ( get_user_meta( $user->ID, self::META, true ) ) {
			$html .= '<br><label><input type="checkbox" name="' . esc_attr( self::REMOVE ) . '" value="1" /> ' . esc_html__( 'Remove the picture (your initials are shown instead)', 'mody_cloud' ) . '</label>';
		}
		return $html;
	}

	/**
	 * Hooked to `personal_options_update` and `edit_user_profile_update`
	 * (after core's nonce and `edit_user` checks): saves or removes the picture.
	 *
	 * @param int $user_id The user.
	 */
	public static function save( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! self::on_main_site() || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Core checked `update-user_{id}` before this action.
		if ( ! empty( $_POST[ self::REMOVE ] ) ) {
			self::remove( $user_id );
		}
		$file = $_FILES[ self::FIELD ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Checked below and by wp_handle_upload().
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $file ) || UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return;
		}
		$result = self::store( $user_id, $file );
		if ( is_wp_error( $result ) ) {
			add_action(
				'user_profile_update_errors',
				static function ( $errors ) use ( $result ) {
					$errors->add( 'mody_avatar', $result->get_error_message() );
				}
			);
		}
	}

	/**
	 * Stores an uploaded picture for a user on the main site, replacing the old one.
	 *
	 * @param int                  $user_id The user.
	 * @param array<string, mixed> $file    One entry of `$_FILES`.
	 * @return int|\WP_Error The attachment ID.
	 */
	public static function store( int $user_id, array $file ) {
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
			return new \WP_Error( 'mody_avatar_size', __( 'The picture is larger than 2 MB.', 'mody_cloud' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp  = (string) ( $file['tmp_name'] ?? '' );
		$type = wp_check_filetype_and_ext( $tmp, (string) ( $file['name'] ?? '' ), self::TYPES );
		if ( '' === $tmp || ! $type['type'] ) {
			return new \WP_Error( 'mody_avatar_type', __( 'The picture must be a JPEG, PNG, WebP or GIF image.', 'mody_cloud' ) );
		}
		// A few kilobytes can say they are tens of thousands of pixels wide: look at the header before anything decodes it. After the type check, so only the four formats PHP sizes without decoding get here (ImageSize).
		if ( ImageSize::too_big( $tmp, ImageSize::PROFILE_MAX_SIDE ) ) {
			return new \WP_Error(
				'mody_avatar_dimensions',
				/* translators: %s: pixels on a side. */
				sprintf( __( 'The picture is too large: it can be up to %s pixels on a side.', 'mody_cloud' ), number_format_i18n( ImageSize::PROFILE_MAX_SIDE ) )
			);
		}
		$clean = is_uploaded_file( $tmp ) ? self::square( $tmp, (string) $type['type'] ) : null;
		if ( ! $clean ) {
			return new \WP_Error( 'mody_avatar_type', __( 'The picture must be a JPEG, PNG, WebP or GIF image.', 'mody_cloud' ) );
		}

		$switched = is_multisite() && ! is_main_site();
		if ( $switched ) {
			switch_to_blog( get_main_site_id() );
		}
		$attachment_id = media_handle_sideload(
			array(
				'name'     => 'profile-picture-' . $user_id . '.' . $clean['ext'],
				'tmp_name' => $clean['path'],
			),
			0,
			__( 'Profile picture', 'mody_cloud' ),
			array( 'post_author' => $user_id )
		);
		if ( is_file( $clean['path'] ) ) {
			wp_delete_file( $clean['path'] ); // Left behind only when the sideload failed.
		}
		if ( ! is_wp_error( $attachment_id ) ) {
			update_post_meta( (int) $attachment_id, self::OWNER, $user_id );
			$old = (int) get_user_meta( $user_id, self::META, true );
			if ( $old && $old !== (int) $attachment_id ) {
				wp_delete_attachment( $old, true );
			}
			$urls = array();
			foreach ( array( 'thumbnail', 'medium', 'full' ) as $size ) {
				$url = wp_get_attachment_image_url( (int) $attachment_id, $size );
				if ( $url ) {
					$urls[ $size ] = $url;
				}
			}
			update_user_meta( $user_id, self::META, (int) $attachment_id );
			update_user_meta( $user_id, self::URLS, $urls );
		}
		if ( $switched ) {
			restore_current_blog();
		}
		return $attachment_id;
	}

	/**
	 * The upload drawn again: centered and cropped square, at most SIZE pixels,
	 * as a new file with none of the original's metadata (GD writes none: no
	 * camera, place or date). PNG, GIF and WebP stay PNG for transparency;
	 * JPEG stays JPEG.
	 *
	 * @param string $path The uploaded file.
	 * @param string $mime Its checked type.
	 * @return array{path: string, ext: string}|null
	 */
	private static function square( string $path, string $mime ): ?array {
		$gd = static fn(): array => array( 'WP_Image_Editor_GD' );
		add_filter( 'wp_image_editors', $gd );
		$editor = wp_get_image_editor( $path );
		remove_filter( 'wp_image_editors', $gd );
		if ( is_wp_error( $editor ) ) {
			return null;
		}
		$size = $editor->get_size();
		$side = min( (int) $size['width'], (int) $size['height'] );
		if ( $side < 1 ) {
			return null;
		}
		$to = min( $side, self::SIZE );
		$editor->crop( (int) floor( ( $size['width'] - $side ) / 2 ), (int) floor( ( $size['height'] - $side ) / 2 ), $side, $side, $to, $to );
		$ext   = 'image/jpeg' === $mime ? 'jpg' : 'png';
		$saved = $editor->save( wp_tempnam( 'mody-avatar' ) . '.' . $ext, 'jpg' === $ext ? 'image/jpeg' : 'image/png' );
		return is_wp_error( $saved ) ? null : array(
			'path' => (string) $saved['path'],
			'ext'  => $ext,
		);
	}

	/**
	 * Filters `ajax_query_attachments_args` and `rest_attachment_query`: the
	 * pictures stay out of the main site's media lists (they are shown as
	 * avatars, not offered as media).
	 *
	 * @param array<string, mixed> $args The query.
	 * @return array<string, mixed>
	 */
	public static function hide_from_media( $args ): array {
		$args                 = (array) $args;
		$args['meta_query']   = (array) ( $args['meta_query'] ?? array() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small table on the main site.
		$args['meta_query'][] = array(
			'key'     => self::OWNER,
			'compare' => 'NOT EXISTS',
		);
		return $args;
	}

	/**
	 * Hooked to `delete_attachment`: a picture deleted from the media library
	 * is no longer its owner's avatar.
	 *
	 * @param int $attachment_id The attachment.
	 */
	public static function forget_attachment( $attachment_id ): void {
		$owner = (int) get_post_meta( (int) $attachment_id, self::OWNER, true );
		if ( $owner && (int) get_user_meta( $owner, self::META, true ) === (int) $attachment_id ) {
			delete_user_meta( $owner, self::META );
			delete_user_meta( $owner, self::URLS );
		}
	}

	/**
	 * Hooked to `wpmu_delete_user` and `delete_user`: a deleted person's
	 * picture goes with them (personal data).
	 *
	 * @param int $user_id The user.
	 */
	public static function forget_user( $user_id ): void {
		self::remove( (int) $user_id );
	}

	/**
	 * Removes a user's picture: the meta and the attachment on the main site.
	 *
	 * @param int $user_id The user.
	 */
	public static function remove( int $user_id ): void {
		$old = (int) get_user_meta( $user_id, self::META, true );
		if ( $old ) {
			$switched = is_multisite() && ! is_main_site();
			if ( $switched ) {
				switch_to_blog( get_main_site_id() );
			}
			wp_delete_attachment( $old, true );
			if ( $switched ) {
				restore_current_blog();
			}
		}
		delete_user_meta( $user_id, self::META );
		delete_user_meta( $user_id, self::URLS );
	}
}
