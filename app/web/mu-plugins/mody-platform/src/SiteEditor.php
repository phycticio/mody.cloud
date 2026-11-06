<?php
/**
 * Site Editor links, and the ability mody/open-editor.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds Site Editor URLs on the current site (mvp-spec section 9).
 *
 * WordPress 7.1 routes the Site Editor with `?p=<path>`: `/page/<id>` with
 * `canvas=edit` opens a page, `/wp_template/<theme>//<slug>` a template,
 * `/styles` the global styles. The older `?postType=…&postId=…` form without
 * `canvas` lands on a list instead.
 */
final class SiteEditor {

	/** What mody/open-editor can open. */
	public const TARGETS = array( 'page', 'template', 'styles' );

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
	 * @param string $path   Route, such as `/page/12` or `/styles`.
	 * @param bool   $canvas Open the editing canvas instead of the list or sidebar.
	 */
	public static function url( string $path, bool $canvas = false ): string {
		$args = array( 'p' => rawurlencode( $path ) );
		if ( $canvas ) {
			$args['canvas'] = 'edit';
		}
		return add_query_arg( $args, admin_url( 'site-editor.php' ) );
	}

	/**
	 * Permission callback of mody/open-editor: pages need `edit_pages`,
	 * templates and styles `edit_theme_options`.
	 *
	 * @param mixed $input The ability input.
	 */
	public static function can_open( $input = null ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$target = is_array( $input ) ? (string) ( $input['target'] ?? 'page' ) : 'page';
		return 'page' === $target ? current_user_can( 'edit_pages' ) : current_user_can( 'edit_theme_options' );
	}

	/**
	 * Execute callback of mody/open-editor. Changes nothing: it returns a URL.
	 *
	 * @param mixed $input `target`, and `page_id`, `page_title` or `template`.
	 * @return array{target: string, url: string, label: string, page_id?: int}|WP_Error
	 */
	public static function open( $input ): array|WP_Error {
		$input  = is_array( $input ) ? $input : array();
		$target = (string) ( $input['target'] ?? 'page' );

		if ( 'styles' === $target ) {
			return self::result( 'styles', self::url( '/styles' ), __( 'Open the styles', 'mody_cloud' ) );
		}
		if ( 'template' === $target ) {
			return self::open_template( (string) ( $input['template'] ?? '' ) );
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
			return self::result( 'page', self::url( '/page' ), __( 'Open the pages', 'mody_cloud' ) );
		}

		$page = self::find_page( $page_id, $title );
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
			sprintf( __( 'Edit "%s"', 'mody_cloud' ), get_the_title( $page ) )
		);
		$result['page_id'] = $page->ID;
		return $result;
	}

	/**
	 * A template of the active theme by slug, or the template list.
	 *
	 * @param string $slug Template slug, such as `front-page` or `page`.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function open_template( string $slug ): array|WP_Error {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return self::result( 'template', self::url( '/template' ), __( 'Open the templates', 'mody_cloud' ) );
		}

		$id       = get_stylesheet() . '//' . $slug;
		$template = get_block_template( $id, 'wp_template' );
		if ( ! $template ) {
			return new WP_Error(
				'mody_template_not_found',
				/* translators: %s: template slug. */
				sprintf( __( 'The theme has no "%s" template.', 'mody_cloud' ), $slug ),
				array( 'templates' => wp_list_pluck( get_block_templates( array(), 'wp_template' ), 'slug' ) )
			);
		}

		return self::result(
			'template',
			self::url( '/wp_template/' . $id, true ),
			/* translators: %s: template name. */
			sprintf( __( 'Edit the "%s" template', 'mody_cloud' ), $template->title )
		);
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
	 * @param string $label  Button label.
	 * @return array{target: string, url: string, label: string}
	 */
	private static function result( string $target, string $url, string $label ): array {
		return array(
			'target' => $target,
			'url'    => $url,
			'label'  => $label,
		);
	}
}
