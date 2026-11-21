<?php
/**
 * Creates or updates the main site's product pages
 * (tickets/product-pages-bari-and-plugins.md): the catalog at /plugins/,
 * a page per Mody plugin under it at /plugins/<slug>/ and, later, /bari/;
 * and the "Our source code" dropdown in the header's menu. There is no
 * /plugin/ and no redirect (decided by the maintainer on 2026-11-21).
 *
 * Run from the repository root, on the main site, after scripts/home-page.php
 * (scripts/ is not mounted in the container, so the texts and screenshots are
 * copied in first):
 *
 *   docker cp scripts/product-pages.json mody_cloud_app:/tmp/product-pages.json
 *   docker cp scripts/product-pages mody_cloud_app:/tmp/product-pages
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/product-pages.php
 *
 * The texts are in scripts/product-pages.json (English). It is safe to run
 * again: it finds each page by its path and replaces its content, so edits
 * made in the editor are lost. Edit the JSON instead.
 *
 * - **What is public:** a plugin's page is published only when the JSON
 *   says "ready", its zip is in the main site's uploads/downloads/ and its
 *   screenshots are in the media library; otherwise it stays a draft and the
 *   script says why. The catalog is published once one product page is.
 * - **The catalog** lists Bari, then the nine plugins as cards. A card
 *   links to its page when that page is published (or, while the catalog
 *   itself is a draft, when the page exists, so it can be reviewed).
 * - **Screenshots** are PNGs in scripts/product-pages/screenshots/, imported
 *   into the main site's media library once (found again by the post meta
 *   mody_product_shot, the file's name).
 * - **The menu:** an "Our source code" dropdown (navigation-submenu with the
 *   class mody-source-code) before Log in, with the catalog and every
 *   published product page; removed while the catalog is a draft.
 *   scripts/home-page.php keeps it when it rebuilds the menu.
 * - Layout: Spectra containers, palette slugs and the theme's gradients only
 *   (rules 2 and 3); every Spectra block gets its spectraId.
 */

use Mody\Agent\AI\SpectraBlocks;
use Mody\Agent\AI\SpectraLayout;

if ( ! is_main_site() ) {
	WP_CLI::error( 'Run this on the main site.' );
}

$file = '/tmp/product-pages.json';
$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
if ( ! is_array( $data ) || empty( $data['catalog'] ) || empty( $data['plugins'] ) ) {
	WP_CLI::error( "The texts are missing: docker cp scripts/product-pages.json mody_cloud_app:$file" );
}
$shots_dir = '/tmp/product-pages/screenshots/';

$supers = get_super_admins();
$author = $supers ? get_user_by( 'login', $supers[0] ) : false;

/*
 * ---------------------------------------------------------------------------
 * Blocks. Each helper returns serialized blocks whose saved HTML is what the
 * editor itself saves for those attributes (the same forms as home-page.php).
 * ---------------------------------------------------------------------------
 */

/** Text for HTML content: only &, < and > are escaped, as the editor saves it. */
$t = static fn( string $text ): string => htmlspecialchars( $text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8' );

/**
 * A block with its attributes and inner markup.
 *
 * @param string $name  The block name, without "core/".
 * @param array  $attrs Its attributes.
 * @param string $inner Its saved HTML and inner blocks.
 */
$block = static fn( string $name, array $attrs, string $inner ): string => '<!-- wp:' . $name . ( $attrs ? ' ' . serialize_block_attributes( $attrs ) : '' ) . " -->\n" . $inner . "\n<!-- /wp:" . $name . ' -->';

$pad = static fn( string $v, string $h ): array => array(
	'top'    => "var:preset|spacing|$v",
	'right'  => "var:preset|spacing|$h",
	'bottom' => "var:preset|spacing|$v",
	'left'   => "var:preset|spacing|$h",
);

/**
 * A full-width section.
 *
 * @param string[] $inner      Its blocks.
 * @param string   $background "gradient:<slug>" or a palette color.
 * @param array    $options    anchor, text (a palette color), wide (bool), gap.
 */
$section = static function ( array $inner, string $background, array $options = array() ) use ( $block, $pad ): string {
	$attrs = array(
		'variationSelected' => true,
		'htmlTag'           => 'section',
		'align'             => 'full',
		'isBlockRootParent' => true,
	);
	if ( ! empty( $options['anchor'] ) ) {
		$attrs['anchor'] = $options['anchor'];
	}
	if ( str_starts_with( $background, 'gradient:' ) ) {
		$attrs['backgroundGradient'] = 'var(--wp--preset--gradient--' . substr( $background, 9 ) . ')';
	} elseif ( '' !== $background ) {
		$attrs['backgroundColor'] = $background;
	}
	if ( ! empty( $options['text'] ) ) {
		$attrs['textColor'] = $options['text'];
	}
	$v              = $options['padding'] ?? '70';
	$attrs['style'] = array(
		'spacing' => array(
			'margin'   => array(
				'top'    => '0',
				'bottom' => '0',
			),
			'blockGap' => 'var:preset|spacing|' . ( $options['gap'] ?? '50' ),
			'padding'  => $pad( $v, '40' ),
		),
		'@mobile' => array( 'spacing' => array( 'padding' => $pad( '70' === $v ? '50' : '30', '30' ) ) ),
	);
	$attrs['layout'] = array( 'type' => 'constrained' );
	if ( $options['wide'] ?? true ) {
		$attrs['layout']['contentSize'] = 'var(--wp--style--global--wide-size)';
	}
	return $block( 'spectra/container', $attrs, implode( "\n\n", $inner ) );
};

/**
 * A grid row.
 *
 * @param string[] $inner   Its cells.
 * @param int      $columns Columns on a desktop (tablets get at most two, phones one).
 */
$grid = static fn( array $inner, int $columns ): string => $block(
	'spectra/container',
	array(
		'variationSelected' => true,
		'style'             => array(
			'spacing' => array( 'blockGap' => 'var:preset|spacing|' . ( $columns > 2 ? '40' : '50' ) ),
			'@tablet' => array( 'layout' => array( 'columnCount' => min( 2, $columns ) ) ),
			'@mobile' => array( 'layout' => array( 'columnCount' => 1 ) ),
		),
		'layout'            => array(
			'type'               => 'grid',
			'columnCount'        => $columns,
			'minimumColumnWidth' => null,
		),
	),
	implode( "\n\n", $inner )
);

/**
 * A column: blocks stacked.
 *
 * @param string[] $inner The blocks.
 * @param array    $style Extra: card (a white card), rule (a line on top), gap, align.
 */
$column = static function ( array $inner, array $style = array() ) use ( $block, $pad ): string {
	$attrs = array( 'variationSelected' => true );
	$css   = array( 'spacing' => array( 'blockGap' => 'var:preset|spacing|' . ( $style['gap'] ?? '30' ) ) );
	if ( ! empty( $style['card'] ) ) {
		$attrs['backgroundColor'] = 'base';
		$css['border']            = array( 'radius' => '16px' );
		$css['spacing']['padding'] = $pad( '40', '40' );
	}
	if ( ! empty( $style['rule'] ) ) {
		$css['border']                    = array(
			'top' => array(
				'color' => 'var:preset|color|primary',
				'width' => '4px',
				'style' => 'solid',
			),
		);
		$css['spacing']['padding'] = array( 'top' => 'var:preset|spacing|40' );
	}
	$attrs['style']  = $css;
	$attrs['layout'] = array(
		'type'              => 'flex',
		'orientation'       => 'vertical',
		'justifyContent'    => 'left',
		'verticalAlignment' => $style['align'] ?? 'top',
	);
	return $block( 'spectra/container', $attrs, implode( "\n\n", $inner ) );
};

/**
 * A paragraph, in one of the forms the pages use.
 *
 * @param string $html Its content, already escaped.
 * @param string $form plain, large, large-center, center, small, small-center, note, eyebrow, eyebrow-left, pill.
 */
$p = static function ( string $html, string $form = 'plain' ) use ( $block ): string {
	$upper = 'font-weight:600;letter-spacing:0.08em;text-transform:uppercase';
	$forms = array(
		'plain'        => array( array(), '<p>' ),
		'large'        => array( array( 'fontSize' => 'large' ), '<p class="has-large-font-size">' ),
		'large-center' => array( array( 'fontSize' => 'large', 'style' => array( 'typography' => array( 'textAlign' => 'center' ) ) ), '<p class="has-text-align-center has-large-font-size">' ),
		'center'       => array( array( 'style' => array( 'typography' => array( 'textAlign' => 'center' ) ) ), '<p class="has-text-align-center">' ),
		'small'        => array( array( 'fontSize' => 'small' ), '<p class="has-small-font-size">' ),
		'small-center' => array( array( 'fontSize' => 'small', 'style' => array( 'typography' => array( 'textAlign' => 'center' ) ) ), '<p class="has-text-align-center has-small-font-size">' ),
		'note'         => array( array( 'textColor' => 'secondary', 'fontSize' => 'small' ), '<p class="has-secondary-color has-text-color has-small-font-size">' ),
		'eyebrow'      => array(
			array(
				'textColor' => 'accent',
				'fontSize'  => 'small',
				'style'     => array(
					'typography' => array(
						'textAlign'     => 'center',
						'fontWeight'    => '600',
						'letterSpacing' => '0.08em',
						'textTransform' => 'uppercase',
					),
				),
			),
			'<p class="has-text-align-center has-accent-color has-text-color has-small-font-size" style="' . $upper . '">',
		),
		'eyebrow-left' => array(
			array(
				'textColor' => 'accent',
				'fontSize'  => 'small',
				'style'     => array(
					'typography' => array(
						'fontWeight'    => '600',
						'letterSpacing' => '0.08em',
						'textTransform' => 'uppercase',
					),
				),
			),
			'<p class="has-accent-color has-text-color has-small-font-size" style="' . $upper . '">',
		),
		'pill'         => array(
			array(
				'backgroundColor' => 'accent',
				'textColor'       => 'base',
				'fontSize'        => 'small',
				'style'           => array(
					'border'     => array( 'radius' => '999px' ),
					'spacing'    => array(
						'padding' => array(
							'top'    => 'var:preset|spacing|20',
							'right'  => 'var:preset|spacing|30',
							'bottom' => 'var:preset|spacing|20',
							'left'   => 'var:preset|spacing|30',
						),
					),
					'typography' => array(
						'fontWeight'    => '600',
						'letterSpacing' => '0.06em',
						'textTransform' => 'uppercase',
					),
				),
			),
			'<p class="has-base-color has-accent-background-color has-text-color has-background has-small-font-size" style="border-radius:999px;padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30);font-weight:600;letter-spacing:0.06em;text-transform:uppercase">',
		),
	);
	list( $attrs, $open ) = $forms[ $form ];
	return $block( 'paragraph', $attrs, $open . $html . '</p>' );
};

/**
 * A heading.
 *
 * @param string $html Its content, already escaped.
 * @param string $form hero (h1 on a dark section), center (h2), center-light (h2 on a dark section), h3, h3-primary.
 */
$h = static function ( string $html, string $form ) use ( $block ): string {
	$forms = array(
		'hero'         => array(
			array(
				'level'     => 1,
				'textColor' => 'base',
				'style'     => array(
					'typography' => array(
						'fontSize'      => 'clamp(2.5rem, 5vw, 4rem)',
						'lineHeight'    => '1.08',
						'letterSpacing' => '-0.02em',
					),
				),
			),
			'<h1 class="wp-block-heading has-base-color has-text-color" style="font-size:clamp(2.5rem, 5vw, 4rem);letter-spacing:-0.02em;line-height:1.08">',
			'</h1>',
		),
		'center'       => array( array( 'style' => array( 'typography' => array( 'textAlign' => 'center' ) ) ), '<h2 class="wp-block-heading has-text-align-center">', '</h2>' ),
		'center-light' => array(
			array(
				'textColor' => 'base',
				'style'     => array( 'typography' => array( 'textAlign' => 'center' ) ),
			),
			'<h2 class="wp-block-heading has-text-align-center has-base-color has-text-color">',
			'</h2>',
		),
		'h2'           => array( array(), '<h2 class="wp-block-heading">', '</h2>' ),
		'h3'           => array(
			array(
				'level'    => 3,
				'fontSize' => 'large',
			),
			'<h3 class="wp-block-heading has-large-font-size">',
			'</h3>',
		),
		'h3-primary'   => array(
			array(
				'level'     => 3,
				'textColor' => 'primary',
				'fontSize'  => 'large',
			),
			'<h3 class="wp-block-heading has-primary-color has-text-color has-large-font-size">',
			'</h3>',
		),
	);
	list( $attrs, $open, $close ) = $forms[ $form ];
	return $block( 'heading', $attrs, $open . $html . $close );
};

/**
 * A bulleted or numbered list.
 *
 * @param string[] $items   Its items, already escaped.
 * @param bool     $ordered Numbered.
 */
$list = static function ( array $items, bool $ordered = false ) use ( $block ): string {
	$tag   = $ordered ? 'ol' : 'ul';
	$attrs = array( 'style' => array( 'spacing' => array( 'padding' => array( 'left' => 'var:preset|spacing|40' ) ) ) );
	if ( $ordered ) {
		$attrs = array( 'ordered' => true ) + $attrs;
	}
	$inner = implode( "\n\n", array_map( static fn( string $item ): string => "<!-- wp:list-item -->\n<li>$item</li>\n<!-- /wp:list-item -->", $items ) );
	return $block( 'list', $attrs, '<' . $tag . ' style="padding-left:var(--wp--preset--spacing--40)" class="wp-block-list">' . $inner . '</' . $tag . '>' );
};

/**
 * Buttons.
 *
 * @param array[] $buttons Each: label, url, style (solid: a light button on a dark section; outline-light; solid-dark).
 * @param bool    $center  Centered.
 */
$buttons = static function ( array $buttons, bool $center = false ) use ( $block, $t ): string {
	$inner = array();
	foreach ( $buttons as $button ) {
		$href = esc_url( $button['url'] );
		$text = $t( $button['label'] );
		switch ( $button['style'] ) {
			case 'outline-light':
				$inner[] = $block( 'button', array( 'textColor' => 'base', 'className' => 'is-style-outline' ), '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link has-base-color has-text-color wp-element-button" href="' . $href . '">' . $text . '</a></div>' );
				break;
			case 'solid-dark':
				$inner[] = $block( 'button', array( 'backgroundColor' => 'primary', 'textColor' => 'base' ), '<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-primary-background-color has-text-color has-background wp-element-button" href="' . $href . '">' . $text . '</a></div>' );
				break;
			default:
				$inner[] = $block( 'button', array( 'backgroundColor' => 'base', 'textColor' => 'contrast' ), '<div class="wp-block-button"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" href="' . $href . '">' . $text . '</a></div>' );
		}
	}
	$attrs = $center ? array(
		'layout' => array(
			'type'           => 'flex',
			'justifyContent' => 'center',
		),
	) : array();
	return $block( 'buttons', $attrs, '<div class="wp-block-buttons">' . implode( "\n\n", $inner ) . '</div>' );
};

/**
 * A picture from the media library, or a placeholder of the same shape.
 *
 * @param int    $id      The attachment's ID (0: the placeholder).
 * @param string $alt     Its alternative text.
 * @param string $caption Its caption ('' for none).
 */
$image = static function ( int $id, string $alt, string $caption = '' ) use ( $block, $t ): string {
	$url     = $id ? (string) wp_get_attachment_image_url( $id, 'large' ) : home_url( '/mody-placeholder/1200x800.svg' );
	$attrs   = $id ? array(
		'id'              => $id,
		'sizeSlug'        => 'large',
		'linkDestination' => 'none',
	) : array(
		'sizeSlug'        => 'large',
		'linkDestination' => 'none',
	);
	$img     = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . ( $id ? ' class="wp-image-' . $id . '"' : '' ) . '/>';
	$caption = '' !== $caption ? '<figcaption class="wp-element-caption">' . $t( $caption ) . '</figcaption>' : '';
	return $block( 'image', $attrs, '<figure class="wp-block-image size-large">' . $img . $caption . '</figure>' );
};

/**
 * Questions and answers, as an accordion.
 *
 * @param array[] $items Each: q, a.
 */
$faq = static function ( array $items ) use ( $block, $p, $t ): string {
	$inner = array();
	foreach ( $items as $i => $item ) {
		$attrs = array(
			'backgroundColor' => 'base',
			'style'           => array( 'border' => array( 'radius' => '12px' ) ),
		);
		if ( 0 === $i ) {
			$attrs = array( 'openByDefault' => true ) + $attrs;
		}
		$header  = '<!-- wp:spectra/accordion-child-header ' . serialize_block_attributes(
			array(
				'style'  => array( 'spacing' => array( 'padding' => array( 'top' => 'var:preset|spacing|30', 'right' => 'var:preset|spacing|40', 'bottom' => 'var:preset|spacing|30', 'left' => 'var:preset|spacing|40' ) ) ),
				'layout' => array( 'type' => 'flex', 'flexWrap' => 'nowrap', 'justifyContent' => 'space-between', 'verticalAlignment' => 'center' ),
			)
		) . " -->\n"
			. '<!-- wp:spectra/accordion-child-header-content ' . serialize_block_attributes( array( 'text' => $item['q'], 'style' => array( 'typography' => array( 'fontWeight' => '600' ) ) ) ) . " /-->\n\n"
			. "<!-- wp:spectra/accordion-child-header-icon /-->\n"
			. '<!-- /wp:spectra/accordion-child-header -->';
		$details = $block(
			'spectra/accordion-child-details',
			array( 'style' => array( 'spacing' => array( 'padding' => array( 'top' => '0', 'right' => 'var:preset|spacing|40', 'bottom' => 'var:preset|spacing|30', 'left' => 'var:preset|spacing|40' ) ) ) ),
			$p( $t( $item['a'] ) )
		);
		$inner[] = $block( 'spectra/accordion-child-item', $attrs, $header . "\n\n" . $details );
	}
	return $block( 'spectra/accordion', array( 'style' => array( 'spacing' => array( 'blockGap' => 'var:preset|spacing|30' ) ) ), implode( "\n\n", $inner ) );
};

/*
 * ---------------------------------------------------------------------------
 * Pages.
 * ---------------------------------------------------------------------------
 */

/**
 * Creates or updates a page (template page-blank) and returns its ID.
 *
 * @param array $page   post_title, post_name, post_parent, post_status, post_content.
 * @param array $seo    The SEO title and description (Mody SEO), or empty.
 */
$save = static function ( array $page, array $seo = array() ) use ( $author ): int {
	$parent   = (int) ( $page['post_parent'] ?? 0 );
	$path     = $parent ? get_page_uri( $parent ) . '/' . $page['post_name'] : $page['post_name'];
	$existing = get_page_by_path( $path, OBJECT, 'page' );
	$page    += array(
		'post_type'   => 'page',
		'post_parent' => $parent,
	);
	if ( $seo ) {
		$page['meta_input'] = array(
			'mody_seo_title'       => $seo[0],
			'mody_seo_description' => $seo[1],
		);
	}
	if ( isset( $page['post_content'] ) ) {
		$page['post_content'] = SpectraLayout::with_ids( SpectraBlocks::normalize( $page['post_content'] ) );
	}
	if ( $existing ) {
		$page['ID'] = $existing->ID;
		// Keep the spectraIds already saved when nothing else changed, so Spectra's cached CSS stays valid.
		$bare = static fn( string $markup ): string => (string) preg_replace( '/,?"spectraId":"[^"]*"/', '', $markup );
		if ( isset( $page['post_content'] ) && $bare( $existing->post_content ) === $bare( $page['post_content'] ) ) {
			$page['post_content'] = $existing->post_content;
		}
		$id = wp_update_post( wp_slash( $page ), true );
	} else {
		$page['post_author'] = $author ? $author->ID : 0;
		$id                  = wp_insert_post( wp_slash( $page ), true );
	}
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $path . ': ' . $id->get_error_message() );
	}
	update_post_meta( $id, '_wp_page_template', 'page-blank' );
	return (int) $id;
};

/**
 * A screenshot's attachment ID, imported from the screenshots folder the
 * first time; 0 when it is neither in the library nor in the folder.
 *
 * @param string $name The PNG's file name.
 * @param string $alt  Its alternative text.
 */
$screenshot = static function ( string $name, string $alt ) use ( $shots_dir, $author ): int {
	$found = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => 'mody_product_shot', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => $name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	if ( $found ) {
		update_post_meta( (int) $found[0], '_wp_attachment_image_alt', $alt );
		return (int) $found[0];
	}
	$source = $shots_dir . basename( $name );
	if ( ! is_readable( $source ) ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	if ( $author ) {
		wp_set_current_user( $author->ID );
	}
	// media_handle_sideload() moves the file: give it a copy.
	$copy = wp_tempnam( $name );
	copy( $source, $copy );
	$id = media_handle_sideload(
		array(
			'name'     => basename( $name ),
			'tmp_name' => $copy,
		),
		0,
		pathinfo( $name, PATHINFO_FILENAME )
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "$name: " . $id->get_error_message() );
		return 0;
	}
	update_post_meta( $id, 'mody_product_shot', $name );
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return (int) $id;
};

$uploads  = wp_upload_dir();
$download = static fn( string $slug, string $version ): array => array(
	'url'  => $uploads['baseurl'] . "/downloads/mody-$slug-$version.zip",
	'path' => $uploads['basedir'] . "/downloads/mody-$slug-$version.zip",
);

$catalog = $data['catalog'];

// The catalog first, as a draft, so the plugin pages have their parent.
$catalog_page = get_page_by_path( $catalog['slug'], OBJECT, 'page' );
$catalog_id   = $catalog_page ? $catalog_page->ID : $save(
	array(
		'post_title'  => $catalog['title'],
		'post_name'   => $catalog['slug'],
		'post_status' => 'draft',
	)
);

/*
 * A plugin's page.
 */
$plugin_page = static function ( string $slug, array $plugin, array $shots, array $zip ) use ( $section, $grid, $column, $p, $h, $list, $buttons, $image, $faq, $t, $catalog ): string {
	$page = $plugin['page'];
	$name = $t( $plugin['name'] );

	$hero_text = array(
		$p( $t( $page['eyebrow'] ), 'pill' ),
		$h( $name, 'hero' ),
		$p( $t( $page['lead'] ), 'large' ),
		$list( array_map( $t, $page['facts'] ) ),
		$buttons(
			array(
				array(
					'label' => $page['download'] . ' ' . $plugin['version'],
					'url'   => $zip['url'],
					'style' => 'solid',
				),
				array(
					'label' => $page['secondary'],
					'url'   => '#features',
					'style' => 'outline-light',
				),
			)
		),
	);
	$first = $page['screenshots'][0] ?? null;
	$hero  = $section(
		array(
			$grid(
				array(
					$column( $hero_text, array( 'gap' => '40', 'align' => 'center' ) ),
					$column( array( $first ? $image( $shots[ $first['file'] ] ?? 0, $first['alt'] ) : '' ), array( 'align' => 'center' ) ),
				),
				2
			),
		),
		'gradient:primary-to-contrast',
		array(
			'anchor'  => 'top',
			'text'    => 'base',
			'padding' => '70',
		)
	);

	$problem = $section(
		array_merge(
			array(
				$p( $t( $page['problem_eyebrow'] ), 'eyebrow' ),
				$h( $t( $page['problem_heading'] ), 'center' ),
			),
			array_map( static fn( string $text ): string => $p( $t( $text ), 'large' ), $page['problem'] )
		),
		'base',
		array(
			'wide' => false,
			'gap'  => '40',
		)
	);

	$features = $section(
		array(
			$p( $t( $page['features_eyebrow'] ), 'eyebrow' ),
			$h( $t( $page['features_heading'] ), 'center' ),
			$grid(
				array_map(
					static fn( array $feature ): string => $column(
						array( $h( $t( $feature['title'] ), 'h3-primary' ), $p( $t( $feature['text'] ) ) ),
						array(
							'card' => true,
							'gap'  => '20',
						)
					),
					$page['features']
				),
				2
			),
		),
		'gradient:base-to-soft-primary',
		array( 'anchor' => 'features' )
	);

	$calls = $section(
		array(
			$p( $t( $page['calls_eyebrow'] ), 'eyebrow' ),
			$h( $t( $page['calls_heading'] ), 'center' ),
			$grid(
				array_map(
					static fn( array $item ): string => $column(
						array( $h( $t( $item['title'] ), 'h3' ), $p( $t( $item['text'] ) ) ),
						array( 'rule' => true )
					),
					$page['calls']
				),
				3
			),
		),
		'base',
		array( 'anchor' => 'calls' )
	);

	$screens = $section(
		array(
			$p( $t( $page['screenshots_eyebrow'] ), 'eyebrow' ),
			$h( $t( $page['screenshots_heading'] ), 'center' ),
			$grid(
				array_map( static fn( array $shot ): string => $image( $shots[ $shot['file'] ] ?? 0, $shot['alt'], $shot['caption'] ), $page['screenshots'] ),
				2
			),
		),
		'gradient:base-to-soft-primary',
		array( 'anchor' => 'screenshots' )
	);

	$install = $section(
		array(
			$grid(
				array(
					$column( array( $h( $t( $page['requirements_heading'] ), 'h2' ), $list( array_map( $t, $page['requirements'] ) ) ) ),
					$column(
						array(
							$h( $t( $page['install_heading'] ), 'h2' ),
							$list( array_map( $t, $page['install'] ), true ),
							$p( $t( $page['install_cli_label'] ) . ' <code>' . $t( $page['install_cli'] ) . '</code>', 'note' ),
						)
					),
				),
				2
			),
		),
		'base',
		array( 'anchor' => 'install' )
	);

	$mody = $section(
		array(
			$h( $t( $page['mody_heading'] ), 'center-light' ),
			$p( $t( $page['mody_text'] ), 'large-center' ),
			$buttons(
				array(
					array(
						'label' => $page['mody_button'],
						'url'   => home_url( '/#join' ),
						'style' => 'solid',
					),
				),
				true
			),
		),
		'gradient:primary-to-accent',
		array(
			'text' => 'base',
			'wide' => false,
			'gap'  => '40',
		)
	);

	$questions = $section(
		array(
			$p( $t( $page['faq_eyebrow'] ), 'eyebrow' ),
			$h( $t( $page['faq_heading'] ), 'center' ),
			$faq( $page['faq'] ),
		),
		'gradient:base-to-soft-primary',
		array(
			'anchor' => 'faq',
			'wide'   => false,
		)
	);

	$license = $section(
		array(
			$p( $t( sprintf( $page['license'], $plugin['version'] ) ) . ' · <a href="' . esc_url( home_url( '/' . $catalog['slug'] . '/' ) ) . '">' . $t( $catalog['title'] ) . '</a> · <a href="' . esc_url( $catalog['support_url'] ) . '">' . $t( $catalog['support_label'] ) . '</a>', 'small-center' ),
		),
		'',
		array(
			'wide'    => false,
			'padding' => '30',
		)
	);

	return implode( "\n\n", array( $hero, $problem, $features, $calls, $screens, $install, $mody, $questions, $license ) );
};

// Each plugin's page: built, and published only when everything it promises exists.
$published = array();
$pages     = array();
foreach ( $data['plugins'] as $slug => $plugin ) {
	if ( empty( $plugin['page'] ) ) {
		continue;
	}
	$zip     = $download( $slug, $plugin['version'] );
	$shots   = array();
	$missing = array();
	foreach ( $plugin['page']['screenshots'] as $shot ) {
		$shots[ $shot['file'] ] = $screenshot( $shot['file'], $shot['alt'] );
		if ( ! $shots[ $shot['file'] ] ) {
			$missing[] = 'the screenshot ' . $shot['file'];
		}
	}
	if ( empty( $plugin['ready'] ) ) {
		$missing[] = '"ready": true in the JSON';
	}
	if ( ! is_readable( $zip['path'] ) ) {
		$missing[] = 'its zip, ' . $zip['path'];
	}
	$status         = $missing ? 'draft' : 'publish';
	$id             = $save(
		array(
			'post_title'   => $plugin['name'],
			'post_name'    => $slug,
			'post_parent'  => $catalog_id,
			'post_status'  => $status,
			'post_content' => $plugin_page( $slug, $plugin, $shots, $zip ),
		),
		array( $plugin['page']['seo_title'], $plugin['page']['seo_description'] )
	);
	$pages[ $slug ] = $id;
	if ( 'publish' === $status ) {
		$published[ $slug ] = $id;
		WP_CLI::log( sprintf( 'Published %s: %s', $plugin['name'], get_permalink( $id ) ) );
	} else {
		WP_CLI::log( sprintf( 'Draft %s (page %d), waiting for %s.', $plugin['name'], $id, implode( ', ', $missing ) ) );
	}
}

$catalog_status = $published ? 'publish' : 'draft';

/*
 * The catalog.
 */
$card = static function ( string $name, string $line, string $works, string $needs, string $link ) use ( $column, $h, $p, $t, $catalog ): string {
	$inner = array( $h( $t( $name ), 'h3-primary' ), $p( $t( $line ) ), $p( $t( $works ), 'note' ) );
	if ( '' !== $needs ) {
		$inner[] = $p( '<strong>' . $t( $catalog['needs'] ) . '</strong> ' . $t( $needs ), 'small' );
	}
	$inner[] = '' !== $link ? $p( '<a href="' . esc_url( $link ) . '">' . $t( $catalog['more'] ) . '</a>' ) : $p( $t( $catalog['soon'] ), 'note' );
	return $column(
		$inner,
		array(
			'card' => true,
			'gap'  => '20',
		)
	);
};
// A card links to a published page; while the catalog is a draft, to any page, for review.
$link  = static function ( string $slug ) use ( $pages, $published, $catalog_status ): string {
	if ( isset( $published[ $slug ] ) ) {
		return (string) get_permalink( $published[ $slug ] );
	}
	return ( 'draft' === $catalog_status && isset( $pages[ $slug ] ) ) ? (string) get_preview_post_link( $pages[ $slug ] ) : '';
};
$cards = array();
foreach ( $data['plugins'] as $slug => $plugin ) {
	$cards[] = $card( $plugin['name'], $plugin['line'], $catalog['single_and_multi'], $plugin['needs'] ?? '', $link( $slug ) );
}
$bari    = $data['bari'];
$catalog_markup = implode(
	"\n\n",
	array(
		$section(
			array(
				// In a column, so the pill is as wide as its words.
				$column(
					array(
						$p( $t( $catalog['eyebrow'] ), 'pill' ),
						$h( $t( $catalog['heading'] ), 'hero' ),
						$p( $t( $catalog['lead'] ), 'large' ),
					),
					array( 'gap' => '40' )
				),
			),
			'gradient:primary-to-contrast',
			array(
				'anchor' => 'top',
				'text'   => 'base',
				'wide'   => false,
			)
		),
		// One section, so the white cards stand on the soft background.
		$section(
			array(
				$p( $t( $catalog['stack_heading'] ), 'eyebrow' ),
				$card( $bari['name'], $bari['line'], empty( $bari['multisite_only'] ) ? $catalog['single_and_multi'] : $catalog['multisite_only'], '', '' ),
				$p( $t( $catalog['plugins_heading'] ), 'eyebrow' ),
				$grid( $cards, 3 ),
				$p( $t( $catalog['footer'] ), 'small-center' ),
			),
			'gradient:base-to-soft-primary',
			array( 'anchor' => 'plugins' )
		),
	)
);
$catalog_id     = $save(
	array(
		'ID'           => $catalog_id,
		'post_title'   => $catalog['title'],
		'post_name'    => $catalog['slug'],
		'post_status'  => $catalog_status,
		'post_content' => $catalog_markup,
	),
	array( $catalog['seo_title'], $catalog['seo_description'] )
);

/*
 * The header's menu: the "Our source code" dropdown, before Log in.
 */
$navigation = get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => 'mody_lang', 'compare' => 'NOT EXISTS' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
if ( ! $navigation ) {
	WP_CLI::warning( 'The header has no menu yet: run scripts/home-page.php, then this script again.' );
} else {
	$menu = (string) preg_replace( '#\s*<!-- wp:navigation-submenu \{[^\n]*"className":"mody-source-code"[^\n]*-->.*?<!-- /wp:navigation-submenu -->#s', '', $navigation[0]->post_content );
	if ( 'publish' === $catalog_status ) {
		$entry = static fn( string $label, string $url ): string => '<!-- wp:navigation-link ' . serialize_block_attributes( array( 'label' => $label, 'url' => $url, 'kind' => 'custom' ) ) . ' /-->';
		$links = array( $entry( $catalog['menu_overview'], home_url( '/' . $catalog['slug'] . '/' ) ) );
		foreach ( $published as $slug => $id ) {
			$links[] = $entry( $data['plugins'][ $slug ]['name'], (string) get_permalink( $id ) );
		}
		$submenu = '<!-- wp:navigation-submenu ' . serialize_block_attributes(
			array(
				'label'     => $catalog['title'],
				'url'       => home_url( '/' . $catalog['slug'] . '/' ),
				'kind'      => 'custom',
				'className' => 'mody-source-code',
			)
		) . " -->\n" . implode( "\n\n", $links ) . "\n<!-- /wp:navigation-submenu -->";
		$login   = strpos( $menu, '"label":"Log in"' );
		$start   = false !== $login ? strrpos( substr( $menu, 0, $login ), '<!-- wp:navigation-link' ) : false;
		$menu    = false !== $start ? substr( $menu, 0, $start ) . $submenu . "\n\n" . substr( $menu, $start ) : trim( $menu ) . "\n\n" . $submenu;
	}
	if ( trim( $menu ) !== trim( $navigation[0]->post_content ) ) {
		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $navigation[0]->ID,
					'post_content' => trim( $menu ),
				)
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			WP_CLI::error( $updated->get_error_message() );
		}
	}
}

WP_CLI::success(
	sprintf(
		'The catalog (page %d) is %s: %s',
		$catalog_id,
		'publish' === $catalog_status ? 'published' : 'a draft',
		'publish' === $catalog_status ? get_permalink( $catalog_id ) : get_preview_post_link( $catalog_id )
	)
);
