<?php
/**
 * Tests mody/update-styles, the guarded patch of a site's Global Styles
 * (ticket M3-10, part A).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/site-styles-test.php
 *
 * On a member site, as a member (no unfiltered_html, so kses runs on every save):
 * 1. The ability: registered, for edit_theme_options only.
 * 2. The ticket's example requests: links, headings, buttons, width, the base
 *    color, each saved as a revision, read back, with the matching Styles screen.
 * 3. The member's own colors: added, used, refused while used, removed.
 * 4. Fonts only from the Font Library: an installed family is activated.
 * 5. The guard: everything refused leaves the styles and revisions unchanged.
 * 6. Removing a style (null) and a change that changes nothing.
 * 7. The agent, for real (part B): the ticket's phrases, each from the site's
 *    own styles, checked on what was saved. Paced for Infomaniak's rate limit;
 *    `-e MODY_TEST_RUNS=3` repeats them, `-e MODY_TEST_OFFLINE=1` skips them.
 *
 * The site's Global Styles, their revisions, the test fonts and users are
 * put back. No model call.
 */

use Mody\Platform\Agent\Agent;
use Mody\Platform\Design\Fonts;
use Mody\Platform\Design\SiteDesign;
use Mody\Platform\Design\SiteStyles;
use Mody\Platform\SiteEditor;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-62s %s\n", $ok ? 'ok' : 'FAIL', $label, $ok ? '' : $detail );
};

$others = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
if ( ! $others ) {
	echo "No member site: create one first.\n";
	exit( 1 );
}
$site   = (int) $others[0];
$suffix = strtolower( wp_generate_password( 6, false ) );
$users  = array();
foreach ( array( 'administrator', 'editor' ) as $role ) {
	$id = (int) wp_insert_user(
		array(
			'user_login' => 'modystyles' . substr( $role, 0, 3 ) . $suffix,
			'user_email' => 'modystyles' . substr( $role, 0, 3 ) . $suffix . '@mody.local',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => '',
		)
	);
	add_user_to_blog( $site, $id, $role );
	$users[ $role ] = $id;
}
$admin = get_user_by( 'login', get_super_admins()[0] )->ID;

switch_to_blog( $site );
wp_clean_theme_json_cache();
$styles_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
$saved     = get_post( $styles_id )->post_content;
$revisions = wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) );
$fonts     = array();
register_shutdown_function(
	static function () use ( $site, $styles_id, $saved, $revisions, &$fonts, $users, $admin ): void {
		wp_set_current_user( $admin ); // Restored without kses.
		switch_to_blog( $site );
		wp_update_post( wp_slash( array( 'ID' => $styles_id, 'post_content' => $saved ) ) );
		foreach ( array_diff( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ), $revisions ) as $revision ) {
			wp_delete_post_revision( $revision );
		}
		foreach ( $fonts as $font ) {
			wp_delete_post( $font, true );
		}
		wp_clean_theme_json_cache();
		restore_current_blog();
		foreach ( $users as $user ) {
			wpmu_delete_user( $user );
		}
	}
);

$config = static function () use ( $styles_id ): array {
	wp_clean_theme_json_cache();
	clean_post_cache( $styles_id );
	$data = json_decode( get_post( $styles_id )->post_content, true );
	return is_array( $data ) ? $data : array();
};
$count  = static fn(): int => count( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ) );
$run    = static fn( array $changes ) => SiteStyles::update( array( 'changes' => $changes ) );
$paths  = static fn( $result ): string => is_array( $result ) ? implode( ', ', array_column( $result['changed'], 'path' ) ) : '';
$detail = static fn( $result ): string => is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$place  = static fn( $result ): string => is_array( $result ) ? (string) $result['place'] : '';
$css    = static fn( string $type, string $slug ): string => 'var(--wp--preset--' . $type . '--' . $slug . ')'; // The form kses keeps, and the ability stores.

// 1. The ability ------------------------------------------------------------------------

echo "The ability (site $site)\n";
$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'mody/update-styles' ) : null;
$check( 'mody/update-styles is registered', null !== $ability );
wp_set_current_user( $users['editor'] );
$check( 'an editor may not use it (no edit_theme_options)', ! SiteStyles::can_update() );
wp_set_current_user( $users['administrator'] );
$check( 'the site\'s administrator may', SiteStyles::can_update() );
$check( '...and has no unfiltered_html (kses runs on saves)', ! current_user_can( 'unfiltered_html' ) );
if ( $ability ) {
	$through = $ability->execute( array( 'changes' => array( 'styles' => array( 'elements' => array( 'link' => array( 'color' => array( 'text' => 'var:preset|color|secondary' ) ) ) ) ) ) );
	$check( 'through the Abilities API, input and output schemas pass', is_array( $through ) && 'styles.elements.link.color.text' === $paths( $through ), $detail( $through ) );
}

// 2. The ticket's examples ---------------------------------------------------------------

echo "\nThe ticket's examples\n";
$before = $count();
$result = $run( array( 'styles' => array( 'elements' => array( 'link' => array( 'color' => array( 'text' => 'var:preset|color|accent' ) ) ) ) ) );
$check( '"Links in the accent color": saved', is_array( $result ) && $css( 'color', 'accent' ) === ( $config()['styles']['elements']['link']['color']['text'] ?? '' ), $detail( $result ) );
$check( '...as one revision', $before + 1 === $count() );
$check( '...old and new value in the result', is_array( $result ) && $css( 'color', 'secondary' ) === $result['changed'][0]['old'] && $css( 'color', 'accent' ) === $result['changed'][0]['new'], $detail( $result ) );
$check( '...Styles → Colors opens, in place', 'Styles → Colors' === $place( $result ) && true === ( $result['navigate'] ?? null ) && str_contains( (string) ( $result['url'] ?? '' ), 'section=%2Fcolors' ), $detail( $result ) );

$result = $run( array( 'styles' => array( 'elements' => array( 'heading' => array( 'typography' => array( 'fontSize' => 'var:preset|font-size|xx-large', 'lineHeight' => 1.15 ) ) ) ) ) );
$check( '"Make the headings bigger": saved', is_array( $result ) && $css( 'font-size', 'xx-large' ) === ( $config()['styles']['elements']['heading']['typography']['fontSize'] ?? '' ), $detail( $result ) );
$check( '...Styles → Typography opens', 'Styles → Typography' === $place( $result ), $detail( $result ) );
$check( '...the link color stays (a merge, not a replace)', $css( 'color', 'accent' ) === ( $config()['styles']['elements']['link']['color']['text'] ?? '' ) );

$result = $run( array( 'styles' => array( 'blocks' => array( 'core/button' => array( 'border' => array( 'radius' => '999px' ) ) ) ) ) );
$check( '"Round the buttons": saved', is_array( $result ) && '999px' === ( $config()['styles']['blocks']['core/button']['border']['radius'] ?? '' ), $detail( $result ) );
$check( '...Styles → Blocks → Button opens', str_starts_with( $place( $result ), 'Styles → Blocks → ' ) && str_contains( (string) ( $result['url'] ?? '' ), 'core%252Fbutton' ), $detail( $result ) );

$result = $run( array( 'settings' => array( 'layout' => array( 'contentSize' => '800px' ) ) ) );
$check( '"A wider content area": saved', is_array( $result ) && '800px' === ( $config()['settings']['layout']['contentSize'] ?? '' ), $detail( $result ) );
$check( '...old value is the theme\'s (720px), Styles → Layout opens', is_array( $result ) && '720px' === $result['changed'][0]['old'] && 'Styles → Layout' === $place( $result ), $detail( $result ) );
$check( '...the site shows it', '800px' === wp_get_global_settings( array( 'layout', 'contentSize' ) ) );

$result  = $run( array( 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'base', 'color' => '#FDF8F0' ) ) ) ) ) );
$palette = $config()['settings']['color']['palette']['theme'] ?? array();
$check( '"Make the background a warm white": base recolored', is_array( $result ) && '#fdf8f0' === ( array_column( $palette, 'color', 'slug' )['base'] ?? '' ), $detail( $result ) );
$check( '...the five theme slugs all stay', array( 'primary', 'secondary', 'accent', 'base', 'contrast' ) === array_column( $palette, 'slug' ), wp_json_encode( array_column( $palette, 'slug' ) ) );
$check( '...only base changed, Styles → Colors → Palette opens', is_array( $result ) && 'settings.color.palette.base' === $paths( $result ) && 'Styles → Colors → Palette' === $place( $result ), $detail( $result ) );
$check( '...mody/update-design still reads the brand colors', 3 === count( SiteDesign::colors() ) );

$result = $run(
	array(
		'styles' => array(
			'elements' => array( 'button' => array( ':hover' => array( 'color' => array( 'background' => 'var(--wp--preset--color--secondary)' ) ) ) ),
			'spacing'  => array( 'blockGap' => 'var:preset|spacing|50' ),
		),
	)
);
$check( 'a hover state and spacing: saved (both reference forms)', is_array( $result ) && 2 === count( $result['changed'] ), $detail( $result ) );
$check( '...two sections changed, so the Styles overview opens', 'Styles' === $place( $result ), $detail( $result ) );

// 3. The member's own colors -----------------------------------------------------------------

echo "\nThe member's own colors\n";
$result = $run( array( 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'sand', 'name' => 'Sand', 'color' => '#e8d8b0' ) ) ) ) ) );
$custom = $config()['settings']['color']['palette']['custom'] ?? array();
$check( 'a new color goes to the custom palette', is_array( $result ) && array( 'sand' ) === array_column( $custom, 'slug' ), $detail( $result ) );
$result = $run( array( 'styles' => array( 'color' => array( 'background' => 'var:preset|color|sand' ) ) ) );
$check( '...and can be used by its slug', is_array( $result ), $detail( $result ) );
$result = $run( array( 'settings' => array( 'color' => array( 'palette' => array( 'custom' => array( array( 'slug' => 'sand', 'name' => 'Warm sand' ) ) ) ) ) ) );
$check( '...renamed (keyed by origin)', is_array( $result ) && 'Warm sand' === ( $config()['settings']['color']['palette']['custom'][0]['name'] ?? '' ), $detail( $result ) );
$before = $count();
$result = $run( array( 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'sand', 'remove' => true ) ) ) ) ) );
$check( '...not removed while a style uses it', is_wp_error( $result ) && str_contains( $result->get_error_message(), 'styles.color.background' ) && $before === $count(), $detail( $result ) );
$result = $run(
	array(
		'styles'   => array( 'color' => array( 'background' => null ) ),
		'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'sand', 'remove' => true ) ) ) ),
	)
);
$check( '...removed with its use, in one change', is_array( $result ) && empty( $config()['settings']['color']['palette']['custom'] ) && ! isset( $config()['styles']['color']['background'] ), $detail( $result ) );

// 4. Fonts from the Font Library -------------------------------------------------------------

echo "\nFonts\n";
$before = $count();
$result = $run( array( 'styles' => array( 'elements' => array( 'heading' => array( 'typography' => array( 'fontFamily' => 'var:preset|font-family|lora' ) ) ) ) ) );
$check( '"Use Lora for headings", not installed: refused', is_wp_error( $result ) && str_contains( $result->get_error_message(), 'Font Library' ) && $before === $count(), $detail( $result ) );

wp_set_current_user( $admin ); // Installed as the Font Library's REST controller does, without kses.
$family = wp_insert_post(
	wp_slash(
		array(
			'post_type'    => 'wp_font_family',
			'post_status'  => 'publish',
			'post_title'   => 'Lora',
			'post_name'    => 'lora',
			'post_content' => wp_json_encode( array( 'fontFamily' => Fonts::stack( 'lora' ) ) ),
		)
	)
);
$fonts[] = $family;
foreach ( Fonts::faces( 'lora' ) as $face ) {
	$fonts[] = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'wp_font_face',
				'post_status'  => 'publish',
				'post_parent'  => $family,
				'post_title'   => 'Lora ' . $face['fontStyle'],
				'post_content' => wp_json_encode( $face, JSON_UNESCAPED_SLASHES ),
			)
		)
	);
}
wp_set_current_user( $users['administrator'] );

// Spectra's StyleGuide bridge activates every installed family on save_post_wp_font_family
// and save_post_wp_font_face (found 2026-11-08). Deactivate it, as the Font Library can.
$result = $run( array( 'settings' => array( 'typography' => array( 'fontFamilies' => array( array( 'slug' => 'lora', 'remove' => true ) ) ) ) ) );
$check( '...installed and active: can be deactivated', is_array( $result ) || 'mody_no_change' === $result->get_error_code(), $detail( $result ) );
$check( '...no longer active', ! in_array( 'lora', array_column( (array) ( $config()['settings']['typography']['fontFamilies']['custom'] ?? array() ), 'slug' ), true ) );

$result   = $run( array( 'styles' => array( 'elements' => array( 'heading' => array( 'typography' => array( 'fontFamily' => 'var:preset|font-family|lora' ) ) ) ) ) );
$families = $config()['settings']['typography']['fontFamilies']['custom'] ?? array();
$check( '...installed: activated and used', is_array( $result ) && array( 'lora' ) === array_column( $families, 'slug' ), $detail( $result ) );
$check( '...its faces come from the Font Library', 2 === count( $families[0]['fontFace'] ?? array() ) && str_contains( (string) wp_json_encode( $families[0]['fontFace'][0]['src'] ?? '', JSON_UNESCAPED_SLASHES ), '/fonts/lora/' ), wp_json_encode( $families ) );
$check( '...the result lists the activation and the style', is_array( $result ) && str_contains( $paths( $result ), 'settings.typography.fontFamilies.lora' ) && str_contains( $paths( $result ), 'styles.elements.heading.typography.fontFamily' ), $detail( $result ) );
$check( '...Styles → Typography opens', 'Styles → Typography' === $place( $result ), $detail( $result ) );
$check( '...the site offers it', in_array( 'lora', array_column( (array) ( wp_get_global_settings( array( 'typography', 'fontFamilies' ) )['custom'] ?? array() ), 'slug' ), true ) );
$result = $run( array( 'settings' => array( 'typography' => array( 'fontFamilies' => array( array( 'slug' => 'inter', 'fontFamily' => 'Comic Sans MS' ) ) ) ) ) );
$check( 'the theme\'s font cannot change', is_wp_error( $result ), $detail( $result ) );

// 5. The guard ---------------------------------------------------------------------------------

echo "\nThe guard (nothing saved)\n";
$snapshot = get_post( $styles_id )->post_content;
$before   = $count();
$refused  = array(
	'custom CSS'                         => array( 'styles' => array( 'css' => 'body{display:none}' ) ),
	'a block\'s CSS'                     => array( 'styles' => array( 'blocks' => array( 'core/paragraph' => array( 'css' => 'color:red' ) ) ) ),
	'hex in a style'                     => array( 'styles' => array( 'color' => array( 'text' => '#ff0000' ) ) ),
	'a named color in a style'           => array( 'styles' => array( 'elements' => array( 'link' => array( 'color' => array( 'text' => 'coral' ) ) ) ) ),
	'rgba in a text shadow'              => array( 'styles' => array( 'typography' => array( 'textShadow' => '1px 1px 2px rgba(0,0,0,.5)' ) ) ),
	'a raw shadow'                       => array( 'styles' => array( 'shadow' => '0 0 4px black' ) ),
	'an unknown palette color'           => array( 'styles' => array( 'color' => array( 'background' => 'var:preset|color|nope' ) ) ),
	'a URL'                              => array( 'styles' => array( 'spacing' => array( 'padding' => 'url(https://example.com/x.png)' ) ) ),
	'a background image'                 => array( 'styles' => array( 'background' => array( 'backgroundImage' => 'none' ) ) ),
	'an unknown style key'               => array( 'styles' => array( 'typography' => array( 'fontSizee' => '2rem' ) ) ),
	'an unknown block'                   => array( 'styles' => array( 'blocks' => array( 'acme/slider' => array( 'color' => array( 'text' => 'var:preset|color|accent' ) ) ) ) ),
	'an unknown element'                 => array( 'styles' => array( 'elements' => array( 'marquee' => array() ) ) ),
	'a pseudo state on headings'         => array( 'styles' => array( 'elements' => array( 'heading' => array( ':hover' => array( 'color' => array( 'text' => 'var:preset|color|accent' ) ) ) ) ) ),
	'a top-level key'                    => array( 'templateParts' => array() ),
	'another setting'                    => array( 'settings' => array( 'custom' => array( 'x' => '1' ) ) ),
	'removing a theme color'             => array( 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'primary', 'remove' => true ) ) ) ) ),
	'a theme color by name'              => array( 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'base', 'color' => 'coral' ) ) ) ) ),
	'a default-origin palette'           => array( 'settings' => array( 'color' => array( 'palette' => array( 'default' => array( array( 'slug' => 'x', 'color' => '#000000' ) ) ) ) ) ),
	'a color named with &'               => array( 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'rock', 'name' => 'Rock & Roll', 'color' => '#111111' ) ) ) ) ),
	'a bad slug'                         => array( 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'Bad Slug', 'color' => '#111111' ) ) ) ) ),
	'a font size with a declaration'     => array( 'settings' => array( 'typography' => array( 'fontSizes' => array( array( 'slug' => 'large', 'size' => '2rem;x:y' ) ) ) ) ),
	'a width with a quote'               => array( 'settings' => array( 'layout' => array( 'contentSize' => '800px"' ) ) ),
	'too large'                          => array( 'styles' => array( 'typography' => array( 'letterSpacing' => str_repeat( '1', SiteStyles::MAX_BYTES ) ) ) ),
	'an empty change'                    => array(),
);
foreach ( $refused as $label => $changes ) {
	$result = $run( $changes );
	$check( 'refused: ' . $label, is_wp_error( $result ), $detail( $result ) );
}
$check( 'the styles are unchanged', get_post( $styles_id )->post_content === $snapshot );
$check( '...and no revision was added', $before === $count() );
$result = $run( array( 'styles' => array( 'css' => 'x', 'color' => array( 'text' => '#000000' ) ) ) );
$check( 'a refusal says what can change, once', is_wp_error( $result ) && 1 === substr_count( $result->get_error_message(), 'No CSS' ), $detail( $result ) );
$result = SiteStyles::update( array( 'changes' => wp_json_encode( array( 'styles' => array( 'elements' => array( 'h2' => array( 'typography' => array( 'fontStyle' => 'italic' ) ) ) ) ) ) ) );
$check( '`changes` sent as a JSON string is read', is_array( $result ), $detail( $result ) );

// 6. Removing and repeating --------------------------------------------------------------------

echo "\nRemoving and repeating\n";
$before = $count();
$result = $run( array( 'styles' => array( 'elements' => array( 'link' => array( 'color' => array( 'text' => 'var:preset|color|accent' ) ) ) ) ) );
$check( 'the same change again: nothing to do', is_wp_error( $result ) && 'mody_no_change' === $result->get_error_code() && $before === $count(), $detail( $result ) );
$result = $run( array( 'styles' => array( 'elements' => array( 'link' => null ) ) ) );
$check( 'null removes a style: the theme\'s shows again', is_array( $result ) && ! isset( $config()['styles']['elements']['link'] ), $detail( $result ) );
$check( '...empty parents are pruned, other elements stay', isset( $config()['styles']['elements']['heading'] ) );

// 7. The agent, for real (part B) ---------------------------------------------------------------

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo "\nThe agent: real requests skipped (MODY_TEST_OFFLINE is set)\n";
} else {
	$runs  = max( 1, (int) getenv( 'MODY_TEST_RUNS' ) );
	$reset = static function () use ( $styles_id, $saved, $admin, $users ): void {
		wp_set_current_user( $admin );
		wp_update_post( wp_slash( array( 'ID' => $styles_id, 'post_content' => $saved ) ) );
		wp_clean_theme_json_cache();
		wp_set_current_user( $users['administrator'] );
	};
	$reset();
	$base = SiteStyles::overview();
	// A size in rem: a preset of the site, a CSS size, or a clamp()'s largest value.
	$rem  = static function ( string $value, array $overview ): float {
		if ( preg_match( '/(?:font-size|spacing)(?:--|\|)([a-z0-9-]+)/', $value, $m ) ) {
			$value = (string) ( ( $overview['font_sizes'] + $overview['spacing'] )[ $m[1] ] ?? '0' );
		}
		if ( preg_match( '/clamp\([^,]+,[^,]+,\s*([^)]+)\)/', $value, $m ) ) {
			$value = $m[1];
		}
		if ( str_contains( $value, '%' ) ) {
			return 1000.0;
		}
		return preg_match( '/([\d.]+)\s*(rem|em|px)?/', $value, $m ) ? ( 'px' === ( $m[2] ?? '' ) ? (float) $m[1] / 16 : (float) $m[1] ) : 0.0;
	};
	$grew = static function ( array $paths, array $now ) use ( $rem, $base ): bool {
		foreach ( $paths as $path ) {
			if ( isset( $now['styles'][ $path ] ) && $rem( $now['styles'][ $path ], $now ) > $rem( (string) ( $base['styles'][ $path ] ?? '0' ), $base ) ) {
				return true;
			}
		}
		return false;
	};
	$light_warm = static function ( string $hex ) use ( $base ): bool {
		list( $r, $g, $b ) = array_map( 'hexdec', str_split( ltrim( $hex, '#' ), 2 ) + array( '0', '0', '0' ) );
		return 3 === count( array( $r, $g, $b ) ) && min( $r, $g, $b ) >= 0xd8 && $r >= $b && strtolower( $hex ) !== $base['palette']['base'];
	};
	$ask = static function ( string $text ) use ( $reset ): array {
		$reset();
		usleep( 1500000 ); // Infomaniak's rate limit (429 after bursts).
		$start = microtime( true );
		$turn  = Agent::respond( $text, array(), null, SiteEditor::screen_from_input( array( 'p' => '/' ) ) );
		$calls = array();
		foreach ( $turn['calls'] ?? array() as $made ) {
			$calls[] = preg_replace( '/^wpab__mody__/', '', (string) $made['name'] ) . ' ' . wp_json_encode( $made['args'], JSON_UNESCAPED_SLASHES );
		}
		wp_clean_theme_json_cache();
		return array(
			'turn'   => $turn,
			'now'    => SiteStyles::overview(),
			'calls'  => implode( ' ; ', $calls ),
			'place'  => (string) ( array_values( array_filter( $turn['actions'] ?? array(), static fn( $a ) => ! empty( $a['navigate'] ) ) )[0]['place'] ?? '' ),
			'detail' => sprintf( '%s | %s | %.1fs', mb_strimwidth( implode( ' ; ', $calls ), 0, 170, '…' ), mb_strimwidth( (string) $turn['reply'], 0, 70, '…' ), microtime( true ) - $start ),
		);
	};
	$same = static fn( array $now ): bool => $now['styles'] === $base['styles'] && $now['palette'] === $base['palette'] && $now['layout'] === $base['layout'];
	$cases = array(
		'Make the headings bigger'                => static fn( $r ) => $grew( array( 'elements.h1.typography.fontSize', 'elements.h2.typography.fontSize', 'elements.h3.typography.fontSize' ), $r['now'] ),
		'Use Lora for headings'                   => static fn( $r ) => str_contains( (string) ( $r['now']['styles']['elements.heading.typography.fontFamily'] ?? '' ), 'font-family|lora' ),
		'Use Fraunces for the headings'           => static fn( $r ) => $same( $r['now'] ) && ( 1 === preg_match( '/install|Font Library/i', (string) $r['turn']['reply'] ) || 'Styles → Typography' === $r['place'] ),
		'Links in the accent color'               => static fn( $r ) => 'var:preset|color|accent' === ( $r['now']['styles']['elements.link.color.text'] ?? '' ),
		'Round the buttons'                       => static fn( $r ) => $grew( array( 'elements.button.border.radius', 'blocks.core/button.border.radius' ), $r['now'] ),
		'More space between sections'             => static fn( $r ) => $grew( array_values( array_filter( array_keys( $r['now']['styles'] ), static fn( $p ) => str_contains( $p, 'spacing' ) && ! str_starts_with( $p, 'elements.button' ) ) ), $r['now'] ),
		'A wider content area'                    => static fn( $r ) => $rem( $r['now']['layout']['contentSize'], $r['now'] ) > $rem( $base['layout']['contentSize'], $base ),
		'Make the background a warm white'        => static fn( $r ) => ( $light_warm( $r['now']['palette']['base'] ) && array_diff_key( $r['now']['palette'], array( 'base' => 1 ) ) === array_diff_key( $base['palette'], array( 'base' => 1 ) ) )
			|| ( preg_match( '/color\|([a-z0-9-]+)$/', (string) ( $r['now']['styles']['color.background'] ?? '' ), $m ) && $light_warm( (string) ( $r['now']['custom'][ $m[1] ] ?? '#000000' ) ) ),
		'Make my site look more modern'           => static fn( $r ) => $same( $r['now'] ) && ( $r['turn']['choices'] || str_starts_with( $r['place'], 'Styles' ) ),
		'Make the primary color coral'            => static fn( $r ) => str_contains( $r['calls'], 'update-design' ) && '#ff7f50' === $r['now']['palette']['primary'],
	);
	$passed = array_fill_keys( array_keys( $cases ), 0 );
	for ( $run = 1; $run <= $runs; $run++ ) {
		echo "\nThe agent (real requests, paced), run $run of $runs\n";
		foreach ( $cases as $text => $ok ) {
			$result = $ask( $text );
			$good   = (bool) $ok( $result );
			$passed[ $text ] += $good ? 1 : 0;
			$check( '"' . $text . '"', $good, $result['detail'] );
			if ( getenv( 'MODY_TEST_VERBOSE' ) && $good ) {
				printf( "     %s\n", $result['detail'] );
			}
		}
	}
	if ( $runs > 1 ) {
		echo "\nPassed, of $runs runs:\n";
		foreach ( $passed as $text => $count ) {
			printf( "  %d/%d  %s\n", $count, $runs, $text );
		}
	}
	$reset();
}

restore_current_blog();
echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
