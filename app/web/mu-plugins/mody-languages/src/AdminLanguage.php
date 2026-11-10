<?php
/**
 * The admin's working language: the content wp-admin and the editors show.
 *
 * - **The choice:** per user and per site (a user option), one of the site's
 *   languages or "all"; the default language until the user picks another.
 *   It changes from the toolbar's Language menu in wp-admin, and from the ⋮
 *   menu of the Site Editor and the post editor (assets/admin-language.js).
 *   The interface itself stays English.
 * - **Filtered by it:** the Pages and Posts lists (with Quick Edit's parent
 *   choices), the REST collections of pages and posts the editors load (the
 *   Site Editor's page list, the parent picker) and the editors' link search. Only for a logged-in user who can
 *   edit posts, and never a lookup by ID or slug.
 * - **Not filtered:** single posts, the translation matrix, the agent (it
 *   queries WordPress directly, not through REST), WP-CLI, front-end pages,
 *   templates, parts, menus and patterns.
 * - **New content:** a page or post created while another language than the
 *   default is chosen is in that language (a standalone page, without an
 *   original), decided 2026-11-10.
 * - **The lists** show a language bar with counts and a Languages column:
 *   each translation to edit, or one to create.
 *
 * Nothing of this runs on a site with one language.
 *
 * @package Mody\Languages
 */

namespace Mody\Languages;

use WP_Admin_Bar;
use WP_Post;
use WP_Query;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The working language in wp-admin and the editors.
 */
final class AdminLanguage {

	/** The user option (per site) holding the choice. */
	public const OPTION = 'mody_admin_language';

	/** The choice that shows every language. */
	public const ALL = 'all';

	public const SCRIPT = 'mody-admin-language';

	/**
	 * Hooked on load.
	 */
	public static function register(): void {
		add_action( 'admin_bar_menu', array( self::class, 'toolbar' ), 100 );
		add_action( 'admin_post_mody_admin_language', array( self::class, 'switch_language' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_list' ) );
		add_filter( 'rest_page_query', array( self::class, 'filter_rest' ), 10, 2 );
		add_filter( 'rest_post_query', array( self::class, 'filter_rest' ), 10, 2 );
		add_filter( 'rest_post_search_query', array( self::class, 'filter_rest' ), 10, 2 );
		add_filter( 'get_pages', array( self::class, 'filter_parent_choices' ), 10, 2 );
		add_action( 'wp_insert_post', array( self::class, 'language_of_new' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_script' ) );
		foreach ( Translations::POST_TYPES as $type ) {
			add_filter( "views_edit-$type", array( self::class, 'language_bar' ) );
			add_filter( "manage_{$type}_posts_columns", array( self::class, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( self::class, 'column' ), 10, 2 );
		}
	}

	/**
	 * The current user's working language on this site: a site language, or
	 * "all". The default language when nothing (or a removed language) is
	 * stored.
	 */
	public static function current(): string {
		$user = get_current_user_id();
		$lang = $user ? (string) get_user_option( self::OPTION, $user ) : '';
		if ( self::ALL === $lang || in_array( $lang, Languages::site_locales(), true ) ) {
			return $lang;
		}
		return Languages::default_locale();
	}

	/**
	 * Saves the current user's working language.
	 *
	 * @param string $lang A site language, or "all".
	 */
	public static function set( string $lang ): bool {
		$user = get_current_user_id();
		if ( ! $user || ( self::ALL !== $lang && ! in_array( $lang, Languages::site_locales(), true ) ) ) {
			return false;
		}
		return (bool) update_user_option( $user, self::OPTION, $lang );
	}

	/**
	 * Whether the working language filters content for this user.
	 */
	public static function filters(): bool {
		return Languages::is_multilingual() && is_user_logged_in() && current_user_can( 'edit_posts' ) && self::ALL !== self::current();
	}

	/**
	 * The language list a post is in: its own when that is one of the site's
	 * other languages, else the default one (no language, the default's own
	 * tag, or a language the site no longer has). Every post is in exactly
	 * one list.
	 *
	 * @param int|WP_Post $post A post.
	 */
	public static function list_of( int|WP_Post $post ): string {
		$lang = Translations::language( $post );
		return Languages::is_other( $lang ) ? $lang : Languages::default_locale();
	}

	/**
	 * The meta query that keeps one language's list (list_of()).
	 *
	 * @param string $lang A site language.
	 * @return array<int|string, mixed>
	 */
	public static function meta_query( string $lang ): array {
		if ( Languages::default_locale() === $lang ) {
			return array(
				'relation' => 'OR',
				array(
					'key'     => Translations::LANG,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Translations::LANG,
					'value'   => Languages::others(),
					'compare' => 'NOT IN',
				),
			);
		}
		return array(
			'key'   => Translations::LANG,
			'value' => $lang,
		);
	}

	/**
	 * Adds the language to a query's arguments, keeping its own meta query.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	private static function with_language( array $args ): array {
		$clause = self::meta_query( self::current() );
		$args['meta_query'] = empty( $args['meta_query'] ) ? array( $clause ) : array( 'relation' => 'AND', $clause, $args['meta_query'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The lists are per site and small.
		return $args;
	}

	/**
	 * The address that switches the working language, then comes back.
	 *
	 * @param string $lang     A site language, or "all".
	 * @param string $redirect Where to come back.
	 */
	public static function switch_url( string $lang, string $redirect = '' ): string {
		$args = array(
			'action' => 'mody_admin_language',
			'lang'   => $lang,
		);
		if ( '' !== $redirect ) {
			$args['redirect'] = rawurlencode( $redirect );
		}
		// Not wp_nonce_url(): it escapes & for HTML, and the editors' script uses the address as it is.
		$args['_wpnonce'] = wp_create_nonce( 'mody_admin_language' );
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * The address of the current request, absolute.
	 */
	public static function here(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated on the way back.
		return set_url_scheme( 'http://' . wp_parse_url( admin_url(), PHP_URL_HOST ) . $uri );
	}

	/**
	 * A choice's name: "All languages" or the language's own name.
	 *
	 * @param string $lang A site language, or "all".
	 */
	public static function label( string $lang ): string {
		return self::ALL === $lang ? __( 'All languages', 'mody_cloud' ) : Languages::label( $lang );
	}

	/**
	 * `admin_bar_menu`: Language: Español, with every choice below it.
	 *
	 * @param WP_Admin_Bar $bar The toolbar.
	 */
	public static function toolbar( WP_Admin_Bar $bar ): void {
		if ( ! is_admin() || ! Languages::is_multilingual() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$here    = self::here();
		$current = self::current();
		$bar->add_node(
			array(
				'id'    => 'mody-admin-language',
				/* translators: %s: a language's own name, or "All languages". */
				'title' => esc_html( sprintf( __( 'Language: %s', 'mody_cloud' ), self::label( $current ) ) ),
				'href'  => current_user_can( 'manage_options' ) ? SettingsPage::url() : false, // Settings → Languages is for administrators.
				'meta'  => array( 'title' => __( 'The language of the content you see and create', 'mody_cloud' ) ),
			)
		);
		foreach ( array_merge( Languages::site_locales(), array( self::ALL ) ) as $lang ) {
			$bar->add_node(
				array(
					'parent' => 'mody-admin-language',
					'id'     => 'mody-admin-language-' . sanitize_key( $lang ),
					'title'  => esc_html( ( $lang === $current ? '✓ ' : '' ) . self::label( $lang ) ),
					'href'   => self::switch_url( $lang, $here ),
				)
			);
		}
	}

	/**
	 * `admin_post_mody_admin_language`: saves the choice, then goes back.
	 */
	public static function switch_language(): void {
		check_admin_referer( 'mody_admin_language' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You cannot change the content language.', 'mody_cloud' ), 403 );
		}
		self::set( sanitize_text_field( wp_unslash( $_GET['lang'] ?? '' ) ) );
		$back = (string) wp_unslash( $_GET['redirect'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		$back = '' !== trim( $back ) ? wp_validate_redirect( remove_query_arg( 'paged', $back ), '' ) : '';
		wp_safe_redirect( '' !== $back ? $back : admin_url() ); // wp_validate_redirect() gives '' back for '', not the fallback.
		exit;
	}

	/**
	 * `pre_get_posts`: the Pages and Posts lists show the working language.
	 * Not the Trash: its Empty Trash button deletes every language's.
	 *
	 * @param WP_Query $query A query.
	 */
	public static function filter_list( WP_Query $query ): void {
		global $pagenow;
		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== $pagenow || ! self::filters() ) {
			return;
		}
		$type = $query->get( 'post_type' );
		$type = $type ? $type : 'post';
		if ( ! is_string( $type ) || ! in_array( $type, Translations::POST_TYPES, true ) || 'trash' === $query->get( 'post_status' ) ) {
			return;
		}
		$query->set( 'meta_query', self::with_language( array( 'meta_query' => $query->get( 'meta_query' ) ) )['meta_query'] );
	}

	/**
	 * `get_pages`: on the Pages and Posts lists, Quick Edit's parent choices
	 * are in the working language. Nowhere else in wp-admin: Settings →
	 * Reading must offer every page as the front page.
	 *
	 * @param \WP_Post[]           $pages Pages.
	 * @param array<string, mixed> $args  get_pages() arguments.
	 * @return \WP_Post[]
	 */
	public static function filter_parent_choices( $pages, $args = array() ) {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! is_array( $pages ) || ! self::filters() || ! in_array( (string) ( $args['post_type'] ?? 'page' ), Translations::POST_TYPES, true ) ) {
			return $pages;
		}
		$lang = self::current();
		return array_values( array_filter( $pages, static fn( $page ): bool => $page instanceof WP_Post && self::list_of( $page ) === $lang ) );
	}

	/**
	 * `rest_page_query`, `rest_post_query`, `rest_post_search_query`: the
	 * collections and the link search the editors load. Lookups by ID or slug
	 * are left alone, and so are other post types in a search.
	 *
	 * @param array<string, mixed> $args    WP_Query arguments.
	 * @param WP_REST_Request      $request The request.
	 * @return array<string, mixed>
	 */
	public static function filter_rest( $args, $request ) {
		if ( ! is_array( $args ) || ! self::filters() || ! $request instanceof WP_REST_Request ) {
			return $args;
		}
		if ( ! empty( $request['include'] ) || ! empty( $request['slug'] ) || ! empty( $args['post__in'] ) || ! empty( $args['p'] ) ) {
			return $args;
		}
		$types = (array) ( $args['post_type'] ?? array() );
		if ( array() !== $types && array() !== array_diff( $types, Translations::POST_TYPES ) ) {
			return $args;
		}
		return self::with_language( $args );
	}

	/**
	 * `wp_insert_post`: a page or post created in wp-admin or an editor while
	 * another language than the default is chosen is in that language.
	 *
	 * @param int     $post_id The post.
	 * @param WP_Post $post    The post.
	 * @param bool    $update  Whether it already existed.
	 */
	public static function language_of_new( $post_id, $post, $update ): void {
		if ( $update || ! $post instanceof WP_Post || ! in_array( $post->post_type, Translations::POST_TYPES, true ) || ! self::filters() ) {
			return;
		}
		if ( ! is_admin() && ! wp_is_serving_rest_request() ) {
			return;
		}
		$lang = self::current();
		if ( Languages::is_other( $lang ) && '' === (string) get_post_meta( $post_id, Translations::LANG, true ) ) {
			update_post_meta( $post_id, Translations::LANG, $lang );
		}
	}

	/**
	 * `enqueue_block_editor_assets`: the Language entries in the ⋮ menu of the
	 * Site Editor and the post editor.
	 */
	public static function editor_script(): void {
		if ( ! Languages::is_multilingual() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$root = dirname( __DIR__ );
		$file = $root . '/assets/admin-language.js';
		wp_enqueue_script( self::SCRIPT, plugins_url( 'assets/admin-language.js', $root . '/mody-languages.php' ), array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-i18n' ), (string) filemtime( $file ), true );
		$choices = array();
		foreach ( array_merge( Languages::site_locales(), array( self::ALL ) ) as $lang ) {
			$choices[] = array(
				'label'   => self::label( $lang ),
				'current' => self::current() === $lang,
				// The editor comes back to where it is; the script adds the address.
				'url'     => self::switch_url( $lang ),
			);
		}
		wp_add_inline_script( self::SCRIPT, 'window.modyAdminLanguage = ' . wp_json_encode( array( 'choices' => $choices ) ) . ';', 'before' );
	}

	/**
	 * Every language's number of pages or posts (trash and auto-drafts left
	 * out, and other authors' private ones for those who cannot read them):
	 * locale => count, by list_of().
	 *
	 * @param string $type page or post.
	 * @return array<string, int>
	 */
	public static function counts( string $type ): array {
		global $wpdb;
		$object  = get_post_type_object( $type );
		$private = $object && current_user_can( $object->cap->read_private_posts ) ? '' : $wpdb->prepare( " AND ( p.post_status <> 'private' OR p.post_author = %d )", get_current_user_id() );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One grouped count per list screen; $private is prepared above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(m.meta_value, '') AS lang, COUNT(*) AS n FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit'){$private}
				GROUP BY lang",
				Translations::LANG,
				$type
			)
		);
		// phpcs:enable
		$counts = array_fill_keys( Languages::site_locales(), 0 );
		$all    = 0;
		foreach ( $rows as $row ) {
			$lang = Languages::is_other( (string) $row->lang ) ? (string) $row->lang : Languages::default_locale();
			$all += (int) $row->n;
			if ( isset( $counts[ $lang ] ) ) {
				$counts[ $lang ] += (int) $row->n;
			}
		}
		$counts[ self::ALL ] = $all;
		return $counts;
	}

	/**
	 * `views_edit-page`, `views_edit-post`: a language bar after the status
	 * links: "Language: All (12) · English (6) · Español (4)".
	 *
	 * @param array<string, string> $views The status links.
	 * @return array<string, string>
	 */
	public static function language_bar( $views ) {
		if ( ! Languages::is_multilingual() || ! is_array( $views ) ) {
			return $views;
		}
		$screen  = get_current_screen();
		$type    = $screen && $screen->post_type ? $screen->post_type : 'post';
		$here    = self::here();
		$current = self::current();
		$links   = array();
		foreach ( self::counts( $type ) as $lang => $count ) {
			$links[] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( self::switch_url( $lang, $here ) ),
				$lang === $current ? ' class="current" aria-current="page"' : '',
				esc_html( self::label( $lang ) ),
				$count
			);
		}
		$views['mody-languages'] = '<span class="mody-admin-languages"><strong>' . esc_html__( 'Language:', 'mody_cloud' ) . '</strong> ' . implode( ' · ', $links ) . '</span>';
		return $views;
	}

	/**
	 * `manage_page_posts_columns`, `manage_post_posts_columns`: the Languages
	 * column, after the title.
	 *
	 * @param array<string, string> $columns The columns.
	 * @return array<string, string>
	 */
	public static function columns( $columns ) {
		if ( ! Languages::is_multilingual() || ! is_array( $columns ) ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['mody_languages'] = __( 'Languages', 'mody_cloud' );
			}
		}
		return $out;
	}

	/**
	 * The Languages column: the post's own language, then each other
	 * language: its translation to edit, or one to create.
	 *
	 * @param string $column  The column.
	 * @param int    $post_id The post.
	 */
	public static function column( $column, $post_id ): void {
		if ( 'mody_languages' !== $column ) {
			return;
		}
		$own    = Translations::language( (int) $post_id );
		$group  = Translations::group( (int) $post_id );
		$object = get_post_type_object( (string) get_post_type( (int) $post_id ) );
		// The same rights SettingsPage::create() asks for; a trashed post has no translations made.
		$can_create = $object && current_user_can( 'edit_post', (int) $post_id ) && current_user_can( $object->cap->publish_posts ) && 'trash' !== get_post_status( (int) $post_id );
		$items = array( '<strong title="' . esc_attr( Languages::label( $own ) ) . '">' . esc_html( strtoupper( Languages::slug( $own ) ) ) . '</strong>' );
		foreach ( Languages::site_locales() as $locale ) {
			if ( $locale === $own ) {
				continue;
			}
			$code = esc_html( strtoupper( Languages::slug( $locale ) ) );
			$id   = $group[ $locale ] ?? 0;
			if ( $id ) {
				$state = Translations::state( $id );
				$edit  = (string) get_edit_post_link( $id, 'url' ); // Empty when the user cannot edit it.
				/* translators: 1: a language's own name; 2: draft, translated or outdated. */
				$title   = sprintf( '' !== $edit ? __( 'Edit the %1$s version (%2$s)', 'mody_cloud' ) : __( 'The %1$s version (%2$s)', 'mody_cloud' ), Languages::label( $locale ), $state );
				$items[] = '' !== $edit
					? sprintf( '<a href="%s" class="mody-language is-%s" title="%s">%s ✓</a>', esc_url( $edit ), esc_attr( $state ), esc_attr( $title ), $code )
					: sprintf( '<span class="mody-language is-%s" title="%s">%s ✓</span>', esc_attr( $state ), esc_attr( $title ), $code );
			} elseif ( $can_create && Languages::is_other( $locale ) ) {
				$items[] = sprintf(
					'<a href="%s" class="mody-language is-missing" title="%s">%s +</a>',
					esc_url( SettingsPage::create_url( (string) $post_id, $locale ) ),
					/* translators: %s: a language's own name. */
					esc_attr( sprintf( __( 'Create the %s version', 'mody_cloud' ), Languages::label( $locale ) ) ),
					$code
				);
			}
		}
		echo implode( ' ', $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
	}
}
