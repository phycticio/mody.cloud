<?php
/**
 * Mody Cloud theme functions (block theme / FSE).
 *
 * Palette, typography and spacing live in theme.json; templates and parts live
 * in templates/ and parts/ as serialized blocks. This file is kept minimal on
 * purpose: platform logic (tiers, brand profile, agent) does not belong in the
 * theme.
 *
 * @package mody_cloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base theme setup.
 */
function mody_cloud_setup(): void {
	// The text domain differs from the theme directory, so load translations explicitly.
	load_theme_textdomain( 'mody_cloud', get_template_directory() . '/languages' );

	// Apply style.css inside the block editor as well.
	add_editor_style( 'style.css' );
}
add_action( 'after_setup_theme', 'mody_cloud_setup' );

/**
 * Enqueue style.css on the front end.
 */
function mody_cloud_enqueue_styles(): void {
	wp_enqueue_style(
		'mody_cloud_style',
		get_theme_file_uri( 'style.css' ),
		array(),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'mody_cloud_enqueue_styles' );
