#!/usr/bin/env bash
#
# Tests the code a downloaded site takes with it (Export\Portable,
# tickets/take-your-site-with-you.md) on a plain single-site WordPress:
# no multisite, no MU plugins, no platform wp-config.
#
# Run from the repository root with the local stack up:
#
#   ./scripts/export-portable-test.sh          # cleans up at the end
#   ./scripts/export-portable-test.sh --keep   # leaves the site and its database
#
# What it does, inside the app container (WordPress core is the stack's own
# copy, so no download):
#   1. builds the payload with Portable::assemble() on the platform;
#   2. makes a single-site WordPress in the container's /tmp/mody-exit-test/site
#      with its own database, mody_exit_test;
#   3. installs it, activates the theme and the five plugins, adds a page;
#   4. checks it from PHP (scripts/export-portable-check.php) and over HTTP
#      with PHP's built-in server on 127.0.0.1:8099.

set -uo pipefail

CONTAINER=mody_cloud_app
T=/tmp/mody-exit-test # Inside the container, off the project mount: no sshfs cache.
SITE=$T/site
URL=http://127.0.0.1:8099
DB=mody_exit_test
KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1

PASS=0
FAIL=0
check() { # check "label" condition-exit-code
	if [ "$2" = 0 ]; then PASS=$((PASS + 1)); echo "  ok   $1"; else FAIL=$((FAIL + 1)); echo "  FAIL $1"; fi
}
in_app() { docker exec -i -u www-data -w "$T" "$CONTAINER" sh -c "$1"; }
in_app_root() { docker exec -i "$CONTAINER" sh -c "$1"; }
wp_site() { docker exec -i -u www-data -w "$T" "$CONTAINER" wp --path="$SITE" "$@"; }
get() { docker exec -i -u www-data "$CONTAINER" curl -s -o /dev/null -w '%{http_code} %{content_type}' "$URL$1"; }
body() { docker exec -i -u www-data "$CONTAINER" curl -s "$URL$1"; }

cleanup() {
	in_app "pkill -f '[p]hp -S 127.0.0.1:8099' 2>/dev/null; true"
	if [ "$KEEP" = 0 ]; then
		in_app "rm -rf $T"
		in_app_root 'MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb --no-defaults -h "$WORDPRESS_DB_HOST" -uroot -e "DROP DATABASE IF EXISTS '"$DB"'"'
	fi
}
trap cleanup EXIT

echo "1. The payload"
in_app_root "mkdir -p $T && chown www-data $T"
in_app "pkill -f '[p]hp -S 127.0.0.1:8099' 2>/dev/null; rm -rf $T/payload $SITE $T/debug.log"
OUT=$(docker exec -i -u www-data "$CONTAINER" wp --path=/var/www/html/wp --url=https://alpha.mody.local eval '$r = Mody\Platform\Export\Portable::assemble( "'"$T"'/payload" ); echo is_wp_error( $r ) ? "ERROR " . $r->get_error_message() : "OK " . wp_json_encode( Mody\Platform\Export\Portable::active_plugins() );')
echo "     $OUT"
case "$OUT" in OK*) check "assembled, every class Mody Site names is in it" 0 ;; *) check "assembled" 1; exit 1 ;; esac
check "no agent, platform or gating code in Mody Site" "$(in_app "ls -R $T/payload/plugins/mody-site | grep -qE 'Agent|AI|Abilities|Gating|Members|Provisioning|Wizard|Onboarding|Dashboard|Altcha|FormGuard|NetworkProfile|Storage|SpectraLoader|SiteDesign|SiteEditor' && echo 1 || echo 0")"
check "no mody-mail, mody-analytics, ai, mcp-adapter or wordfence" "$(in_app "ls $T/payload/plugins | grep -qE 'mody-mail|mody-analytics|^ai$|mcp-adapter|ollama|wordfence|query-monitor|user-switching' && echo 1 || echo 0")"
check "Inter is in the theme" "$(in_app "test -f $T/payload/themes/theme/assets/fonts/inter/InterVariable.woff2 && grep -q 'file:./assets/fonts/inter/InterVariable.woff2' $T/payload/themes/theme/theme.json && echo 0 || echo 1")"
check "GPL text with each Mody plugin and the theme" "$(in_app "test -f $T/payload/plugins/mody-site/LICENSE.txt -a -f $T/payload/plugins/mody-languages/LICENSE.txt -a -f $T/payload/plugins/mody-images/LICENSE.txt -a -f $T/payload/themes/theme/LICENSE.txt && echo 0 || echo 1")"

echo "2. A plain WordPress"
in_app "cp -R /var/www/html/wp $SITE && mkdir -p $SITE/wp-content/uploads && cp -R $T/payload/themes $T/payload/plugins $SITE/wp-content/"
in_app_root 'MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb --no-defaults -h "$WORDPRESS_DB_HOST" -uroot -e "DROP DATABASE IF EXISTS '"$DB"'; CREATE DATABASE '"$DB"' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON '"$DB"'.* TO \`$WORDPRESS_DB_USER\`@\`%\`;"'
in_app "cat > $SITE/wp-config.php" <<'PHP'
<?php
// A plain single-site wp-config, as a host's installer writes it.
define( 'DB_NAME', 'mody_exit_test' );
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
define( 'WP_DEBUG_LOG', '/tmp/mody-exit-test/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP
in_app "cat > $T/router.php" <<'PHP'
<?php
// PHP's built-in server: files as they are, everything else through WordPress.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && is_file( __DIR__ . '/site' . $path ) ) {
	return false;
}
// As Nginx and Apache pass pretty addresses to index.php: no PATH_INFO.
unset( $_SERVER['PATH_INFO'], $_SERVER['ORIG_PATH_INFO'] );
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/site/index.php';
chdir( __DIR__ . '/site' );
require __DIR__ . '/site/index.php';
PHP
wp_site core install --url="$URL" --title="Exit test" --admin_user=owner --admin_password=exit-test-password --admin_email=owner@example.test --skip-email >/dev/null
check "installed" $?
check "single site" "$(wp_site eval 'echo is_multisite() ? 1 : 0;')"
wp_site theme activate theme >/dev/null
check "the theme is active" $?
wp_site plugin activate mody-site mody-languages mody-images spectra-blocks antispam-bee >/dev/null 2>&1
check "the five plugins activate" $?
check "an upload becomes a WebP (Mody Images)" "$(wp_site eval 'require_once ABSPATH . "wp-admin/includes/media.php"; require_once ABSPATH . "wp-admin/includes/file.php"; require_once ABSPATH . "wp-admin/includes/image.php"; $t = wp_tempnam( "p.jpg" ); $i = imagecreatetruecolor( 800, 600 ); imagejpeg( $i, $t, 95 ); $id = media_handle_sideload( array( "name" => "p.jpg", "tmp_name" => $t ), 0 ); echo ! is_wp_error( $id ) && "image/webp" === get_post_mime_type( $id ) ? 0 : 1; if ( ! is_wp_error( $id ) ) { wp_delete_attachment( $id, true ); }')"
wp_site rewrite structure '/%postname%/' >/dev/null

echo "3. From PHP"
docker cp scripts/export-portable-check.php "$CONTAINER:$T/check.php" >/dev/null
in_app_root "chown www-data $T/check.php"
RESULT=$(wp_site eval-file "$T/check.php" 2>&1)
echo "$RESULT" | sed 's/^/  /'
P=$(echo "$RESULT" | grep -c '^ok ')
F=$(echo "$RESULT" | grep -c '^FAIL')
PASS=$((PASS + P))
FAIL=$((FAIL + F))
[ "$P" -gt 0 ] || check "the PHP checks ran" 1

echo "4. Over HTTP"
docker exec -d -u www-data -w "$T" "$CONTAINER" php -S 127.0.0.1:8099 -t "$SITE" "$T/router.php"
for i in 1 2 3 4 5 6 7 8 9 10; do get / | grep -q '^200' && break; sleep 0.5; done
PAGE=$(wp_site post list --post_type=page --name=exit-test --field=url)
PAGE_PATH=${PAGE#"$URL"}
check "the front page answers 200" "$(get / | grep -q '^200' && echo 0 || echo 1)"
HTML=$(body "$PAGE_PATH")
check "the page answers 200" "$(get "$PAGE_PATH" | grep -q '^200' && echo 0 || echo 1)"
check "  its SEO title" "$(grep -q '<title>Exit test SEO title</title>' <<<"$HTML" && echo 0 || echo 1)"
check "  its meta description" "$(grep -qE '<meta name="description" content="The page(&#039;|.)s own description." />' <<<"$HTML" && echo 0 || echo 1)"
check "  Inter's faces from the theme" "$(grep -q 'themes/theme/assets/fonts/inter/InterVariable.woff2' <<<"$HTML" && echo 0 || echo 1)"
check "  a Spectra container rendered" "$(grep -q 'wp-block-spectra-container' <<<"$HTML" && echo 0 || echo 1)"
check "  the language switcher rendered" "$(grep -q 'mody-language-switcher' <<<"$HTML" && echo 0 || echo 1)"
check "the Inter file is served" "$(get /wp-content/themes/theme/assets/fonts/inter/InterVariable.woff2 | grep -q '^200' && echo 0 || echo 1)"
check "a placeholder image is served" "$(get /mody-placeholder/1200x800.svg | grep -q '^200 image/svg+xml' && echo 0 || echo 1)"
check "an initials avatar is served" "$(get /mody-avatar/3/AB.svg | grep -q '^200 image/svg+xml' && echo 0 || echo 1)"
LOGIN=$(body /wp-login.php)
check "the login page answers 200" "$(get /wp-login.php | grep -q '^200' && echo 0 || echo 1)"
check "  in the site's brand (login.css)" "$(grep -q 'mody-site/assets/login.css' <<<"$LOGIN" && echo 0 || echo 1)"
check "  without the antibot widget" "$(grep -qi 'altcha' <<<"$LOGIN" && echo 1 || echo 0)"
ES=$(wp_site post list --post_type=page --name=exit-test-es --field=url)
ES_PATH=${ES#"$URL"}
check "the Spanish page answers at $ES_PATH" "$(get "$ES_PATH" | grep -q '^200' && echo 0 || echo 1)"
check "no PHP error, warning or notice in the log" "$(in_app "grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' $T/debug.log 2>/dev/null | head -20 >&2; grep -qE 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' $T/debug.log 2>/dev/null && echo 1 || echo 0")"

echo
echo "$PASS passed, $FAIL failed."
[ "$FAIL" = 0 ]
