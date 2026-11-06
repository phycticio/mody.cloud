<?php
/**
 * The "Mody Agent" page in wp-admin.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use Mody\Platform\Brand\BrandProfile;

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
	 */
	public static function register_menu(): void {
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
	 * Loads the chat script and its settings (`load-<page>`).
	 */
	public static function enqueue(): void {
		$root    = dirname( __DIR__, 2 );
		$version = static fn( string $file ): string => (string) filemtime( $root . '/assets/' . $file );

		wp_enqueue_style( 'mody-agent-chat', plugins_url( 'assets/agent-chat.css', $root . '/mody-platform.php' ), array(), $version( 'agent-chat.css' ) );
		wp_enqueue_script( 'mody-agent-chat', plugins_url( 'assets/agent-chat.js', $root . '/mody-platform.php' ), array(), $version( 'agent-chat.js' ), true );

		// A job still running (the page was reloaded meanwhile): the page resumes polling it.
		$running = ChatController::running_job();
		wp_add_inline_script(
			'mody-agent-chat',
			'window.modyAgent = ' . wp_json_encode(
				array(
					'endpoint' => rest_url( ChatController::REST_NAMESPACE . '/chat' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'history'  => ChatController::history(),
					'pending'  => $running ? array(
						'job'     => $running['id'],
						'message' => $running['message'],
					) : null,
					'interval' => 1500,
					'strings'  => array(
						'you'      => __( 'You', 'mody_cloud' ),
						'agent'    => __( 'Mody', 'mody_cloud' ),
						'working'  => __( 'Working on it…', 'mody_cloud' ),
						'slow'     => __( 'Still working. Creating a page can take a minute.', 'mody_cloud' ),
						'failed'   => __( 'The request failed. Reload the page and try again.', 'mody_cloud' ),
						'greeting' => __( 'Hello! Tell me what you would like to do with your site. I can create pages (as drafts), change its colors and open the editor for you.', 'mody_cloud' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		$chips = array(
			__( 'Create an About page', 'mody_cloud' ),
			__( 'Create a Contact page', 'mody_cloud' ),
			__( 'Change the colors', 'mody_cloud' ),
			__( 'What can you do?', 'mody_cloud' ),
		);
		?>
		<div class="wrap mody-agent">
			<h1><?php esc_html_e( 'Mody Agent', 'mody_cloud' ); ?></h1>
			<?php if ( null === BrandProfile::for_site( get_current_blog_id() ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'This site has no brand profile yet. The agent needs it to write in your brand: complete the brand wizard first.', 'mody_cloud' ); ?></p></div>
			<?php endif; ?>

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
