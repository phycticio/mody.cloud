<?php
/**
 * The ability mody/publish-page.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Site;

use Mody\Platform\SiteEditor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes a draft page on demand (documentation/mvp-spec.md section 8,
 * ticket M3-03): the member asks the agent, or uses the Site Editor's
 * Publish button. The agent never publishes by itself (decided 2026-11-06).
 *
 * The page is published as it is now, without BlockValidator: the member
 * asked for it, and may have edited it by hand, which the Publish button does
 * not validate either. Generated content was validated by mody/generate-page.
 */
final class PagePublisher {

	/** The statuses this ability publishes. */
	public const PUBLISHABLE = array( 'draft', 'pending' );

	/**
	 * Permission callback of mody/publish-page. The page itself also needs
	 * `edit_post`, checked once it is found.
	 */
	public static function can_publish(): bool {
		return is_user_logged_in() && current_user_can( 'publish_pages' );
	}

	/**
	 * Execute callback of mody/publish-page. Input already validated against
	 * the ability's schema.
	 *
	 * @param mixed $input `page_id`, or `page_title`.
	 * @return array<string, mixed>|WP_Error The page, its URL and its Site Editor URL.
	 */
	public static function publish( $input ): array|WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$page_id = (int) ( $input['page_id'] ?? 0 );
		$title   = sanitize_text_field( (string) ( $input['page_title'] ?? '' ) );
		if ( ! $page_id && '' === $title ) {
			return new WP_Error( 'mody_page_required', __( 'Say which page to publish.', 'mody_cloud' ), array( 'drafts' => SiteEditor::pages( self::PUBLISHABLE ) ) );
		}

		// A draft with this title first; else any page, to say why it cannot be published.
		$page = SiteEditor::find_page( $page_id, $title, self::PUBLISHABLE );
		if ( is_wp_error( $page ) && 'mody_page_not_found' === $page->get_error_code() && ! $page_id ) {
			$any  = SiteEditor::find_page( 0, $title );
			$page = is_wp_error( $any ) ? $page : $any;
		}
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( ! current_user_can( 'edit_post', $page->ID ) ) {
			return new WP_Error( 'mody_forbidden', __( 'You cannot publish this page.', 'mody_cloud' ) );
		}

		$name = get_the_title( $page );
		if ( 'publish' === $page->post_status ) {
			/* translators: %s: page title. */
			return new WP_Error( 'mody_page_published', sprintf( __( 'The page "%s" is already published.', 'mody_cloud' ), $name ) );
		}
		if ( ! in_array( $page->post_status, self::PUBLISHABLE, true ) ) {
			return new WP_Error(
				'mody_page_not_draft',
				/* translators: 1: page title, 2: its status, such as "private". */
				sprintf( __( 'The page "%1$s" is not a draft (it is %2$s). Only drafts can be published here.', 'mody_cloud' ), $name, $page->post_status )
			);
		}

		// What the editor's Publish button does: the content as it is, the date set to now.
		$published = wp_update_post(
			array(
				'ID'          => $page->ID,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $published ) || 'publish' !== get_post_status( $page->ID ) ) {
			return new WP_Error( 'mody_page_not_published', __( 'The page could not be published. Please try again, or use the Publish button in the Site Editor.', 'mody_cloud' ) );
		}

		return array(
			'page_id'  => $page->ID,
			'title'    => $name,
			'status'   => 'publish',
			'url'      => (string) get_permalink( $page->ID ),
			/* translators: %s: page title. */
			'label'    => sprintf( __( 'View "%s"', 'mody_cloud' ), $name ),
			'edit_url' => SiteEditor::page_url( $page->ID ),
		);
	}
}
