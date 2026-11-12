<?php
/**
 * Limits on model work and on what the abilities API tells members, wired in
 * one place (security pass, 2026-11-12).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * What this class registers (see Slots and Budget for the rules):
 *
 * - **Model requests.** `pre_http_request` stops a chat completion request
 *   before it leaves when the job running it is past its deadline, and, outside
 *   a chat message (whose tools check the allowance themselves, so that its
 *   last answer is not cut off), when the daily allowance is used up. It sees
 *   every model request, including the page writer's parallel ones.
 * - **The `ai` plugin's abilities.** Each `ai/*` ability runs under model_job():
 *   the daily allowance, one at a time per user and site, and the network's
 *   slots. They are not ours to wrap (the plugin builds each from its own
 *   WP_Ability subclass, which sets its callbacks itself), so core's
 *   `wp_pre_execute_ability` filter runs the whole execute() inside the guard.
 *   `mody/generate-page` does the same in Abilities.
 * - **Server details.** `core/get-environment-info` (PHP, database and
 *   WordPress versions, the environment type) is for super admins only: every
 *   member is an administrator of their own site, and manage_options is all it
 *   asked. The other core abilities return the member's own site and user.
 */
final class Hardening {

	/** Core abilities that tell about the server: super admins only. */
	public const SERVER_ABILITIES = array( 'core/get-environment-info' );

	/** Wall-clock seconds a REST or MCP ability run may keep calling the model. */
	public const ABILITY_DEADLINE = 4 * MINUTE_IN_SECONDS;

	/** Abilities whose execute() runs inside guard_ability() now, by name. */
	private static array $running = array();

	/**
	 * Hooks everything this class does. Called once from mody-platform.php.
	 */
	public static function register(): void {
		add_filter( 'pre_http_request', array( self::class, 'guard_request' ), 1, 3 ); // First: a refused request is not faked or sent.
		add_filter( 'wp_pre_execute_ability', array( self::class, 'guard_ability' ), 10, 4 );
		add_filter( 'wp_ability_permission_result', array( self::class, 'restrict_ability' ), 10, 2 );
	}

	/**
	 * Runs a model-backed job under the daily allowance and the locks.
	 *
	 * @param string   $kind    `job` or `ai` (Slots).
	 * @param callable $work    The job.
	 * @param int      $seconds Wall-clock seconds it may keep calling the model.
	 * @return mixed What the job returns, or a WP_Error: `mody_budget_exceeded` (429),
	 *               `mody_agent_busy` (409), `mody_busy` (429).
	 */
	public static function model_job( string $kind, callable $work, int $seconds = self::ABILITY_DEADLINE ): mixed {
		$over = Budget::check();
		if ( $over ) {
			return $over;
		}
		return Slots::run( $kind, $work, $seconds );
	}

	/**
	 * `pre_http_request`: refuses a chat completion request after the job's
	 * deadline, or over the daily allowance.
	 *
	 * @param mixed  $pre  The value so far (false: send the request).
	 * @param array  $args The request's arguments.
	 * @param string $url  The request's URL.
	 * @return mixed
	 */
	public static function guard_request( $pre, $args, $url ) {
		if ( false !== $pre || ! is_string( $url ) || ! str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/chat/completions' ) ) {
			return $pre;
		}
		if ( Slots::expired() ) {
			return new WP_Error( 'mody_job_deadline', __( 'This took too long and was stopped. Please try again.', 'mody_cloud' ), array( 'status' => 504 ) );
		}
		if ( ! TurnMetrics::active() ) {
			$over = Budget::check();
			if ( $over ) {
				return $over;
			}
		}
		return $pre;
	}

	/**
	 * `wp_pre_execute_ability`: an `ai/*` ability runs, whole, inside model_job().
	 * The ability then checks its input and the user's permissions as always.
	 * Nothing is taken for a visitor who is not logged in: core refuses them.
	 *
	 * @param mixed           $pre     The sentinel: return it unchanged to run the ability as usual.
	 * @param string          $name    The ability's name.
	 * @param mixed           $input   The raw input.
	 * @param \WP_Ability|null $ability The ability.
	 * @return mixed
	 */
	public static function guard_ability( $pre, $name, $input, $ability = null ) {
		if ( ! is_string( $name ) || ! str_starts_with( $name, 'ai/' ) || isset( self::$running[ $name ] ) || ! is_user_logged_in() || ! $ability instanceof \WP_Ability ) {
			return $pre;
		}
		return self::model_job(
			'ai',
			static function () use ( $name, $input, $ability ) {
				self::$running[ $name ] = true; // The ability's own execute() passes this filter again.
				try {
					return $ability->execute( $input );
				} finally {
					unset( self::$running[ $name ] );
				}
			}
		);
	}

	/**
	 * `wp_ability_permission_result`: the server's details are for super admins.
	 *
	 * @param mixed  $permission The permission callback's answer.
	 * @param string $name       The ability's name.
	 * @return mixed
	 */
	public static function restrict_ability( $permission, $name ) {
		return in_array( $name, self::SERVER_ABILITIES, true ) && ! is_super_admin() ? false : $permission;
	}
}
