<?php
/**
 * Site Editor links, the site's lists, and the ability mody/open-editor.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform;

use WP_Block_Type_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds Site Editor URLs on the current site (mvp-spec section 9) and opens
 * its screens for the agent (ticket M3-09).
 *
 * WordPress 7.1 routes the Site Editor with `?p=<path>`: `/page/<id>` with
 * `canvas=edit` opens a page, `/wp_template/<theme>//<slug>` a template,
 * `/styles` the global styles, where `section=<screen>` picks a screen such
 * as `/colors`. The older `?postType=…&postId=…` form without `canvas` lands
 * on a list instead.
 *
 * Every result of mody/open-editor carries `navigate`: in the Site Editor the
 * chat bubble switches the screen in place (no page load, the chat stays
 * open); elsewhere the link is a button (decided 2026-11-08).
 */
final class SiteEditor {

	/** What mody/open-editor can open. */
	public const TARGETS = array( 'page', 'template', 'part', 'list', 'styles', 'navigation', 'patterns', 'home', 'identity' );

	/**
	 * Styles screens: key => `section` path. Additional CSS is left out:
	 * members have no `unfiltered_html` on Multisite. `stylebook` is the
	 * Style Book preview, not a section.
	 */
	public const SECTIONS = array(
		'overview'   => '/',
		'browse'     => '/variations',
		'colors'     => '/colors',
		'palette'    => '/colors/palette',
		'typography' => '/typography',
		'layout'     => '/layout',
		'blocks'     => '/blocks',
		'revisions'  => '/revisions',
		'stylebook'  => '',
	);

	/** Pages listed in a "not found" error, so the agent can ask which one. */
	private const LISTED_PAGES = 20;

	/** Page statuses found by title and listed in a "not found" error. */
	public const PAGE_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * The Site Editor URL of a page.
	 *
	 * @param int $page_id Page ID on the current site.
	 */
	public static function page_url( int $page_id ): string {
		return self::url( '/page/' . $page_id, true );
	}

	/**
	 * A Site Editor URL on the current site.
	 *
	 * @param string                $path   Route, such as `/page/12` or `/styles`.
	 * @param bool                  $canvas Open the editing canvas instead of the list or sidebar.
	 * @param array<string, string> $query  More query arguments, such as `section`.
	 */
	public static function url( string $path, bool $canvas = false, array $query = array() ): string {
		$args = array( 'p' => rawurlencode( $path ) ) + array_map( 'rawurlencode', $query );
		if ( $canvas ) {
			$args['canvas'] = 'edit';
		}
		return add_query_arg( $args, admin_url( 'site-editor.php' ) );
	}

	/**
	 * The Styles screen of a section key, for mody/update-design too.
	 *
	 * @param string $section One of the SECTIONS keys.
	 * @param string $block   A block type for the `blocks` section, such as `core/button`, or ''.
	 * @return array{url: string, place: string}
	 */
	public static function styles_screen( string $section, string $block = '' ): array {
		if ( 'stylebook' === $section ) {
			return array(
				'url'   => self::url( '/styles', false, array( 'preview' => 'stylebook' ) ),
				'place' => __( 'the Style Book', 'mody_cloud' ),
			);
		}
		$places = array(
			'overview'   => __( 'Styles', 'mody_cloud' ),
			'browse'     => __( 'Styles → Browse styles', 'mody_cloud' ),
			'colors'     => __( 'Styles → Colors', 'mody_cloud' ),
			'palette'    => __( 'Styles → Colors → Palette', 'mody_cloud' ),
			'typography' => __( 'Styles → Typography', 'mody_cloud' ),
			'layout'     => __( 'Styles → Layout', 'mody_cloud' ),
			'blocks'     => __( 'Styles → Blocks', 'mody_cloud' ),
			'revisions'  => __( 'Styles → Revisions', 'mody_cloud' ),
		);
		$section = isset( $places[ $section ] ) ? $section : 'overview';
		$path    = self::SECTIONS[ $section ];
		$place   = $places[ $section ];
		if ( 'blocks' === $section && '' !== $block ) {
			$type  = WP_Block_Type_Registry::get_instance()->get_registered( $block );
			$path  = '/blocks/' . rawurlencode( $block ); // As the Site Editor builds it.
			$place = $places['blocks'] . ' → ' . ( $type && $type->title ? $type->title : $block );
		}
		return array(
			'url'   => '/' === $path ? self::url( '/styles' ) : self::url( '/styles', false, array( 'section' => $path ) ),
			'place' => $place,
		);
	}

	/**
	 * Permission callback of mody/open-editor: pages need `edit_pages`, the
	 * Identity screen `manage_options` (site settings), everything else
	 * `edit_theme_options`.
	 *
	 * @param mixed $input The ability input.
	 */
	public static function can_open( $input = null ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$target = is_array( $input ) ? (string) ( $input['target'] ?? 'page' ) : 'page';
		if ( 'identity' === $target ) {
			return current_user_can( 'manage_options' ); // Site settings (M2-08).
		}
		return 'page' === $target ? current_user_can( 'edit_pages' ) : current_user_can( 'edit_theme_options' );
	}

	/**
	 * Execute callback of mody/open-editor. Changes nothing: it returns where
	 * to go.
	 *
	 * @param mixed $input `target`, and `page_id`, `page_title`, `template`,
	 *                     `list`, `section` or `block`.
	 * @return array<string, mixed>|WP_Error `target`, `url`, `label` (the
	 *                                       button), `place` (what opens) and `navigate`.
	 */
	public static function open( $input ): array|WP_Error {
		$input = is_array( $input ) ? $input : array();
		$slug  = (string) ( $input['template'] ?? '' );

		switch ( (string) ( $input['target'] ?? 'page' ) ) {
			case 'styles':
				$block = sanitize_text_field( (string) ( $input['block'] ?? '' ) );
				if ( '' !== $block && ! WP_Block_Type_Registry::get_instance()->is_registered( $block ) ) {
					/* translators: %s: block name. */
					return new WP_Error( 'mody_block_not_found', sprintf( __( 'There is no "%s" block.', 'mody_cloud' ), $block ) );
				}
				$screen = self::styles_screen( '' !== $block ? 'blocks' : (string) ( $input['section'] ?? 'overview' ), $block );
				/* translators: %s: a Styles screen, such as "Styles → Colors". */
				return self::result( 'styles', $screen['url'], sprintf( __( 'Open %s', 'mody_cloud' ), $screen['place'] ), $screen['place'] );
			case 'template':
				return self::open_template( $slug, 'wp_template' );
			case 'part':
				return self::open_template( $slug, 'wp_template_part' );
			case 'list':
				return self::open_list( sanitize_key( (string) ( $input['list'] ?? '' ) ) );
			case 'navigation':
				return self::open_navigation();
			case 'patterns':
				return self::result( 'patterns', self::url( '/pattern' ), __( 'Open the patterns', 'mody_cloud' ), __( 'the Patterns screen', 'mody_cloud' ) );
			case 'identity':
				// The site's title, tagline, logo, icon, mission and tone (M2-08).
				return self::result( 'identity', self::url( '/identity' ), __( 'Open Identity', 'mody_cloud' ), __( 'the Identity screen', 'mody_cloud' ) );
			case 'home':
				return self::result( 'home', self::url( '/' ), __( 'Open the Site Editor', 'mody_cloud' ), __( 'the Site Editor\'s start screen', 'mody_cloud' ) );
		}
		return self::open_page( (int) ( $input['page_id'] ?? 0 ), sanitize_text_field( (string) ( $input['page_title'] ?? '' ) ) );
	}

	/**
	 * A page by ID or exact title, or the page list when neither is given.
	 *
	 * @param int    $page_id Page ID, or 0.
	 * @param string $title   Page title, or ''.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function open_page( int $page_id, string $title ): array|WP_Error {
		if ( ! $page_id && '' === $title ) {
			return self::result( 'page', self::url( '/page' ), __( 'Open the pages', 'mody_cloud' ), __( 'the Pages list', 'mody_cloud' ) );
		}

		$page = self::match_page( $page_id, $title );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( ! current_user_can( 'edit_post', $page->ID ) ) {
			return new WP_Error( 'mody_forbidden', __( 'You cannot edit this page.', 'mody_cloud' ) );
		}

		$result            = self::result(
			'page',
			self::page_url( $page->ID ),
			/* translators: %s: page title. */
			sprintf( __( 'Edit "%s"', 'mody_cloud' ), get_the_title( $page ) ),
			/* translators: %s: page title. */
			sprintf( __( 'the page "%s"', 'mody_cloud' ), get_the_title( $page ) )
		);
		$result['page_id'] = $page->ID;
		return $result;
	}

	/**
	 * A template or template part of the active theme by slug, or its list.
	 *
	 * @param string $slug Slug, such as `front-page` or `header`.
	 * @param string $type `wp_template` or `wp_template_part`.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function open_template( string $slug, string $type ): array|WP_Error {
		$part = 'wp_template_part' === $type;
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return $part
				? self::result( 'part', self::url( '/pattern', false, array( 'postType' => 'wp_template_part', 'categoryId' => 'all-parts' ) ), __( 'Open the template parts', 'mody_cloud' ), __( 'the Template parts list', 'mody_cloud' ) )
				: self::result( 'template', self::url( '/template' ), __( 'Open the templates', 'mody_cloud' ), __( 'the Templates list', 'mody_cloud' ) );
		}

		$id       = get_stylesheet() . '//' . $slug;
		$template = get_block_template( $id, $type );
		if ( ! $template ) {
			return new WP_Error(
				$part ? 'mody_part_not_found' : 'mody_template_not_found',
				$part
					/* translators: %s: template part slug. */
					? sprintf( __( 'The theme has no "%s" template part.', 'mody_cloud' ), $slug )
					/* translators: %s: template slug. */
					: sprintf( __( 'The theme has no "%s" template.', 'mody_cloud' ), $slug ),
				array( $part ? 'parts' : 'templates' => wp_list_pluck( get_block_templates( array(), $type ), 'slug' ) )
			);
		}

		return self::result(
			$part ? 'part' : 'template',
			self::url( '/' . $type . '/' . $id, true ),
			$part
				/* translators: %s: template part name. */
				? sprintf( __( 'Edit the "%s" template part', 'mody_cloud' ), $template->title )
				/* translators: %s: template name. */
				: sprintf( __( 'Edit the "%s" template', 'mody_cloud' ), $template->title ),
			$part
				/* translators: %s: template part name. */
				? sprintf( __( 'the "%s" template part', 'mody_cloud' ), $template->title )
				/* translators: %s: template name. */
				: sprintf( __( 'the "%s" template', 'mody_cloud' ), $template->title )
		);
	}

	/**
	 * The template that shows one of the site's lists (decided 2026-11-08).
	 *
	 * @param string $key A key of lists(), such as `post` or `category`.
	 * @return array<string, mixed>|WP_Error `mody_list_not_found` lists the site's lists.
	 */
	private static function open_list( string $key ): array|WP_Error {
		$lists = self::lists();
		if ( ! isset( $lists[ $key ] ) || '' === $lists[ $key ]['template'] ) {
			/* translators: %s: list key. */
			$missing = sprintf( __( 'This site has no "%s" list.', 'mody_cloud' ), $key );
			return new WP_Error(
				'mody_list_not_found',
				'' === $key ? __( 'Say which list to open.', 'mody_cloud' ) : $missing,
				array(
					'lists' => array_map(
						static fn( string $k, array $list ): string => $k . ': ' . $list['label'],
						array_keys( $lists ),
						$lists
					),
				)
			);
		}
		$list   = $lists[ $key ];
		$result = self::result(
			'list',
			self::url( '/wp_template/' . get_stylesheet() . '//' . $list['template'], true ),
			/* translators: %s: template name. */
			sprintf( __( 'Edit the "%s" template', 'mody_cloud' ), $list['template_title'] ),
			/* translators: 1: template name, 2: what the list shows, such as "Categories". */
			sprintf( __( 'the "%1$s" template, which shows %2$s', 'mody_cloud' ), $list['template_title'], $list['label'] )
		);
		$result['template'] = $list['template'];
		return $result;
	}

	/**
	 * The site's only menu, or the Navigation screen.
	 *
	 * @return array<string, mixed>
	 */
	private static function open_navigation(): array {
		$menus = get_posts(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'publish',
				'numberposts' => 2,
			)
		);
		if ( 1 === count( $menus ) ) {
			/* translators: %s: menu name. */
			$place = sprintf( __( 'the "%s" menu', 'mody_cloud' ), get_the_title( $menus[0] ) );
			return self::result( 'navigation', self::url( '/wp_navigation/' . $menus[0]->ID ), __( 'Open the menu', 'mody_cloud' ), $place );
		}
		return self::result( 'navigation', self::url( '/navigation' ), __( 'Open the menus', 'mody_cloud' ), __( 'the Navigation screen', 'mody_cloud' ) );
	}

	/**
	 * The site's lists of content and the template that shows each
	 * (decided 2026-11-08): the latest posts, each public post type with an
	 * archive, each public taxonomy, authors, dates and search. Each list
	 * follows WordPress's template hierarchy (`get_template_hierarchy()`) to
	 * the first template the site has, its own edited templates included.
	 *
	 * @return array<string, array{label: string, slug: string, count: int|null, kind: string, template: string, template_title: string}>
	 *         Keyed by what mody/open-editor takes as `list`.
	 */
	public static function lists(): array {
		$lists = array();
		$count = static fn( string $type ): int => (int) ( wp_count_posts( $type )->publish ?? 0 );

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			$lists['post'] = array( __( 'the latest posts, on the front page', 'mody_cloud' ), 'front-page', $count( 'post' ), 'posts' );
		} elseif ( $posts_page ) {
			/* translators: %s: page title. */
			$lists['post'] = array( sprintf( __( 'the latest posts, on the "%s" page', 'mody_cloud' ), get_the_title( $posts_page ) ), 'home', $count( 'post' ), 'posts' );
		}
		foreach ( get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		) as $type ) {
			if ( $type->has_archive ) {
				/* translators: %s: post type name, plural. */
				$lists[ $type->name ] = array( sprintf( __( 'the list of %s', 'mody_cloud' ), $type->labels->name ), 'archive-' . $type->name, $count( $type->name ), 'posts' );
			}
		}
		foreach ( get_taxonomies(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			),
			'objects'
		) as $taxonomy ) {
			if ( 'post_format' === $taxonomy->name ) {
				continue;
			}
			$terms = wp_count_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
				)
			);
			$slug  = match ( $taxonomy->name ) {
				'category' => 'category',
				'post_tag' => 'tag',
				default    => 'taxonomy-' . $taxonomy->name,
			};
			/* translators: %s: taxonomy name, singular, such as "Category". */
			$lists[ $taxonomy->name ] = array( sprintf( __( 'the posts of each %s', 'mody_cloud' ), strtolower( $taxonomy->labels->singular_name ) ), $slug, is_wp_error( $terms ) ? null : (int) $terms, 'terms' );
		}
		$lists['author'] = array( __( 'the posts of each author', 'mody_cloud' ), 'author', null, '' );
		$lists['date']   = array( __( 'the posts of a year, month or day', 'mody_cloud' ), 'date', null, '' );
		$lists['search'] = array( __( 'search results', 'mody_cloud' ), 'search', null, '' );

		$templates = array();
		foreach ( get_block_templates( array(), 'wp_template' ) as $template ) {
			$templates[ $template->slug ] = $template;
		}
		$resolved = array();
		foreach ( $lists as $key => list( $label, $slug, $items, $kind ) ) {
			$found = '';
			foreach ( get_template_hierarchy( $slug ) as $candidate ) {
				if ( isset( $templates[ $candidate ] ) ) {
					$found = $candidate;
					break;
				}
			}
			$resolved[ $key ] = array(
				'label'          => $label,
				'slug'           => $slug,
				'count'          => $items,
				'kind'           => $kind, // posts, terms, or '' when not counted.
				'template'       => $found,
				'template_title' => '' !== $found ? (string) $templates[ $found ]->title : '',
			);
		}
		return $resolved;
	}

	/**
	 * Checks where the owner is in the Site Editor, as the chat bubble sends
	 * it: the route `p`, the Styles `section` and `canvas`.
	 *
	 * @param mixed $raw The `screen` parameter.
	 * @return array{p: string, section: string, canvas: bool}|null Null when it is not a Site Editor screen.
	 */
	public static function screen_from_input( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$p       = '' === (string) ( $raw['p'] ?? '' ) ? '/' : (string) $raw['p'];
		$section = (string) ( $raw['section'] ?? '' );
		if ( strlen( $p ) > 200 || ! preg_match( '#^/[A-Za-z0-9_./%-]*$#', $p ) ) {
			return null;
		}
		if ( strlen( $section ) > 100 || ! preg_match( '#^(/[A-Za-z0-9_/%-]*)?$#', $section ) ) {
			$section = '';
		}
		return array(
			'p'       => $p,
			'section' => $section,
			'canvas'  => 'edit' === ( $raw['canvas'] ?? '' ),
		);
	}

	/**
	 * Names a Site Editor screen for the agent's prompt.
	 *
	 * @param array{p: string, section: string, canvas: bool} $screen From screen_from_input().
	 */
	public static function describe( array $screen ): string {
		$p = $screen['p'];
		if ( '/styles' === $p ) {
			if ( str_starts_with( $screen['section'], '/blocks/' ) ) {
				return self::styles_screen( 'blocks', rawurldecode( substr( $screen['section'], 8 ) ) )['place'];
			}
			$key = array_search( '' === $screen['section'] ? '/' : $screen['section'], self::SECTIONS, true );
			return self::styles_screen( false === $key ? 'overview' : (string) $key )['place'];
		}
		$lists = array(
			'/'           => __( 'the Site Editor\'s start screen', 'mody_cloud' ),
			'/page'       => __( 'the Pages list', 'mody_cloud' ),
			'/template'   => __( 'the Templates list', 'mody_cloud' ),
			'/pattern'    => __( 'the Patterns screen', 'mody_cloud' ),
			'/navigation' => __( 'the Navigation screen', 'mody_cloud' ),
			'/identity'   => __( 'the Identity screen', 'mody_cloud' ),
		);
		if ( isset( $lists[ $p ] ) ) {
			return $lists[ $p ];
		}
		if ( preg_match( '#^/page/(\d+)$#', $p, $match ) && get_post( (int) $match[1] ) ) {
			/* translators: %s: page title. */
			return sprintf( __( 'the page "%s"', 'mody_cloud' ), get_the_title( (int) $match[1] ) );
		}
		if ( preg_match( '#^/(wp_template|wp_template_part)/(.+)$#', $p, $match ) ) {
			$template = get_block_template( rawurldecode( $match[2] ), $match[1] );
			if ( $template ) {
				return 'wp_template' === $match[1]
					/* translators: %s: template name. */
					? sprintf( __( 'the "%s" template', 'mody_cloud' ), $template->title )
					/* translators: %s: template part name. */
					: sprintf( __( 'the "%s" template part', 'mody_cloud' ), $template->title );
			}
		}
		if ( str_starts_with( $p, '/wp_navigation/' ) ) {
			return __( 'a navigation menu', 'mody_cloud' );
		}
		return __( 'a screen of the Site Editor', 'mody_cloud' );
	}

	/**
	 * A page to open, by ID or title (decided 2026-11-08, M3-09): the exact
	 * title first (find_page()), else the one page whose title partly matches
	 * what the owner said ("the about page" finds "About us"). Several
	 * matches are an error listing them, so the agent asks which one.
	 *
	 * @param int    $page_id Page ID, or 0 to look up the title.
	 * @param string $title   The title as the owner said it.
	 * @return \WP_Post|WP_Error `mody_page_ambiguous` or `mody_page_not_found`, both with `pages`.
	 */
	public static function match_page( int $page_id, string $title ): \WP_Post|WP_Error {
		$page = self::find_page( $page_id, $title );
		if ( ! is_wp_error( $page ) || $page_id || 'mody_page_not_found' !== $page->get_error_code() ) {
			return $page;
		}
		$wanted = self::normalize_title( $title );
		if ( strlen( $wanted ) < 3 ) {
			return $page;
		}
		$matches = array_values(
			array_filter(
				get_posts(
					array(
						'post_type'   => 'page',
						'post_status' => self::PAGE_STATUSES,
						'numberposts' => 200,
						'orderby'     => 'modified',
					)
				),
				static function ( \WP_Post $candidate ) use ( $wanted ): bool {
					$have = self::normalize_title( get_the_title( $candidate ) );
					return strlen( $have ) >= 3 && ( str_contains( $have, $wanted ) || str_contains( $wanted, $have ) );
				}
			)
		);
		if ( 1 === count( $matches ) ) {
			return $matches[0];
		}
		if ( ! $matches ) {
			return $page;
		}
		return new WP_Error(
			'mody_page_ambiguous',
			/* translators: %s: what the owner called the page. */
			sprintf( __( 'More than one page matches "%s".', 'mody_cloud' ), $title ),
			array(
				'pages' => array_map(
					static fn( \WP_Post $match ) => array(
						'id'     => $match->ID,
						'title'  => get_the_title( $match ),
						'status' => $match->post_status,
					),
					array_slice( $matches, 0, 8 )
				),
			)
		);
	}

	/**
	 * A title as words to compare: lowercase, no accents or punctuation, and
	 * without "the", "my", "our" in front or "page" at the end.
	 *
	 * @param string $title A page title, or what the owner called it.
	 */
	private static function normalize_title( string $title ): string {
		$words = strtolower( remove_accents( wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES ) ) ) );
		$words = trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $words ) );
		$words = (string) preg_replace( '/^(the|my|our) /', '', $words );
		return trim( (string) preg_replace( '/ pages?$/', '', $words ) );
	}

	/**
	 * A page of the current site, by ID or else by exact title (the most
	 * recently changed one). Also for mody/publish-page.
	 *
	 * @param int      $page_id  Page ID, or 0 to look up the title.
	 * @param string   $title    Exact title, case-insensitive.
	 * @param string[] $statuses Statuses a title lookup searches, and the error lists.
	 * @return \WP_Post|WP_Error `mody_page_not_found` lists the pages in $statuses.
	 */
	public static function find_page( int $page_id, string $title, array $statuses = self::PAGE_STATUSES ): \WP_Post|WP_Error {
		if ( ! $page_id && '' !== $title ) {
			$found   = get_posts(
				array(
					'post_type'   => 'page',
					'post_status' => $statuses,
					'title'       => $title,
					'numberposts' => 1,
					'orderby'     => 'modified',
					'fields'      => 'ids',
				)
			);
			$page_id = (int) ( $found[0] ?? 0 );
		}

		$page = $page_id ? get_post( $page_id ) : null;
		if ( $page && 'page' === $page->post_type && 'trash' === $page->post_status ) {
			/* translators: %s: page title. */
			return new WP_Error( 'mody_page_in_trash', sprintf( __( 'The page "%s" is in the trash. Restore it from the Pages screen first.', 'mody_cloud' ), get_the_title( $page ) ) );
		}
		if ( ! $page || 'page' !== $page->post_type || 'auto-draft' === $page->post_status ) {
			return new WP_Error(
				'mody_page_not_found',
				'' !== $title && ! $page_id
					/* translators: %s: page title. */
					? sprintf( __( 'There is no page titled "%s" on this site.', 'mody_cloud' ), $title )
					/* translators: %d: page ID. */
					: sprintf( __( 'There is no page %d on this site.', 'mody_cloud' ), $page_id ),
				array( 'pages' => self::pages( $statuses ) )
			);
		}
		return $page;
	}

	/**
	 * The site's most recently changed pages: ID, title and status.
	 *
	 * @param string[] $statuses Page statuses to list.
	 * @return list<array{id: int, title: string, status: string}>
	 */
	public static function pages( array $statuses = self::PAGE_STATUSES ): array {
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => $statuses,
				'numberposts' => self::LISTED_PAGES,
				'orderby'     => 'modified',
			)
		);
		return array_map(
			static fn( $page ) => array(
				'id'     => $page->ID,
				'title'  => get_the_title( $page ),
				'status' => $page->post_status,
			),
			$pages
		);
	}

	/**
	 * The ability's result shape.
	 *
	 * @param string $target What is opened.
	 * @param string $url    Site Editor URL.
	 * @param string $label  Button label, where the editor cannot switch in place.
	 * @param string $place  What opens, for the chat's note and the model's reply.
	 * @return array{target: string, url: string, label: string, place: string, navigate: bool}
	 */
	private static function result( string $target, string $url, string $label, string $place ): array {
		return array(
			'target'   => $target,
			'url'      => $url,
			'label'    => $label,
			'place'    => $place,
			'navigate' => true,
		);
	}
}
