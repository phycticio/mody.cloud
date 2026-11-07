<?php
/**
 * Tests the design presets as the theme's style variations (ticket M2-06).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/style-variations-test.php
 *
 * 1. The theme: every styles/*.json file is valid theme.json (version 3), with a
 *    title and the five palette slugs; WordPress lists them as style variations.
 * 2. Presets reads them: the default (theme.json) first, then the variations.
 * 3. On a member site: applying a preset writes the whole variation into the
 *    site's Global Styles, as the Site Editor does, and the Site Editor's own
 *    REST routes see it as that variation; a color change afterwards makes it
 *    custom; the default empties the Global Styles.
 * 4. The heading rule in style.css (headings take their group's text color).
 *
 * The member site's Global Styles and their revisions are restored. No model call.
 */

use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-48s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$admins = get_super_admins();
wp_set_current_user( get_user_by( 'login', $admins[0] )->ID );

// 1. The theme files ------------------------------------------------------------------

echo "Theme files\n";
$dir   = get_theme_root( 'theme' ) . '/theme';
$files = glob( $dir . '/styles/*.json' );
$bad   = array();
foreach ( array_merge( array( $dir . '/theme.json' ), $files ) as $file ) {
	$data  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$slugs = is_array( $data ) ? array_column( (array) ( $data['settings']['color']['palette'] ?? array() ), 'color', 'slug' ) : array();
	$hex   = array_filter( $slugs, array( SiteDesign::class, 'is_hex' ) );
	if ( ! is_array( $data ) || 3 !== ( $data['version'] ?? 0 ) || array_keys( $hex ) != array( 'primary', 'secondary', 'accent', 'base', 'contrast' ) // phpcs:ignore Universal.Operators.StrictComparisons
		|| ( str_contains( $file, '/styles/' ) && '' === (string) ( $data['title'] ?? '' ) ) ) {
		$bad[] = basename( $file );
	}
}
$check( 'theme.json and every style: version 3, five colors, a title', ! $bad, $bad ? implode( ', ', $bad ) : count( $files ) . ' styles' );
$variations = WP_Theme_JSON_Resolver::get_style_variations();
$check( 'WordPress lists them as style variations', count( $variations ) === count( $files ), implode( ', ', array_column( $variations, 'title' ) ) );

// 2. Presets -----------------------------------------------------------------------------

echo "\nPresets\n";
$expected = array( Presets::DEFAULT );
foreach ( $variations as $variation ) {
	$expected[] = sanitize_title( $variation['title'] );
}
$check( 'the default, then the variations', $expected === Presets::slugs(), Presets::names() );
$file_colors = static function ( string $title ) use ( $files ): array {
	foreach ( $files as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $title === ( $data['title'] ?? '' ) ) {
			return array_intersect_key( array_map( 'strtolower', array_column( $data['settings']['color']['palette'], 'color', 'slug' ) ), array_flip( SiteDesign::SLUGS ) );
		}
	}
	return array();
};
$wrong = array();
foreach ( $variations as $variation ) {
	$slug = sanitize_title( $variation['title'] );
	if ( Presets::colors( $slug ) != $file_colors( $variation['title'] ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
		$wrong[] = $slug;
	}
}
$check( 'colors read from the files', ! $wrong, $wrong ? implode( ', ', $wrong ) : '' );
$check( 'the default style is the theme\'s own (empty Global Styles)', array( 'settings' => array(), 'styles' => array() ) === Presets::variation( Presets::DEFAULT ) && null === Presets::variation( 'no-such-style' ) );

// 3. On a member site -----------------------------------------------------------------------

$others = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
if ( ! $others ) {
	echo "\nNo member site: section 3 skipped.\n";
} else {
	$site = (int) $others[0];
	echo "\nOn a member site ($site)\n";
	switch_to_blog( $site );
	wp_clean_theme_json_cache();
	$styles_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
	$saved     = get_post( $styles_id )->post_content;
	$revisions = wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) );
	register_shutdown_function(
		static function () use ( $site, $styles_id, $saved, $revisions ): void {
			switch_to_blog( $site );
			wp_update_post( wp_slash( array( 'ID' => $styles_id, 'post_content' => $saved ) ) );
			foreach ( array_diff( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ), $revisions ) as $revision ) {
				wp_delete_post_revision( $revision );
			}
			wp_clean_theme_json_cache();
			restore_current_blog();
		}
	);

	$applied = SiteDesign::apply_preset( 'forest' );
	$check( 'apply Forest: the site shows its colors', true === $applied && Presets::colors( 'forest' ) == SiteDesign::colors(), is_wp_error( $applied ) ? $applied->get_error_message() : wp_json_encode( SiteDesign::colors() ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$check( '...it is the active style', 'forest' === SiteDesign::active_preset() );

	// What the Site Editor sees: its own REST routes for the variations and the site's styles.
	$listed = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/theme/variations' ) )->get_data();
	$user   = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . $styles_id ) )->get_data();
	$forest = array_values( array_filter( (array) $listed, static fn( $v ) => 'Forest' === ( $v['title'] ?? '' ) ) )[0] ?? array();
	$sort   = static function ( $value ) use ( &$sort ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return array_map( $sort, $value );
	};
	$same   = $forest && $sort( (array) ( $user['settings'] ?? array() ) ) === $sort( (array) $forest['settings'] ) && $sort( (array) ( $user['styles'] ?? array() ) ) === $sort( (array) $forest['styles'] );
	$check( 'the Site Editor\'s routes: the site holds Forest', $same, count( (array) $listed ) . ' variations listed' );
	$check( '...with a revision, as an edit in the Site Editor', count( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ) ) > count( $revisions ) );

	SiteDesign::apply( array( 'primary' => '#ff7f50' ) );
	$check( 'a color changed afterwards: no active style', null === SiteDesign::active_preset() && '#ff7f50' === SiteDesign::colors()['primary'] );

	$applied = SiteDesign::apply_preset( Presets::DEFAULT );
	$config  = json_decode( get_post( $styles_id )->post_content, true );
	$check( 'the default: Global Styles emptied, the theme\'s style', true === $applied && Presets::DEFAULT === SiteDesign::active_preset() && ! isset( $config['settings'] ) && ! isset( $config['styles'] ) && Presets::colors( Presets::DEFAULT ) == SiteDesign::colors() ); // phpcs:ignore Universal.Operators.StrictComparisons
	restore_current_blog();
}

// 4. The heading rule ----------------------------------------------------------------------

echo "\nTheme CSS\n";
$css = (string) file_get_contents( $dir . '/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$check( 'headings take their group\'s text color', (bool) preg_match( '/\.has-text-color\s+:is\(h1,\s*h2,\s*h3,\s*h4,\s*h5,\s*h6\)\s*\{\s*color:\s*inherit;/', $css ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
