<?php
/**
 * Addresses: the language prefix.
 *
 * - **Reading:** before WordPress parses a front-end request, a prefix of one
 *   of the site's other languages (/es/...) is removed from REQUEST_URI and
 *   becomes the request's language. Once parsed, REQUEST_URI is put back, so
 *   core's canonical redirects compare the real address. A top-level page in
 *   the default language whose slug is a prefix keeps its address (pages
 *   made before the language was added); new top-level pages cannot take an
 *   offered prefix as their slug.
 * - **The language's home page:** /es/ shows the translation of the front
 *   page (with its page number and preview), or redirects to / when there
 *   is none. On a site whose front page lists the posts, /es/ lists them.
 *   The translation is treated as a front page: the Front Page template, the
 *   site's title and tagline as the document title, the home body class.
 * - **Links:** a translation's permalink carries its prefix; the front page's
 *   translation is /es/. url_to_postid() understands prefixed addresses.
 * - **The wrong address:** a page or post asked for under another prefix (or
 *   none), and the front page's translation asked for by its slug, are
 *   redirected (301) to their own address, keeping the page number and the
 *   query string. A page in a language the site no longer has is served
 *   where it is, never redirected.
 *
 * The language comes only from the address, never from a cookie or the
 * browser, so caches in front of WordPress stay correct.
 *
 * @package Mody\Languages
 */

namespace Mody\Languages;

use WP;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the language from the address and puts it into links.
 */
final class Router {

	/** The request's language; '' until a request is read (the default). */
	private static string $current = '';

	/** REQUEST_URI as it arrived, while WordPress parses the stripped one. */
	private static ?string $original_uri = null;

	/** The front page's translation shown as the language's home page, or 0. */
	private static int $front_translation = 0;

	/**
	 * Hooked on load.
	 */
	public static function register(): void {
		add_filter( 'do_parse_request', array( self::class, 'read_prefix' ), 1, 2 );
		add_action( 'parse_request', array( self::class, 'after_parse' ), 1 );
		add_action( 'template_redirect', array( self::class, 'redirect_wrong_address' ), 1 );
		add_filter( 'page_link', array( self::class, 'page_link' ), 20, 2 );
		add_filter( 'post_link', array( self::class, 'post_link' ), 20, 2 );
		add_filter( 'url_to_postid', array( self::class, 'url_to_postid' ) );
		add_filter( 'wp_unique_post_slug_is_bad_hierarchical_slug', array( self::class, 'reserved_slug' ), 10, 4 );
		add_filter( 'wp_unique_post_slug_is_bad_flat_slug', array( self::class, 'reserved_post_slug' ), 10, 3 );
		add_filter( 'page_template_hierarchy', array( self::class, 'front_page_template' ) );
		add_filter( 'document_title_parts', array( self::class, 'front_page_title' ) );
		add_filter( 'body_class', array( self::class, 'front_page_body_class' ) );
	}

	/**
	 * The request's language: one of the site's languages.
	 */
	public static function current(): string {
		return '' === self::$current ? Languages::default_locale() : self::$current;
	}

	/**
	 * Whether the request is in one of the site's other languages.
	 */
	public static function is_other(): bool {
		return '' !== self::$current;
	}

	/**
	 * Sets the request's language (tests; the switcher's preview).
	 *
	 * @param string $locale A locale; '' for the default language.
	 */
	public static function set_current( string $locale ): void {
		self::$current = Languages::is_other( $locale ) ? $locale : '';
	}

	/**
	 * A language's home page address: / for the default, /es/ otherwise.
	 *
	 * @param string $locale A locale.
	 */
	public static function home( string $locale ): string {
		$home = trailingslashit( home_url() );
		return Languages::is_other( $locale ) ? $home . Languages::slug( $locale ) . '/' : $home;
	}

	/**
	 * The path of the request inside the site, without the site's own path
	 * (production's main site lives under /wp), and its query string.
	 *
	 * @param string $uri A REQUEST_URI or an address on this site.
	 * @return array{0: string, 1: string, 2: string} [site path, path inside it, query with its ?].
	 */
	public static function split_uri( string $uri ): array {
		$site_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$path      = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query     = str_contains( $uri, '?' ) ? substr( $uri, strpos( $uri, '?' ) ) : '';
		$path      = ltrim( $path, '/' );
		if ( '' !== $site_path && ( $path === $site_path || str_starts_with( $path, $site_path . '/' ) ) ) {
			$path = substr( $path, strlen( $site_path ) );
		}
		return array( '' === $site_path ? '/' : '/' . $site_path . '/', ltrim( $path, '/' ), $query );
	}

	/**
	 * The language whose prefix starts a path inside the site, or ''. A
	 * top-level page in the default language with that slug wins.
	 *
	 * @param string $path A path inside the site, without a leading slash.
	 * @return array{0: string, 1: string} [locale, the path without the prefix].
	 */
	public static function prefix_of( string $path ): array {
		$first  = (string) strtok( $path, '/' );
		$locale = '' === $first ? '' : Languages::from_slug( $first );
		if ( '' === $locale ) {
			return array( '', $path );
		}
		$page = get_page_by_path( $first );
		if ( $page instanceof WP_Post && 0 === (int) $page->post_parent && Translations::language( $page ) === Languages::default_locale() ) {
			return array( '', $path );
		}
		return array( $locale, ltrim( substr( $path, strlen( $first ) ), '/' ) );
	}

	/**
	 * `do_parse_request`: reads and removes the language prefix.
	 *
	 * @param bool $parse Whether WordPress parses the request.
	 * @param WP   $wp    The request.
	 */
	public static function read_prefix( $parse, $wp ) {
		self::$current           = '';
		self::$front_translation = 0;
		if ( ! $parse || is_admin() || ! Languages::is_multilingual() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return $parse;
		}
		$uri                          = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only split and compared.
		[ $site_path, $path, $query ] = self::split_uri( $uri );
		[ $locale, $rest ]            = self::prefix_of( $path );
		if ( '' === $locale ) {
			return $parse;
		}
		self::$current          = $locale;
		self::$original_uri     = $uri;
		$_SERVER['REQUEST_URI'] = $site_path . $rest . $query;
		return $parse;
	}

	/**
	 * `parse_request`: puts REQUEST_URI back, and points the language's home
	 * page (and its page numbers: /es/2/, /es/page/2/) to the front page's
	 * translation.
	 *
	 * @param WP $wp The request.
	 */
	public static function after_parse( WP $wp ): void {
		if ( null !== self::$original_uri ) {
			$_SERVER['REQUEST_URI'] = self::$original_uri;
			self::$original_uri     = null;
		}
		if ( ! self::is_other() || 'page' !== get_option( 'show_on_front' ) ) {
			return;
		}
		$request = trim( (string) $wp->request, '/' );
		$extra   = array_diff_key( $wp->query_vars, array_flip( array( 'page', 'paged', 'preview' ) ) );
		if ( ! preg_match( '#^(?:(?:page/)?(\d+))?$#', $request, $match ) || ( array() !== $extra && ! ( 1 === count( $extra ) && isset( $extra['pagename'] ) && ctype_digit( (string) $extra['pagename'] ) ) ) ) {
			return;
		}
		$front = (int) get_option( 'page_on_front' );
		$page  = $front ? Translations::in( $front, self::$current ) : 0;
		if ( ! $page ) {
			wp_safe_redirect( self::home( Languages::default_locale() ), 302 );
			exit;
		}
		$number = max( (int) ( $match[1] ?? 0 ), (int) ( $wp->query_vars['page'] ?? 0 ), (int) ( $wp->query_vars['paged'] ?? 0 ) );
		$vars   = array( 'page_id' => $page );
		if ( $number > 1 ) {
			$vars['page'] = $number;
		}
		if ( isset( $wp->query_vars['preview'] ) ) {
			$vars['preview'] = $wp->query_vars['preview'];
		}
		$wp->query_vars          = $vars;
		self::$front_translation = $page;
	}

	/**
	 * Whether the request shows the front page's translation at /es/.
	 */
	public static function is_front_translation(): bool {
		return 0 !== self::$front_translation && is_page( self::$front_translation );
	}

	/**
	 * `template_redirect`: a page or post asked for at an address that is not
	 * its own goes there (301): the wrong language, or the front page's
	 * translation by its slug.
	 */
	public static function redirect_wrong_address(): void {
		if ( ! Languages::is_multilingual() || ! is_singular( Translations::POST_TYPES ) || is_preview() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! in_array( Translations::language( $post ), Languages::site_locales(), true ) ) {
			return;
		}
		$wrong_language = Translations::language( $post ) !== self::current();
		$front_by_slug  = ! self::is_front_translation() && self::home( self::current() ) === self::localize( '', $post->ID ) && Translations::source( $post ) !== $post->ID;
		if ( ! $wrong_language && ! $front_by_slug ) {
			return;
		}

		$target = (string) get_permalink( $post );
		$number = (int) get_query_var( 'page' );
		if ( $number > 1 && '' !== $target && get_option( 'permalink_structure' ) ) {
			$target = trailingslashit( $target ) . user_trailingslashit( (string) $number, 'single_paged' );
		}
		$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passed on as it came.
		if ( '' !== $query ) {
			$target .= ( str_contains( $target, '?' ) ? '&' : '?' ) . $query;
		}
		$here = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared only.
		if ( '' === $target || (string) wp_parse_url( $target, PHP_URL_PATH ) . ( '' !== $query ? '?' . $query : '' ) === $here ) {
			return;
		}
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * `page_link`.
	 *
	 * @param string $link    The page's address.
	 * @param int    $post_id The page.
	 */
	public static function page_link( $link, $post_id ) {
		return self::localize( (string) $link, (int) $post_id );
	}

	/**
	 * `post_link`.
	 *
	 * @param string  $link The post's address.
	 * @param WP_Post $post The post.
	 */
	public static function post_link( $link, $post ) {
		return $post instanceof WP_Post ? self::localize( (string) $link, $post->ID ) : $link;
	}

	/**
	 * Adds a translation's prefix to its address; the front page's
	 * translation is the language's home page. Nothing to do (and no query)
	 * on a site with one language.
	 *
	 * @param string $link    An address on this site.
	 * @param int    $post_id The post it leads to.
	 */
	public static function localize( string $link, int $post_id ): string {
		if ( ! Languages::is_multilingual() ) {
			return $link;
		}
		$locale = (string) get_post_meta( $post_id, Translations::LANG, true );
		if ( '' === $locale || ! Languages::is_other( $locale ) ) {
			return $link;
		}
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		if ( $front && $front !== $post_id && Translations::source( $post_id ) === $front && 'publish' === get_post_status( $post_id ) ) {
			return self::home( $locale );
		}
		$home = untrailingslashit( home_url() );
		if ( ! str_starts_with( $link, $home . '/' ) && ! str_starts_with( $link, $home . '?' ) ) {
			return $link;
		}
		$rest = substr( $link, strlen( $home ) );
		return $home . '/' . Languages::slug( $locale ) . ( str_starts_with( $rest, '?' ) ? '/' : '' ) . $rest;
	}

	/**
	 * `url_to_postid`: a prefixed address is read without its prefix.
	 *
	 * @param string $url An address.
	 */
	public static function url_to_postid( $url ) {
		if ( ! Languages::is_multilingual() || ! is_string( $url ) ) {
			return $url;
		}
		$home = untrailingslashit( home_url() );
		$bare = (string) preg_replace( '#^https?:#', '', $url );
		if ( ! str_starts_with( $bare, (string) preg_replace( '#^https?:#', '', $home ) . '/' ) ) {
			return $url;
		}
		[ , $path, $query ] = self::split_uri( $url );
		[ $locale, $rest ]  = self::prefix_of( $path );
		return '' === $locale ? $url : $home . '/' . $rest . $query;
	}

	/**
	 * `wp_unique_post_slug_is_bad_hierarchical_slug`: a top-level page may not
	 * take an offered language's prefix as its slug.
	 *
	 * @param bool   $bad       Whether the slug is refused.
	 * @param string $slug      The slug.
	 * @param string $post_type The post type.
	 * @param int    $parent    The parent's ID.
	 */
	public static function reserved_slug( $bad, $slug, $post_type, $parent ) {
		if ( 'page' !== $post_type || (int) $parent ) {
			return $bad;
		}
		$prefixes = array_map( static fn( array $language ): string => $language[0], Languages::OFFERED );
		return $bad || in_array( (string) $slug, $prefixes, true );
	}

	/**
	 * `wp_unique_post_slug_is_bad_flat_slug`: nor a post (with a /%postname%/
	 * structure, its address would be the prefix).
	 *
	 * @param bool   $bad       Whether the slug is refused.
	 * @param string $slug      The slug.
	 * @param string $post_type The post type.
	 */
	public static function reserved_post_slug( $bad, $slug, $post_type ) {
		return 'post' === $post_type ? self::reserved_slug( $bad, $slug, 'page', 0 ) : $bad;
	}

	/**
	 * `page_template_hierarchy`: the front page's translation uses the Front
	 * Page template, like the front page.
	 *
	 * @param string[] $templates Template slugs.
	 * @return string[]
	 */
	public static function front_page_template( $templates ) {
		return self::is_front_translation() ? array_merge( array( 'front-page' ), (array) $templates ) : $templates;
	}

	/**
	 * `document_title_parts`: the front page's translation is titled like the
	 * front page (the site's title and tagline).
	 *
	 * @param array<string, string> $parts The title's parts.
	 * @return array<string, string>
	 */
	public static function front_page_title( $parts ) {
		if ( ! self::is_front_translation() ) {
			return $parts;
		}
		return array_filter(
			array(
				'title'   => get_bloginfo( 'name', 'display' ),
				'tagline' => get_bloginfo( 'description', 'display' ),
			)
		);
	}

	/**
	 * `body_class`: the front page's translation has the home class.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function front_page_body_class( $classes ) {
		return self::is_front_translation() ? array_merge( (array) $classes, array( 'home' ) ) : $classes;
	}
}
