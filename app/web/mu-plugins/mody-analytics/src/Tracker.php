<?php
/**
 * The front end's beacon: one per page view.
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * A few lines printed in the footer, no file and no library. They send the
 * page's path, the referring address and the screen's width to
 * Collector's route with `navigator.sendBeacon()` (plain text, so the
 * browser sends it without a preflight, and it survives leaving the page).
 *
 * Not printed for logged-in users (the site's own people), previews, the
 * Customizer, embeds and error pages. Automated browsers that say so
 * (`navigator.webdriver`) send nothing.
 */
final class Tracker {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'wp_footer', array( self::class, 'print_script' ), 100 );
	}

	/**
	 * Whether this page view is counted.
	 */
	public static function counts(): bool {
		$counts = ! is_admin() && ! is_user_logged_in() && ! is_preview() && ! is_customize_preview() && ! is_embed() && ! is_404() && ! is_feed();
		/**
		 * Filters whether the current page view is counted.
		 *
		 * @param bool $counts Whether it is.
		 */
		return (bool) apply_filters( 'mody_analytics_counts', $counts );
	}

	/**
	 * `wp_footer`: the beacon.
	 */
	public static function print_script(): void {
		if ( ! self::counts() ) {
			return;
		}
		$url = rest_url( Collector::NAMESPACE . Collector::ROUTE );
		wp_print_inline_script_tag(
			'(function(){if(navigator.webdriver){return;}'
			. 'var b=JSON.stringify({p:location.pathname,r:document.referrer,w:screen.width}),u=' . wp_json_encode( $url ) . ';'
			. 'if(navigator.sendBeacon){navigator.sendBeacon(u,new Blob([b],{type:"text/plain"}));}'
			. 'else if(window.fetch){fetch(u,{method:"POST",body:b,keepalive:true,headers:{"Content-Type":"text/plain"}});}})();',
			array( 'id' => 'mody-analytics' )
		);
	}
}
