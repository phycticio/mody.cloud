<?php
/**
 * The agent as a chat bubble in the editors (ticket M3-05).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;


defined( 'ABSPATH' ) || exit;

/**
 * A round button at the bottom right of the Site Editor and the post editor
 * that opens the agent's chat (asked for by the maintainer, 2026-11-07).
 *
 * `assets/agent-bubble.js` builds the chat's elements in a panel, then
 * `assets/agent-chat.js` then runs the conversation in them: the endpoint,
 * background jobs and stored conversation. The bubble is the one way to
 * talk to Mody: the "Mody Agent" wp-admin page, left only for super admins
 * on the main site, was removed on 2026-11-11 (the maintainer's decision);
 * its old address opens the Site Editor with the chat open
 * (redirect_old_page()).
 */
final class EditorBubble {

	/** URL parameter that opens the bubble when the page loads: `mody-chat=open`. */
	public const OPEN_PARAM = 'mody-chat';

	/** The removed wp-admin page's slug (admin.php?page=mody-agent). */
	private const OLD_PAGE = 'mody-agent';

	/** Editor screens that get the bubble: the post editor and the Site Editor. */
	private const SCREENS = array( 'post', 'site-editor' );

	/**
	 * Hooked to `enqueue_block_editor_assets`, which fires in both editors.
	 */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_admin() || ! $screen || ! in_array( $screen->base, self::SCREENS, true ) || ! current_user_can( Agent::CAPABILITY ) ) {
			return;
		}

		$root    = dirname( __DIR__, 2 );
		$url     = static fn( string $file ): string => plugins_url( 'assets/' . $file, $root . '/mody-platform.php' );
		$version = static fn( string $file ): string => (string) filemtime( $root . '/assets/' . $file );

		wp_enqueue_style( 'mody-agent-chat', $url( 'agent-chat.css' ), array(), $version( 'agent-chat.css' ) );
		\Mody\Platform\Admin\AdminColors::add_agent_chat_css(); // The user's messages in their color scheme's second color.
		wp_enqueue_style( 'mody-agent-bubble', $url( 'agent-bubble.css' ), array( 'mody-agent-chat' ), $version( 'agent-bubble.css' ) );
		// The bubble builds the elements first; the chat script then runs on them.
		// wp-data and wp-blocks: what is open, and applying the agent's edits (M3-06).
		wp_enqueue_script( 'mody-agent-bubble', $url( 'agent-bubble.js' ), array( 'wp-data', 'wp-blocks' ), $version( 'agent-bubble.js' ), true );
		wp_enqueue_script( 'mody-agent-chat', $url( 'agent-chat.js' ), array( 'mody-agent-bubble' ), $version( 'agent-chat.js' ), true );

		wp_add_inline_script( 'mody-agent-bubble', 'window.modyAgent = ' . wp_json_encode( self::settings() ) . ';', 'before' );
	}

	/**
	 * An admin URL that opens with the chat bubble open, for the agent's
	 * buttons: they open in the same tab, and the conversation carries on
	 * there (decided 2026-11-07).
	 *
	 * @param string $url An admin URL, such as a Site Editor link.
	 */
	public static function open_url( string $url ): string {
		return add_query_arg( self::OPEN_PARAM, 'open', $url );
	}

	/**
	 * `admin_page_access_denied`, just before WordPress refuses an unknown
	 * page: the removed "Mody Agent" page's address, from a bookmark or an
	 * old link, opens the Site Editor with the chat open.
	 */
	public static function redirect_old_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which admin page is asked for.
		if ( isset( $_GET['page'] ) && self::OLD_PAGE === $_GET['page'] && current_user_can( Agent::CAPABILITY ) ) {
			wp_safe_redirect( self::open_url( admin_url( 'site-editor.php' ) ) );
			exit;
		}
	}

	/**
	 * The chat's settings for `agent-chat.js`, with the bubble's own.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$settings                       = self::base_settings();
		$settings['bubble']             = true;
		$settings['openParam']          = self::OPEN_PARAM;
		$settings['chips']              = self::chips();
		$settings['maxLength']          = Agent::MAX_MESSAGE_LENGTH;
		$settings['strings']['open']      = __( 'Ask Mody', 'mody_cloud' );
		$settings['strings']['close']     = __( 'Close', 'mody_cloud' );
		$settings['strings']['title']     = __( 'Mody', 'mody_cloud' );
		$settings['strings']['reset']     = __( 'New conversation', 'mody_cloud' );
		$settings['strings']['send']      = __( 'Send', 'mody_cloud' );
		$settings['strings']['message']   = __( 'Message', 'mody_cloud' );
		$settings['strings']['hint']      = __( 'For example: make this paragraph shorter, or create an About page', 'mody_cloud' );
		$settings['strings']['undo']      = __( 'Undo this change', 'mody_cloud' );
		$settings['strings']['otherPage'] = __( 'Another page is open now, so the change was not applied. Open the page again and ask once more.', 'mody_cloud' );
		$settings['strings']['blockGone'] = __( 'The selected block is no longer there, so the change was not applied.', 'mody_cloud' );
		/* translators: %s: a Site Editor screen, such as "Styles → Colors". */
		$settings['strings']['opened'] = __( 'Opened %s.', 'mody_cloud' );
		$settings['strings']['back']   = __( 'Go back', 'mody_cloud' );
		// After a turn that changed the styles or the site settings, when the editor holds unsaved changes to them too.
		$settings['strings']['unsaved'] = __( 'You also have unsaved changes here. Where they overlap with this change, the editor shows yours, and saving keeps them.', 'mody_cloud' );
		// A change held for the owner's decision (Agent::hold()): the three choices, and a save that failed.
		$settings['decisions']               = Agent::decision_labels();
		$settings['menu']                    = \Mody\Platform\Site\Menu::id(); // Reloaded after mody/update-menu (2026-11-11).
		$settings['strings']['settleFailed'] = __( 'Your changes could not be saved, so I made no change. Save them with the editor\'s Save button, then ask me again.', 'mody_cloud' );
		return $settings;
	}

	/**
	 * What the chat script needs: the endpoint, the stored conversation, a
	 * running job, the decisions and the texts.
	 *
	 * @return array<string, mixed>
	 */
	private static function base_settings(): array {
		// A job still running (the page was reloaded meanwhile): the chat resumes polling it.
		$running = ChatController::running_job();
		return array(
			'endpoint' => rest_url( ChatController::REST_NAMESPACE . '/chat' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'history'  => ChatController::display_history(),
			'pending'  => $running ? array(
				'job'     => $running['id'],
				'message' => $running['message'],
			) : null,
			'interval' => 1500,
			// A change held for the owner's decision (Agent::hold()): a setting to confirm
			// is shown with its three choices (2026-11-11).
			'decisions' => Agent::decision_labels(),
			'strings'  => array(
				'you'      => __( 'You', 'mody_cloud' ),
				'agent'    => __( 'Mody', 'mody_cloud' ),
				'working'  => __( 'Working on it…', 'mody_cloud' ),
				'slow'     => __( 'Still working. Creating a page can take a minute.', 'mody_cloud' ),
				'failed'   => __( 'The request failed. Reload the page and try again.', 'mody_cloud' ),
				'greeting' => __( 'Hello! Tell me what you would like to do with your site. I can create pages (as drafts) and publish them when you ask, change its styles and settings, and open the editor for you.', 'mody_cloud' ),
			),
		);
	}

	/**
	 * The suggested requests shown as chips.
	 *
	 * @return list<string>
	 */
	public static function chips(): array {
		return array(
			__( 'Create an About page', 'mody_cloud' ),
			__( 'Create a Contact page', 'mody_cloud' ),
			__( 'Change the colors', 'mody_cloud' ),
			__( 'What can you do?', 'mody_cloud' ),
		);
	}
}
