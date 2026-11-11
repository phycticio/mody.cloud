<?php
/**
 * The ability mody/update-menu: the site's header menu, as a list of items.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Site;

use Mody\Platform\SiteEditor;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Part 3 of tickets/agent-knows-gutenberg-spectra-settings.md (2026-11-11).
 *
 * The menu is the `wp_navigation` post the header's navigation block shows:
 * its `ref`, or, without one, the one WordPress falls back to (the most
 * recently published menu, created from the pages when there is none, as
 * the front end does). The agent sees its items in its system prompt
 * (context()) and sends the whole new list: pages by title, links by
 * address, one level of submenus. The blocks are built here, never by the
 * model. A list that drops an item waits for the owner's confirmation
 * (to_confirm(), Agent::hold()).
 *
 * On a site with several languages this is the default language's menu;
 * the others are edited in the Site Editor (mody-languages, part 6).
 */
final class Menu {

	/** Most items in a menu, submenu items included. */
	public const MAX_ITEMS = 40;

	/** Longest label, in characters. */
	public const MAX_LABEL = 60;

	/** What an automatic list of pages (core/page-list) is called in an item list. */
	public const ALL_PAGES = 'all pages';

	/**
	 * Permission callback of mody/update-menu.
	 */
	public static function can_update(): bool {
		return is_user_logged_in() && current_user_can( 'edit_theme_options' );
	}

	/**
	 * The menu the header shows, or null when the site has none and none can be made.
	 */
	public static function post(): ?WP_Post {
		$ref = self::header_ref();
		if ( $ref ) {
			$post = get_post( $ref );
			if ( $post instanceof WP_Post && 'wp_navigation' === $post->post_type && 'publish' === $post->post_status ) {
				return $post;
			}
		}
		$fallback = class_exists( \WP_Navigation_Fallback::class ) ? \WP_Navigation_Fallback::get_fallback() : null;
		return $fallback instanceof WP_Post ? $fallback : null;
	}

	/**
	 * The `ref` of the header's navigation block, or 0.
	 */
	private static function header_ref(): int {
		$part = get_block_template( get_stylesheet() . '//header', 'wp_template_part' );
		if ( ! $part ) {
			return 0;
		}
		$find = static function ( array $blocks ) use ( &$find ): int {
			foreach ( $blocks as $block ) {
				if ( 'core/navigation' === ( $block['blockName'] ?? '' ) ) {
					return (int) ( $block['attrs']['ref'] ?? 0 );
				}
				$found = $find( $block['innerBlocks'] ?? array() );
				if ( $found ) {
					return $found;
				}
			}
			return 0;
		};
		return $find( parse_blocks( (string) $part->content ) );
	}

	/**
	 * The menu's items as the agent reads and writes them: a page's title, a
	 * link (`label`, `url`), `children` for a submenu, or ALL_PAGES.
	 *
	 * @param WP_Post|null $post The menu; the header's by default.
	 * @return array<int, mixed>
	 */
	public static function items( ?WP_Post $post = null ): array {
		$post = $post ?? self::post();
		return $post ? self::read( parse_blocks( (string) $post->post_content ) ) : array();
	}

	/**
	 * Items from parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, mixed>
	 */
	private static function read( array $blocks ): array {
		$items = array();
		foreach ( $blocks as $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = (array) ( $block['attrs'] ?? array() );
			if ( 'core/page-list' === $name ) {
				$items[] = self::ALL_PAGES;
				continue;
			}
			if ( ! in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
				continue;
			}
			$label = wp_strip_all_tags( (string) ( $attrs['label'] ?? '' ) );
			$page  = 'post-type' === ( $attrs['kind'] ?? '' ) && ! empty( $attrs['id'] ) ? get_post( (int) $attrs['id'] ) : null;
			if ( $page instanceof WP_Post && ( '' === $label || get_the_title( $page ) === $label ) ) {
				$item = array( 'page' => get_the_title( $page ) );
			} elseif ( $page instanceof WP_Post ) {
				$item = array(
					'page'  => get_the_title( $page ),
					'label' => $label,
				);
			} else {
				$item = array(
					'label' => $label,
					'url'   => (string) ( $attrs['url'] ?? '' ),
				);
			}
			$children = 'core/navigation-submenu' === $name ? self::read( $block['innerBlocks'] ?? array() ) : array();
			if ( $children ) {
				$item['children'] = $children;
			}
			$items[] = 1 === count( $item ) && isset( $item['page'] ) ? $item['page'] : $item;
		}
		return $items;
	}

	/**
	 * A short text of each item, to compare old and new lists.
	 *
	 * @param array<int, mixed> $items Items.
	 * @return string[]
	 */
	private static function keys( array $items ): array {
		$keys = array();
		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				$keys[] = strtolower( $item );
				continue;
			}
			$item   = (array) $item;
			$keys[] = strtolower( (string) ( $item['page'] ?? $item['url'] ?? $item['label'] ?? '' ) );
			$keys   = array_merge( $keys, self::keys( (array) ( $item['children'] ?? array() ) ) );
		}
		return $keys;
	}

	/**
	 * Whether a call drops an item of the menu: then it waits for the owner.
	 *
	 * @param mixed $input The ability's input.
	 */
	public static function to_confirm( $input ): bool {
		$new = is_array( $input ) && is_array( $input['items'] ?? null ) ? $input['items'] : array();
		return (bool) array_diff( self::keys( self::items() ), self::keys( $new ) );
	}

	/**
	 * Builds the blocks of a list of items, or says what is wrong.
	 *
	 * @param array<int, mixed> $items Items.
	 * @param bool              $top   Whether these are top-level items (submenus allowed).
	 * @param int               $count Items so far, against MAX_ITEMS.
	 * @return string|WP_Error The blocks.
	 */
	private static function blocks( array $items, bool $top, int &$count ) {
		$blocks = array();
		foreach ( $items as $item ) {
			if ( ++$count > self::MAX_ITEMS ) {
				/* translators: %d: the most items. */
				return new WP_Error( 'mody_menu_too_long', sprintf( __( 'A menu has at most %d items.', 'mody_cloud' ), self::MAX_ITEMS ) );
			}
			if ( is_string( $item ) && self::ALL_PAGES === strtolower( trim( $item ) ) ) {
				$blocks[] = '<!-- wp:page-list /-->';
				continue;
			}
			$item  = is_string( $item ) ? array( 'page' => $item ) : ( is_array( $item ) ? $item : array() );
			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			if ( mb_strlen( $label ) > self::MAX_LABEL ) {
				/* translators: %d: the longest label. */
				return new WP_Error( 'mody_menu_label', sprintf( __( 'A menu label has at most %d characters.', 'mody_cloud' ), self::MAX_LABEL ) );
			}
			if ( isset( $item['page'] ) && is_scalar( $item['page'] ) ) {
				$page = SiteEditor::match_page( 0, (string) $item['page'] );
				if ( is_wp_error( $page ) || 'publish' !== $page->post_status ) {
					/* translators: %s: a page title. */
					return new WP_Error( 'mody_menu_page', sprintf( __( 'Only published pages can be in the menu; "%s" is not one.', 'mody_cloud' ), (string) $item['page'] ) );
				}
				$attrs = array(
					'label' => '' !== $label ? $label : get_the_title( $page ),
					'type'  => 'page',
					'id'    => (int) $page->ID,
					'url'   => (string) get_permalink( $page ),
					'kind'  => 'post-type',
				);
			} else {
				$url = trim( (string) ( $item['url'] ?? '' ) );
				$ok  = '' !== $label && ( preg_match( '#^(https?://[^\s]+|/[^\s]*|\#[\w-]+|mailto:[^\s@]+@[^\s@]+|tel:\+?[0-9 ()-]+)$#i', $url ) );
				if ( ! $ok ) {
					return new WP_Error( 'mody_menu_item', __( 'Each menu item is a published page\'s title, or a link with a label and an address (https://…, /path, #anchor, mailto: or tel:).', 'mody_cloud' ) );
				}
				$attrs = array(
					'label' => $label,
					'url'   => str_starts_with( $url, 'http' ) ? esc_url_raw( $url ) : $url,
					'kind'  => 'custom',
				);
			}
			$children = isset( $item['children'] ) && is_array( $item['children'] ) ? $item['children'] : array();
			if ( $children && ! $top ) {
				return new WP_Error( 'mody_menu_depth', __( 'Submenus can have only one level.', 'mody_cloud' ) );
			}
			if ( ! $children ) {
				$blocks[] = '<!-- wp:navigation-link ' . serialize_block_attributes( $attrs + array( 'isTopLevelLink' => $top ) ) . ' /-->';
				continue;
			}
			$inner = self::blocks( $children, false, $count );
			if ( is_wp_error( $inner ) ) {
				return $inner;
			}
			$blocks[] = '<!-- wp:navigation-submenu ' . serialize_block_attributes( $attrs ) . " -->\n" . $inner . "\n<!-- /wp:navigation-submenu -->";
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Execute callback of mody/update-menu.
	 *
	 * @param mixed $input `items`: the menu's new items, in order.
	 * @return array<string, mixed>|WP_Error `old`, `new`, and the Site Editor's menu screen.
	 */
	public static function update( $input ): array|WP_Error {
		$items = is_array( $input ) && is_array( $input['items'] ?? null ) ? array_values( $input['items'] ) : null;
		if ( null === $items ) {
			return new WP_Error( 'mody_menu_items', __( 'Give the menu\'s items, in order.', 'mody_cloud' ) );
		}
		$post = self::post();
		if ( ! $post ) {
			return new WP_Error( 'mody_menu_missing', __( 'This site has no menu yet. Open the header in the Site Editor to add one.', 'mody_cloud' ) );
		}
		$count  = 0;
		$blocks = self::blocks( $items, true, $count );
		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}
		$old    = self::items( $post );
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $blocks,
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$saved = get_post( $post->ID );
		return array(
			'old'   => $old,
			'new'   => self::items( $saved instanceof WP_Post ? $saved : null ),
			'url'   => SiteEditor::url( '/wp_navigation/' . $post->ID ),
			'label' => __( 'Open the menu in the Site Editor', 'mody_cloud' ),
		);
	}

	/**
	 * The menu for the agent's system prompt.
	 */
	public static function context(): string {
		$post = self::post_without_creating();
		$note = class_exists( \Mody\Languages\Languages::class ) && \Mody\Languages\Languages::is_multilingual() ? ' This is the default language\'s menu; the other languages\' menus are changed in the Site Editor.' : '';
		if ( ! $post ) {
			return 'The site\'s menu (the header\'s links): none yet; it lists all published pages until one is set.' . $note;
		}
		return 'The site\'s menu (the header\'s links), in order, as the menu tool takes them: ' . wp_json_encode( self::items( $post ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '.' . $note;
	}

	/**
	 * The header's menu's ID, or 0, without creating one: for the editor's
	 * bubble, which reloads it after a change (EditorBubble).
	 */
	public static function id(): int {
		$post = self::post_without_creating();
		return $post ? (int) $post->ID : 0;
	}

	/**
	 * The header's menu, without creating a fallback (the prompt only reads).
	 */
	private static function post_without_creating(): ?WP_Post {
		add_filter( 'wp_navigation_should_create_fallback', '__return_false' );
		$post = self::post();
		remove_filter( 'wp_navigation_should_create_fallback', '__return_false' );
		return $post;
	}
}
