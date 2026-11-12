<?php
/**
 * Starts the builder: a detached WP-CLI process per download job.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Runs Exporter::run() outside the web request (tickets/take-your-site-with-you.md,
 * decision 5).
 *
 * The normal way is a detached WP-CLI process, `wp mody-export run <job>`,
 * spawned from the request with setsid and nohup: it holds none of the five
 * PHP-FPM workers, has no max_execution_time, and survives the end of the
 * request that started it. start() launches one; watch() is the watchdog, so
 * a job that is waiting for its turn, or whose builder was killed (a container
 * restart, an out-of-memory), is picked up again. WP-Cron does not reach member
 * sites in Docker, so nothing here depends on it.
 *
 * Two builds run at once on the whole network (Jobs::MAX_RUNNING). start() and
 * watch() both respect it, and count a builder that was spawned a moment ago
 * and has not claimed its job yet as running (free()); a job that finds the
 * network full stays queued. The check and the spawn marker are made under one
 * database lock, so requests that arrive together cannot all see a free slot.
 *
 * Who calls watch(), the source of truth for "jobs keep moving":
 *
 * - the dashboard's status poll (Dashboard\ExportPanel::handle_status), every
 *   few seconds while a member waits for a build;
 * - the Mody dashboard's render, so an administrator opening the page restarts
 *   a stuck job even when nobody is polling;
 * - the end of every build (Exporter::run(), in its `finally`): the slot it
 *   frees goes to the next job at once;
 * - `wp mody-export watch` (Command::watch()), for a host cron. The deploy
 *   does not install it. On the production host, every five minutes, so files
 *   are deleted and jobs restarted even while no member has a page open:
 *   `0-59/5 * * * * docker exec -u www-data mody_cloud_app wp --path=/var/www/html/wp --url=https://mody.cloud mody-export watch --quiet`
 *
 * The fallback, when no process can be spawned (proc_open is disabled, there
 * is no `wp` binary, or three spawns for one job never claimed it): the job
 * is built inside a PHP-FPM worker after the response is sent
 * (`fastcgi_finish_request()` in a shutdown hook, the pattern of the
 * wizard and the chat). It holds that worker until the build ends, so it is
 * the exception, not the plan.
 *
 * A builder's output goes to `build.log` in the job's folder, which the
 * sweep deletes with the files.
 */
final class Runner {

	/** Seconds a spawned builder is given to claim its job before watch() spawns another. */
	private const CLAIM_WAIT = 30;

	/** Spawns for one claim that came to nothing before the job is built inside a PHP-FPM worker. */
	private const MAX_SPAWNS = 3;

	/** Site transient (per job) remembering the last spawn. */
	private const SPAWN_KEY = 'mody_export_spawn_';

	/** The jobs to build after this response, when no process could be spawned. */
	private static array $later = array();

	/**
	 * Starts a job's builder, without waiting for it, when there is a free
	 * build (Jobs::MAX_RUNNING). When the network is full the job stays queued,
	 * and watch() starts it as soon as a build ends. A job that is not waiting
	 * (finished, or with a builder that is working), or whose builder was
	 * spawned a moment ago, is left alone.
	 *
	 * @param int $job_id Job.
	 * @return bool Whether a builder was started (or scheduled, in the fallback).
	 */
	public static function start( int $job_id ): bool {
		$job = Jobs::get( $job_id );
		if ( ! $job || ! in_array( $job_id, Jobs::waiting(), true ) ) {
			return false;
		}
		if ( self::given_up( $job ) ) {
			return false;
		}
		$spawns = self::locked(
			static function () use ( $job ): int {
				return self::is_spawning( $job ) || self::slots( self::waiting_jobs() ) < 1 ? 0 : self::mark( $job );
			},
			0
		);
		if ( $spawns < 1 ) {
			return false;
		}
		self::launch( $job, $spawns );
		return true;
	}

	/**
	 * The watchdog: while fewer than Jobs::MAX_RUNNING builds are running (or
	 * about to), starts the oldest waiting jobs. A job is spawned at most once
	 * per CLAIM_WAIT seconds (its builder needs a moment to boot WordPress and
	 * claim it), and a running job gone stale is restarted from the step it was
	 * on. One that was claimed Jobs::MAX_ATTEMPTS times and still did not finish
	 * is failed instead. Who calls it is in the class description.
	 *
	 * @return int Builders started.
	 */
	public static function watch(): int {
		// Decided under one lock: which jobs take the free builds, each marked as spawned.
		$launches = self::locked(
			static function (): array {
				$jobs = array_values( array_filter( self::waiting_jobs(), static fn( array $job ): bool => ! self::given_up( $job ) ) );
				$free = self::slots( $jobs );
				$next = array();
				foreach ( $jobs as $job ) {
					if ( $free < 1 ) {
						break;
					}
					if ( ! self::is_spawning( $job ) ) {
						$next[] = array( $job, self::mark( $job ) );
						--$free;
					}
				}
				return $next;
			},
			array()
		);
		foreach ( $launches as $launch ) {
			self::launch( $launch[0], $launch[1] );
		}
		return count( $launches );
	}

	/**
	 * Builds that can start now: Jobs::MAX_RUNNING less the ones running and
	 * the ones whose builder was spawned a moment ago and has not claimed yet.
	 */
	public static function free(): int {
		return self::slots( self::waiting_jobs() );
	}

	/**
	 * Whether a job's builder was spawned a moment ago, and is about to claim it.
	 *
	 * @param array $job A job.
	 */
	public static function is_spawning( array $job ): bool {
		$state = get_site_transient( self::SPAWN_KEY . $job['id'] );
		return is_array( $state ) && ( $state['attempts'] ?? -1 ) === $job['attempts'] && time() - (int) ( $state['at'] ?? 0 ) < self::CLAIM_WAIT;
	}

	/**
	 * The WP-CLI command that builds a job, on the job's site (WP-CLI needs
	 * the site's address to load the right site of the network).
	 *
	 * @param array $job A job.
	 * @return string[] The binary and its arguments, unescaped; empty when there is no `wp`.
	 */
	public static function command( array $job ): array {
		$wp = self::binary();
		if ( '' === $wp ) {
			return array();
		}
		return array(
			$wp,
			'--path=' . untrailingslashit( ABSPATH ),
			'--url=' . self::site_url( $job ),
			'mody-export',
			'run',
			(string) $job['id'],
		);
	}

	/**
	 * The jobs that will be built inside this request's PHP-FPM worker once it
	 * has answered (the fallback).
	 *
	 * @return int[] Job IDs.
	 */
	public static function scheduled(): array {
		return self::$later;
	}

	/**
	 * `shutdown`: builds the scheduled jobs after the response is sent. Public
	 * only because WordPress calls it.
	 */
	public static function run_later(): void {
		$ids         = self::$later;
		self::$later = array();
		if ( ! $ids ) {
			return;
		}
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A build takes minutes; max_execution_time is 300.
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		foreach ( $ids as $job_id ) {
			Exporter::run( $job_id );
		}
	}

	/**
	 * Spawns the detached builder.
	 *
	 * @param array $job A job.
	 * @return bool Whether a process was launched; false means use the fallback.
	 */
	private static function spawn( array $job ): bool {
		$command = self::command( $job );

		/**
		 * Replaces spawning: return true when something started the builder,
		 * false to use the in-process fallback, null (the default) to spawn.
		 * For tests, which must not launch real builds.
		 *
		 * @param bool|null $spawned Null.
		 * @param array     $job     The job.
		 * @param string[]  $command The command that would run.
		 */
		$spawned = apply_filters( 'mody_export_spawn', null, $job, $command );
		if ( null !== $spawned ) {
			return (bool) $spawned;
		}
		if ( ! $command || ! function_exists( 'proc_open' ) || ! function_exists( 'proc_close' ) ) {
			return false;
		}
		$dir = Jobs::dir( $job );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// The shell backgrounds the builder and exits at once; setsid (when there is one) gives it a session of its own, so
		// nothing sent to this worker's process group reaches it, and nohup keeps it from a hangup.
		$script  = 'if command -v setsid >/dev/null 2>&1; then S=setsid; else S=; fi; ';
		$script .= '$S nohup ' . implode( ' ', array_map( 'escapeshellarg', $command ) );
		$script .= ' </dev/null >>' . escapeshellarg( $dir . '/build.log' ) . ' 2>&1 &';
		$process = proc_open( // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- The point of this class: a detached builder.
			array( 'sh', '-c', $script ),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'file', '/dev/null', 'w' ),
				2 => array( 'file', '/dev/null', 'w' ),
			),
			$pipes,
			sys_get_temp_dir()
		);
		if ( ! is_resource( $process ) ) {
			return false;
		}
		proc_close( $process ); // The shell, not the builder: it has already exited.
		return true;
	}

	/**
	 * The jobs waiting for a builder (Jobs::waiting()), as rows.
	 *
	 * @return array[] Jobs, oldest first.
	 */
	private static function waiting_jobs(): array {
		$jobs = array();
		foreach ( Jobs::waiting() as $job_id ) {
			$job = Jobs::get( $job_id );
			if ( $job ) {
				$jobs[] = $job;
			}
		}
		return $jobs;
	}

	/**
	 * Free builds, given the waiting jobs.
	 *
	 * @param array[] $waiting The waiting jobs (waiting_jobs()).
	 */
	private static function slots( array $waiting ): int {
		$spawning = count( array_filter( $waiting, array( self::class, 'is_spawning' ) ) );
		return max( 0, Jobs::MAX_RUNNING - Jobs::running() - $spawning );
	}

	/**
	 * Fails a waiting job that was claimed Jobs::MAX_ATTEMPTS times already:
	 * Jobs::claim() refuses it, so a builder spawned for it would exit at once.
	 *
	 * @param array $job A job.
	 * @return bool Whether it was given up.
	 */
	private static function given_up( array $job ): bool {
		if ( $job['attempts'] < Jobs::MAX_ATTEMPTS ) {
			return false;
		}
		Jobs::fail( $job['id'], 'The builder stopped before it finished, ' . Jobs::MAX_ATTEMPTS . ' times.' );
		return true;
	}

	/**
	 * Remembers that a job's builder is being spawned now.
	 *
	 * @param array $job A job.
	 * @return int Spawns made for this attempt, this one included.
	 */
	private static function mark( array $job ): int {
		$spawns = self::spawns( $job ) + 1;
		set_site_transient(
			self::SPAWN_KEY . $job['id'],
			array(
				'at'       => time(),
				'attempts' => $job['attempts'],
				'n'        => $spawns,
			),
			HOUR_IN_SECONDS
		);
		return $spawns;
	}

	/**
	 * Spawns a marked job's builder, or schedules the fallback when it cannot
	 * be spawned or three spawns never claimed the job.
	 *
	 * @param array $job    A job.
	 * @param int   $spawns The spawn count mark() returned.
	 */
	private static function launch( array $job, int $spawns ): void {
		if ( $spawns > self::MAX_SPAWNS || ! self::spawn( $job ) ) {
			self::later( $job['id'] );
		}
	}

	/**
	 * Runs a callback holding a database lock, so that checking for a free
	 * build and marking the job as spawned is one step across requests (a
	 * named lock: no table, nothing to clean up if the process dies).
	 *
	 * @template T
	 * @param callable():T $callback What to run.
	 * @param T            $default  What to return when the lock could not be had in five seconds.
	 * @return T
	 */
	private static function locked( callable $callback, $default ) {
		global $wpdb;
		$lock = $wpdb->base_prefix . 'mody_export_spawn';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A named lock.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) ) ) {
			return $default;
		}
		try {
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A named lock.
		}
	}

	/**
	 * How many spawns for this job's current attempt never led to a claim.
	 *
	 * @param array $job A job.
	 */
	private static function spawns( array $job ): int {
		$state = get_site_transient( self::SPAWN_KEY . $job['id'] );
		return is_array( $state ) && ( $state['attempts'] ?? -1 ) === $job['attempts'] ? (int) ( $state['n'] ?? 0 ) : 0;
	}

	/**
	 * Schedules the in-process fallback, once per job and request.
	 *
	 * @param int $job_id Job.
	 */
	private static function later( int $job_id ): void {
		if ( in_array( $job_id, self::$later, true ) ) {
			return;
		}
		if ( ! self::$later ) {
			add_action( 'shutdown', array( self::class, 'run_later' ), 0 );
		}
		self::$later[] = $job_id;
	}

	/**
	 * The WP-CLI binary: the filter, then the PATH, then the two usual places.
	 *
	 * @return string An absolute path; empty when there is none.
	 */
	private static function binary(): string {
		/**
		 * Filters the WP-CLI binary the builder is spawned with.
		 *
		 * @param string $binary An absolute path; empty to look for `wp`.
		 */
		$binary = (string) apply_filters( 'mody_export_wp_binary', '' );
		if ( '' !== $binary ) {
			return $binary;
		}
		$dirs = array_merge( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ), array( '/usr/local/bin', '/usr/bin' ) );
		foreach ( $dirs as $dir ) {
			if ( '' !== $dir && @is_executable( $dir . '/wp' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir may forbid a directory.
				return $dir . '/wp';
			}
		}
		return '';
	}

	/**
	 * The address WP-CLI loads a job's site by: the domain and path in the
	 * network's site list. A site that no longer exists loads the main site,
	 * where Exporter::run() fails the job.
	 *
	 * @param array $job A job.
	 */
	private static function site_url( array $job ): string {
		$site   = get_site( (int) $job['site_id'] );
		$scheme = (string) wp_parse_url( network_home_url(), PHP_URL_SCHEME );
		$scheme = '' !== $scheme ? $scheme : 'https';
		if ( ! $site ) {
			return network_home_url();
		}
		return $scheme . '://' . $site->domain . untrailingslashit( $site->path );
	}
}
