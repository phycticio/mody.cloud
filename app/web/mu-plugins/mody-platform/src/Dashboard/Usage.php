<?php
/**
 * Mody's work on a site: messages and tokens, by kind of work.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Dashboard;

use Mody\Platform\Agent\AgentEvents;
use Mody\Platform\Agent\TurnMetrics;

defined( 'ABSPATH' ) || exit;

/**
 * Read from the agent's `turn` rows (Agent\TurnMetrics, written for every
 * message since 2026-11-11; earlier messages have none). A message counts
 * in one group (tickets/mody-dashboard.md, decision 5):
 *
 * - **design** when it wrote or changed a page, a template, a design or the
 *   styles (DESIGN_TOOLS), even if it also changed a setting;
 * - **configuration** when it ran any other tool: settings, the menu,
 *   publishing, opening a screen;
 * - **conversation** when it ran none: an answer, a question back, a refusal;
 * - **analytics** for the analyst on the Visitors section (`kind` = `analytics`,
 *   tickets/analytics-chat.md), whatever it ran.
 *
 * Tokens are what the model read and wrote for the message: its prompt
 * (most of it read again from Infomaniak's cache) and its answers, the page
 * writer's included.
 */
final class Usage {

	/** The groups, in the order shown. */
	public const GROUPS = array( 'design', 'configuration', 'conversation', 'analytics' );

	/** Tools that design: pages, templates, parts, the design and the styles. */
	public const DESIGN_TOOLS = array( 'generate-page', 'edit_content', 'update-design', 'update-styles' );

	/** Plain functions that act on nothing: a question back, a refusal. */
	private const TALK_TOOLS = array( 'ask_user', 'refuse_request' );

	/**
	 * The group of a message, from the tools it ran.
	 *
	 * @param string[] $tools The tools.
	 * @param string   $kind  The turn's kind: 'analytics' for the analyst, '' for the editor's agent.
	 */
	public static function group( array $tools, string $kind = '' ): string {
		if ( 'analytics' === $kind ) {
			return 'analytics';
		}
		$tools = array_diff( array_map( 'strval', $tools ), self::TALK_TOOLS );
		if ( array_intersect( $tools, self::DESIGN_TOOLS ) ) {
			return 'design';
		}
		return $tools ? 'configuration' : 'conversation';
	}

	/**
	 * A site's messages and tokens over the last days, by group, with the
	 * pages and templates that were open most.
	 *
	 * @param int $site_id Site.
	 * @param int $days    Days, today included.
	 * @param int $top     Pages listed.
	 * @return array{
	 *   messages: int, tokens: int, since: string,
	 *   groups: array<string, array{messages: int, tokens: int}>,
	 *   open: array<int, array{type: string, id: int|string, messages: int, tokens: int}>
	 * }
	 */
	public static function summary( int $site_id, int $days = 30, int $top = 10 ): array {
		global $wpdb;
		$table = AgentEvents::table();
		$from  = gmdate( 'Y-m-d H:i:s', (int) strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 ' . wp_timezone_string() ) - ( max( 1, $days ) - 1 ) * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The agent's own audit table.
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT result FROM {$table} WHERE site_id = %d AND tool = %s AND created_at >= %s", $site_id, TurnMetrics::TOOL, $from ) );
		$since = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$table} WHERE site_id = %d AND tool = %s", $site_id, TurnMetrics::TOOL ) );
		// phpcs:enable

		$groups = array_fill_keys(
			self::GROUPS,
			array(
				'messages' => 0,
				'tokens'   => 0,
			)
		);
		$open   = array();
		foreach ( (array) $rows as $json ) {
			$data = json_decode( (string) $json, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$tokens = (int) ( $data['prompt_tokens'] ?? 0 ) + (int) ( $data['completion_tokens'] ?? 0 );
			$group  = self::group( (array) ( $data['tools'] ?? array() ), (string) ( $data['kind'] ?? '' ) );
			++$groups[ $group ]['messages'];
			$groups[ $group ]['tokens'] += $tokens;
			$type = (string) ( $data['open']['type'] ?? '' );
			$id   = $data['open']['id'] ?? '';
			if ( '' !== $type && ( is_int( $id ) || ( is_string( $id ) && '' !== $id ) ) ) {
				$key = $type . '|' . $id;
				$open[ $key ] ??= array(
					'type'     => $type,
					'id'       => $id,
					'messages' => 0,
					'tokens'   => 0,
				);
				++$open[ $key ]['messages'];
				$open[ $key ]['tokens'] += $tokens;
			}
		}
		usort( $open, static fn( array $a, array $b ): int => $b['tokens'] <=> $a['tokens'] );

		return array(
			'messages' => array_sum( array_column( $groups, 'messages' ) ),
			'tokens'   => array_sum( array_column( $groups, 'tokens' ) ),
			'since'    => $since,
			'groups'   => $groups,
			'open'     => array_slice( $open, 0, $top ),
		);
	}

	/**
	 * What was open, as the owner knows it: the page's or template's title.
	 *
	 * @param string     $type A post type.
	 * @param int|string $id   A post ID, or a template's `theme//slug`.
	 */
	public static function title( string $type, $id ): string {
		if ( is_string( $id ) && str_contains( $id, '//' ) ) {
			$template = get_block_template( $id, 'wp_template_part' === $type ? 'wp_template_part' : 'wp_template' );
			return $template ? (string) ( $template->title ?: $template->slug ) : $id;
		}
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return __( '(deleted)', 'mody_cloud' );
		}
		return '' !== $post->post_title ? $post->post_title : __( '(no title)', 'mody_cloud' );
	}
}
