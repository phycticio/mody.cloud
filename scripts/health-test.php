<?php
/**
 * Tests Mody Health (tickets/health-page.md): Network Admin → Mody → Health.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/health-test.php
 *
 * 1. Tokens: this month's rows of Mody Agent's log added up per site, every
 *    user's, with the cost estimate and the month's projection.
 * 2. Failures: Mody's turns that ended in an error.
 * 3. Sites: a site at 80% of its space or more is listed.
 * 4. Accounts and payments: the counts, and the key's mode, never the key.
 * 5. The page: under the Mody menu, for super admins only.
 *
 * A throwaway site and its log rows are made and removed. No model call.
 */

use Mody\Health\HealthPage;
use Mody\Health\Report;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-72s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
$suffix = strtolower( wp_generate_password( 6, false ) );
$super  = get_user_by( 'login', get_super_admins()[0] );
$site   = (int) wpmu_create_blog( 'healthtest' . $suffix . '.' . preg_replace( '/^www\./', '', get_network()->domain ), '/', 'Health Test', (int) $super->ID );
$events = class_exists( \Mody\Agent\AgentEvents::class ) ? \Mody\Agent\AgentEvents::table() : '';
register_shutdown_function(
	static function () use ( $site, $events, $wpdb ): void {
		if ( $events ) {
			$wpdb->delete( $events, array( 'site_id' => $site ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( get_site( $site ) ) {
			wpmu_delete_blog( $site, true );
		}
	}
);
$check( 'a throwaway site', $site > 0 );

// 1. Tokens ---------------------------------------------------------------------------------------

echo "\nTokens\n";
if ( ! $events ) {
	$check( 'Mody Agent is active (its log is what the page reads)', false );
} else {
	$before = Report::tokens();
	$row    = static fn( string $tool, array $result, string $when = '' ): bool => (bool) $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$events,
		array(
			'user_id'    => (int) $super->ID,
			'site_id'    => $site,
			'input'      => 'health test',
			'tool'       => $tool,
			'result'     => wp_json_encode( $result ),
			'created_at' => '' !== $when ? $when : gmdate( 'Y-m-d H:i:s' ),
		)
	);
	$row( 'turn', array( 'prompt_tokens' => 3000000, 'completion_tokens' => 1000000, 'outcome' => 'answered' ) );
	$row( 'usage', array( 'prompt_tokens' => 400000, 'completion_tokens' => 100000 ) );
	$row( 'turn', array( 'prompt_tokens' => 9000000, 'completion_tokens' => 0, 'outcome' => 'answered' ), gmdate( 'Y-m-d H:i:s', strtotime( gmdate( 'Y-m-01' ) . ' -2 days' ) ) ); // Last month: not counted.
	$row( 'search', array( 'prompt_tokens' => 7000000 ) ); // A tool's row: not counted.
	$after = Report::tokens();
	$mine  = array_values( array_filter( $after['sites'], static fn( array $s ): bool => $site === $s['id'] ) );
	$check( 'this month\'s turn and usage rows of the site, nothing else', $mine && 4500000 === $mine[0]['tokens'], $mine ? (string) $mine[0]['tokens'] : 'not listed' );
	$check( '... the total grows by them', $after['total'] - $before['total'] === 4500000, (string) ( $after['total'] - $before['total'] ) );
	$check( '... first among the sites, at 90% of its month', $site === $after['sites'][0]['id'] && 0.9 === $mine[0]['share'] );
	$check( '... the cost estimate', abs( $after['cost_eur'] - round( $after['total'] / 1000000 * Report::EUR_PER_MILLION, 2 ) ) < 0.001, (string) $after['cost_eur'] );
	$tenth = (int) strtotime( gmdate( 'Y-m-10 00:00:00' ) . ' UTC' );
	$proj  = Report::tokens( $tenth );
	$check( 'the projection: the pace so far over the whole month', $proj['projected'] === (int) round( $proj['total'] / 9 * (int) gmdate( 't', $tenth ) ), $proj['projected'] . ' from ' . $proj['total'] );

	// 2. Failures.
	echo "\nFailures\n";
	$was = Report::failures();
	$row( 'turn', array( 'prompt_tokens' => 10, 'completion_tokens' => 0, 'outcome' => 'error' ) );
	$now = Report::failures();
	$check( 'a turn that ended in an error is counted, among all turns', $now['agent_errors'] === $was['agent_errors'] + 1 && $now['agent_turns'] === $was['agent_turns'] + 1, $now['agent_errors'] . ' of ' . $now['agent_turns'] );
	$check( '... failed site creations and domains are counted when their plugins are there', ( null === $now['jobs_failed'] ) !== class_exists( \Mody\Sites\Jobs::class ) && ( null === $now['domains'] ) !== class_exists( \Mody\Domains\Mapping::class ) );
}

// 3. Sites ----------------------------------------------------------------------------------------

echo "\nSites\n";
update_blog_option( $site, 'blog_upload_space', 1 ); // 1 MB.
switch_to_blog( $site );
$dir = wp_upload_dir()['basedir'];
restore_current_blog();
wp_mkdir_p( $dir );
file_put_contents( $dir . '/health-test.bin', str_repeat( 'x', 900 * 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
delete_transient( 'dirsize_cache' );
switch_to_blog( $site );
delete_transient( 'dirsize_cache' );
restore_current_blog();
$sites = Report::sites();
$near  = array_values( array_filter( $sites['near'], static fn( array $s ): bool => $site === $s['id'] ) );
$check( 'a site at 80% of its space or more is listed', 1 === count( $near ) && $near[0]['share'] >= Report::SPACE_WARNING, $near ? $near[0]['used_mb'] . ' of ' . $near[0]['allowed_mb'] : wp_json_encode( $sites['near'] ) );
$check( '... the member sites counted, the main site left out', $sites['count'] === count( get_sites( array( 'fields' => 'ids', 'number' => 0, 'site__not_in' => array( get_main_site_id() ), 'deleted' => 0 ) ) ) );

// 4. Accounts and payments ---------------------------------------------------------------------------

echo "\nAccounts and payments\n";
$accounts = Report::accounts();
if ( class_exists( \Mody\Members\Members::class ) ) {
	$all = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Mody\Members\Members::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'accounts: the total, by plan and by status', $all === $accounts['total'] && array_sum( $accounts['statuses'] ) === $all && $accounts['new_day'] <= $accounts['new_week'] && $accounts['new_week'] <= $accounts['new_month'] );
}
$payments = Report::payments();
if ( null !== $payments ) {
	$key = class_exists( \Mody\Billing\Mollie::class ) ? \Mody\Billing\Mollie::key() : '';
	$check( 'payments: on or off and the key\'s mode, never the key', in_array( $payments['key'], array( 'none', 'test', 'live' ), true ) && ( '' === $key || ! str_contains( (string) wp_json_encode( $payments ), $key ) ) && $payments['switched_on'] === (bool) get_site_option( 'mody_payments_open' ) );
}

// 5. The page -------------------------------------------------------------------------------------

echo "\nThe page\n";
wp_set_current_user( (int) $super->ID );
set_current_screen( 'dashboard-network' );
ob_start();
HealthPage::render();
$html = (string) ob_get_clean();
$check( 'a super admin sees the tokens, the accounts, the sites and what failed', str_contains( $html, 'Mody&#039;s tokens this month' ) && str_contains( $html, 'Accounts' ) && str_contains( $html, 'Sites and space' ) && str_contains( $html, 'What failed' ) && str_contains( $html, 'healthtest' . $suffix ) );
$check( '... and no key', '' === ( class_exists( \Mody\Billing\Mollie::class ) ? \Mody\Billing\Mollie::key() : '' ) || ! str_contains( $html, \Mody\Billing\Mollie::key() ) );
$member = (int) wp_insert_user( array( 'user_login' => 'healthm' . $suffix, 'user_email' => 'healthm' . $suffix . '@mody.local', 'user_pass' => wp_generate_password( 24 ), 'role' => '' ) );
wp_set_current_user( $member );
ob_start();
HealthPage::render();
$check( 'anyone else sees nothing', '' === trim( (string) ob_get_clean() ) );
wp_set_current_user( 0 );
wpmu_delete_user( $member );
$check( 'the page hangs under the Mody menu (Mody Sites), for manage_network', 'manage_network' === HealthPage::CAPABILITY && ( ! class_exists( \Mody\Sites\WizardPage::class ) || 'mody-new-site' === \Mody\Sites\WizardPage::SLUG ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
