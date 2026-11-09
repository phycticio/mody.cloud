<?php
/**
 * The editor tour: the Mody agent first, then the Site Editor's parts
 * (ticket M1-03).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Onboarding;

use Mody\Platform\Members\Members;
use Mody\Platform\SiteEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Loads `assets/editor-tour.js` in the Site Editor while a member's tour is
 * pending (Onboarding::tour_pending()), or with `?mody-tour=start`.
 *
 * Plain script, no build step, like the chat bubble. Each step points at an
 * element of the Site Editor (a spotlight and a card), or shows in the
 * middle when the element is not there; the page stays usable under it.
 * The step list is here, so its words are translatable. The selectors are
 * WordPress 7.1's: the sidebar items' IDs (`#global-styles-navigation-item`
 * and so on), the content area, the canvas and the Save button.
 *
 * Finishing or skipping sets the member's stage to `done` through
 * `wp/v2/users/me`, and switches off core's own "Welcome to the site editor"
 * guide (the `core/edit-site` preference `welcomeGuide`), which would cover
 * the tour and loads its pictures from s.w.org (rule 1).
 */
final class EditorTour {

	/**
	 * `enqueue_block_editor_assets`, after the chat bubble's (priority 20).
	 */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_admin() || ! $screen || 'site-editor' !== $screen->base || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		$pending = Onboarding::tour_pending( $user_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows the tour.
		$asked = isset( $_GET[ Onboarding::TOUR_PARAM ] ) && 'start' === sanitize_key( wp_unslash( $_GET[ Onboarding::TOUR_PARAM ] ) );
		if ( ! $pending && ! $asked ) {
			return;
		}

		$root    = dirname( __DIR__, 2 );
		$url     = static fn( string $file ): string => plugins_url( 'assets/' . $file, $root . '/mody-platform.php' );
		$version = static fn( string $file ): string => (string) filemtime( $root . '/assets/' . $file );
		$agent   = wp_script_is( 'mody-agent-chat', 'enqueued' );
		$deps    = array( 'wp-data', 'wp-api-fetch', 'wp-preferences' );
		if ( $agent ) {
			$deps[] = 'mody-agent-chat'; // The bubble exists before the tour starts.
		}

		wp_enqueue_style( 'mody-editor-tour', $url( 'editor-tour.css' ), array(), $version( 'editor-tour.css' ) );
		wp_enqueue_script( 'mody-editor-tour', $url( 'editor-tour.js' ), $deps, $version( 'editor-tour.js' ), true );
		wp_add_inline_script( 'mody-editor-tour', 'window.modyTour = ' . wp_json_encode( self::settings( $user_id, $pending, $agent ) ) . ';', 'before' );
	}

	/**
	 * The tour's settings, steps and words.
	 *
	 * @param int  $user_id The member.
	 * @param bool $pending Whether their stage is `tour` (finishing sets `done`).
	 * @param bool $agent   Whether the chat bubble is on this screen.
	 * @return array<string, mixed>
	 */
	public static function settings( int $user_id, bool $pending, bool $agent ): array {
		$user  = get_userdata( $user_id );
		$name  = $user ? $user->display_name : '';
		$site  = get_bloginfo( 'name' );
		$ends  = Members::trial_ends( $user_id );
		$steps = self::steps( $site, $agent );

		return array(
			'pending'  => $pending,
			'meta'     => Onboarding::META,
			'done'     => Onboarding::STAGE_DONE,
			'identity' => SiteEditor::url( '/identity' ),
			'steps'    => $steps,
			'name'     => $name,
			'site'     => $site,
			'strings'  => array(
				'next'     => __( 'Next', 'mody_cloud' ),
				'back'     => __( 'Back', 'mody_cloud' ),
				'skip'     => __( 'Skip tour', 'mody_cloud' ),
				'skipStep' => __( 'Skip this step', 'mody_cloud' ),
				'start'    => __( 'Show me around', 'mody_cloud' ),
				'finish'   => __( 'Start building', 'mody_cloud' ),
				'waiting'  => __( 'Waiting for your message…', 'mody_cloud' ),
				'thinking' => __( 'Mody is working on it…', 'mody_cloud' ),
				'answered' => __( 'Mody answered. Read it, then go on.', 'mody_cloud' ),
				/* translators: 1: the step's number, 2: how many steps there are. */
				'progress' => __( '%1$d of %2$d', 'mody_cloud' ),
				'close'    => __( 'Close the tour', 'mody_cloud' ),
				'label'    => __( 'Editor tour', 'mody_cloud' ),
				/* translators: 1: the member's name, 2: the site's name. */
				'welcome'  => '' !== $name ? __( 'Welcome, %1$s!', 'mody_cloud' ) : __( 'Welcome to %2$s!', 'mody_cloud' ),
				'trialEnd' => $ends
					/* translators: %s: the trial's last day. */
					? sprintf( __( 'Your free trial runs until %s.', 'mody_cloud' ), wp_date( (string) get_option( 'date_format' ), $ends ) )
					: '',
			),
		);
	}

	/**
	 * The steps, in order. Each has an `id`, a `title`, a `text`, and
	 * optionally `target` (CSS selectors, the first found is used), `wait`
	 * (`reply`: the agent's answer to a message sent during the step),
	 * `starters` (texts that fill the chat's input), `open` (`chat`: open
	 * the bubble) and `leave` (`identity`: return to the Identity screen).
	 *
	 * @param string $site  The site's name.
	 * @param bool   $agent Whether the chat bubble is on this screen.
	 * @return list<array<string, mixed>>
	 */
	public static function steps( string $site, bool $agent ): array {
		$steps = array(
			array(
				'id'    => 'welcome',
				'title' => '', // The welcome line, with the member's name.
				/* translators: %s: the site's name. */
				'text'  => sprintf( __( 'This is the Site Editor, where %s is built. Mody, your AI agent, does most of the work with you. Let\'s take two minutes to see how it all works.', 'mody_cloud' ), $site ),
			),
			array(
				'id'     => 'identity',
				'target' => array( '.edit-site-layout__area', '#mody-identity-fields' ),
				'title'  => __( 'Your site\'s identity', 'mody_cloud' ),
				'text'   => __( 'Its name, tagline, logo and icon, and below them your mission and tone of voice. Mody reads them every time it writes for you, so everything stays on brand.', 'mody_cloud' ),
			),
		);
		if ( $agent ) {
			$steps[] = array(
				'id'     => 'agent',
				'target' => array( '#mody-agent-bubble .mody-bubble__toggle' ),
				'title'  => __( 'Meet Mody, your agent', 'mody_cloud' ),
				'text'   => __( 'Ask in plain words. Mody writes pages, changes colors and fonts, sets your tagline, and opens the right screen for you. It is always here, at the bottom right of the editor.', 'mody_cloud' ),
			);
			$steps[] = array(
				'id'       => 'try-1',
				'target'   => array( '#mody-agent-panel' ),
				'open'     => 'chat',
				'wait'     => 'reply',
				/* translators: %s: the site's name. */
				'title'    => sprintf( __( 'Try it: tell Mody about %s', 'mody_cloud' ), $site ),
				'text'     => __( 'Type what you do, in one sentence, and press Enter: Mody can keep it as your mission or your tagline. Pick a starter below to fill in the beginning.', 'mody_cloud' ),
				'starters' => array(
					__( 'Our mission: we ', 'mody_cloud' ),
					__( 'Our tagline is: ', 'mody_cloud' ),
				),
			);
			$steps[] = array(
				'id'       => 'try-2',
				'target'   => array( '#mody-agent-panel' ),
				'open'     => 'chat',
				'wait'     => 'reply',
				'leave'    => 'identity',
				'title'    => __( 'Nice! Now ask for something you can see', 'mody_cloud' ),
				'text'     => __( 'For example a page, or other colors. Mody may ask you a question first, or open the screen where the change is.', 'mody_cloud' ),
				'starters' => array(
					/* translators: %s: the site's name. */
					sprintf( __( 'Write a home page for %s about ', 'mody_cloud' ), $site ),
					__( 'Use warmer colors', 'mody_cloud' ),
					__( 'Make the headings bigger', 'mody_cloud' ),
				),
			);
		}
		return array_merge(
			$steps,
			array(
				array(
					'id'     => 'preview',
					'target' => array( '.edit-site-layout__canvas-container' ),
					'title'  => __( 'Your site, live', 'mody_cloud' ),
					'text'   => __( 'This is your site as visitors see it. Click it to edit the page itself, block by block. While a page is open, Mody changes what you are looking at.', 'mody_cloud' ),
				),
				array(
					'id'     => 'styles',
					'target' => array( '#global-styles-navigation-item' ),
					'title'  => __( 'Styles', 'mody_cloud' ),
					'text'   => __( 'Colors, fonts and spacing for the whole site. Pick one of the ready-made styles, or fine-tune each part.', 'mody_cloud' ),
				),
				array(
					'id'     => 'pages',
					'target' => array( '#page-navigation-item' ),
					'title'  => __( 'Pages', 'mody_cloud' ),
					'text'   => __( 'Every page of your site. Pages Mody writes arrive here as drafts, and go live when you publish them, or ask Mody to.', 'mody_cloud' ),
				),
				array(
					'id'     => 'navigation',
					'target' => array( '#navigation-navigation-item' ),
					'title'  => __( 'Navigation', 'mody_cloud' ),
					'text'   => __( 'The menus of your header and footer: which pages they link to, and in what order.', 'mody_cloud' ),
				),
				array(
					'id'     => 'patterns',
					'target' => array( '#patterns-navigation-item' ),
					'title'  => __( 'Patterns', 'mody_cloud' ),
					'text'   => __( 'Ready-made sections, such as a hero, a price list or testimonials, to add to any page. Your header and footer are here too.', 'mody_cloud' ),
				),
				array(
					'id'     => 'templates',
					'target' => array( '#template-navigation-item' ),
					'title'  => __( 'Templates', 'mody_cloud' ),
					'text'   => __( 'The layouts your pages and posts use, such as the blog or the "page not found" page. Most sites never need to change them.', 'mody_cloud' ),
				),
				array(
					'id'     => 'save',
					'target' => array( '.edit-site-save-hub' ),
					'title'  => __( 'Save', 'mody_cloud' ),
					'text'   => __( 'What you change in the editor waits here until you save it, and so do Mody\'s edits to the page that is open, so you can undo them. Mody saves its changes to your colors and settings itself.', 'mody_cloud' ),
				),
				array(
					'id'    => 'done',
					'title' => __( 'You\'re all set', 'mody_cloud' ),
					'text'  => __( 'Mody is always at the bottom right. Ask it for your next page, or explore the editor on your own.', 'mody_cloud' ),
				),
			)
		);
	}
}
