#!/usr/bin/env bash
#
# Tests the nine Mody plugins offered on mody.cloud as a stranger gets them:
# the zips bin/package builds, installed on a plain WordPress, single site and
# multisite, with nothing else of Mody Cloud (tickets/product-pages-bari-and-plugins.md,
# part 3, D5).
#
# Run from the repository root with the local stack up (and Node 22 for the build):
#
#   ./scripts/standalone-test.sh          # cleans up at the end
#   ./scripts/standalone-test.sh --keep   # leaves the sites and their databases
#
# What it does:
#   1. ./bin/package --working-copy all, into a temporary folder: this
#      checkout's files, committed or not;
#   2. inside the app container (WordPress core is the stack's own copy): a
#      single site on 127.0.0.1:8094 and a subdirectory multisite with a
#      second site on localhost:8095, each with its own database; the theme
#      and the nine plugins installed from the zips with WP-CLI, activated
#      (network-activated on the multisite);
#   3. the checks in scripts/standalone-check.php on each;
#   4. over HTTP, with PHP's built-in server, logged in with a real cookie:
#      the login page, two-step login's first visit, the Security page and
#      its sections, the Dashboard and its board, the editor, the front end,
#      avatars and placeholders; no PHP error, warning or notice in the log.

# No pipefail: the checks pipe into grep -q, which stops reading at the first
# match, and the writer's broken pipe must not count as a failure.
set -u

CONTAINER=mody_cloud_app
T=/tmp/mody-standalone-test
KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1
PLUGINS="security analytics dashboard images seo profiles fonts patterns palette"

PASS=0
FAIL=0
check() { # check "label" condition-exit-code
	if [ "$2" = 0 ]; then PASS=$((PASS + 1)); echo "  ok   $1"; else FAIL=$((FAIL + 1)); echo "  FAIL $1"; fi
}
in_app() { docker exec -i -u www-data -w "$T" "$CONTAINER" sh -c "$1"; }
in_app_root() { docker exec -i "$CONTAINER" sh -c "$1"; }

OUT="$(mktemp -d)"
cleanup() {
	in_app_root "pkill -f '[p]hp -S 127.0.0.1:809[45]' 2>/dev/null; true"
	rm -rf "$OUT"
	if [ "$KEEP" = 0 ]; then
		in_app_root "rm -rf $T"
		for DB in mody_standalone_single mody_standalone_multi; do
			in_app_root 'MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb --no-defaults -h "$WORDPRESS_DB_HOST" -uroot -e "DROP DATABASE IF EXISTS '"$DB"'"'
		done
	fi
}
trap cleanup EXIT

echo "1. The zips"
if ./bin/package --working-copy --out="$OUT" all >"$OUT/package.log" 2>&1; then
	check "bin/package built them" 0
else
	check "bin/package built them" 1
	tail -20 "$OUT/package.log"
	exit 1
fi
for P in $PLUGINS; do
	ZIP=$(ls "$OUT"/mody-"$P"-*.zip 2>/dev/null | head -n 1)
	check "mody-$P: a zip with readme.txt and LICENSE.txt" "$([ -n "$ZIP" ] && unzip -Z1 "$ZIP" | grep -qx "mody-$P/readme.txt" && unzip -Z1 "$ZIP" | grep -qx "mody-$P/LICENSE.txt" && echo 0 || echo 1)"
done
check "  Security carries ALTCHA's widget, Dashboard its board" "$(unzip -Z1 "$OUT"/mody-security-*.zip | grep -qx 'mody-security/dist/altcha.js' && unzip -Z1 "$OUT"/mody-dashboard-*.zip | grep -qx 'mody-dashboard/dist/analytics-board.js' && echo 0 || echo 1)"
check "  no other dist/ anywhere" "$(for Z in "$OUT"/mody-*.zip; do unzip -Z1 "$Z"; done | grep '/dist/' | grep -vqE '^mody-(security/dist/altcha|dashboard/dist/analytics-board)\.(js|asset\.php)$' && echo 1 || echo 0)"
THEME=$(ls "$OUT"/mody-theme-*.zip | head -n 1)
check "the theme's zip: mody-theme/ with Inter baked in" "$(unzip -Z1 "$THEME" | grep -qx 'mody-theme/assets/fonts/inter/InterVariable.woff2' && unzip -p "$THEME" mody-theme/theme.json | grep -q 'file:./assets/fonts/inter/InterVariable.woff2' && echo 0 || echo 1)"
check "latest.json names the nine plugins and the theme" "$(php -d display_startup_errors=0 -r '$l = json_decode( file_get_contents( $argv[1] ), true ); $ok = 9 === count( $l["plugins"] ?? array() ) && isset( $l["themes"]["mody-theme"] ); foreach ( $l["plugins"] as $slug => $e ) { $ok = $ok && "https://mody.cloud/web/uploads/downloads/" . $slug . "-" . $e["version"] . ".zip" === $e["package"] && "" !== $e["changelog"] && "8.1" === $e["requires_php"]; } exit( $ok ? 0 : 1 );' "$OUT/latest.json" >/dev/null 2>&1 && echo 0 || echo 1)"
ZIP_SUM=$(shasum -a 256 "$OUT"/mody-seo-*.zip | cut -d' ' -f1)
./bin/package --working-copy --out="$OUT" seo >/dev/null 2>&1
check "the same sources give the same zip" "$([ "$ZIP_SUM" = "$(shasum -a 256 "$OUT"/mody-seo-*.zip | cut -d' ' -f1)" ] && echo 0 || echo 1)"

in_app_root "pkill -f '[p]hp -S 127.0.0.1:809[45]' 2>/dev/null; rm -rf $T && mkdir -p $T/zips && chown -R www-data $T"
for Z in "$OUT"/*.zip; do docker cp "$Z" "$CONTAINER:$T/zips/" >/dev/null; done
docker cp scripts/standalone-check.php "$CONTAINER:$T/check.php" >/dev/null
in_app_root "chown -R www-data $T"

# PHP's built-in server: files as they are; a subdirectory site's wp-admin,
# wp-includes, wp-content and .php addresses as Apache's multisite rules
# rewrite them; everything else through WordPress.
in_app "cat > $T/router.php" <<'PHP'
<?php
$mody_site = getenv( 'MODY_SITE' );
$mody_path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( ! file_exists( $mody_site . $mody_path ) && preg_match( '#^/[^/]+(/(?:wp-(?:content|admin|includes)(?:/.*)?|[^/]*\.php))$#', $mody_path, $mody_m ) ) {
	$mody_path = $mody_m[1];
}
$mody_file = $mody_site . $mody_path;
if ( is_dir( $mody_file ) && is_file( rtrim( $mody_file, '/' ) . '/index.php' ) ) {
	$mody_path = rtrim( $mody_path, '/' ) . '/index.php';
	$mody_file = $mody_site . $mody_path;
}
if ( '/' !== $mody_path && is_file( $mody_file ) ) {
	if ( ! str_ends_with( $mody_file, '.php' ) ) {
		$mody_types = array( 'js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2' );
		header( 'Content-Type: ' . ( $mody_types[ pathinfo( $mody_file, PATHINFO_EXTENSION ) ] ?? 'application/octet-stream' ) );
		readfile( $mody_file );
		return true;
	}
} else {
	$mody_path = '/index.php';
	$mody_file = $mody_site . '/index.php';
	unset( $_SERVER['PATH_INFO'], $_SERVER['ORIG_PATH_INFO'] );
}
$_SERVER['SCRIPT_NAME']     = $mody_path;
$_SERVER['PHP_SELF']        = $mody_path;
$_SERVER['SCRIPT_FILENAME'] = $mody_file;
chdir( dirname( $mody_file ) );
unset( $mody_site, $mody_m, $mody_types );
require $mody_file;
PHP

make_site() { # make_site single|multi
	local KIND=$1 SITE=$T/$1 DB=mody_standalone_$1 URL
	[ "$KIND" = single ] && URL=http://127.0.0.1:8094 || URL=http://localhost:8095
	in_app "cp -R /var/www/html/wp $SITE && mkdir -p $SITE/wp-content/plugins $SITE/wp-content/themes $SITE/wp-content/uploads"
	in_app_root 'MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb --no-defaults -h "$WORDPRESS_DB_HOST" -uroot -e "DROP DATABASE IF EXISTS '"$DB"'; CREATE DATABASE '"$DB"' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON '"$DB"'.* TO \`$WORDPRESS_DB_USER\`@\`%\`;"'
	{
		cat <<PHP
<?php
// A plain wp-config, as a host's installer writes it.
define( 'DB_NAME', '$DB' );
define( 'WP_DEBUG_LOG', '$T/$KIND.log' );
PHP
		cat <<'PHP'
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $mody_key ) {
	define( $mody_key, hash( 'sha256', $mody_key . __FILE__ ) );
}
$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
PHP
		if [ "$KIND" = multi ]; then
			cat <<'PHP'
define( 'WP_ALLOW_MULTISITE', true );
if ( file_exists( __DIR__ . '/multisite.php' ) ) {
	require __DIR__ . '/multisite.php';
}
PHP
		fi
		cat <<'PHP'
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP
	} | in_app "cat > $SITE/wp-config.php"
	if [ "$KIND" = single ]; then
		wp_at "$SITE" core install --url="$URL" --title="Standalone single" --admin_user=owner --admin_password=standalone-test-password --admin_email=owner@example.test --skip-email >/dev/null
	else
		wp_at "$SITE" core multisite-install --url="$URL" --base=/ --title="Standalone network" --admin_user=owner --admin_password=standalone-test-password --admin_email=owner@example.test --skip-email --skip-config >/dev/null
		in_app "cat > $SITE/multisite.php" <<'PHP'
<?php
define( 'MULTISITE', true );
define( 'SUBDOMAIN_INSTALL', false );
define( 'DOMAIN_CURRENT_SITE', 'localhost:8095' );
define( 'PATH_CURRENT_SITE', '/' );
define( 'SITE_ID_CURRENT_SITE', 1 );
define( 'BLOG_ID_CURRENT_SITE', 1 );
PHP
	fi
	check "$KIND: installed" $?
	wp_at "$SITE" rewrite structure '/%postname%/' >/dev/null 2>&1
	wp_at "$SITE" theme install "$T/zips/$(basename "$THEME")" >/dev/null 2>&1
	if [ "$KIND" = multi ]; then
		wp_at "$SITE" theme enable mody-theme --network >/dev/null 2>&1
		wp_at "$SITE" site create --slug=second --title="Second site" >/dev/null 2>&1
		check "multi: a second site" $?
	fi
	wp_at "$SITE" theme activate mody-theme >/dev/null 2>&1
	check "$KIND: the Mody theme from its zip is active" $?
	if [ "$KIND" = multi ]; then
		wp_at "$SITE" --url=localhost:8095/second/ theme activate mody-theme >/dev/null 2>&1
		check "multi: on the second site too" $?
	fi
	local ACTIVATE=--activate
	[ "$KIND" = multi ] && ACTIVATE=--activate-network
	local ZIPS=""
	for P in $PLUGINS; do ZIPS="$ZIPS $T/zips/$(basename "$(ls "$OUT"/mody-"$P"-*.zip)")"; done
	# shellcheck disable=SC2086 # One zip per word.
	RESULT=$(wp_at "$SITE" plugin install $ZIPS $ACTIVATE 2>&1 | grep -vE "$CORE_CHECKS")
	check "$KIND: the nine zips install and activate" "$(echo "$RESULT" | grep -qiE 'error|warning' && echo 1 || echo 0)"
	echo "$RESULT" | grep -iE 'error|warning' | head -5
}
wp_at() { local SITE=$1; shift; docker exec -i -u www-data -w "$T" "$CONTAINER" wp --path="$SITE" "$@"; }

# Over HTTP, as the owner: a session made by WordPress, sent as cookies.
cookies() { # cookies <site>
	wp_at "$1" eval '$t = WP_Session_Tokens::get_instance( 1 )->create( time() + 3600 ); echo AUTH_COOKIE . "=" . rawurlencode( wp_generate_auth_cookie( 1, time() + 3600, "auth", $t ) ) . "; " . LOGGED_IN_COOKIE . "=" . rawurlencode( wp_generate_auth_cookie( 1, time() + 3600, "logged_in", $t ) );'
}
http() { # http <base> <host> <path> [cookie]: prints "code location" and keeps the body in $T/body
	docker exec -i -u www-data "$CONTAINER" curl -s -o "$T/body" -w '%{http_code} %{redirect_url}' -H "Host: $2" ${4:+-H "Cookie: $4"} "$1$3"
}
body() { in_app "cat $T/body"; }
serve() { # serve <site> <port>
	docker exec -d -u www-data -w "$T" -e MODY_SITE="$T/$1" "$CONTAINER" php -S "127.0.0.1:$2" "$T/router.php"
	for i in 1 2 3 4 5 6 7 8 9 10; do in_app "curl -s -o /dev/null http://127.0.0.1:$2/" && break; sleep 0.5; done
}
php_checks() { # php_checks <site>
	local OUTPUT P F
	OUTPUT=$(wp_at "$T/$1" eval-file "$T/check.php" 2>&1)
	echo "$OUTPUT" | sed "s/^/  $1: /"
	P=$(echo "$OUTPUT" | grep -c '^ok ')
	F=$(echo "$OUTPUT" | grep -vc '^ok ')
	PASS=$((PASS + P))
	FAIL=$((FAIL + F))
	[ "$P" -gt 0 ] || check "$1: the PHP checks ran" 1
}
# Core's own checks with WordPress.org fail here (WP_HTTP_BLOCK_EXTERNAL: the
# test asks nothing outside) and warn; those lines are core's, not ours.
CORE_CHECKS='(wp_version_check|wp_update_plugins|wp_update_themes|translations_api)\(\): An unexpected error occurred'
clean_log() { # clean_log <kind>
	check "$1: no PHP error, warning or notice in the log" "$(in_app "grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' $T/$1.log 2>/dev/null | grep -vE '$CORE_CHECKS' | head -10 >&2; grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' $T/$1.log 2>/dev/null | grep -qvE '$CORE_CHECKS' && echo 1 || echo 0")"
}
has() { body | grep -q -- "$1" && echo 0 || echo 1; }
# A 200 whose page reached its end: a fatal error halfway still answers 200.
ok200() { [ "${R%% *}" = 200 ] && body | grep -q '</html>' && ! body | grep -q 'There has been a critical error'; }

echo "2. A plain single site"
make_site single
php_checks single
B=http://127.0.0.1:8094
H=127.0.0.1:8094
serve single 8094
C=$(cookies "$T/single")
R=$(http $B $H /wp-login.php)
check "the login page: 200 with ALTCHA's widget" "$(ok200 && has 'wp-content/plugins/mody-security/dist/altcha.js' | grep -q 0 && echo 0 || echo 1)"
check "  the widget's script is served" "$(http $B $H /wp-content/plugins/mody-security/dist/altcha.js | grep -q '^200' && echo 0 || echo 1)"
check "  its challenge route answers" "$(http $B $H /wp-json/mody-platform/v1/altcha | grep -q '^200' && has '"keySignature"')"
R=$(http $B $H /wp-admin/ "$C")
check "first visit to wp-admin: sent to Profile to set up two-step login" "$(echo "$R" | grep -qE "^302 $B/wp-admin/profile.php\?mody_two_factor=required" && echo 0 || echo 1)"
R=$(http $B $H '/wp-admin/profile.php?mody_two_factor=required' "$C")
check "  Profile: 200, the notice and the Two-step login section (no loop)" "$(ok200 && has 'Your account must use two-step login' | grep -q 0 && has 'id="mody-two-factor"')"
wp_at "$T/single" eval '\Mody\Security\Settings::save( array( "two_factor_required" => "none" ) );' >/dev/null
R=$(http $B $H /wp-admin/ "$C")
check "with two-step login not required: wp-admin opens the Mody Dashboard" "$(echo "$R" | grep -qE "^302 .*admin.php\?page=mody-dashboard" && echo 0 || echo 1)"
R=$(http $B $H '/wp-admin/admin.php?page=mody-dashboard' "$C")
check "  the Dashboard: 200, in Mody's layout" "$(ok200 && has 'mody-brand__' )"
R=$(http $B $H '/wp-admin/admin.php?page=mody-dashboard&section=visitors' "$C")
check "  Visitors: 200, the board from the plugin's dist/" "$(ok200 && has 'wp-content/plugins/mody-dashboard/dist/analytics-board.js')"
check "  the board's script is served" "$(http $B $H /wp-content/plugins/mody-dashboard/dist/analytics-board.js | grep -q '^200' && echo 0 || echo 1)"
for S in overview two-factor login firewall alerts files log; do
	R=$(http $B $H "/wp-admin/options-general.php?page=mody-security&section=$S" "$C")
	check "Settings → Security, $S: 200" "$(ok200 && has 'mody-brand__')"
done
check "  under Settings in the menu" "$(has 'options-general.php?page=mody-security')"
R=$(http $B $H '/wp-admin/post-new.php?post_type=page' "$C")
check "the editor: 200 with the SEO panel and the palette-only pickers" "$(ok200 && has 'mody-seo/assets/seo-panel.js' | grep -q 0 && has 'components-color-palette__custom-color-wrapper')"
R=$(http $B $H / )
check "the front page: 200 with the visitor counter" "$(ok200 && has '<script id="mody-analytics">')"
check "an initials avatar is served" "$(http $B $H /mody-avatar/1/OW.svg | grep -q '^200' && has '<svg')"
check "a placeholder image is served" "$(http $B $H /mody-placeholder/1200x800.svg | grep -q '^200' && has '<svg')"
clean_log single

echo "3. A plain multisite"
make_site multi
php_checks multi
B=http://127.0.0.1:8095
H=localhost:8095
serve multi 8095
C=$(cookies "$T/multi")
R=$(http $B $H /wp-admin/ "$C")
check "first visit to wp-admin: sent to the user profile to set up two-step login" "$(echo "$R" | grep -qE "^302 http://localhost:8095/wp-admin/user/profile.php\?mody_two_factor=required" && echo 0 || echo 1)"
R=$(http $B $H '/wp-admin/user/profile.php?mody_two_factor=required' "$C")
check "  the user profile: 200 with the Two-step login section" "$(ok200 && has 'id="mody-two-factor"')"
wp_at "$T/multi" eval '\Mody\Security\Settings::save( array( "two_factor_required" => "none" ) );' >/dev/null
for S in overview files; do
	R=$(http $B $H "/wp-admin/network/settings.php?page=mody-security&section=$S" "$C")
	check "Network Admin → Settings → Security, $S: 200" "$(ok200 && has 'mody-brand__')"
done
R=$(http $B $H '/second/wp-admin/admin.php?page=mody-dashboard&section=visitors' "$C")
check "the second site's Dashboard, Visitors: 200 with the board" "$(ok200 && has 'mody-dashboard/dist/analytics-board.js')"
R=$(http $B $H /second/)
check "the second site's front page: 200 with the visitor counter" "$(ok200 && has '<script id="mody-analytics">')"
check "the second site's login page has ALTCHA's widget" "$(http $B $H /second/wp-login.php | grep -q '^200' && has 'mody-security/dist/altcha.js')"
clean_log multi

echo
echo "$PASS passed, $FAIL failed."
[ "$FAIL" = 0 ]
