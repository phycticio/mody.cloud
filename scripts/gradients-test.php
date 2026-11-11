<?php
/**
 * Tests gradients: Mody creates them and uses them (2026-11-11, asked for by
 * the maintainer: "Give the agent the ability to create gradients").
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/gradients-test.php
 *
 * On the first member site, as a throwaway administrator. The site's styles
 * and their revisions are put back at the end. Refuses to run in production.
 *
 * 1. Creating (mody/update-styles, settings.color.gradients): linear and
 *    radial gradients of palette colors; color codes, colors not in the
 *    palette, CSS tricks and too many stops refused; a color added in the
 *    same change usable; a color a gradient uses cannot be removed; the
 *    theme's gradients keep their slugs.
 * 2. Using them on pages (BlockValidator): a section's backgroundGradient
 *    and a button's gradient by slug; unknown slugs, raw CSS, Spectra's
 *    advanced gradients and gradients on core blocks refused; normalize()
 *    writes the CSS variable, which stays valid and renders.
 * 3. The prompts: the agent's styles context and the writers' prompts list
 *    the site's gradients.
 * 4. Real requests (Qwen on Infomaniak): create a gradient, show one on the
 *    buttons, a page with a gradient section, an edit of a section.
 *    `-e MODY_TEST_OFFLINE=1` skips them.
 */

use Mody\Platform\Agent\Agent;
use Mody\Platform\AI\BlockValidator;
use Mody\Platform\AI\PageGenerator;
use Mody\Platform\AI\SpectraBlocks;
use Mody\Platform\AI\SpectraLayout;
use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Design\SiteStyles;

if ( 'production' === wp_get_environment_type() ) {
	echo "Refusing to run in production.\n";
	return;
}
require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-64s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
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
$site   = (int) $sites[0];
$suffix = strtolower( wp_generate_password( 5, false ) );
$member = (int) wpmu_create_user( "modygrad{$suffix}", wp_generate_password( 24 ), "modygrad{$suffix}@mody.local" );
add_user_to_blog( $site, $member, 'administrator' );
$super = get_user_by( 'login', get_super_admins()[0] )->ID;

switch_to_blog( $site );
wp_clean_theme_json_cache();
$styles_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
$saved     = get_post( $styles_id )->post_content;
$revisions = wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) );
register_shutdown_function(
	static function () use ( $site, $styles_id, $saved, $revisions, $member, $super ): void {
		wp_set_current_user( $super ); // Restored without kses.
		switch_to_blog( $site );
		wp_update_post( wp_slash( array( 'ID' => $styles_id, 'post_content' => $saved ) ) );
		foreach ( array_diff( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ), $revisions ) as $revision ) {
			wp_delete_post_revision( $revision );
		}
		wp_clean_theme_json_cache();
		Agent::forget_held();
		restore_current_blog();
		wpmu_delete_user( $member );
	}
);
wp_set_current_user( $member );
$styles = wp_get_ability( 'mody/update-styles' );
$run    = static fn( array $changes ) => $styles->execute( array( 'changes' => $changes ) );
$add    = static fn( array $entries ) => $run( array( 'settings' => array( 'color' => array( 'gradients' => $entries ) ) ) );
$list   = static function (): array {
	wp_clean_theme_json_cache();
	return SiteStyles::overview()['gradients'];
};
$why = static fn( $result ): string => is_wp_error( $result ) ? mb_strimwidth( $result->get_error_message(), 0, 110, '…' ) : 'saved';

// 1. Creating ---------------------------------------------------------------------------

echo "Creating gradients\n";
$out = $add( array( array( 'slug' => 'sunrise', 'name' => 'Sunrise', 'gradient' => 'linear-gradient(90deg, var:preset|color|accent 0%, var:preset|color|primary 100%)' ) ) );
$check( 'a linear gradient of palette colors: saved', ! is_wp_error( $out ) && 'linear-gradient(90deg, var:preset|color|accent 0%, var:preset|color|primary 100%)' === ( $list()['sunrise'] ?? '' ), $why( $out ) );
$stored = json_decode( get_post( $styles_id )->post_content, true )['settings']['color']['gradients']['custom'][0]['gradient'] ?? '';
$check( '... stored with CSS variables, as WordPress keeps the theme\'s', 'linear-gradient(90deg, var(--wp--preset--color--accent) 0%, var(--wp--preset--color--primary) 100%)' === $stored, $stored );
$out = $add( array( array( 'slug' => 'glow', 'gradient' => 'radial-gradient(circle at top, var(--wp--preset--color--base) 0%, transparent 70%)' ) ) );
$check( 'a radial one with transparent: saved', ! is_wp_error( $out ) && isset( $list()['glow'] ), $why( $out ) );
$refused = array(
	'a color code'          => 'linear-gradient(90deg, #ff0000 0%, var:preset|color|primary 100%)',
	'a color not in palette' => 'linear-gradient(90deg, var:preset|color|neon 0%, var:preset|color|primary 100%)',
	'rgb()'                 => 'linear-gradient(90deg, rgb(0,0,0), var:preset|color|primary)',
	'a url()'               => 'linear-gradient(90deg, var:preset|color|primary, var:preset|color|accent), url(https://example.com/x.png)',
	'one stop'              => 'linear-gradient(90deg, var:preset|color|primary)',
	'six stops'             => 'linear-gradient(90deg, var:preset|color|primary, var:preset|color|accent, var:preset|color|base, var:preset|color|contrast, var:preset|color|secondary, var:preset|color|primary)',
	'a conic gradient'      => 'conic-gradient(var:preset|color|primary, var:preset|color|accent)',
	'a stop past 100%'      => 'linear-gradient(90deg, var:preset|color|primary 0%, var:preset|color|accent 150%)',
);
$before = $list();
foreach ( $refused as $what => $value ) {
	$out = $add( array( array( 'slug' => 'bad', 'gradient' => $value ) ) );
	$check( "$what: refused, nothing saved", is_wp_error( $out ) && $before === $list(), $why( $out ) );
}
$out = $run(
	array(
		'settings' => array(
			'color' => array(
				'gradients' => array( array( 'slug' => 'coral-fade', 'gradient' => 'linear-gradient(180deg, var:preset|color|coral 0%, var:preset|color|base 100%)' ) ),
				'palette'   => array( array( 'slug' => 'coral', 'name' => 'Coral', 'color' => '#ff7f50' ) ),
			),
		),
	)
);
$check( 'a color added in the same change (listed after): usable', ! is_wp_error( $out ) && isset( $list()['coral-fade'] ), $why( $out ) );
$out = $run( array( 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'coral', 'remove' => true ) ) ) ) ) );
$check( 'a color a gradient uses: cannot be removed', is_wp_error( $out ) && str_contains( $out->get_error_message(), 'coral-fade' ), $why( $out ) );
$out = $add( array( array( 'slug' => 'primary-to-accent', 'gradient' => 'linear-gradient(45deg, var:preset|color|primary 0%, var:preset|color|accent 100%)' ) ) );
$check( 'a theme gradient: its value changes, its slug stays', ! is_wp_error( $out ) && str_starts_with( $list()['primary-to-accent'] ?? '', 'linear-gradient(45deg' ), $why( $out ) );
$out = $add( array( array( 'slug' => 'primary-to-accent', 'remove' => true ) ) );
$check( '... and cannot be removed', is_wp_error( $out ) );
$out = $run( array( 'styles' => array( 'elements' => array( 'button' => array( 'color' => array( 'gradient' => 'var:preset|gradient|sunrise' ) ) ) ) ) );
$check( 'shown as a style: the buttons wear "sunrise"', ! is_wp_error( $out ), $why( $out ) );
$out = $run( array( 'styles' => array( 'elements' => array( 'button' => array( 'color' => array( 'gradient' => 'var:preset|gradient|nope' ) ) ) ) ) );
$check( '... an unknown gradient refused', is_wp_error( $out ), $why( $out ) );

// 2. Using them on pages ------------------------------------------------------------------

echo "\nUsing gradients on pages\n";
$section = static fn( array $attrs, string $inner = "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->" ): string => '<!-- wp:spectra/container ' . wp_json_encode( json_decode( SpectraLayout::SECTION, true ) + $attrs ) . " -->\n" . $inner . "\n<!-- /wp:spectra/container -->";
$button  = static fn( string $json ): string => $section( array(), "<!-- wp:spectra/buttons -->\n<!-- wp:spectra/button " . $json . " /-->\n<!-- /wp:spectra/buttons -->" );
$valid   = static fn( string $markup ): bool => true === BlockValidator::validate( $markup );
$check( 'the site\'s gradients, its own included', in_array( 'sunrise', BlockValidator::gradients(), true ) && in_array( 'primary-to-contrast', BlockValidator::gradients(), true ) );
$check( 'a section\'s backgroundGradient by slug: valid', $valid( $section( array( 'backgroundGradient' => 'sunrise' ) ) ) );
$check( 'a button\'s gradient by slug: valid', $valid( $button( '{"text":"Go","gradient":"primary-to-contrast","textColor":"base"}' ) ) );
$check( 'a style preset reference: valid', $valid( $button( '{"text":"Go","style":{"color":{"gradient":"var:preset|gradient|sunrise"}}}' ) ) );
$check( 'an unknown slug: refused', ! $valid( $section( array( 'backgroundGradient' => 'rainbow' ) ) ) );
$check( 'raw CSS: refused', ! $valid( $section( array( 'backgroundGradient' => 'linear-gradient(90deg, var(--wp--preset--color--primary), var(--wp--preset--color--accent))' ) ) ) );
$check( 'Spectra\'s advanced gradient: refused', ! $valid( $section( array( 'advBgGradient' => 'linear-gradient(90deg,red,blue)' ) ) ) );
$check( 'a gradient on a core block: refused, with where to put it', ! $valid( $section( array(), "<!-- wp:heading {\"gradient\":\"sunrise\"} -->\n<h2 class=\"wp-block-heading has-sunrise-gradient-background has-background\">Hi</h2>\n<!-- /wp:heading -->" ) ) );
$normalized = SpectraBlocks::normalize( SpectraLayout::normalize( $section( array( 'backgroundGradient' => 'sunrise' ) ) ) );
// Read back through the parser: the markup escapes "--" in attributes.
$gradient_of = static fn( string $markup ): string => (string) ( parse_blocks( $markup )[0]['attrs']['backgroundGradient'] ?? '' );
$check( 'normalize(): the CSS variable, still valid', 'var(--wp--preset--gradient--sunrise)' === $gradient_of( $normalized ) && $valid( $normalized ), $gradient_of( $normalized ) );
$check( '... and renders it', str_contains( do_blocks( $normalized ), '--spectra-background-gradient: var(--wp--preset--gradient--sunrise)' ) );
$check( 'the stylesheet defines the site\'s own gradient', str_contains( wp_get_global_stylesheet( array( 'variables' ) ), '--wp--preset--gradient--sunrise' ) );

// 3. The prompts -----------------------------------------------------------------------------

echo "\nThe prompts\n";
$check( 'the agent\'s styles context: the gradients and how to make one', str_contains( SystemPrompt::styles_context(), 'Gradients (settings.color.gradients)' ) && str_contains( SystemPrompt::styles_context(), 'sunrise linear-gradient' ) );
$check( 'the page and edit writers: the site\'s gradients', str_contains( SystemPrompt::for_page( 'a page' ), 'sunrise (Sunrise)' ) && str_contains( SystemPrompt::for_edit( true ), "The site's gradients:" ) );

// 4. Real requests ---------------------------------------------------------------------------

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo "\nReal requests skipped (MODY_TEST_OFFLINE is set)\n";
} else {
	echo "\nReal requests (Qwen on Infomaniak)\n";
	wp_update_post( wp_slash( array( 'ID' => $styles_id, 'post_content' => $saved ) ) );
	wp_clean_theme_json_cache();
	$turn = Agent::respond( 'Create a gradient called Ocean breeze, from our secondary color to our primary color, top to bottom' );
	$made = array_filter( $list(), static fn( string $value, string $slug ): bool => str_contains( $slug, 'ocean' ) || ( str_contains( $value, 'secondary' ) && str_contains( $value, 'primary' ) && str_contains( $value, '180deg' ) ), ARRAY_FILTER_USE_BOTH );
	$check( '"Create a gradient … secondary to primary, top to bottom": saved', (bool) $made, wp_json_encode( $made ) . ' "' . mb_strimwidth( (string) $turn['reply'], 0, 80, '…' ) . '"' );
	sleep( 3 );
	$turn  = Agent::respond( 'Make all the buttons use the primary to accent gradient' );
	$style = json_decode( get_post( $styles_id )->post_content, true )['styles']['elements']['button']['color']['gradient'] ?? '';
	$check( '"Make all the buttons use the primary to accent gradient"', str_contains( (string) $style, 'primary-to-accent' ), (string) $style . ' "' . mb_strimwidth( (string) $turn['reply'], 0, 80, '…' ) . '"' );
	sleep( 3 );
	$page = PageGenerator::generate_page( $site, 'A landing page for our bike shop. The first section, with the title and a button, has the primary to accent gradient as its background.' );
	$first = is_wp_error( $page ) ? '' : $gradient_of( $page );
	$check( 'a page with a gradient section: valid, the first section wears it', 'var(--wp--preset--gradient--primary-to-accent)' === $first, is_wp_error( $page ) ? $page->get_error_message() : $first );
	sleep( 3 );
	$plain = $section( array(), "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Our workshop</h2>\n<!-- /wp:heading -->" );
	$edit  = PageGenerator::edit_markup( $site, $plain, 'Give this section the primary to contrast gradient as background, with light text', true );
	$check( 'an edit: "give this section the primary to contrast gradient"', ! is_wp_error( $edit ) && str_contains( $edit, 'primary-to-contrast' ), is_wp_error( $edit ) ? $edit->get_error_message() : mb_strimwidth( $edit, 0, 160, '…' ) );
}

wp_set_current_user( 0 );
restore_current_blog();
echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
