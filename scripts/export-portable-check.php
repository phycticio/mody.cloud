<?php
/**
 * The PHP half of scripts/export-portable-test.sh: run with `wp eval-file`
 * on the plain single-site WordPress that test makes. Prints one line per
 * check, "ok ..." or "FAIL ...". Also adds the page the HTTP half reads.
 *
 * @package Mody\Platform
 */

$check = static function ( string $label, bool $passed ): void {
	echo ( $passed ? 'ok   ' : 'FAIL ' ) . $label . "\n";
};

// The owner controls the site completely: no platform lock travelled.
$owner = get_user_by( 'login', 'owner' );
wp_set_current_user( $owner->ID );
foreach ( array( 'update_core', 'update_plugins', 'update_themes', 'install_plugins', 'activate_plugins', 'delete_plugins', 'switch_themes', 'edit_theme_options' ) as $cap ) {
	$check( "the owner can $cap", current_user_can( $cap ) );
}
$check( 'core\'s update checks are scheduled', false !== has_action( 'wp_version_check' ) && false !== has_action( 'admin_init', '_maybe_update_core' ) );

// Nothing of the agent or the platform.
foreach ( array( 'Mody\Platform\Agent\ChatController', 'Mody\Platform\Abilities', 'Mody\Platform\Gating\Updates', 'Mody\Platform\Gating\HiddenScreens', 'Mody\Platform\Security\Altcha', 'Mody\Platform\Users\NetworkProfile', 'Mody\Platform\Site\Storage', 'Mody\Mail\Mail' ) as $class ) {
	$check( "no $class", ! class_exists( $class ) );
}
$check( 'no Mody ability registered', ! function_exists( 'wp_get_abilities' ) || ! array_filter( array_keys( (array) wp_get_abilities() ), static fn( $name ) => str_starts_with( (string) $name, 'mody/' ) ) );
$check( 'wp_mail is WordPress\'s own (no pre_wp_mail filter)', ! has_filter( 'pre_wp_mail' ) );

// Plugins load in order: Mody Site before Spectra, so the guard is first.
$active = (array) get_option( 'active_plugins' );
$check( 'Mody Site loads before Spectra', array_search( 'mody-site/mody-site.php', $active, true ) < array_search( 'spectra-blocks/spectra-blocks.php', $active, true ) );

// What Mody Site and the plugins bring.
$registry = WP_Block_Type_Registry::get_instance();
$check( 'Spectra\'s container block is registered', $registry->is_registered( 'spectra/container' ) );
$check( 'the Google Map block is not (the guard)', ! $registry->is_registered( 'spectra/google-map' ) );
$check( 'the language switcher block is registered', $registry->is_registered( 'mody/language-switcher' ) );
$check( 'the SEO title meta is registered', registered_meta_key_exists( 'post', 'mody_seo_title', 'page' ) );
$avatar = (string) get_avatar_url( $owner->ID );
$check( 'avatars are initials on this site, not Gravatar', str_starts_with( $avatar, home_url( '/mody-avatar/' ) ) );
$theme = wp_get_global_settings( array( 'typography', 'fontFamilies', 'theme' ) );
$faces = $theme[0]['fontFace'] ?? array();
$check( 'Inter has its two faces from the theme', 2 === count( $faces ) && 'file:./assets/fonts/inter/InterVariable.woff2' === ( (array) ( $faces[0]['src'] ?? array() ) )[0] ?? '' );

// A page, as a site from Mody Cloud has them, and its Spanish translation.
$markup = '<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section"} -->'
	. '<!-- wp:paragraph --><p>A section of the page.</p><!-- /wp:paragraph -->'
	. '<!-- wp:image --><figure class="wp-block-image"><img src="/mody-placeholder/1200x800.svg" alt=""/></figure><!-- /wp:image -->'
	. '<!-- wp:mody/language-switcher /-->'
	. '<!-- /wp:spectra/container -->';
$page   = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Exit test',
		'post_name'    => 'exit-test',
		'post_excerpt' => 'The page\'s own description.',
		'post_content' => $markup,
		'meta_input'   => array( 'mody_seo_title' => 'Exit test SEO title' ),
	),
	true
);
$check( 'a page is saved', ! is_wp_error( $page ) );
Mody\Languages\Languages::save( array( 'es_ES' ) );
$spanish = Mody\Languages\Translations::create( (int) $page, 'es_ES', $markup, 'Prueba de salida' );
$check( 'its Spanish translation is saved', ! is_wp_error( $spanish ) );
if ( ! is_wp_error( $spanish ) ) {
	wp_publish_post( $spanish );
}
flush_rewrite_rules();
