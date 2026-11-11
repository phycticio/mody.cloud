<?php
/**
 * Tests mody/update-settings (tickets/agent-knows-gutenberg-spectra-settings.md, part 1).
 * No model call.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/update-settings-test.php
 *
 * On the first member site, as a throwaway administrator of it (an editor
 * too). Every option the test touches is put back at the end. Refuses to run
 * in production.
 *
 * 1. The list: every setting reads, its allowed values are described, the
 *    tiers are the decided ones, and the catalog for the prompt is short.
 * 2. Each kind of value: set through the ability, read back, refused when wrong.
 * 3. All or nothing: one refused setting (or one the platform never lets
 *    Mody change) changes nothing, and says why.
 * 4. Special settings: the home page by title, search engines (the site's
 *    row too), permalinks, other languages, the login color scheme.
 * 5. Permissions: an editor may not; the declaration stays short.
 * 6. The agent, for real (part 2): ten requests to Qwen on Infomaniak
 *    (search engines held, comments, posts per page, the home page held, the
 *    admin email refused, the timezone, a language held, the tagline, a
 *    question, the login scheme). `-e MODY_TEST_RUNS=3` repeats them,
 *    `-e MODY_TEST_OFFLINE=1` skips them.
 */

use Mody\Platform\Site\Settings;

if ( 'production' === wp_get_environment_type() ) {
	echo "Refusing to run in production.\n";
	return;
}
require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-62s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$sites = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
if ( ! $sites ) {
	echo "No member site: nothing to test.\n";
	return;
}
$site = (int) $sites[0];
switch_to_blog( $site );

$suffix = strtolower( wp_generate_password( 6, false ) );
$admin  = (int) wpmu_create_user( "modysettings{$suffix}", wp_generate_password( 24 ), "modysettings{$suffix}@mody.local" );
$editor = (int) wpmu_create_user( "modysettingsed{$suffix}", wp_generate_password( 24 ), "modysettingsed{$suffix}@mody.local" );
add_user_to_blog( $site, $admin, 'administrator' );
add_user_to_blog( $site, $editor, 'editor' );

// Everything the test may change, put back at the end.
$options = array_merge( array_column( Settings::specs(), 'option' ), array( 'show_on_front', 'page_on_front', 'page_for_posts' ) );
$before  = array();
foreach ( $options as $option ) {
	$before[ $option ] = get_option( $option, null );
}
$public_before = (int) get_site( $site )->public;
$page_ids      = array();
register_shutdown_function(
	static function () use ( $site, $before, $public_before, &$page_ids, $admin, $editor ): void {
		switch_to_blog( $site );
		foreach ( $before as $option => $value ) {
			null === $value ? delete_option( $option ) : update_option( $option, $value );
		}
		update_blog_status( $site, 'public', (string) $public_before );
		foreach ( $page_ids as $page_id ) {
			wp_delete_post( $page_id, true );
		}
		// The rewrite object holds the structure in memory (Settings::save()).
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( (string) $before['permalink_structure'] );
		flush_rewrite_rules( false );
		restore_current_blog();
		wpmu_delete_user( $admin );
		wpmu_delete_user( $editor );
	}
);
wp_set_current_user( $admin );
$ability = wp_get_ability( 'mody/update-settings' );
$run     = static fn( array $changes ) => $ability->execute( array( 'changes' => $changes ) );

// 1. The list ------------------------------------------------------------------------

echo "The list\n";
$specs   = Settings::specs();
$broken  = array();
foreach ( array_keys( $specs ) as $name ) {
	Settings::current( $name );
	if ( '' === Settings::allowed( $name ) ) {
		$broken[] = $name;
	}
}
$check( count( $specs ) . ' settings, each read and described', ! $broken, implode( ', ', $broken ) );
$confirm = array_keys( array_filter( $specs, static fn( array $spec ): bool => Settings::CONFIRM === $spec['tier'] ) );
sort( $confirm );
$decided = array( 'comment_blocklist_words', 'comment_moderation_words', 'comments_only_registered', 'homepage', 'other_languages', 'permalinks', 'posts_page', 'search_engines_visible' );
$check( 'the confirm tier is the decided one', $decided === $confirm, implode( ', ', $confirm ) );
$forbidden = array_intersect( array_column( $specs, 'option' ), array( 'admin_email', 'new_admin_email', 'WPLANG', 'siteurl', 'home', 'mailserver_url', 'mailserver_login', 'mailserver_pass', 'users_can_register', 'default_role', 'active_plugins', 'template', 'stylesheet' ) );
$check( 'nothing refused by the decision is on it', ! $forbidden && ! preg_grep( '/^(connectors_|wpai_)/', array_column( $specs, 'option' ) ), implode( ', ', $forbidden ) );
$catalog = Settings::catalog();
$check( 'the catalog: one line each, under 4,000 characters', count( explode( "\n", $catalog ) ) === count( $specs ) && strlen( $catalog ) < 4000, strlen( $catalog ) . ' characters' );
$check( '... confirm settings marked', 8 === substr_count( $catalog, '[confirm]' ) );
$check( 'to_confirm() picks them out of a call', array( 'homepage' ) === Settings::to_confirm( array( 'changes' => array( 'posts_per_page' => 5, 'homepage' => 'About' ) ) ) );

// 2. Each kind of value ---------------------------------------------------------------

echo "\nEach kind of value\n";
$category = wp_insert_term( "Recipes {$suffix}", 'category' );
$cases    = array(
	'title'                     => array( 'Bread & Co', 'Bread & Co', '' ),
	'tone'                      => array( 'technical', 'technical', 'shouty' ),
	'timezone'                  => array( 'Europe/Madrid', 'Europe/Madrid', 'Mars/Olympus' ),
	'date_format'               => array( 'd/m/Y', 'd/m/Y', str_repeat( 'Y', 41 ) ),
	'week_starts_on'            => array( 'Monday', 'monday', 'someday' ),
	'posts_per_page'            => array( '7', 7, 0 ),
	'feed_content'              => array( 'excerpt', 'excerpt', 'summary' ),
	'default_post_format'       => array( 'quote', 'quote', 'poem' ),
	'comments_on_new_posts'     => array( 'false', false, 'maybe' ),
	'comments_held_for_approval' => array( true, true, array() ),
	'close_old_comments'        => array( 'yes', true, '' ),
	'comment_order'             => array( 'newest_first', 'newest first', 'random' ),
	'thread_depth'              => array( 4, 4, 11 ),
	'medium_width'              => array( 640, 640, -1 ),
	'show_avatars'              => array( false, false, 'sometimes' ),
	'default_category'          => array( "Recipes {$suffix}", "Recipes {$suffix}", 'No such category' ),
);
foreach ( $cases as $name => list( $value, $expected, $wrong ) ) {
	$out = $run( array( $name => $value ) );
	$ok  = ! is_wp_error( $out ) && $expected === Settings::current( $name ) && $expected === ( $out['changed'][ $name ]['new'] ?? null );
	$bad = $run( array( $name => $wrong ) );
	$check( "$name: set, read back, a wrong value refused", $ok && is_wp_error( $bad ) && $expected === Settings::current( $name ), is_wp_error( $out ) ? $out->get_error_message() : wp_json_encode( Settings::current( $name ) ) );
}
$out = $run( array( 'comment_moderation_words' => array( 'casino', ' viagra ', '' ) ) );
$check( 'a list of words: trimmed, empty items dropped', ! is_wp_error( $out ) && array( 'casino', 'viagra' ) === Settings::current( 'comment_moderation_words' ) );
$check( '... stored one per line, as core does', "casino\nviagra" === get_option( 'moderation_keys' ) );
$out = $run( array( 'tagline' => '<script>alert(1)</script>Fresh bread' ) );
$check( 'text is cleaned of tags', ! is_wp_error( $out ) && 'Fresh bread' === Settings::current( 'tagline' ), wp_json_encode( Settings::current( 'tagline' ) ) );

// 3. All or nothing ----------------------------------------------------------------------

echo "\nAll or nothing\n";
$per_page = Settings::current( 'posts_per_page' );
$out      = $run(
	array(
		'posts_per_page' => 12,
		'admin_email'    => 'thief@example.com',
	)
);
$check( 'a refused setting: nothing changed, and why', is_wp_error( $out ) && $per_page === Settings::current( 'posts_per_page' ) && str_contains( $out->get_error_message(), 'Settings → General' ) && 'thief@example.com' !== get_option( 'admin_email' ), is_wp_error( $out ) ? $out->get_error_message() : '' );
$out = $run( array( 'mailserver_pass' => 'x' ) );
$check( 'an unknown setting: refused by name', is_wp_error( $out ) && str_contains( $out->get_error_message(), 'mailserver_pass' ) );
$out = $run(
	array(
		'posts_per_page' => 12,
		'thread_depth'   => 99,
	)
);
$check( 'one wrong value among right ones: nothing changed', is_wp_error( $out ) && $per_page === Settings::current( 'posts_per_page' ) );
$check( 'no changes at all: refused by the schema', is_wp_error( $ability->execute( array( 'changes' => array() ) ) ) );

// 4. Special settings -----------------------------------------------------------------------

echo "\nSpecial settings\n";
$about      = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => "About {$suffix}" ) );
$draft      = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => "Draft {$suffix}" ) );
$page_ids   = array( $about, $draft );
$out        = $run( array( 'homepage' => "About {$suffix}" ) );
$check( 'homepage: a published page by title', ! is_wp_error( $out ) && 'page' === get_option( 'show_on_front' ) && $about === (int) get_option( 'page_on_front' ) && "About {$suffix}" === Settings::current( 'homepage' ) );
$out = $run( array( 'homepage' => "Draft {$suffix}" ) );
$check( '... a draft refused', is_wp_error( $out ) && $about === (int) get_option( 'page_on_front' ) );
$out = $run( array( 'homepage' => 'latest posts' ) );
$check( '... "latest posts" again', ! is_wp_error( $out ) && 'posts' === get_option( 'show_on_front' ) && Settings::LATEST === Settings::current( 'homepage' ) );
$out = $run( array( 'search_engines_visible' => false ) );
$check( 'search engines off: the option and the site\'s row', ! is_wp_error( $out ) && '0' === (string) get_option( 'blog_public' ) && 0 === (int) get_site( $site )->public );
$run( array( 'search_engines_visible' => true ) );
$check( '... and on again', '1' === (string) get_option( 'blog_public' ) && 1 === (int) get_site( $site )->public );
$out = $run( array( 'permalinks' => 'month and name' ) );
$check( 'permalinks: a known structure, the rules flushed', ! is_wp_error( $out ) && '/%year%/%monthnum%/%postname%/' === get_option( 'permalink_structure' ) && (bool) preg_grep( '#^\(\[0-9\]\{4\}\)/\(\[0-9\]\{1,2\}\)/\(\[\^/\]\+\)#', array_keys( (array) get_option( 'rewrite_rules' ) ) ) );
$check( '... any other refused', is_wp_error( $run( array( 'permalinks' => '/%author%/%postname%/' ) ) ) );
if ( class_exists( \Mody\Languages\Languages::class ) ) {
	$out = $run( array( 'other_languages' => array( 'es_ES', 'fr_FR' ) ) );
	$check( 'other languages: offered locales saved', ! is_wp_error( $out ) && array( 'es_ES', 'fr_FR' ) === Settings::current( 'other_languages' ) );
	$check( '... one not offered refused', is_wp_error( $run( array( 'other_languages' => array( 'ja' ) ) ) ) );
}
$schemes = array_keys( Mody\Platform\Login\Schemes::for_palette( Mody\Platform\Login\Schemes::palette() ) );
$last    = (string) end( $schemes );
$out     = $run( array( 'login_color_scheme' => $last ) );
$check( 'login color scheme: one the palette allows', ! is_wp_error( $out ) && $last === Settings::current( 'login_color_scheme' ), $last );
$check( '... an unknown one refused', is_wp_error( $run( array( 'login_color_scheme' => 'neon' ) ) ) );

// 5. Permissions and size ------------------------------------------------------------------------

echo "\nPermissions and size\n";
wp_set_current_user( $editor );
$out = $ability->execute( array( 'changes' => array( 'posts_per_page' => 3 ) ) );
$check( 'an editor may not change settings', is_wp_error( $out ) && 3 !== Settings::current( 'posts_per_page' ) );
wp_set_current_user( $admin );
$declaration = wp_json_encode(
	array(
		'name'        => 'wpab__mody__update-settings',
		'description' => $ability->get_description(),
		'parameters'  => $ability->get_input_schema(),
	)
);
$check( 'the declaration is short (ai-engine.md, "Prompt size")', strlen( (string) $declaration ) < 320, strlen( (string) $declaration ) . ' bytes' );

// 6. The agent, for real (part 2) ------------------------------------------------------------

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo "\nThe agent: real requests skipped (MODY_TEST_OFFLINE is set)\n";
} else {
	echo "\nThe agent, for real (Qwen on Infomaniak)\n";
	$runs    = max( 1, (int) getenv( 'MODY_TEST_RUNS' ) );
	$restore = static function () use ( $before ): void {
		foreach ( $before as $option => $value ) {
			null === $value ? delete_option( $option ) : update_option( $option, $value );
		}
		Mody\Platform\Agent\Agent::forget_held();
	};
	$changes_of = static function ( array $turn ): array {
		$changes = array();
		foreach ( $turn['calls'] ?? array() as $made_call ) {
			if ( str_ends_with( (string) $made_call['name'], 'update-settings' ) ) {
				$changes += (array) ( $made_call['args']['changes'] ?? array() );
			}
		}
		return $changes;
	};
	$called = static fn( array $turn, string $tool ): bool => (bool) array_filter( $turn['calls'] ?? array(), static fn( $made_call ): bool => str_ends_with( (string) $made_call['name'], $tool ) );
	$restore();
	$run( array( 'timezone' => 'UTC' ) );
	$cases = array(
		'Hide the site from search engines'            => static fn( array $t, array $c ): bool => false === filter_var( $c['search_engines_visible'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) && array( 'apply', 'cancel' ) === ( $t['decision']['choices'] ?? null ) && '1' === (string) get_option( 'blog_public' ),
		'Close comments on new posts'                  => static fn( array $t, array $c ): bool => 'closed' === get_option( 'default_comment_status' ) && ! isset( $t['decision'] ),
		'Show 5 posts per page'                        => static fn( array $t, array $c ): bool => 5 === (int) get_option( 'posts_per_page' ),
		"Make the \"About {$suffix}\" page the home page" => static fn( array $t, array $c ): bool => isset( $c['homepage'] ) && isset( $t['decision'] ) && 'page' !== get_option( 'show_on_front' ),
		// Declined, through the tool or in words (rule 1): what matters is that nothing changed.
		'Change the admin email to boss@example.com'   => static fn( array $t, array $c ): bool => ! $c && 'boss@example.com' !== get_option( 'admin_email' ) && 'boss@example.com' !== get_option( 'new_admin_email' ),
		'Set the timezone to Madrid'                   => static fn( array $t, array $c ): bool => 'Europe/Madrid' === get_option( 'timezone_string' ),
		'Add Spanish as a second language'             => static fn( array $t, array $c ): bool => array( 'es_ES' ) === array_values( (array) ( $c['other_languages'] ?? array() ) ) && isset( $t['decision'] ),
		'Change the tagline to Bread for offices'      => static fn( array $t, array $c ): bool => 'Bread for offices' === rtrim( Settings::current( 'tagline' ), '.' ),
		'How many posts are shown per page?'           => static fn( array $t, array $c ): bool => ! $c && ! $called( $t, 'generate-page' ) && str_contains( $t['reply'], (string) Settings::current( 'posts_per_page' ) ),
		// Dark is offered only when the palette allows it: then it is chosen, else Mody says which are.
		'Use the dark login page'                      => static fn( array $t, array $c ): bool => in_array( 'dark', $schemes, true ) ? 'dark' === Settings::current( 'login_color_scheme' ) : ( ! $c && ( ! empty( $t['choices'] ) || str_contains( strtolower( $t['reply'] ), 'light' ) ) ),
	);
	foreach ( $cases as $text => $ok ) {
		$passed = 0;
		$seen   = array();
		for ( $i = 0; $i < $runs; $i++ ) {
			$restore();
			wp_set_current_user( $admin );
			$started = microtime( true );
			$turn    = Mody\Platform\Agent\Agent::respond( $text );
			$changes = $changes_of( $turn );
			$good    = $ok( $turn, $changes );
			$passed += $good ? 1 : 0;
			$seen[]  = ( $good ? '' : 'FAILED: ' ) . wp_json_encode( $changes ) . ( isset( $turn['decision'] ) ? ' held' : '' ) . ( ! empty( $turn['refused'] ) ? ' refused' : '' ) . ( ! empty( $turn['choices'] ) ? ' asked' : '' ) . ' "' . mb_strimwidth( (string) $turn['reply'], 0, 100, '…' ) . '" ' . round( microtime( true ) - $started, 1 ) . 's';
			sleep( 3 ); // Infomaniak's rate limit is shared with production.
		}
		$check( "\"$text\": $passed/$runs", $passed === $runs, implode( ' | ', $seen ) );
	}
	$restore();
}

wp_set_current_user( 0 );
restore_current_blog();
echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
