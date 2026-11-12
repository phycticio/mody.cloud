#!/usr/bin/env bash
#
# Tests the Nginx and compose hardening (documentation/architecture.md#security-hardening):
# the template renders and passes `nginx -t` (local and production variants),
# the sensitive files are never served in any letter case, PHP is never served
# as text, static files come only from the allow-list, the /wp redirect cannot
# leave the site, XML-RPC is gone, request bodies are small except on the upload
# endpoints, the security headers are there, and a member's uploads still work.
#
# Run from the repository root with the local stack up, after
# `docker compose restart nginx` (Nginx renders its template only at start):
#
#   ./scripts/nginx-hardening-test.sh
#
# It talks to the stack's own Nginx on its published HTTPS port with a Host
# header, so Nginx Proxy Manager (which adds and rewrites headers) is not in the
# way. The upload checks run in the app container as the first super admin, with
# a minted login session, and delete what they create. Output: "ok" and "FAIL"
# lines, then a count; the exit status is 1 when anything failed.
#
# Overrides: SERVER (Nginx container), APP (PHP container), MAIN_HOST,
# SUB_HOST (a member site).

set -uo pipefail

cd "$(dirname "$0")/.."

SERVER="${SERVER:-mody_cloud_server}"
APP="${APP:-mody_cloud_app}"
MAIN_HOST="${MAIN_HOST:-mody.local}"
SUB_HOST="${SUB_HOST:-alpha.mody.local}"

PASS=0
FAIL=0
ok() { printf 'ok   %s\n' "$1"; PASS=$((PASS + 1)); }
bad() { printf 'FAIL %s%s\n' "$1" "${2:+ ($2)}"; FAIL=$((FAIL + 1)); }
# check "description" <exit status of a test> ["detail shown on failure"]
check() { if [ "$2" -eq 0 ]; then ok "$1"; else bad "$1" "${3:-}"; fi; }

for NEED in docker curl; do
  command -v "$NEED" >/dev/null 2>&1 || { echo "FAIL $NEED is required"; exit 1; }
done
docker inspect "$SERVER" >/dev/null 2>&1 || { echo "FAIL the $SERVER container is not running (start the stack: docker compose up -d)"; exit 1; }

PORT="$(docker port "$SERVER" 443/tcp 2>/dev/null | head -n 1 | sed 's/.*://')"
[ -n "$PORT" ] || { echo "FAIL the Nginx container publishes no HTTPS port"; exit 1; }
BASE="https://127.0.0.1:${PORT}"

TMP="$(mktemp -d)"
CANARIES=()
cleanup() {
  local FILE
  for FILE in "${CANARIES[@]:-}"; do [ -n "$FILE" ] && rm -f "$FILE"; done
  rm -rf "$TMP"
}
trap cleanup EXIT

BODY="$TMP/body"
HEAD="$TMP/head"

# status HOST PATH [curl args]: the status code; body in $BODY, headers in $HEAD.
status() {
  local HOST="$1" URI="$2"
  shift 2
  curl -sk --max-time 120 -D "$HEAD" -o "$BODY" -w '%{http_code}' -H "Host: ${HOST}" "$@" "${BASE}${URI}"
}
header() { grep -i "^$1:" "$HEAD" | head -n 1 | tr -d '\r' | sed 's/^[^:]*: *//'; }
header_count() { grep -ic "^$1:" "$HEAD" || true; }

# ---------------------------------------------------------------------------
echo "== Template: renders and passes nginx -t"
# ---------------------------------------------------------------------------
IMAGE="$(docker inspect "$SERVER" --format '{{.Config.Image}}')"
NETWORK="$(docker inspect "$SERVER" --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' | tr ' ' '\n' | grep -v '^proxy' | grep . | head -n 1)"
SSL_DIRECTIVES_LOCAL="$(docker exec "$SERVER" printenv NGINX_APP_SSL_DIRECTIVES)"
RENDER='envsubst "$(printf "\${%s} " $(env | cut -d= -f1))" < /etc/nginx/templates/app.conf.template > /etc/nginx/conf.d/app.conf && rm -f /etc/nginx/conf.d/default.conf && nginx -t 2>&1'

# The same renderer the image runs at start, in a throwaway container on the
# stack's network (so "wordpress" resolves) that shares the server's mounts.
LOCAL_OUT="$(docker run --rm --network "$NETWORK" --volumes-from "$SERVER" \
  -e PROXY_NAME="$MAIN_HOST" -e HOST_NAME="$MAIN_HOST" \
  -e NGINX_APP_LISTEN="443 ssl" -e NGINX_APP_SERVER_NAME="$MAIN_HOST *.$MAIN_HOST" \
  -e NGINX_APP_SSL_DIRECTIVES="$SSL_DIRECTIVES_LOCAL" \
  -e NGINX_HTTP_REDIRECT_SERVER='server { listen 80; server_name _; return 301 https://$host$request_uri; }' \
  "$IMAGE" sh -c "$RENDER")"
check "nginx -t passes for the local variant (443 ssl)" "$(printf '%s' "$LOCAL_OUT" | grep -q 'test is successful'; echo $?)" "$LOCAL_OUT"

PROD_OUT="$(docker run --rm --network "$NETWORK" --volumes-from "$SERVER" \
  -e PROXY_NAME=mody.cloud -e HOST_NAME=mody.cloud \
  -e NGINX_APP_LISTEN="80" -e NGINX_APP_SERVER_NAME="mody.cloud *.mody.cloud" \
  -e NGINX_APP_SSL_DIRECTIVES="$(printf 'listen 443 ssl;\nssl_certificate /etc/nginx/ssl/cert.pem;\nssl_certificate_key /etc/nginx/ssl/key.pem;')" \
  -e NGINX_HTTP_REDIRECT_SERVER="" \
  "$IMAGE" sh -c "$RENDER")"
check "nginx -t passes for the production variant (80, internal 443, no redirect server)" "$(printf '%s' "$PROD_OUT" | grep -q 'test is successful'; echo $?)" "$PROD_OUT"

# Does the running Nginx use this template? (It renders it only at start.)
RUNNING_TEMPLATE_MARK="$(docker exec "$SERVER" grep -c 'mody_frame_options' /etc/nginx/conf.d/app.conf 2>/dev/null)"
check "the running Nginx was started with this template (docker compose restart nginx)" "$([ "${RUNNING_TEMPLATE_MARK:-0}" -gt 0 ]; echo $?)"

# ---------------------------------------------------------------------------
echo "== Sensitive files are not served, in any letter case"
# ---------------------------------------------------------------------------
for URI in /auth.json /AUTH.JSON /Auth.Json /composer.json /Composer.json /COMPOSER.LOCK \
  /vendor/autoload.php /Vendor/autoload.php /VENDOR/composer/installed.json \
  /logs/debug.log /Logs/debug.log /LOGS/debug.log /dumps/ /Dumps/ /Dumps/exports/ /dumps/db.sql.gz \
  /.env /.Env /.git/config /.Git/config /.DS_Store \
  /backup.sql /Backup.SQL /backup.sql.gz /site.zip.bak /notes.md /config.yml \
  /web/plugins/wordfence/readme.txt /web/plugins/wordfence/README.TXT /web/plugins/wordfence/license.txt \
  /web/plugins/wordfence/vendor/composer/LICENSE /web/plugins/mcp-adapter/composer.json /web/plugins/mcp-adapter/COMPOSER.JSON \
  /web/plugins/x/package.json /web/plugins/x/CHANGELOG.md /web/mu-plugins/spectra-blocks/readme.txt /web/themes/theme/README.md \
  /web/plugins/x/backup.zip /dist/build.zip /web/core /web/uploads/core /dist/core /dist/core.1234 /assets/Core.123 \
  /web/uploads/x.php /web/uploads/x.PHP /web/uploads/x.Php5 /web/uploads/x.phtml /web/uploads/x.php.jpg /Web/Uploads/shell.php; do
  CODE="$(status "$MAIN_HOST" "$URI")"
  LEAK="$(grep -c -E '<\?php|http-basic|DB_PASSWORD|"require"' "$BODY" || true)"
  check "$URI is refused (403 or 404), no source or credentials in the body" "$([ "$CODE" = 403 ] || [ "$CODE" = 404 ]; [ $? -eq 0 ] && [ "${LEAK:-0}" -eq 0 ]; echo $?)" "HTTP $CODE, $LEAK leaks"
done

# ---------------------------------------------------------------------------
echo "== PHP is run or refused, never served as text"
# ---------------------------------------------------------------------------
for URI in /index.PHP /Index.Php /wp-config.php /wp-config.PHP /Wp-Config.Php /wp-config-sample.php /wp/wp-config-sample.php \
  /wp-settings.PHP /wp-load.PHP /wp-login.PHP /wp-admin/admin.PHP /WP-ADMIN/admin.php \
  /web/mu-plugins/mody-mail/src/Config.PHP /WEB/mu-plugins/mody-mail/src/Config.PHP /web/mu-plugins/mody-platform.PHP \
  /web/db.php /web/db.PHP /web/index.php /assets/x.php /assets/x.PHP /dist/altcha.asset.php /dist/altcha.asset.PHP \
  /vendor/autoload.php /Vendor/Autoload.PHP /x/y/z.php /x.PHP; do
  CODE="$(status "$MAIN_HOST" "$URI")"
  LEAK="$(grep -c -E '<\?php|DB_PASSWORD|define\( *.DB_' "$BODY" || true)"
  check "$URI: no PHP source in the response" "$([ "${LEAK:-0}" -eq 0 ]; echo $?)" "HTTP $CODE"
done
for URI in /index.PHP /wp-config.PHP /web/mu-plugins/mody-mail/src/Config.PHP /assets/x.php /web/db.php /vendor/autoload.php /x.PHP; do
  CODE="$(status "$MAIN_HOST" "$URI")"
  check "$URI is refused (403 or 404)" "$([ "$CODE" = 403 ] || [ "$CODE" = 404 ]; echo $?)" "HTTP $CODE"
done
CODE="$(status "$MAIN_HOST" /index.php)"
check "/index.php still runs through PHP (WordPress answers, 200 or 301)" "$([ "$CODE" = 200 ] || [ "$CODE" = 301 ]; echo $?)" "HTTP $CODE"

# ---------------------------------------------------------------------------
echo "== Static files: only the allow-listed folders are read from disk"
# ---------------------------------------------------------------------------
# A file that exists at the root of app/ is not served; one in uploads is.
ROOT_CANARY="app/nginx-hardening-canary.txt"
UPLOAD_CANARY="app/web/uploads/nginx-hardening-canary.txt"
CANARIES+=("$ROOT_CANARY" "$UPLOAD_CANARY")
printf 'CANARY-ROOT' > "$ROOT_CANARY"
printf 'CANARY-UPLOADS' > "$UPLOAD_CANARY"
# sshfs may take a moment to show a new file to the Nginx container.
for I in 1 2 3 4 5 6 7 8 9 10; do
  docker exec "$SERVER" sh -c 'test -f /var/www/html/nginx-hardening-canary.txt && test -f /var/www/html/web/uploads/nginx-hardening-canary.txt' && break
  sleep 1
done
CODE="$(status "$MAIN_HOST" /nginx-hardening-canary.txt)"
check "an existing file at the root of app/ is not served (WordPress answers instead)" "$(! grep -q CANARY-ROOT "$BODY"; echo $?)" "HTTP $CODE"
CODE="$(status "$MAIN_HOST" /NGINX-HARDENING-CANARY.TXT)"
check "... nor under another letter case" "$(! grep -q CANARY-ROOT "$BODY"; echo $?)" "HTTP $CODE"
CODE="$(status "$MAIN_HOST" /web/uploads/nginx-hardening-canary.txt)"
check "a file in web/uploads is served (the control)" "$([ "$CODE" = 200 ] && grep -q CANARY-UPLOADS "$BODY"; echo $?)" "HTTP $CODE"
CODE="$(status "$MAIN_HOST" /core)"
check "/core is a WordPress address, not a file download" "$([ "$CODE" != 403 ] && ! grep -q -a '^.ELF' "$BODY"; echo $?)" "HTTP $CODE"
CODE="$(status "$MAIN_HOST" /.well-known/security.txt)"
check "/.well-known/ is not denied (WordPress answers)" "$([ "$CODE" != 403 ]; echo $?)" "HTTP $CODE"

# expect_ok HOST URI "regex for the Content-Type"
expect_ok() {
  local CODE TYPE
  CODE="$(status "$1" "$2")"
  TYPE="$(header content-type)"
  check "$1$2 is served (200, $3)" "$([ "$CODE" = 200 ] && printf '%s' "$TYPE" | grep -q -E "$3"; echo $?)" "HTTP $CODE $TYPE"
}
for HOST in "$MAIN_HOST" "$SUB_HOST"; do
  expect_ok "$HOST" /wp-includes/js/jquery/jquery.min.js 'javascript'
  expect_ok "$HOST" /wp-includes/css/dist/block-library/style.min.css 'text/css'
  expect_ok "$HOST" /wp-includes/fonts/dashicons.woff2 'font/woff2'
  expect_ok "$HOST" /wp-admin/css/common.min.css 'text/css'
  expect_ok "$HOST" /wp-admin/js/common.min.js 'javascript'
  expect_ok "$HOST" /wp-admin/load-scripts.php 'javascript'
  expect_ok "$HOST" /web/themes/theme/style.css 'text/css'
  expect_ok "$HOST" /dist/altcha.js 'javascript'
  expect_ok "$HOST" /web/mu-plugins/mody-platform/assets/brand-page.css 'text/css'
  expect_ok "$HOST" /wp-login.php 'text/html'
  expect_ok "$HOST" /wp-json/ 'application/json'
  expect_ok "$HOST" '/?rest_route=/' 'application/json'
  expect_ok "$HOST" /mody-placeholder/600x400.svg 'image/svg'
  expect_ok "$HOST" /robots.txt 'text/plain'
done
expect_ok "$MAIN_HOST" /assets/images/author-signature-bg.png 'image/png'
CODE="$(status "$MAIN_HOST" /wp-admin/)"
check "/wp-admin/ reaches WordPress (a redirect to the login)" "$([ "$CODE" = 302 ]; echo $?)" "HTTP $CODE"
CODE="$(status "$MAIN_HOST" /wp-admin)"
check "/wp-admin (no slash) redirects to /wp-admin/" "$([ "$CODE" = 301 ] && [ "$(header location)" = "/wp-admin/" ]; echo $?)" "HTTP $CODE $(header location)"
CODE="$(status "$MAIN_HOST" /wp-admin/network/)"
check "/wp-admin/network/ reaches WordPress" "$([ "$CODE" = 302 ]; echo $?)" "HTTP $CODE"
CODE="$(status "$MAIN_HOST" /es/)"
check "a language prefix (/es/) reaches WordPress, not a 403 or 5xx" "$([ "$CODE" = 200 ] || [ "$CODE" = 301 ] || [ "$CODE" = 302 ] || [ "$CODE" = 404 ]; echo $?)" "HTTP $CODE"
CODE="$(status "$MAIN_HOST" /wp-admin/admin-ajax.php -X POST -d 'action=heartbeat')"
check "admin-ajax.php runs through PHP" "$([ "$CODE" = 200 ] || [ "$CODE" = 400 ]; echo $?)" "HTTP $CODE"
CODE="$(status "$MAIN_HOST" /wp-cron.php)"
check "wp-cron.php runs through PHP" "$([ "$CODE" = 200 ]; echo $?)" "HTTP $CODE"

# ---------------------------------------------------------------------------
echo "== XML-RPC and trackbacks are gone"
# ---------------------------------------------------------------------------
for URI in /xmlrpc.php /XMLRPC.php /Xmlrpc.PHP /wp-trackback.php /WP-TRACKBACK.php /wp/xmlrpc.php; do
  CODE="$(status "$MAIN_HOST" "$URI" -X POST --data '<methodCall><methodName>system.listMethods</methodName></methodCall>')"
  check "$URI is not served (403, 404 or the /wp redirect to a 404)" "$([ "$CODE" = 403 ] || [ "$CODE" = 404 ] || [ "$CODE" = 308 ]; echo $?)" "HTTP $CODE"
  check "$URI answers no XML-RPC" "$(! grep -q -E 'methodResponse|XML-RPC server accepts' "$BODY"; echo $?)"
done
CODE="$(status "$MAIN_HOST" /wp/xmlrpc.php)"
check "/wp/xmlrpc.php redirects to /xmlrpc.php, which is a 404" "$([ "$CODE" = 308 ] && [ "$(header location)" = "https://${MAIN_HOST}/xmlrpc.php" ]; echo $?)" "$(header location)"

# ---------------------------------------------------------------------------
echo "== The /wp redirect cannot leave the site"
# ---------------------------------------------------------------------------
# expect_redirect URI EXPECTED_LOCATION
expect_redirect() {
  local CODE LOCATION
  CODE="$(status "$MAIN_HOST" "$1")"
  LOCATION="$(header location)"
  check "$1 -> 308 $2" "$([ "$CODE" = 308 ] && [ "$LOCATION" = "$2" ]; echo $?)" "HTTP $CODE $LOCATION"
}
expect_redirect '/wp//evil.example/x?a=1' "https://${MAIN_HOST}//evil.example/x?a=1"
expect_redirect '/wp///evil.example' "https://${MAIN_HOST}///evil.example"
expect_redirect '/wp/\evil.example' "https://${MAIN_HOST}/\\evil.example"
expect_redirect '/wp//\evil.example' "https://${MAIN_HOST}//\\evil.example"
expect_redirect '/wp/%2Fevil.example' "https://${MAIN_HOST}/%2Fevil.example"
expect_redirect '/wp' "https://${MAIN_HOST}/"
expect_redirect '/wp/' "https://${MAIN_HOST}/"
expect_redirect '/wp?x=1' "https://${MAIN_HOST}/?x=1"
expect_redirect '/wp/wp-login.php?redirect_to=1' "https://${MAIN_HOST}/wp-login.php?redirect_to=1"
expect_redirect '/WP/wp-login.php' "https://${MAIN_HOST}/wp-login.php"
CODE="$(status "$SUB_HOST" '/wp//evil.example')"
check "a member site's host is kept: https://$SUB_HOST//evil.example" "$([ "$CODE" = 308 ] && [ "$(header location)" = "https://${SUB_HOST}//evil.example" ]; echo $?)" "$(header location)"
CODE="$(status "$MAIN_HOST" /wp-login.php)"
check "/wp-login.php is not mistaken for the /wp prefix" "$([ "$CODE" = 200 ]; echo $?)" "HTTP $CODE"
CODE="$(status "$MAIN_HOST" /wpfoo)"
check "/wpfoo is not mistaken for the /wp prefix" "$([ "$CODE" != 308 ]; echo $?)" "HTTP $CODE"

# ---------------------------------------------------------------------------
echo "== Headers"
# ---------------------------------------------------------------------------
status "$MAIN_HOST" / >/dev/null
check "the front page: X-Content-Type-Options nosniff" "$([ "$(header x-content-type-options)" = nosniff ]; echo $?)"
check "the front page: Referrer-Policy strict-origin-when-cross-origin" "$([ "$(header referrer-policy)" = strict-origin-when-cross-origin ]; echo $?)"
check "the front page: X-Frame-Options SAMEORIGIN" "$([ "$(header x-frame-options)" = SAMEORIGIN ]; echo $?)"
check "the front page: no X-Powered-By" "$([ -z "$(header x-powered-by)" ]; echo $?)"
check "the front page: Server shows no version" "$(header server | grep -q -E '^nginx$'; echo $?)" "$(header server)"
check "directly over https: HSTS is sent" "$(header strict-transport-security | grep -q 'max-age='; echo $?)"
status "$MAIN_HOST" / -H 'X-Forwarded-Proto: https' >/dev/null
check "behind a proxy (X-Forwarded-Proto set): no HSTS from this Nginx" "$([ -z "$(header strict-transport-security)" ]; echo $?)"
status "$MAIN_HOST" /wp-login.php >/dev/null
check "the login page: SAMEORIGIN is there (a second copy from WordPress is harmless)" "$(header x-frame-options | grep -q SAMEORIGIN; echo $?)"
status "$MAIN_HOST" /some-post/embed/ >/dev/null
check "a WordPress embed address (/embed/) is not blocked from framing" "$([ -z "$(header x-frame-options)" ]; echo $?)"
status "$MAIN_HOST" '/?p=1&embed=true' >/dev/null
check "... nor ?embed=true" "$([ -z "$(header x-frame-options)" ]; echo $?)"
status "$MAIN_HOST" /web/themes/theme/style.css >/dev/null
check "a static file carries nosniff" "$([ "$(header x-content-type-options)" = nosniff ]; echo $?)"
status "$MAIN_HOST" /Logs/debug.log >/dev/null
check "an error response carries nosniff" "$([ "$(header x-content-type-options)" = nosniff ]; echo $?)"

# ---------------------------------------------------------------------------
echo "== Request bodies: small by default, large on the upload endpoints"
# ---------------------------------------------------------------------------
head -c 40000000 /dev/zero > "$TMP/40m.bin"
head -c 20000000 /dev/zero > "$TMP/20m.bin"
# too_big HOST URI FILE [curl args]: 413 expected.
too_big() {
  local CODE
  CODE="$(status "$1" "$2" -X POST --data-binary "@$3" "${@:4}")"
  check "40 MB POST to $2 is refused with 413" "$([ "$CODE" = 413 ]; echo $?)" "HTTP $CODE"
}
# passes HOST URI FILE [curl args]: anything but 413 (PHP answered).
passes() {
  local CODE
  CODE="$(status "$1" "$2" -X POST --data-binary "@$3" "${@:4}")"
  check "40 MB POST to $2 gets past Nginx (HTTP $CODE, not 413)" "$([ "$CODE" != 413 ] && [ "$CODE" != 000 ] && [ "$CODE" != 502 ] && [ "$CODE" != 504 ]; echo $?)" "HTTP $CODE"
}
too_big "$MAIN_HOST" /wp-login.php "$TMP/40m.bin"
too_big "$MAIN_HOST" /wp-comments-post.php "$TMP/40m.bin"
too_big "$MAIN_HOST" /wp-json/mody-analytics/v1/view "$TMP/40m.bin"
too_big "$MAIN_HOST" /wp-json/mody-platform/v1/register "$TMP/40m.bin"
too_big "$MAIN_HOST" /wp-json/wp/v2/pages "$TMP/40m.bin"
too_big "$MAIN_HOST" /wp-admin/admin-ajax.php "$TMP/40m.bin"
too_big "$MAIN_HOST" /wp-admin/profile.php "$TMP/40m.bin"
too_big "$SUB_HOST" /wp-login.php "$TMP/40m.bin"
CODE="$(status "$MAIN_HOST" /wp-login.php -X POST --data-binary "@$TMP/40m.bin" -H 'Transfer-Encoding: chunked' --http1.1)"
check "a chunked 40 MB POST to /wp-login.php is refused with 413 too" "$([ "$CODE" = 413 ]; echo $?)" "HTTP $CODE"
CODE="$(status "$MAIN_HOST" /wp-login.php -X POST --data-binary "@$TMP/20m.bin")"
check "a 20 MB POST to /wp-login.php is still accepted (under 32M)" "$([ "$CODE" != 413 ]; echo $?)" "HTTP $CODE"
passes "$MAIN_HOST" /wp-json/wp/v2/media "$TMP/40m.bin" -H 'Content-Type: image/png'
passes "$SUB_HOST" /wp-json/wp/v2/media "$TMP/40m.bin" -H 'Content-Type: image/png'
passes "$MAIN_HOST" /wp-json/wp/v2/media/12 "$TMP/40m.bin" -H 'Content-Type: image/png'
passes "$MAIN_HOST" /es/wp-json/wp/v2/media "$TMP/40m.bin" -H 'Content-Type: image/png'
passes "$MAIN_HOST" /wp-json/wp/v2/font-families "$TMP/40m.bin"
passes "$MAIN_HOST" /wp-json/wp/v2/font-families/3/font-faces "$TMP/40m.bin"
passes "$MAIN_HOST" /wp-admin/async-upload.php "$TMP/40m.bin"
passes "$SUB_HOST" /wp-admin/async-upload.php "$TMP/40m.bin"
passes "$MAIN_HOST" /wp-admin/media-new.php "$TMP/40m.bin"
CODE="$(status "$MAIN_HOST" /wp-json/wp/v2/media -X POST --data-binary "@$TMP/40m.bin" -H 'Content-Type: image/png' -H 'Transfer-Encoding: chunked' --http1.1)"
check "a chunked 40 MB POST to the media route gets past Nginx (HTTP $CODE)" "$([ "$CODE" != 413 ] && [ "$CODE" != 000 ]; echo $?)"
CODE="$(status "$MAIN_HOST" /wp-json/wp/v2/media -X POST --data '{}' -H 'Content-Type: application/json')"
check "the media route is answered by WordPress (401, not an Nginx 404)" "$([ "$CODE" = 401 ] || [ "$CODE" = 403 ]; echo $?)" "HTTP $CODE"
check "... as JSON" "$(grep -q '"code"' "$BODY"; echo $?)"

# ---------------------------------------------------------------------------
echo "== A member's uploads (real login, real files; they are deleted)"
# ---------------------------------------------------------------------------
if docker inspect "$APP" >/dev/null 2>&1; then
  UPLOAD_OUT="$(docker exec -i -u www-data "$APP" wp --path=/var/www/html/wp --url="https://${MAIN_HOST}" eval-file - <<'PHP' 2>/dev/null
<?php
// Runs in the app container. Reaches Nginx as the browser would (the network alias
// of the main host), as the first super admin with a minted login session.
use Mody\Platform\Export\Downloads;
use Mody\Platform\Export\Exporter;
use Mody\Platform\Export\Jobs;

$out   = array();
$admin = get_user_by( 'login', get_super_admins()[0] );
wp_set_current_user( $admin->ID );
$expiration = time() + 900;
$token      = WP_Session_Tokens::get_instance( $admin->ID )->create( $expiration );
$logged     = wp_generate_auth_cookie( $admin->ID, $expiration, 'logged_in', $token );
$_COOKIE[ LOGGED_IN_COOKIE ] = $logged;
$cookie = SECURE_AUTH_COOKIE . '=' . wp_generate_auth_cookie( $admin->ID, $expiration, 'secure_auth', $token ) . '; ' . LOGGED_IN_COOKIE . '=' . $logged;
$rest   = wp_create_nonce( 'wp_rest' );
$media  = wp_create_nonce( 'media-form' );
$host   = wp_parse_url( home_url(), PHP_URL_HOST );

// A valid 1x1 PNG with 36 MB of metadata: a big file (over Nginx's 32M default) that
// WordPress handles in no time.
$chunk = static function ( string $type, string $data ): string {
	return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
};
$file = '/tmp/mody-nginx-test.png';
file_put_contents( $file, "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', 1, 1, 8, 2, 0, 0, 0 ) ) . $chunk( 'tEXt', "Comment\0" . str_repeat( 'x', 36 * 1024 * 1024 ) ) . $chunk( 'IDAT', gzcompress( "\0\xff\0\0" ) ) . $chunk( 'IEND', '' ) );
$out['file_mb'] = round( filesize( $file ) / 1048576, 1 );

$call = static function ( string $path, array $args ) use ( $host, $cookie ): array {
	$command = 'curl -sk --max-time 120 -o /tmp/mody-nginx-test.out -w %{http_code} -H ' . escapeshellarg( 'Cookie: ' . $cookie ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' ' . escapeshellarg( 'https://' . $host . $path );
	$code    = trim( (string) shell_exec( $command ) );
	return array( $code, (string) file_get_contents( '/tmp/mody-nginx-test.out' ) );
};

$ids = array();
// The block editor's way: the raw file to the REST media route.
list( $code, $body ) = $call( '/wp-json/wp/v2/media', array( '-X', 'POST', '-H', 'X-WP-Nonce: ' . $rest, '-H', 'Content-Disposition: attachment; filename="nginx-hardening-rest.png"', '-H', 'Content-Type: image/png', '--data-binary', '@' . $file ) );
$data = json_decode( $body, true );
$out['rest_upload'] = array( 'code' => $code, 'id' => $data['id'] ?? null );
if ( ! empty( $data['id'] ) ) {
	$ids[] = (int) $data['id'];
}
// The media modal's way: a multipart form to async-upload.php.
list( $code, $body ) = $call( '/wp-admin/async-upload.php', array( '-X', 'POST', '-F', 'name=nginx-hardening-async.png', '-F', 'action=upload-attachment', '-F', '_wpnonce=' . $media, '-F', 'async-upload=@' . $file . ';type=image/png' ) );
$data = json_decode( $body, true );
$out['async_upload'] = array( 'code' => $code, 'success' => $data['success'] ?? null, 'id' => $data['data']['id'] ?? null );
if ( ! empty( $data['data']['id'] ) ) {
	$ids[] = (int) $data['data']['id'];
}
// Logged in does not lift the default: the same file to a route that is no upload.
list( $code ) = $call( '/wp-json/wp/v2/pages', array( '-X', 'POST', '-H', 'X-WP-Nonce: ' . $rest, '-H', 'Content-Type: application/json', '--data-binary', '@' . $file ) );
$out['pages_over_limit'] = $code;
// A small request still works logged in.
list( $code, $body ) = $call( '/wp-json/wp/v2/users/me', array( '-H', 'X-WP-Nonce: ' . $rest ) );
$out['users_me'] = $code;

// "Take your site with you": a signed link, PHP answers with X-Accel-Redirect, and Nginx's
// internal location streams the file (so the deny rules for .zip and .sql.gz must not apply
// to it). A finished job for the main site, with three files, is made and removed here.
global $wpdb;
$job_token = bin2hex( random_bytes( 16 ) );
$wpdb->insert( Jobs::table(), array( 'site_id' => 1, 'user_id' => $admin->ID, 'token' => $job_token, 'step' => 'done', 'status' => 'done', 'progress' => '{}', 'files' => '{}', 'finished_at' => gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
$job   = Jobs::get( (int) $wpdb->insert_id );
$files = array();
$bytes = array();
foreach ( array( 'code', 'database', 'media' ) as $which ) {
	$bytes[ $which ] = random_bytes( 3 * 1048576 );
	wp_mkdir_p( Jobs::dir( $job ) );
	file_put_contents( Jobs::dir( $job ) . '/' . Exporter::PATHS[ $which ], $bytes[ $which ] );
	$files[ $which ] = array( 'name' => Jobs::file_name( $job, $which ), 'size' => strlen( $bytes[ $which ] ), 'sha256' => hash( 'sha256', $bytes[ $which ] ) );
}
$wpdb->update( Jobs::table(), array( 'files' => wp_json_encode( $files ) ), array( 'id' => $job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
$job = Jobs::get( $job['id'] );
$headers_of = static function ( string $text ): array {
	$found = array();
	foreach ( explode( "\n", $text ) as $line ) {
		if ( str_contains( $line, ':' ) ) {
			list( $name, $value ) = explode( ':', $line, 2 );
			$found[ strtolower( trim( $name ) ) ] = trim( $value );
		}
	}
	return $found;
};
$download = static function ( string $which, array $extra = array() ) use ( $job, $host, $bytes, $headers_of ): array {
	$path = (string) wp_parse_url( Downloads::url( $job, $which ), PHP_URL_PATH );
	shell_exec( 'curl -sk --max-time 60 -D /tmp/mody-nginx-test.head -o /tmp/mody-nginx-test.dl ' . implode( ' ', array_map( 'escapeshellarg', $extra ) ) . ' ' . escapeshellarg( 'https://' . $host . $path ) );
	$head = (string) file_get_contents( '/tmp/mody-nginx-test.head' );
	$body = (string) file_get_contents( '/tmp/mody-nginx-test.dl' );
	return array(
		'code'    => preg_match( '#^HTTP/\S+ (\d{3})#', $head, $m ) ? (int) $m[1] : 0,
		'headers' => $headers_of( $head ),
		'bytes'   => strlen( $body ),
		'same'    => $body === $bytes[ $which ],
	);
};
$zip = $download( 'code' );
$out['export_code'] = array(
	'code'   => $zip['code'],
	'same'   => $zip['same'],
	'type'   => $zip['headers']['content-type'] ?? '',
	'nginx'  => $zip['headers']['x-robots-tag'] ?? '',
	'nosniff' => $zip['headers']['x-content-type-options'] ?? '',
	'dispo'  => str_starts_with( $zip['headers']['content-disposition'] ?? '', 'attachment' ),
);
$gz = $download( 'database' );
$out['export_database'] = array( 'code' => $gz['code'], 'same' => $gz['same'], 'type' => $gz['headers']['content-type'] ?? '', 'nginx' => $gz['headers']['x-robots-tag'] ?? '' );
$range = $download( 'media', array( '-H', 'Range: bytes=100-199' ) );
$out['export_range'] = array( 'code' => $range['code'], 'bytes' => $range['bytes'], 'content_range' => $range['headers']['content-range'] ?? '' );
list( $code ) = $call( '/_mody_exports/1/' . $job_token . '/code.zip', array() );
$out['export_internal_direct'] = $code;
shell_exec( 'rm -rf ' . escapeshellarg( Jobs::dir( $job ) ) );
@rmdir( dirname( Jobs::dir( $job ) ) ); // The site's folder, when it is empty.
$wpdb->delete( Jobs::table(), array( 'id' => $job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
@unlink( '/tmp/mody-nginx-test.head' );
@unlink( '/tmp/mody-nginx-test.dl' );

foreach ( $ids as $id ) {
	wp_delete_attachment( $id, true );
}
WP_Session_Tokens::get_instance( $admin->ID )->destroy( $token );
@unlink( $file );
@unlink( '/tmp/mody-nginx-test.out' );
echo wp_json_encode( $out, JSON_UNESCAPED_SLASHES );
PHP
)"
  FILE_MB="$(printf '%s' "$UPLOAD_OUT" | grep -o '"file_mb":[0-9.]*' | cut -d: -f2 | cut -d. -f1)"
  check "the test file is over the default limit (36+ MB)" "$([ "${FILE_MB:-0}" -ge 36 ]; echo $?)" "$UPLOAD_OUT"
  check "REST media upload of a 36+ MB file: 201 Created" "$(printf '%s' "$UPLOAD_OUT" | grep -q '"rest_upload":{"code":"201"'; echo $?)" "$UPLOAD_OUT"
  check "async-upload.php of a 36+ MB file: success" "$(printf '%s' "$UPLOAD_OUT" | grep -q '"async_upload":{"code":"200","success":true'; echo $?)" "$UPLOAD_OUT"
  check "a 36+ MB body to a route that is no upload: 413 even when logged in" "$(printf '%s' "$UPLOAD_OUT" | grep -q '"pages_over_limit":"413"'; echo $?)" "$UPLOAD_OUT"
  check "a small authenticated request still works" "$(printf '%s' "$UPLOAD_OUT" | grep -q '"users_me":"200"'; echo $?)" "$UPLOAD_OUT"
  check "export link: the zip is served through the internal location (200, application/zip, the same bytes, Nginx's X-Robots-Tag and nosniff)" "$(printf '%s' "$UPLOAD_OUT" | grep -qF '"export_code":{"code":200,"same":true,"type":"application/zip","nginx":"noindex","nosniff":"nosniff","dispo":true}'; echo $?)" "$UPLOAD_OUT"
  check "export link: the .sql.gz database is served too (the deny rules do not reach the internal location)" "$(printf '%s' "$UPLOAD_OUT" | grep -qF '"export_database":{"code":200,"same":true,"type":"application/gzip","nginx":"noindex"}'; echo $?)" "$UPLOAD_OUT"
  check "export link: a Range request resumes (206, 100 bytes)" "$(printf '%s' "$UPLOAD_OUT" | grep -qF '"export_range":{"code":206,"bytes":100,"content_range":"bytes 100-199/'; echo $?)" "$UPLOAD_OUT"
  check "export folder: /_mody_exports/ asked directly is a 404" "$(printf '%s' "$UPLOAD_OUT" | grep -q '"export_internal_direct":"404"'; echo $?)" "$UPLOAD_OUT"
else
  bad "the $APP container is not running, so the upload checks could not run"
fi

# ---------------------------------------------------------------------------
echo "== compose.yml, .gitignore, bin/deploy, PHP settings"
# ---------------------------------------------------------------------------
COMPOSE_OUT="$(docker compose config 2>/dev/null)"
printf '%s' "$COMPOSE_OUT" | awk '/^    ulimits:$/ {u=1; next} u && /^      core:$/ {c=1; next} c && /hard: 0/ {n++; u=0; c=0} END {exit (n >= 2 ? 0 : 1)}'
check "compose.yml sets core: 0 for the PHP and database services (two ulimits)" $?
PORTS_BAD="$(printf '%s' "$COMPOSE_OUT" | awk '/host_ip:/ {ip=$2} /target:/ {t=$2} /published:/ {if ((t == 3306 || t == 9003) && ip != "127.0.0.1") print "port " t " on " ip}')"
check "compose.yml publishes MariaDB (3306) and Xdebug (9003) on 127.0.0.1 only" "$([ -z "$PORTS_BAD" ]; echo $?)" "$PORTS_BAD"
printf '%s' "$COMPOSE_OUT" | grep -q 'published: "3306"'
check "... and still publishes them (local work)" $?
PROD_PORTS="$(COMPOSE_FILE= docker compose -f compose.yml -f compose.prod.yml.sample config 2>/dev/null | grep -c -E 'target: (3306|9003)' || true)"
check "with compose.prod.yml.sample on top, neither is published" "$([ "${PROD_PORTS:-1}" -eq 0 ]; echo $?)" "$PROD_PORTS published"
git check-ignore -q app/core && git check-ignore -q app/core.1234
check ".gitignore ignores app/core and app/core.<pid>" $?
grep -q -- "--exclude='/app/core'" bin/deploy && grep -q -- "--exclude='/app/core\.\*'" bin/deploy
check "bin/deploy excludes app/core and app/core.* from the sync" $?
bash -n bin/deploy
check "bin/deploy still parses" $?
grep -q -E '^expose_php *= *Off' config/php/security.ini
check "config/php/security.ini sets expose_php = Off" $?
grep -q 'security.ini' compose.yml
check "compose.yml mounts security.ini" $?
if docker exec "$APP" sh -c 'ulimit -c' 2>/dev/null | grep -q '^0$'; then
  ok "the running PHP container already has core: 0"
else
  echo "note the running PHP container still has the old core limit: docker compose up -d recreates it with core: 0 (compose.yml is right, see above)"
fi

echo
echo "${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
