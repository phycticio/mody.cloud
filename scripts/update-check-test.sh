#!/usr/bin/env bash
#
# Tests the update check the nine Mody plugins offered on mody.cloud carry
# (UpdateCheck, tickets/product-pages-bari-and-plugins.md, part 5, D14 and
# D21) on a plain single-site WordPress, with this checkout's plugin folders.
#
# Run from the repository root with the local stack up:
#
#   ./scripts/update-check-test.sh          # cleans up at the end
#   ./scripts/update-check-test.sh --keep   # leaves the site and its database
#
# What it does:
#   1. on the host: the nine copies of UpdateCheck are the same class, and
#      each plugin's header says Update URI: https://mody.cloud/;
#   2. inside the app container (WordPress core is the stack's own copy):
#      a single-site WordPress in /tmp/mody-update-test/site with its own
#      database, mody_update_test, and three of the plugins copied from this
#      checkout (so it tests this checkout, whichever one the stack serves);
#   3. the checks in scripts/update-check-check.php, with mody.cloud answered
#      by PHP's built-in server on 127.0.0.1:8098, which also writes down the
#      request's headers exactly as they arrive.

set -uo pipefail

CONTAINER=mody_cloud_app
T=/tmp/mody-update-test
SITE=$T/site
DB=mody_update_test
KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1
OFFERED="security analytics dashboard images seo profiles fonts patterns palette"
INSTALLED="seo images palette"

PASS=0
FAIL=0
check() { # check "label" condition-exit-code
	if [ "$2" = 0 ]; then PASS=$((PASS + 1)); echo "  ok   $1"; else FAIL=$((FAIL + 1)); echo "  FAIL $1"; fi
}
in_app() { docker exec -i -u www-data -w "$T" "$CONTAINER" sh -c "$1"; }
in_app_root() { docker exec -i "$CONTAINER" sh -c "$1"; }
wp_site() { docker exec -i -u www-data -w "$T" "$CONTAINER" wp --path="$SITE" "$@"; }

cleanup() {
	in_app "pkill -f '[p]hp -S 127.0.0.1:8098' 2>/dev/null; true"
	if [ "$KEEP" = 0 ]; then
		in_app_root "rm -rf $T"
		in_app_root 'MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb --no-defaults -h "$WORDPRESS_DB_HOST" -uroot -e "DROP DATABASE IF EXISTS '"$DB"'"'
	fi
}
trap cleanup EXIT

PLUGINS=app/web/mu-plugins

echo "1. The nine copies"
REFERENCE=$(sed 's/^namespace Mody\\[A-Za-z]*;$/namespace X;/' "$PLUGINS/mody-seo/src/UpdateCheck.php" | shasum | cut -d' ' -f1)
for p in $OFFERED; do
	FILE=$PLUGINS/mody-$p/src/UpdateCheck.php
	SUM=$(sed 's/^namespace Mody\\[A-Za-z]*;$/namespace X;/' "$FILE" 2>/dev/null | shasum | cut -d' ' -f1)
	check "mody-$p carries the same UpdateCheck" "$([ -f "$FILE" ] && [ "$SUM" = "$REFERENCE" ] && echo 0 || echo 1)"
	MAIN=$PLUGINS/mody-$p/mody-$p.php
	check "  its header: a version and Update URI: https://mody.cloud/" "$(grep -qE '^ \* Version: +[0-9]' "$MAIN" && grep -qE '^ \* Update URI: +https://mody\.cloud/$' "$MAIN" && echo 0 || echo 1)"
	check "  it registers the check" "$(grep -q '^UpdateCheck::register( __FILE__ );$' "$MAIN" && echo 0 || echo 1)"
done
check "no other Mody plugin points at mody.cloud" "$(grep -lE '^ \* Update URI: +https://mody\.cloud/' $PLUGINS/mody-*/mody-*.php | grep -vE "mody-($(echo $OFFERED | tr ' ' '|'))/" | grep -q . && echo 1 || echo 0)"

echo "2. A plain WordPress"
in_app_root "pkill -f '[p]hp -S 127.0.0.1:8098' 2>/dev/null; true"
in_app_root "rm -rf $T && mkdir -p $T && chown www-data $T"
in_app "cp -R /var/www/html/wp $SITE && mkdir -p $SITE/wp-content/plugins $SITE/wp-content/themes $SITE/wp-content/uploads $T/mody.cloud/web/uploads/downloads"
for p in $INSTALLED; do
	docker cp "$PLUGINS/mody-$p" "$CONTAINER:$SITE/wp-content/plugins/mody-$p" >/dev/null
done
docker cp scripts/update-check-check.php "$CONTAINER:$T/check.php" >/dev/null
# The Mody theme with its default values, as Bari will carry it (D16): without a theme, core itself logs notices.
docker cp app/web/themes/theme "$CONTAINER:$SITE/wp-content/themes/theme" >/dev/null
in_app_root "chown -R www-data $T"
in_app_root 'MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb --no-defaults -h "$WORDPRESS_DB_HOST" -uroot -e "DROP DATABASE IF EXISTS '"$DB"'; CREATE DATABASE '"$DB"' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON '"$DB"'.* TO \`$WORDPRESS_DB_USER\`@\`%\`;"'
in_app "cat > $SITE/wp-config.php" <<'PHP'
<?php
// A plain single-site wp-config, as a host's installer writes it.
define( 'DB_NAME', 'mody_update_test' );
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
define( 'WP_DEBUG_LOG', '/tmp/mody-update-test/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP
# The stand-in for mody.cloud: files as they are, and every request's headers written down.
in_app "cat > $T/router.php" <<'PHP'
<?php
file_put_contents( __DIR__ . '/headers.json', json_encode( array( 'uri' => $_SERVER['REQUEST_URI'], 'headers' => getallheaders() ) ) . "\n", FILE_APPEND );
return false;
PHP
wp_site core install --url=http://127.0.0.1:8097 --title="Update test" --admin_user=owner --admin_password=update-test-password --admin_email=owner@example.test --skip-email >/dev/null
check "installed" $?
check "single site" "$(wp_site eval 'echo is_multisite() ? 1 : 0;')"
wp_site theme activate theme >/dev/null
check "the theme is active" $?
# shellcheck disable=SC2086 # One folder per word.
wp_site plugin activate $(for p in $INSTALLED; do printf 'mody-%s ' "$p"; done) >/dev/null
check "Mody SEO, Images and Palette are active" $?
docker exec -d -u www-data -w "$T" "$CONTAINER" php -S 127.0.0.1:8098 -t "$T/mody.cloud" "$T/router.php"
for i in 1 2 3 4 5 6 7 8 9 10; do in_app "curl -s -o /dev/null http://127.0.0.1:8098/" && break; sleep 0.5; done
in_app "rm -f $T/headers.json"

echo "3. The checks"
OUT=$(wp_site eval-file "$T/check.php" 2>&1)
echo "$OUT" | sed 's/^/  /'
P=$(echo "$OUT" | grep -c '^ok ')
F=$(echo "$OUT" | grep -c '^FAIL ')
PASS=$((PASS + P))
FAIL=$((FAIL + F))
[ "$P" -gt 0 ] || check "the PHP checks ran" 1
check "no PHP error, warning or notice in the log" "$(in_app "grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' $T/debug.log 2>/dev/null | head -20 >&2; grep -qE 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' $T/debug.log 2>/dev/null && echo 1 || echo 0")"

echo
echo "$PASS passed, $FAIL failed."
[ "$FAIL" = 0 ]
