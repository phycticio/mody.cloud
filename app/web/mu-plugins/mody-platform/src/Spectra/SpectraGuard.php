<?php
/**
 * Keeps Spectra (spectra-blocks) inside rule 1: no connections to US or
 * other non-European services.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Spectra;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns off every part of Spectra that connects to a third party, without
 * touching the plugin's files (they are Composer-managed). Checked against
 * spectra-blocks 1.0.13 on 2026-11-06; recheck on every Spectra update
 * (documentation/mvp-spec.md section 10).
 *
 * Plugin source untouched: it uses Spectra's own switches where they exist
 * (filters and options), and these fallbacks where they do not:
 * - Blocks: unregistered on the server, removed in the editor, never rendered.
 * - Libraries: ZipWP images and the NPS survey never load (see boot()).
 * - Safety net: server-side requests to the vendor and Google hosts are refused.
 * - Admin: the Spectra dashboard and onboarding pages (YouTube videos, Google
 *   Fonts, ZipWP AI upsells) are hidden; their settings are pinned below.
 *
 * Spectra's default fonts (Inter, Cardo) ship with the plugin and stay local.
 */
final class SpectraGuard {

	/**
	 * Blocks that embed or call a third party.
	 * - spectra/google-map: a Google Maps iframe, saved into the content.
	 * - gutenberg-templates/spectra-ai: ZipWP's AI service (design library).
	 */
	public const DENIED_BLOCKS = array( 'spectra/google-map', 'gutenberg-templates/spectra-ai' );

	/** Spectra abilities (MCP, agent) that drive Google Fonts or Google Maps. */
	public const DENIED_ABILITIES = array(
		'spectra-blocks/create-google-map',
		'spectra-blocks/add-google-font',
		'spectra-blocks/list-available-google-fonts',
		'spectra-blocks/remove-google-font',
	);

	/**
	 * Hosts Spectra and its libraries contact (the host and its subdomains):
	 * Brainstorm Force (telemetry, NPS, licensing, store), ZipWP and Starter
	 * Templates (AI, images, design library), and Google (Fonts, Maps).
	 */
	public const DENIED_HOSTS = array(
		'brainstormforce.com',
		'zipwp.com',
		'startertemplates.com',
		'websitedemos.net',
		'wpspectra.com',
		'wpastra.com',
		'fonts.googleapis.com',
		'fonts.gstatic.com',
		'maps.googleapis.com',
		'maps.google.com',
	);

	/** Spectra options pinned to their off value, whatever is stored. */
	public const PINNED_OPTIONS = array(
		'spectra_blocks_enable_templates_button' => 'no',       // Design library button.
		'spectra_blocks_load_select_font_globally' => 'disabled', // Google Fonts in global styles.
		'spectra_blocks_load_gfonts_locally'     => 'disabled', // Downloads fonts from Google.
		'spectra_blocks_usage_optin'             => 'no',       // Telemetry.
		/*
		 * Spectra's own abilities and MCP server (decided 2026-11-06, mvp-spec
		 * section 10): weak permissions (edit_posts, edit_others_posts) and
		 * direct writes to posts. If Mody uses them, it will be behind its own
		 * mody/* abilities, never exposed to members or MCP as they are.
		 */
		'spectra_blocks_enable_abilities'        => 'disabled',
		'spectra_blocks_enable_mcp_server'       => 'disabled',
	);

	/**
	 * Block attributes that hold script. Spectra's Block JS prints
	 * `spectraCustomJS` of every block it renders as an inline `<script>` in
	 * the footer. The platform does not use it, and a member (who has no
	 * `unfiltered_html`) must not be able to run script on their site, which
	 * shares its login cookie with the whole network (security pass,
	 * 2026-11-12). The attributes are removed from every block, wherever it
	 * sits (page, template, part, pattern, reusable block, widget), when it
	 * is saved by a user without `unfiltered_html` and when it renders on a
	 * member site, and Spectra's printing is switched off there.
	 */
	public const SCRIPT_ATTRIBUTES = array( 'spectraCustomJS' );

	/**
	 * Block attributes that Spectra prints as an element's own name, and the
	 * names each may hold (security pass, 2026-11-12; checked against
	 * spectra-blocks 1.0.13 by reading every `<<?php echo` and `<%1$s` in
	 * build/blocks/*: the view or controller). Spectra escapes the name but an
	 * escaped name can still carry attributes or become an element that loads
	 * script: a tag of `img src=x onerror=...` printed `<img src=x onerror=...>`,
	 * and `script src=https://...` printed a live `<script src>`. Some blocks
	 * check the value, and some do not:
	 * - `htmlTag` of `spectra/container` and `spectra/popup-builder`: unchecked.
	 * - `tagName` of `spectra/accordion-child-header-content`: checked only when
	 *   its parent header is a button. The parent hands over its raw
	 *   `headerElement` as context, so a `div` header turns the check off.
	 * - `headerElement` of `spectra/accordion-child-header`: checked for the
	 *   header itself, but the unchecked value is what its content child reads.
	 * - `tagName` of `spectra/content` and `spectra/modal-child-content`:
	 *   checked by Spectra against its own lists (kept here too).
	 *
	 * Any value that is not one of the names is dropped, which leaves the
	 * block's default element: when the block renders on a member site, and when
	 * a user without `unfiltered_html` saves it (clamp_elements()). Update this
	 * map with Spectra: scripts/security-isolation-test.php fails when a block
	 * has an attribute named like an element that is not in it.
	 */
	public const ELEMENT_ATTRIBUTES = array(
		'spectra/container'                      => array( 'htmlTag' => self::CONTAINER_TAGS ),
		'spectra/popup-builder'                  => array( 'htmlTag' => self::CONTAINER_TAGS ),
		'spectra/content'                        => array( 'tagName' => self::CONTENT_TAGS ),
		'spectra/modal-child-content'            => array( 'tagName' => self::TEXT_TAGS ),
		'spectra/accordion-child-header-content' => array( 'tagName' => self::TEXT_TAGS ),
		'spectra/accordion-child-header'         => array( 'headerElement' => self::HEADER_ELEMENTS ),
	);

	/**
	 * The names for those attributes on any other Spectra block (a block a
	 * Spectra update adds), and `htmlTag` on any block at all.
	 */
	public const ELEMENT_DEFAULTS = array(
		'htmlTag'       => self::CONTAINER_TAGS,
		'tagName'       => self::TEXT_TAGS,
		'headerElement' => self::HEADER_ELEMENTS,
	);

	/** A text element: what an accordion title or a modal button's text may be. */
	public const TEXT_TAGS = array( 'span', 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/** An accordion's header: the button (the default) or a heading, paragraph or div. */
	public const HEADER_ELEMENTS = array( 'button', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p' );

	/** The elements Spectra's own `spectra/content` accepts (its controller's list), none that loads or runs anything. */
	public const CONTENT_TAGS = array(
		'p',
		'span',
		'div',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'blockquote',
		'address',
		'cite',
		'time',
		'label',
		'figcaption',
		'caption',
		'legend',
		'dt',
		'dd',
		'strong',
		'em',
		'small',
		'mark',
		'del',
		'ins',
		'sub',
		'sup',
		'abbr',
		'code',
		'pre',
		'kbd',
		'samp',
		'var',
		'output',
		'q',
		'a',
	);

	/**
	 * The element names Spectra's container offers in the editor (its
	 * `htmlTag`, see ELEMENT_ATTRIBUTES).
	 */
	public const CONTAINER_TAGS = array(
		'div',
		'header',
		'footer',
		'main',
		'article',
		'section',
		'aside',
		'figure',
		'figcaption',
		'nav',
		'hgroup',
		'dialog',
		'a',
		'ul',
		'ol',
		'li',
		'dl',
		'dt',
		'dd',
		'form',
		'fieldset',
		'details',
		'summary',
		'address',
		'table',
		'colgroup',
		'thead',
		'tbody',
		'tfoot',
		'tr',
		'td',
		'th',
		'img',
		'picture',
		'video',
		'audio',
		'source',
		'track',
		'iframe',
		'embed',
		'object',
		'canvas',
		'svg',
		'hr',
		'br',
		'wbr',
	);

	/** Spectra's class that harvests and prints the attributes above. */
	private const BLOCK_JS_CLASS = 'SpectraBlocks\\Extensions\\BlockJsCompiler';

	/** Editor upsell extensions (build/extensions/<name>): a YouTube autoplay video, ZipWP AI and Pro nudges. */
	public const DENIED_EDITOR_EXTENSIONS = array( 'video-popup-nudge', 'zip-ai-notice', 'gbs-pro-nudge' );

	/** Admin pages hidden and redirected: Spectra's dashboard and its onboarding wizard. */
	private const DENIED_ADMIN_PAGES = array( 'spectra-blocks', 'spectra-blocks-onboarding' );

	/**
	 * Installs the guard. Runs when the MU plugin loads, before any plugin,
	 * so it is in place before Spectra's loader runs.
	 */
	public static function boot(): void {
		/*
		 * ZipWP images and the NPS survey have no off switch. Spectra loads
		 * them only if its copy wins BSF's version negotiation, held in these
		 * library-owned globals. Claiming a higher version with no file path
		 * means neither library is ever included.
		 */
		foreach ( array( 'zipwp_images', 'nps_survey' ) as $library ) {
			$GLOBALS[ $library . '_version' ] = '9999';
			$GLOBALS[ $library . '_init' ]    = '';
		}

		add_filter( 'ast_block_templates_disable', '__return_true', 99 );          // Design library, spectra-ai block.
		add_filter( 'bsf_usage_tracking_enabled', '__return_false', 99 );          // BSF analytics.
		add_filter( 'uds_survey_allowed_screens', '__return_empty_array', 99 );   // Deactivation survey.
		add_filter( 'spectra_blocks_enable_redirect_activation', '__return_false', 99 ); // Onboarding redirect.

		foreach ( self::PINNED_OPTIONS as $option => $value ) {
			$pin = static fn() => $value;
			add_filter( "pre_option_{$option}", $pin, 99 );
			add_filter( "pre_site_option_{$option}", $pin, 99 );
		}

		add_filter( 'pre_http_request', array( self::class, 'refuse_denied_hosts' ), 1, 3 );

		add_action( 'init', array( self::class, 'unregister_blocks' ), PHP_INT_MAX );
		// Last: core's _wp_add_block_level_preset_styles (priority 10) always returns null.
		add_filter( 'pre_render_block', array( self::class, 'skip_denied_blocks' ), PHP_INT_MAX, 2 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ), PHP_INT_MAX );
		add_action( 'wp_abilities_api_init', array( self::class, 'unregister_abilities' ), PHP_INT_MAX );

		add_action( 'admin_menu', array( self::class, 'remove_admin_pages' ), PHP_INT_MAX );
		add_action( 'admin_init', array( self::class, 'redirect_admin_pages' ) );

		// Block JS: never printed on member sites, never saved by users without unfiltered_html.
		add_filter( 'render_block_data', array( self::class, 'strip_block_scripts' ), 0 );
		add_action( 'init', array( self::class, 'remove_block_js' ), 0 ); // Spectra adds its hooks on plugins_loaded.
		add_filter( 'wp_insert_post_data', array( self::class, 'strip_saved_scripts' ), 5 ); // Before Spectra's own check (10).
		add_filter( 'wp_insert_attachment_data', array( self::class, 'strip_saved_scripts' ), 5 );
		add_filter( 'pre_update_option_widget_block', array( self::class, 'strip_widget_scripts' ) );
	}

	/**
	 * Whether a URL points to a denied host or one of its subdomains.
	 *
	 * @param string $url The URL.
	 */
	public static function is_denied_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		foreach ( self::DENIED_HOSTS as $denied ) {
			if ( $host === $denied || str_ends_with( $host, '.' . $denied ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Refuses server-side requests to denied hosts (`pre_http_request`).
	 *
	 * @param false|array|WP_Error $preempt A preemptive return value.
	 * @param array                $args    Request arguments.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public static function refuse_denied_hosts( $preempt, $args, $url ) {
		if ( false !== $preempt || ! is_string( $url ) || ! self::is_denied_url( $url ) ) {
			return $preempt;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Shows what tried to leave Europe.
		error_log( sprintf( '[mody-platform] refused a request to %s (rule 1, SpectraGuard)', $host ) );
		return new WP_Error( 'mody_denied_host', sprintf( 'Requests to %s are blocked (rule 1: no non-European services).', $host ) );
	}

	/**
	 * Unregisters the denied blocks on the server (`init`, last).
	 */
	public static function unregister_blocks(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::DENIED_BLOCKS as $name ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}
		}
	}

	/**
	 * Renders nothing for a denied block, even if saved content contains one
	 * (`pre_render_block`). spectra/google-map saves its iframe into the content.
	 *
	 * @param string|null          $pre_render The pre-rendered content.
	 * @param array<string, mixed> $block      The parsed block.
	 * @return string|null
	 */
	public static function skip_denied_blocks( $pre_render, $block ) {
		return in_array( $block['blockName'] ?? '', self::DENIED_BLOCKS, true ) ? '' : $pre_render;
	}

	/**
	 * Editor: removes the upsell scripts and unregisters the denied blocks in
	 * the browser too, because Spectra's editor script registers them there
	 * by itself (`enqueue_block_editor_assets`, last).
	 */
	public static function editor_assets(): void {
		foreach ( self::DENIED_EDITOR_EXTENSIONS as $extension ) {
			wp_dequeue_script( "spectra-blocks-extension-{$extension}-editor" );
			foreach ( wp_styles()->queue as $handle ) {
				if ( str_contains( $handle, "-extension-{$extension}-" ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}

		wp_register_script( 'mody-spectra-guard', false, array( 'wp-blocks', 'wp-dom-ready' ), '1', true );
		wp_add_inline_script(
			'mody-spectra-guard',
			sprintf(
				'wp.domReady( function () { %s.forEach( function ( name ) { if ( wp.blocks.getBlockType( name ) ) { wp.blocks.unregisterBlockType( name ); } } ); } );',
				wp_json_encode( self::DENIED_BLOCKS )
			)
		);
		wp_enqueue_script( 'mody-spectra-guard' );
	}

	/**
	 * Unregisters Spectra's Google Fonts and Google Maps abilities (`wp_abilities_api_init`, last).
	 */
	public static function unregister_abilities(): void {
		foreach ( self::DENIED_ABILITIES as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
	}

	/**
	 * Removes what could carry script from a block as it renders on a member
	 * site (`render_block_data`, first): the script attributes, and every
	 * attribute Spectra prints as an element's name unless it holds a name
	 * ELEMENT_ATTRIBUTES allows (clamp_elements()). Every block passes through
	 * here before it renders, inner blocks included, so this covers each carrier
	 * of content: posts, templates and parts, patterns, reusable blocks, and
	 * what a widget block renders; and content saved before the check existed.
	 *
	 * @param mixed $block The parsed block.
	 * @return mixed
	 */
	public static function strip_block_scripts( $block ) {
		if ( ! is_array( $block ) || empty( $block['attrs'] ) || ! is_array( $block['attrs'] ) || ! self::is_member_site() ) {
			return $block;
		}
		foreach ( self::SCRIPT_ATTRIBUTES as $attribute ) {
			unset( $block['attrs'][ $attribute ] );
		}
		[ $block['attrs'] ] = self::clamp_elements( (string) ( $block['blockName'] ?? '' ), $block['attrs'] );
		return $block;
	}

	/**
	 * Drops every attribute a block prints as an element's name whose value is
	 * not a name it may hold (ELEMENT_ATTRIBUTES, ELEMENT_DEFAULTS). The block
	 * then uses its default. A value that is not a string is dropped too.
	 *
	 * @param string               $block_name The block's name.
	 * @param array<string, mixed> $attrs      Its attributes.
	 * @return array{0: array<string, mixed>, 1: int} The attributes, and how many were dropped.
	 */
	public static function clamp_elements( string $block_name, array $attrs ): array {
		$dropped = 0;
		foreach ( array_keys( self::ELEMENT_DEFAULTS ) as $attribute ) {
			if ( ! array_key_exists( $attribute, $attrs ) ) {
				continue;
			}
			if ( isset( self::ELEMENT_ATTRIBUTES[ $block_name ][ $attribute ] ) ) {
				$names = self::ELEMENT_ATTRIBUTES[ $block_name ][ $attribute ];
			} elseif ( 'htmlTag' === $attribute || str_starts_with( $block_name, 'spectra/' ) ) {
				$names = self::ELEMENT_DEFAULTS[ $attribute ];
			} else {
				continue;
			}
			if ( ! is_string( $attrs[ $attribute ] ) || ! in_array( $attrs[ $attribute ], $names, true ) ) {
				unset( $attrs[ $attribute ] );
				++$dropped;
			}
		}
		return array( $attrs, $dropped );
	}

	/**
	 * Takes Spectra's Block JS off the page on a member site (`init`, first):
	 * its harvesting of each block's script and its printing in the footer.
	 * With strip_block_scripts() nothing reaches it anyway; this makes sure
	 * a Spectra update that reads the attribute some other way prints nothing.
	 */
	public static function remove_block_js(): void {
		global $wp_filter;
		if ( ! self::is_member_site() ) {
			return;
		}
		foreach ( array( 'render_block', 'wp_footer' ) as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && is_object( $function[0] ) && self::BLOCK_JS_CLASS === get_class( $function[0] ) ) {
						remove_filter( $hook, $function, (int) $priority );
					}
				}
			}
		}
	}

	/**
	 * Removes the script attributes, and the element names that are not allowed
	 * (clamp_elements()), from content a user without `unfiltered_html` saves
	 * (`wp_insert_post_data` and `wp_insert_attachment_data`: posts, pages,
	 * templates, parts, patterns, navigation, autosaves). Spectra drops only the
	 * script attributes that are new on the post; this drops every one, because
	 * nothing prints them any more. WP-CLI and cron are the server itself and
	 * are left alone.
	 *
	 * @param mixed $data The post data, slashed.
	 * @return mixed
	 */
	public static function strip_saved_scripts( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['post_content'] ) || ! is_string( $data['post_content'] ) || self::is_trusted_writer() ) {
			return $data;
		}
		$content = wp_unslash( $data['post_content'] );
		$clean   = self::strip_content_scripts( $content );
		if ( $clean !== $content ) {
			$data['post_content'] = wp_slash( $clean );
		}
		return $data;
	}

	/**
	 * Cleans the block widgets a user without `unfiltered_html` saves
	 * (`pre_update_option_widget_block`) the same way.
	 *
	 * @param mixed $widgets The widget instances, by number.
	 * @return mixed
	 */
	public static function strip_widget_scripts( $widgets ) {
		if ( ! is_array( $widgets ) || self::is_trusted_writer() ) {
			return $widgets;
		}
		foreach ( $widgets as $number => $instance ) {
			if ( is_array( $instance ) && isset( $instance['content'] ) && is_string( $instance['content'] ) ) {
				$widgets[ $number ]['content'] = self::strip_content_scripts( $instance['content'] );
			}
		}
		return $widgets;
	}

	/**
	 * Block content without the script attributes and the element names that
	 * are not allowed. Matched on the decoded blocks, not on the text: JSON lets
	 * a key be spelled with `\uXXXX` escapes. Content with nothing to remove is
	 * returned as it came (no round trip).
	 *
	 * @param string $content Serialized blocks.
	 */
	public static function strip_content_scripts( string $content ): string {
		if ( ! has_blocks( $content ) ) {
			return $content;
		}
		$blocks = parse_blocks( $content );
		return self::drop_unsafe_attributes( $blocks ) > 0 ? serialize_blocks( $blocks ) : $content;
	}

	/**
	 * Removes the script attributes, and the element names that are not
	 * allowed, from parsed blocks, in place.
	 *
	 * @param array<int|string, mixed> $blocks Parsed blocks, edited by reference.
	 * @return int How many attributes were removed.
	 */
	private static function drop_unsafe_attributes( array &$blocks ): int {
		$dropped = 0;
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				foreach ( self::SCRIPT_ATTRIBUTES as $attribute ) {
					if ( array_key_exists( $attribute, $block['attrs'] ) ) {
						unset( $block['attrs'][ $attribute ] );
						++$dropped;
					}
				}
				[ $block['attrs'], $clamped ] = self::clamp_elements( (string) ( $block['blockName'] ?? '' ), $block['attrs'] );
				$dropped                      += $clamped;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$dropped += self::drop_unsafe_attributes( $block['innerBlocks'] );
			}
		}
		unset( $block );
		return $dropped;
	}

	/**
	 * Whether this is a member site: any site of the network but the main one.
	 * The same rule as Gating\MemberSite::is_member_site(), written out here
	 * because this class goes into Mody Site (Export\Portable), which has no
	 * member sites: a downloaded single site is never one.
	 */
	private static function is_member_site(): bool {
		return is_multisite() && ! is_main_site();
	}

	/**
	 * Whether the current writer may keep script in content: a user with
	 * `unfiltered_html` (super admins on multisite), or the server itself
	 * (WP-CLI, cron), as Spectra's own check does.
	 */
	private static function is_trusted_writer(): bool {
		return current_user_can( 'unfiltered_html' ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Hides Spectra's dashboard and onboarding menus (`admin_menu`, last).
	 */
	public static function remove_admin_pages(): void {
		foreach ( self::DENIED_ADMIN_PAGES as $slug ) {
			remove_menu_page( $slug );
		}
	}

	/**
	 * Sends direct visits to the hidden pages to the dashboard (`admin_init`).
	 */
	public static function redirect_admin_pages(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		if ( in_array( $page, self::DENIED_ADMIN_PAGES, true ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}
}
