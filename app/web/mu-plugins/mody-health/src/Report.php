<?php
/**
 * The numbers on the Health page, each part from the plugin that owns it.
 *
 * @package Mody\Health
 */

namespace Mody\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only. Each method returns null when the plugin it reads is missing.
 */
final class Report {

	/**
	 * What a million of Mody's tokens costs, in EUR: Infomaniak's price for the
	 * model with our mix of prompt and answer tokens, about CHF 0.60 a million
	 * with cached prompt tokens at full price, 1 CHF taken as 1.07 EUR
	 * (tickets/pricing-per-site.md, The numbers behind it). An estimate.
	 */
	public const EUR_PER_MILLION = 0.64;

	/** A site's space used, as a share of its allowance, from which it is listed. */
	public const SPACE_WARNING = 0.8;

	/** A site's month of Mody's tokens, as a share of its allowance, from which it is flagged. */
	public const TOKENS_WARNING = 0.8;

	/** The most sites the page looks at (the space of each needs a directory walk). */
	public const MAX_SITES = 1000;

	/** Mody Agent's log rows that hold tokens: its turns and the `ai` plugin's features. */
	private const TOKEN_TOOLS = array( 'turn', 'usage' );

	/**
	 * Accounts (Mody Members): totals, plans, statuses, sign-ups lately.
	 *
	 * @return array{total: int, plans: array<string, int>, statuses: array<string, int>, new_day: int, new_week: int, new_month: int, invited: int, registration_open: bool, invited_only: bool}|null
	 */
	public static function accounts(): ?array {
		if ( ! class_exists( \Mody\Members\Members::class ) ) {
			return null;
		}
		global $wpdb;
		$table = \Mody\Members\Members::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Mody Members' table; read only.
		$plans    = (array) $wpdb->get_results( "SELECT plan, COUNT(*) AS n FROM {$table} GROUP BY plan", ARRAY_A );
		$statuses = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$since    = static fn( int $days ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		$invited  = (int) $wpdb->get_var( "SELECT COUNT(invited_by) FROM {$table}" );
		// phpcs:enable
		$by = static fn( array $rows, string $key ): array => array_map( 'intval', array_column( $rows, 'n', $key ) );
		return array(
			'total'             => array_sum( $by( $plans, 'plan' ) ),
			'plans'             => $by( $plans, 'plan' ),
			'statuses'          => $by( $statuses, 'status' ),
			'new_day'           => $since( 1 ),
			'new_week'          => $since( 7 ),
			'new_month'         => $since( 30 ),
			'invited'           => $invited,
			'registration_open' => (bool) get_site_option( 'mody_registration_open' ),
			'invited_only'      => class_exists( \Mody\Members\Offers::class ) && \Mody\Members\Offers::invited_only(),
		);
	}

	/**
	 * Sites and their space (WordPress's own): how many, the space used in
	 * all, and the sites near or over their allowance.
	 *
	 * @return array{count: int, used_mb: float, near: array<int, array{id: int, domain: string, used_mb: float, allowed_mb: float, share: float}>}
	 */
	public static function sites(): array {
		$near = array();
		$used = 0.0;
		$ids  = get_sites(
			array(
				'fields'       => 'ids',
				'number'       => self::MAX_SITES,
				'site__not_in' => array( get_main_site_id() ),
				'deleted'      => 0,
			)
		);
		foreach ( $ids as $id ) {
			switch_to_blog( (int) $id );
			$site_used = (float) get_space_used();
			$allowed   = (float) get_space_allowed();
			restore_current_blog();
			$used += $site_used;
			if ( $allowed > 0 && $site_used >= $allowed * self::SPACE_WARNING ) {
				$near[] = array(
					'id'         => (int) $id,
					'domain'     => (string) get_site( (int) $id )->domain,
					'used_mb'    => round( $site_used, 1 ),
					'allowed_mb' => $allowed,
					'share'      => round( $site_used / $allowed, 3 ),
				);
			}
		}
		usort( $near, static fn( array $a, array $b ): int => $b['share'] <=> $a['share'] );
		return array(
			'count'   => count( $ids ),
			'used_mb' => round( $used, 1 ),
			'near'    => $near,
		);
	}

	/**
	 * Mody's tokens this calendar month (Mody Agent's log), every request
	 * counted, super admins' included (Infomaniak bills them all): the total,
	 * its estimated cost, the month's projection, and the sites that used the
	 * most, each against its allowance.
	 *
	 * @param int $now A Unix time (tests).
	 * @return array{total: int, cost_eur: float, projected: int, projected_eur: float, sites: array<int, array{id: int, domain: string, tokens: int, share: float|null}>, allowance: int}|null
	 */
	public static function tokens( int $now = 0 ): ?array {
		if ( ! class_exists( \Mody\Agent\AgentEvents::class ) ) {
			return null;
		}
		$now   = $now ? $now : time();
		$start = gmdate( 'Y-m-01 00:00:00', $now );
		global $wpdb;
		$table = \Mody\Agent\AgentEvents::table();
		$in    = "'" . implode( "','", self::TOKEN_TOOLS ) . "'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Mody Agent's log; read only; constants.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT site_id, SUM( COALESCE( CAST( JSON_VALUE( result, '$.prompt_tokens' ) AS UNSIGNED ), 0 ) + COALESCE( CAST( JSON_VALUE( result, '$.completion_tokens' ) AS UNSIGNED ), 0 ) ) AS tokens FROM {$table} WHERE tool IN ( {$in} ) AND created_at >= %s GROUP BY site_id",
				$start
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error ) {
			// No JSON_VALUE: PHP adds them up.
			$rows   = array();
			$sums   = array();
			$events = (array) $wpdb->get_results( $wpdb->prepare( "SELECT site_id, result FROM {$table} WHERE tool IN ( {$in} ) AND created_at >= %s", $start ), ARRAY_A );
			foreach ( $events as $event ) {
				$data                                   = json_decode( (string) $event['result'], true );
				$sums[ (int) $event['site_id'] ] = ( $sums[ (int) $event['site_id'] ] ?? 0 ) + (int) ( $data['prompt_tokens'] ?? 0 ) + (int) ( $data['completion_tokens'] ?? 0 );
			}
			foreach ( $sums as $site_id => $tokens ) {
				$rows[] = array(
					'site_id' => $site_id,
					'tokens'  => $tokens,
				);
			}
		}
		// phpcs:enable
		$allowance = class_exists( \Mody\Agent\Budget::class ) ? (int) \Mody\Agent\Budget::MONTH : 0;
		$sites     = array();
		$total     = 0;
		foreach ( (array) $rows as $row ) {
			$tokens = (int) $row['tokens'];
			$total += $tokens;
			$site   = get_site( (int) $row['site_id'] );
			$sites[] = array(
				'id'     => (int) $row['site_id'],
				/* translators: %d: a site's ID. */
				'domain' => $site ? (string) $site->domain : sprintf( __( 'deleted site #%d', 'mody_cloud' ), (int) $row['site_id'] ),
				'tokens' => $tokens,
				'share'  => $allowance ? round( $tokens / $allowance, 3 ) : null,
			);
		}
		usort( $sites, static fn( array $a, array $b ): int => $b['tokens'] <=> $a['tokens'] );
		$elapsed   = max( 1.0, ( $now - (int) strtotime( $start . ' UTC' ) ) / DAY_IN_SECONDS );
		$projected = (int) round( $total / $elapsed * (int) gmdate( 't', $now ) );
		return array(
			'total'         => $total,
			'cost_eur'      => round( $total / 1000000 * self::EUR_PER_MILLION, 2 ),
			'projected'     => $projected,
			'projected_eur' => round( $projected / 1000000 * self::EUR_PER_MILLION, 2 ),
			'sites'         => $sites,
			'allowance'     => $allowance,
		);
	}

	/**
	 * What failed lately: provisioning jobs (Mody Sites, 30 days), Mody's
	 * turns that ended in an error (Mody Agent, 7 days), domains not live
	 * (Mody Domains). A part is null without its plugin.
	 *
	 * @return array{jobs_failed: int|null, agent_errors: int|null, agent_turns: int|null, domains: array<string, int>|null}
	 */
	public static function failures(): array {
		global $wpdb;
		$out = array(
			'jobs_failed'  => null,
			'agent_errors' => null,
			'agent_turns'  => null,
			'domains'      => null,
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Other plugins' tables; read only.
		if ( class_exists( \Mody\Sites\Jobs::class ) ) {
			$out['jobs_failed'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Mody\Sites\Jobs::table() . " WHERE status = 'failed' AND updated_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		}
		if ( class_exists( \Mody\Agent\AgentEvents::class ) ) {
			$table = \Mody\Agent\AgentEvents::table();
			$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
			$out['agent_turns']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE tool = 'turn' AND created_at >= %s", $since ) );
			$out['agent_errors'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE tool = 'turn' AND created_at >= %s AND result LIKE %s", $since, '%"outcome":"error"%' ) );
		}
		if ( class_exists( \Mody\Domains\Mapping::class ) ) {
			$rows           = (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . \Mody\Domains\Mapping::table() . ' GROUP BY status', ARRAY_A );
			$out['domains'] = array_map( 'intval', array_column( $rows, 'n', 'status' ) );
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Payments (Mody Billing): whether members are offered them, the switch,
	 * and the key's mode, never the key.
	 *
	 * @return array{offered: bool, switched_on: bool, key: string}|null
	 */
	public static function payments(): ?array {
		if ( ! class_exists( \Mody\Billing\Mollie::class ) ) {
			return null;
		}
		$key = \Mody\Billing\Mollie::key();
		return array(
			'offered'     => \Mody\Billing\Mollie::offered(),
			'switched_on' => (bool) get_site_option( 'mody_payments_open' ),
			'key'         => '' === $key ? 'none' : ( str_starts_with( $key, 'live_' ) ? 'live' : 'test' ),
		);
	}
}
