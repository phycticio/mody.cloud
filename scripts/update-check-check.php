<?php
/**
 * The PHP checks of scripts/update-check-test.sh, run with `wp eval-file` on
 * its plain single-site WordPress with Mody SEO, Images and Palette active.
 *
 * mody.cloud is never asked: a request to it is recorded, then sent with the
 * very same arguments to the test's stand-in on 127.0.0.1:8098, which serves
 * /tmp/mody-update-test/mody.cloud and writes down the headers that arrive.
 * WordPress.org is answered with an empty list. Prints "ok …" and "FAIL …".
 */

use Mody\Seo\UpdateCheck;

$check = static function ( string $label, bool $ok, $detail = '' ): void {
	echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . ( ! $ok && '' !== $detail ? ' (' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ')' : '' ) . "\n";
};

$root     = '/tmp/mody-update-test';
$latest   = $root . '/mody.cloud/web/uploads/downloads/latest.json';
$headers  = $root . '/headers.json';
$requests = array();
$relaying = false;

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$requests, &$relaying ) {
		if ( $relaying ) {
			return $pre;
		}
		$requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( str_starts_with( $url, 'https://mody.cloud/' ) ) {
			// The same request, to the stand-in. Only the address differs, and the
			// stand-in is on 127.0.0.1, which wp_safe_remote_get() refuses.
			$relaying = true;
			$response = ( new WP_Http() )->request( 'http://127.0.0.1:8098' . wp_parse_url( $url, PHP_URL_PATH ), array( 'reject_unsafe_urls' => false ) + $args );
			$relaying = false;
			return $response;
		}
		if ( str_contains( $url, 'api.wordpress.org/plugins/update-check/' ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'plugins' => array(), 'translations' => array(), 'no_update' => array() ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		return new WP_Error( 'mody_update_test', 'Not asked in this test.' );
	},
	10,
	3
);

$to_mody = static function () use ( &$requests ): array {
	return array_values( array_filter( $requests, static fn( array $r ): bool => str_starts_with( $r['url'], 'https://mody.cloud/' ) ) );
};
$run = static function (): object {
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	return get_site_transient( 'update_plugins' );
};
$fresh = static function () use ( $headers ): void {
	delete_site_transient( UpdateCheck::CACHE );
	@unlink( $headers ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
};

// 1. The plugins as WordPress reads them.
$plugins = get_plugins();
foreach ( array( 'mody-seo/mody-seo.php', 'mody-images/mody-images.php', 'mody-palette/mody-palette.php' ) as $file ) {
	$check( "$file: Update URI https://mody.cloud/, version 1.0.0", 'https://mody.cloud/' === ( $plugins[ $file ]['UpdateURI'] ?? '' ) && '1.0.0' === ( $plugins[ $file ]['Version'] ?? '' ), $plugins[ $file ] ?? 'missing' );
}
$check( 'not Mody Cloud\'s network here', ! UpdateCheck::platform() );

// 2. A newer Mody SEO, the same Mody Images, and a Mody Palette whose zip is not on mody.cloud.
file_put_contents(
	$latest,
	wp_json_encode(
		array(
			'plugins' => array(
				'mody-seo'     => array(
					'version'      => '1.0.1',
					'package'      => 'https://mody.cloud/web/uploads/downloads/mody-seo-1.0.1.zip',
					'url'          => 'https://mody.cloud/plugins/seo/',
					'requires'     => '7.1',
					'tested'       => '7.1',
					'requires_php' => '8.1',
					'changelog'    => 'Fixes the description of password-protected pages.',
				),
				'mody-images'  => array(
					'version' => '1.0.0',
					'package' => 'https://mody.cloud/web/uploads/downloads/mody-images-1.0.0.zip',
				),
				'mody-palette' => array(
					'version' => '2.0.0',
					'package' => 'https://example.com/mody-palette-2.0.0.zip',
				),
			),
		)
	)
);
$fresh();
$updates = $run();
$asked   = $to_mody();
$check( 'one request to mody.cloud for the three plugins', 1 === count( $asked ), count( $asked ) );
$first = $asked[0] ?? array(
	'url'  => '',
	'args' => array(),
);
$check( '  to latest.json, with no query', UpdateCheck::URL === $first['url'], $first['url'] );
$check( '  its own User-Agent', 'Mody-Update-Check' === ( $first['args']['user-agent'] ?? '' ), $first['args']['user-agent'] ?? '' );
$check( '  no cookies, no headers of its own, no redirects', array() === ( $first['args']['cookies'] ?? null ) && array() === ( $first['args']['headers'] ?? null ) && 0 === ( $first['args']['redirection'] ?? null ), $first['args'] );
$check( '  only to safe addresses (wp_safe_remote_get)', true === ( $first['args']['reject_unsafe_urls'] ?? false ) );
$wire = is_readable( $headers ) ? json_decode( (string) strtok( (string) file_get_contents( $headers ), "\n" ), true ) : null;
$sent = is_array( $wire['headers'] ?? null ) ? $wire['headers'] : array();
$check( 'what arrived: User-Agent Mody-Update-Check', 'Mody-Update-Check' === ( $sent['User-Agent'] ?? $sent['user-agent'] ?? '' ), $sent );
$check( '  and nothing about the site: no address, no WordPress version, no cookie, no referrer', ! preg_match( '#127\.0\.0\.1:8097|update test|WordPress/|' . preg_quote( get_bloginfo( 'version' ), '#' ) . '#i', wp_json_encode( $sent ) ) && ! isset( $sent['Cookie'], $sent['Referer'] ), $sent );
$check( '  the address asked has no query', '/web/uploads/downloads/latest.json' === ( $wire['uri'] ?? '' ), $wire['uri'] ?? '' );
$org = array_values( array_filter( $requests, static fn( array $r ): bool => str_contains( $r['url'], 'api.wordpress.org/plugins/update-check/' ) ) );
// Core's own check sends WordPress.org every installed plugin's header (not ours to change); with an
// Update URI elsewhere, WordPress.org leaves the plugin alone, so a plugin there by the same name never replaces ours.
$sent_org = json_decode( (string) ( $org[0]['args']['body']['plugins'] ?? '' ), true );
$check( 'WordPress.org is told each Mody plugin belongs to mody.cloud (its Update URI)', $org && 'https://mody.cloud/' === ( $sent_org['plugins']['mody-seo/mody-seo.php']['UpdateURI'] ?? '' ) && 'https://mody.cloud/' === ( $sent_org['plugins']['mody-palette/mody-palette.php']['UpdateURI'] ?? '' ), $sent_org['plugins'] ?? 'not asked' );
$seo = $updates->response['mody-seo/mody-seo.php'] ?? null;
$check( 'Mody SEO 1.0.1 is offered, from mody.cloud', $seo && '1.0.1' === $seo->new_version && 'https://mody.cloud/web/uploads/downloads/mody-seo-1.0.1.zip' === $seo->package && 'https://mody.cloud/plugins/seo/' === $seo->url, $seo );
$check( '  with its requirements', $seo && '7.1' === ( $seo->tested ?? '' ) && '8.1' === ( $seo->requires_php ?? '' ) );
$check( 'Mody Images is up to date', isset( $updates->no_update['mody-images/mody-images.php'] ) && ! isset( $updates->response['mody-images/mody-images.php'] ) );
$check( 'Mody Palette\'s zip elsewhere is ignored', ! isset( $updates->response['mody-palette/mody-palette.php'] ) && ! isset( $updates->no_update['mody-palette/mody-palette.php'] ) );

// 3. The shared answer: WordPress checks again, nobody asks again.
$run();
$check( 'a second check within 12 hours asks nothing', 1 === count( $to_mody() ), count( $to_mody() ) );

// 4. The update notice's details come from latest.json, not WordPress.org.
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
$before = count( $requests );
$info   = plugins_api( 'plugin_information', array( 'slug' => 'mody-seo' ) );
$check( 'Mody SEO\'s details: its name, version 1.0.1 and changelog', is_object( $info ) && 'Mody SEO' === $info->name && '1.0.1' === $info->version && str_contains( $info->sections['changelog'] ?? '', 'password-protected' ), $info );
$check( '  with no request at all', count( $requests ) === $before, array_slice( $requests, $before ) );
$check( 'another plugin\'s details are left to WordPress', false === UpdateCheck::details( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );

// 5. mody.cloud does not answer: no update, and the next check waits too.
unlink( $latest );
$fresh();
$updates = $run();
$check( 'without latest.json: one request, no update', 2 === count( $to_mody() ) && ! isset( $updates->response['mody-seo/mody-seo.php'] ), count( $to_mody() ) );
$run();
$check( '  and the failure waits 12 hours too', 2 === count( $to_mody() ), count( $to_mody() ) );

// 6. On Mody Cloud's network (Mody Updates is there): never.
eval( 'namespace Mody\Updates; final class Updates {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
$fresh();
$run();
$check( 'with Mody Updates present, nothing is asked', 2 === count( $to_mody() ) && UpdateCheck::platform(), count( $to_mody() ) );
