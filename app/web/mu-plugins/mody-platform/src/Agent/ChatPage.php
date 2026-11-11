<?php
/**
 * The "Mody Agent" page in wp-admin.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use Mody\Platform\Gating\MemberSite;

defined( 'ABSPATH' ) || exit;

/**
 * A plain chat page (mvp-spec section 8): HTML from PHP, and a small script
 * that posts to `mody-platform/v1/chat` with `fetch`. No JS build step.
 */
final class ChatPage {

	/** Admin page slug. */
	public const SLUG = 'mody-agent';

	/**
	 * Hooked to `admin_menu`.
	 *
	 * Decided 2026-11-07 (M3-05): on member sites the editor's chat bubble
	 * replaces this page; on the main site only super admins keep it.
	 */
	public static function register_menu(): void {
		if ( ! self::shows_menu() ) {
			return;
		}
		$hook = add_menu_page(
			__( 'Mody Agent', 'mody_cloud' ),
			__( 'Mody Agent', 'mody_cloud' ),
			Agent::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-format-chat',
			3
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * Whether the current user gets this page on the current site: never on
	 * member sites (the bubble replaces it), and on the main site of a
	 * network only for super admins.
	 */
	public static function shows_menu(): bool {
		return ! MemberSite::is_member_site() && ( ! is_multisite() || is_super_admin() );
	}

	/**
	 * Loads the chat script and its settings (`load-<page>`).
	 */
	public static function enqueue(): void {
		$root    = dirname( __DIR__, 2 );
		$version = static fn( string $file ): string => (string) filemtime( $root . '/assets/' . $file );

		wp_enqueue_style( 'mody-agent-chat', plugins_url( 'assets/agent-chat.css', $root . '/mody-platform.php' ), array(), $version( 'agent-chat.css' ) );
		\Mody\Platform\Admin\AdminColors::add_agent_chat_css(); // The user's messages in their color scheme's second color.
		wp_enqueue_script( 'mody-agent-chat', plugins_url( 'assets/agent-chat.js', $root . '/mody-platform.php' ), array(), $version( 'agent-chat.js' ), true );
		wp_add_inline_script( 'mody-agent-chat', 'window.modyAgent = ' . wp_json_encode( self::settings() ) . ';', 'before' );
	}

	/**
	 * What the chat script needs: shared by this page and the editor's bubble.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
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
			// can be held on this page too, not only in the editor's bubble (2026-11-11).
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

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		$chips = self::chips();
		?>
		<div class="wrap mody-agent">
			<h1><?php esc_html_e( 'Mody Agent', 'mody_cloud' ); ?></h1>

			<div id="mody-agent-log" class="mody-agent__log" role="log" aria-live="polite"></div>

			<div class="mody-agent__chips">
				<?php foreach ( $chips as $chip ) : ?>
					<button type="button" class="button mody-agent__chip" data-message="<?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $chip ); ?></button>
				<?php endforeach; ?>
			</div>

			<form id="mody-agent-form" class="mody-agent__form">
				<label for="mody-agent-input" class="screen-reader-text"><?php esc_html_e( 'Message', 'mody_cloud' ); ?></label>
				<textarea id="mody-agent-input" rows="3" maxlength="<?php echo (int) Agent::MAX_MESSAGE_LENGTH; ?>" placeholder="<?php esc_attr_e( 'For example: create a page about our opening hours', 'mody_cloud' ); ?>"></textarea>
				<div class="mody-agent__buttons">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Send', 'mody_cloud' ); ?></button>
					<button type="button" id="mody-agent-reset" class="button-link"><?php esc_html_e( 'New conversation', 'mody_cloud' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}
}
