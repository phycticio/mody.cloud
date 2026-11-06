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
