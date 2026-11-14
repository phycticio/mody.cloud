<?php
/**
 * Tests custom domains (tickets/custom-domains.md, 2026-11-13).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/domains-test.php
 *
 * 1. A domain as typed: made plain, refused when it is not a domain or is
 *    the network's own; the record's name at the registrar.
 * 2. Adding: one per site, one site per domain, never the main site.
 * 3. The DNS check (answers from a filter): a CNAME to the target, or the
 *    target's addresses; otherwise why not.
 * 4. The certificate through Nginx Proxy Manager (a fake NPM): pending →
 *    issuing → active, the proxy host forwarding like the network's, the
 *    site's address switched (the old one kept); without NPM it waits; after
 *    a day it stops; Try again.
 * 5. Over HTTP, through the local Nginx: the domain serves its site; the
 *    login goes to the mody.cloud address; the old address sends visitors to
 *    the domain; an unknown domain gets no site.
 * 6. Admin links stay on the admin host.
 * 7. Taking it off: the address back, NPM's host and certificate removed,
 *    the mapping gone; a deleted site's domain goes too.
 * 8. The Dashboard's section.
 *
 * A throwaway site is made and deleted. No real DNS, no real NPM.
 */

use Mody\Platform\Domains\Domains;
use Mody\Platform\Domains\Mapping;
use Mody\Platform\Domains\Panel;
use Mody\Platform\Domains\ProxyManager;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-74s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
$suffix  = strtolower( wp_generate_password( 6, false ) );
$network = Mapping::network_domain();
$target  = Domains::target();
$env     = array();
foreach ( array( 'NPM_API_URL', 'NPM_API_EMAIL', 'NPM_API_PASSWORD' ) as $name ) {
	$env[ $name ] = getenv( $name );
	putenv( $name );
}
$supers = get_super_admins();
$super  = get_user_by( 'login', $supers[0] );
$site   = (int) wpmu_create_blog( 'domtest' . $suffix . '.' . $network, '/', 'Domain Test Bakery', (int) $super->ID );
$other  = (int) wpmu_create_blog( 'domtest2' . $suffix . '.' . $network, '/', 'Other Site', (int) $super->ID );
register_shutdown_function(
	static function () use ( $site, $other, $env, $wpdb ): void {
		foreach ( array( $site, $other ) as $id ) {
			$wpdb->delete( Mapping::table(), array( 'site_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( get_site( $id ) ) {
				wpmu_delete_blog( $id, true );
			}
		}
		foreach ( $env as $name => $value ) {
			false === $value ? putenv( $name ) : putenv( $name . '=' . $value );
		}
	}
);
foreach ( array( $site, $other ) as $id ) { // The platform's theme, as on every member site (a bare site has core's default, not installed).
	update_blog_option( $id, 'template', 'theme' );
	update_blog_option( $id, 'stylesheet', 'theme' );
}
$check( 'a throwaway site', $site > 0 && $other > 0 );

// 1. A domain as typed --------------------------------------------------------------------------

echo "\nA domain as typed\n";
$plain = array_map( static fn( string $t ) => Domains::normalize( $t ), array( 'https://WWW.Shop-Test.com/about?x=1', 'shop.example.co.uk.', 'www.shop.test:8080' ) );
$check( 'made plain: no scheme, path, port, final dot; lowercase', array( 'www.shop-test.com', 'shop.example.co.uk', 'www.shop.test' ) === $plain, wp_json_encode( $plain ) );
$bad = array_map( static fn( string $t ): string => is_wp_error( $r = Domains::normalize( $t ) ) ? $r->get_error_code() : $r, array( 'not a domain', 'localhost', '192.168.1.10', 'shop.' . $network, $network, $target, '-bad-.com' ) );
$check( 'refused: not a domain, an address, the network\'s own, the target', array( 'mody_domain_invalid', 'mody_domain_invalid', 'mody_domain_invalid', 'mody_domain_ours', 'mody_domain_ours', 'mody_domain_ours', 'mody_domain_invalid' ) === $bad, wp_json_encode( $bad ) );
$check( 'the record\'s name: www, @ for bare domains (shop.co.uk too), a subdomain', 'www' === Panel::record_name( 'www.shop.com' ) && '@' === Panel::record_name( 'shop.com' ) && '@' === Panel::record_name( 'shop.co.uk' ) && 'blog.www' === Panel::record_name( 'blog.www.shop.com' ) && 'www' === Panel::record_name( 'www.shop.co.uk' ) );

// 2. Adding ---------------------------------------------------------------------------------------------

echo "\nAdding\n";
$domain = 'www.domtest' . $suffix . '.test';
$row    = Domains::add( $site, 'https://' . strtoupper( $domain ) . '/' );
$check( 'added: pending, plain', is_array( $row ) && $domain === $row['domain'] && 'pending' === $row['status'], is_wp_error( $row ) ? $row->get_error_message() : '' );
$codes = array_map(
	static fn( $r ): string => is_wp_error( $r ) ? $r->get_error_code() : 'added',
	array( Domains::add( $site, 'www.another' . $suffix . '.test' ), Domains::add( $other, $domain ), Domains::add( get_main_site_id(), 'www.main' . $suffix . '.test' ) )
);
$check( 'refused: a second domain, a domain already used, the main site', array( 'mody_domain_one', 'mody_domain_taken', 'mody_domain_site' ) === $codes, implode( ', ', $codes ) );

// 3. The DNS check ---------------------------------------------------------------------------------------

echo "\nThe DNS check\n";
$dns = array();
add_filter(
	'mody_domains_dns',
	static function ( $records, string $name, int $type ) use ( &$dns ) {
		return $dns[ $name ][ $type ] ?? array();
	},
	10,
	3
);
$nothing = Domains::points_here( $domain );
$check( 'no record yet: not here, "cannot find"', is_wp_error( $nothing ) && str_contains( $nothing->get_error_message(), 'cannot find' ) );
$dns[ $domain ][ DNS_A ] = array( array( 'ip' => '203.0.113.9' ) );
$dns[ $target ][ DNS_A ] = array( array( 'ip' => '198.51.100.7' ) );
$elsewhere               = Domains::points_here( $domain );
$check( 'pointing elsewhere: not here, says to use a CNAME to the target', is_wp_error( $elsewhere ) && str_contains( $elsewhere->get_error_message(), $target ) );
$dns[ $domain ][ DNS_A ] = array( array( 'ip' => '198.51.100.7' ) );
$check( 'the target\'s addresses: here (a bare domain with ALIAS)', true === Domains::points_here( $domain ) );
$dns[ $domain ] = array( DNS_CNAME => array( array( 'target' => $target . '.' ) ) );
$check( 'a CNAME to the target: here', true === Domains::points_here( $domain ) );

// 4. The certificate through NPM ------------------------------------------------------------------------

echo "\nThe certificate\n";
$after = Domains::advance( $row );
$check( 'without NPM: it points here, so issuing, and says certificates are not set up', 'issuing' === $after['status'] && str_contains( (string) $after['error'], 'not set up' ) );
putenv( 'NPM_API_URL=http://npm.test/api' );
putenv( 'NPM_API_EMAIL=admin@npm.test' );
putenv( 'NPM_API_PASSWORD=secret' );
ProxyManager::reset();
$npm = array(
	'calls' => array(),
	'hosts' => array(
		array(
			'id'             => 18,
			'domain_names'   => array( $network ),
			'forward_scheme' => 'http',
			'forward_host'   => 'host.docker.internal',
			'forward_port'   => 8195,
		),
	),
	'certs' => array(),
	'fail'  => false,
);
add_filter(
	'pre_http_request',
	static function ( $pre, array $args, string $url ) use ( &$npm ) {
		if ( ! str_starts_with( $url, 'http://npm.test/api' ) ) {
			return $pre;
		}
		$path  = substr( $url, strlen( 'http://npm.test/api' ) );
		$body  = json_decode( (string) ( $args['body'] ?? '' ), true );
		$npm['calls'][] = array( $args['method'] ?? 'GET', $path, $body, $args['headers']['Authorization'] ?? '' );
		$reply = static fn( int $code, $data ): array => array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $data ),
			'headers'  => array(),
		);
		if ( '/tokens' === $path ) {
			return $reply( 200, array( 'token' => 'tok123' ) );
		}
		if ( 'GET' === $args['method'] && '/nginx/proxy-hosts' === $path ) {
			return $reply( 200, $npm['hosts'] );
		}
		if ( 'GET' === $args['method'] && '/nginx/certificates' === $path ) {
			return $reply( 200, $npm['certs'] );
		}
		if ( 'POST' === $args['method'] && '/nginx/certificates' === $path ) {
			if ( $npm['fail'] ) {
				return $reply( 400, array( 'error' => array( 'message' => 'Some challenges have failed.' ) ) );
			}
			$npm['certs'][] = array( 'id' => 41, 'domain_names' => $body['domain_names'] );
			return $reply( 201, array( 'id' => 41 ) );
		}
		if ( 'POST' === $args['method'] && '/nginx/proxy-hosts' === $path ) {
			$npm['hosts'][] = array( 'id' => 77, 'domain_names' => $body['domain_names'] );
			return $reply( 201, array( 'id' => 77 ) );
		}
		if ( 'DELETE' === $args['method'] ) {
			return $reply( 200, true );
		}
		return $reply( 404, array( 'error' => array( 'message' => 'Not found' ) ) );
	},
	10,
	3
);
$old    = (string) get_blog_option( $site, 'home' );
$after  = Domains::advance( Domains::of_site( $site ) );
$cert   = array_values( array_filter( $npm['calls'], static fn( array $c ): bool => 'POST' === $c[0] && '/nginx/certificates' === $c[1] ) );
$host   = array_values( array_filter( $npm['calls'], static fn( array $c ): bool => 'POST' === $c[0] && '/nginx/proxy-hosts' === $c[1] ) );
$check( 'active: NPM\'s certificate and proxy host kept', 'active' === $after['status'] && 77 === (int) $after['npm_host_id'] && 41 === (int) $after['npm_cert_id'] && null === $after['error'] );
$check( '... a Let\'s Encrypt certificate for the domain, HTTP challenge, only what NPM 2.16 accepts', 1 === count( $cert ) && 'letsencrypt' === $cert[0][2]['provider'] && array( $domain ) === $cert[0][2]['domain_names'] && array( 'dns_challenge' => false ) === $cert[0][2]['meta'] ); // NPM 2.16's schema: nothing else in meta.
$check( '... the proxy host forwards like the network\'s, HTTPS forced, the certificate on it', 1 === count( $host ) && 'host.docker.internal' === $host[0][2]['forward_host'] && 8195 === $host[0][2]['forward_port'] && 'http' === $host[0][2]['forward_scheme'] && true === $host[0][2]['ssl_forced'] && 41 === $host[0][2]['certificate_id'] );
$check( '... logged in with a token', 'Bearer tok123' === $host[0][3] );
$check( '... the site\'s public address is the domain; the admin stays; the old one kept', 'https://' . $domain === get_blog_option( $site, 'home' ) && str_contains( (string) get_blog_option( $site, 'siteurl' ), 'domtest' . $suffix . '.' . $network ) && $old === $after['old_home'] );
$check( '... the mapping finds it', is_array( Mapping::active( $domain ) ) && $site === (int) Mapping::active( $domain )['site_id'] );

// An NPM user that sees only its own items: the forwarding comes from the stack's published port.
$saved_hosts  = $npm['hosts'];
$saved_port   = getenv( 'NGINX_HTTP_PORT' );
$npm['hosts'] = array();
$npm['calls'] = array();
putenv( 'NGINX_HTTP_PORT=8195' );
$third = 'www.third' . $suffix . '.test';
$made  = ProxyManager::add( $third );
$post  = array_values( array_filter( $npm['calls'], static fn( array $c ): bool => 'POST' === $c[0] && '/nginx/proxy-hosts' === $c[1] ) );
$check( 'an NPM user that cannot see mody.cloud\'s host: forwards to host.docker.internal and the stack\'s port', is_array( $made ) && 1 === count( $post ) && 'host.docker.internal' === $post[0][2]['forward_host'] && 8195 === $post[0][2]['forward_port'], is_wp_error( $made ) ? $made->get_error_message() : '' );
putenv( 'NGINX_HTTP_PORT' );
$none = ProxyManager::add( 'www.fourth' . $suffix . '.test' );
$check( '... and says so when neither is known', is_wp_error( $none ) && 'mody_npm_network' === $none->get_error_code() );
false === $saved_port ? putenv( 'NGINX_HTTP_PORT' ) : putenv( 'NGINX_HTTP_PORT=' . $saved_port );
$npm['hosts'] = $saved_hosts;

// Another domain whose certificate fails: it keeps trying, then stops after a day; Try again restarts.
$dom2 = 'shop' . $suffix . '.test';
$row2 = Domains::add( $other, $dom2 );
$dns[ $dom2 ] = array( DNS_CNAME => array( array( 'target' => $target ) ) );
$npm['fail'] = true;
$row2 = Domains::advance( $row2 );
$check( 'a certificate that fails: still issuing, with NPM\'s reason', 'issuing' === $row2['status'] && str_contains( (string) $row2['error'], 'challenges have failed' ) );
$wpdb->update( Mapping::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ), array( 'id' => (int) $row2['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$row2 = Domains::advance( Domains::of_site( $other ) );
$check( '... after a day: failed, the site\'s address unchanged', 'failed' === $row2['status'] && ! str_contains( (string) get_blog_option( $other, 'home' ), $dom2 ) );
$npm['fail'] = false;
$check( 'the run leaves failed domains alone', 0 === Domains::run()['checked'] || 'failed' === Domains::of_site( $other )['status'] );

// 5. Over HTTP ----------------------------------------------------------------------------------------------

echo "\nOver HTTP\n";
$get = static function ( string $host, string $path = '/' ): array {
	$response = wp_remote_get(
		'https://mody_cloud_server' . $path,
		array(
			'headers'     => array( 'Host' => $host ),
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 60,
		)
	);
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'error'    => is_wp_error( $response ) ? $response->get_error_message() : '',
	);
};
$front = $get( $domain );
$check( 'the domain serves its site', 200 === $front['code'] && str_contains( $front['body'], 'Domain Test Bakery' ), $front['code'] . ' ' . $front['error'] );
$check( '... its links are on the domain', str_contains( $front['body'], 'https://' . $domain ) );
$login = $get( $domain, '/wp-login.php?redirect_to=x' );
$check( 'the login on the domain goes to the mody.cloud address', 302 === $login['code'] && str_contains( $login['location'], 'domtest' . $suffix . '.' . $network . '/wp-login.php' ) && str_contains( $login['location'], 'redirect_to=x' ), $login['code'] . ' ' . $login['location'] );
$admin = $get( $domain, '/wp-admin/' );
$check( '... so does wp-admin', 302 === $admin['code'] && str_contains( $admin['location'], 'domtest' . $suffix . '.' . $network . '/wp-admin/' ), $admin['location'] );
$oldsite = $get( 'domtest' . $suffix . '.' . $network, '/' );
$check( 'the old address sends visitors to the domain', in_array( $oldsite['code'], array( 301, 302 ), true ) && str_starts_with( $oldsite['location'], 'https://' . $domain ), $oldsite['code'] . ' ' . $oldsite['location'] );
$unknown = $get( 'www.unknown' . $suffix . '.test' );
$check( 'an unknown domain gets no site', ! str_contains( $unknown['body'], 'Domain Test Bakery' ), (string) $unknown['code'] );

// 6. Admin links -------------------------------------------------------------------------------------------

echo "\nAdmin links\n";
switch_to_blog( $site );
$moved = Domains::on_admin_host( home_url( '/?p=12&preview=true' ) );
restore_current_blog();
$check( 'previews and admin REST addresses stay on the admin host', str_contains( $moved, 'domtest' . $suffix . '.' . $network ) && ! str_contains( $moved, $domain ) && str_contains( $moved, 'preview=true' ), $moved );

// 7. Taking it off ------------------------------------------------------------------------------------------

echo "\nTaking it off\n";
$npm['calls'] = array();
$done         = Domains::remove( $site );
$deletes      = array_values( array_filter( $npm['calls'], static fn( array $c ): bool => 'DELETE' === $c[0] ) );
$check( 'off: the old address back, NPM\'s host and certificate removed, no mapping', true === $done && $old === get_blog_option( $site, 'home' ) && array( '/nginx/proxy-hosts/77', '/nginx/certificates/41' ) === array_column( $deletes, 1 ) && null === Mapping::active( $domain ) && null === Domains::of_site( $site ) );
$gone = $get( $domain );
$check( '... the domain no longer serves the site', ! str_contains( $gone['body'], 'Domain Test Bakery' ), (string) $gone['code'] );
wpmu_delete_blog( $other, true );
$check( 'a deleted site\'s domain goes with it', null === Domains::of_site( $other ) );

// 8. The Dashboard's section --------------------------------------------------------------------------------

echo "\nThe Dashboard\n";
switch_to_blog( $site );
wp_set_current_user( (int) $super->ID );
ob_start();
Panel::render();
$empty = (string) ob_get_clean();
Domains::add( $site, $domain );
$dns[ $domain ] = array();
Domains::advance( Domains::of_site( $site ) );
ob_start();
Panel::render();
$pending = (string) ob_get_clean();
Domains::remove( $site );
restore_current_blog();
$check( 'no domain: the form', str_contains( $empty, 'Use this domain' ) && str_contains( $empty, 'name="domain"' ) );
$check( 'pending: the CNAME to add (www → the target), Check now, Take it off', str_contains( $pending, '<code>CNAME</code>' ) && str_contains( $pending, '<code>www</code>' ) && str_contains( $pending, '<code>' . esc_html( $target ) . '</code>' ) && str_contains( $pending, 'Check now' ) && str_contains( $pending, 'Take this domain off' ) );
$check( 'the section shows on member sites only, for their administrators', ( switch_to_blog( $site ) || true ) && Panel::available() && ( restore_current_blog() || true ) && ! Panel::available() );
wp_set_current_user( 0 );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
