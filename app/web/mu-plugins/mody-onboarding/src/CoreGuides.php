<?php
/**
 * Core's welcome guides, switched off in both editors.
 *
 * @package Mody\Onboarding
 */

namespace Mody\Onboarding;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress shows a welcome guide the first time someone opens the post
 * editor, the Site Editor, a page or template in it, or its Styles. Each
 * guide loads its pictures and videos from s.w.org (WordPress.org, in the
 * United States: rule 1), and in the Site Editor they open on top of the
 * editor tour (found on 2026-11-20: Mody opened a page during the tour and
 * "Editing a page" covered the tour's card). The tour is the platform's
 * welcome, so these guides are off for everyone (decided by the agent on
 * 2026-11-20, in the night report; until then the tour switched off only the
 * Site Editor's first guide, on the site it ran on, when it finished).
 *
 * They are user preferences (`core/preferences`, saved per user and site),
 * so a small script sets each one to false when an editor loads and keeps it
 * false (a guide's "Learn about styles" command sets one back to true). The
 * Help menu's "Welcome Guide" items stop opening anything; WordPress's
 * documentation stays a link away.
 */
final class CoreGuides {

	/** The preferences that show a guide: scope => keys. */
	public const GUIDES = array(
		'core/edit-post' => array( 'welcomeGuide', 'welcomeGuideTemplate' ),
		'core/edit-site' => array( 'welcomeGuide', 'welcomeGuidePage', 'welcomeGuideStyles', 'welcomeGuideTemplate' ),
	);

	/**
	 * `enqueue_block_editor_assets`: the script, after core's preferences
	 * store (both editors load it).
	 */
	public static function enqueue(): void {
		wp_add_inline_script( 'wp-preferences', self::script(), 'after' );
	}

	/**
	 * The script: every guide's preference false now, and whenever it changes.
	 */
	public static function script(): string {
		return '( function () { var guides = ' . wp_json_encode( self::GUIDES ) . ';'
			. ' function quiet() { if ( ! window.wp || ! wp.data ) { return; } var get = wp.data.select( "core/preferences" ), set = wp.data.dispatch( "core/preferences" );'
			. ' Object.keys( guides ).forEach( function ( scope ) { guides[ scope ].forEach( function ( key ) { if ( false !== get.get( scope, key ) ) { set.set( scope, key, false ); } } ); } ); }'
			. ' try { quiet(); wp.data.subscribe( quiet, "core/preferences" ); } catch ( e ) {} } )();';
	}
}
