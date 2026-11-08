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
 * `assets/agent-chat.js`, the chat page's own script, runs on them: same
 * endpoint, background jobs and stored conversation as the page. On member
 * sites the bubble replaces the page (ChatPage::register_menu()).
 */
final class EditorBubble {

	/** URL parameter that opens the bubble when the page loads: `mody-chat=open`. */
	public const OPEN_PARAM = 'mody-chat';

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
	 * The chat page's settings, plus the bubble's own.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$settings                       = ChatPage::settings();
		$settings['bubble']             = true;
		$settings['openParam']          = self::OPEN_PARAM;
		$settings['chips']              = ChatPage::chips();
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
		return $settings;
	}
}
