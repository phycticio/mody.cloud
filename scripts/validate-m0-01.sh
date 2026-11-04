#!/usr/bin/env bash
# M0-01 acceptance checks (ticket: tickets/M0-01-multisite-foundation.md).
#
# Run from anywhere in the repository:
#   bash scripts/validate-m0-01.sh
#
# Read-only: it never mutates the stack. Paste the whole output back to the
# agent to close out the runtime half of the ticket.
set -u
cd "$(dirname "$0")/.." || exit 1

# Machine-specific values (HOST_NAME, NGINX_HTTPS_PORT) come from .env, the
# same way bin/wp does.
if [ ! -f .env ]; then
  echo "No .env in the repository root." >&2
  exit 1
fi
# shellcheck disable=SC1091
source .env

WP="./bin/wp"
PORT="${NGINX_HTTPS_PORT:-443}"

hr() { printf '\n=== %s ===\n' "$1"; }

hr "1. Multisite constants (expect MULTISITE=true SUBDOMAIN_INSTALL=true, port-free domains)"
$WP eval 'printf("MULTISITE=%s SUBDOMAIN_INSTALL=%s DOMAIN_CURRENT_SITE=%s COOKIE_DOMAIN=%s\n", var_export(MULTISITE,true), var_export(SUBDOMAIN_INSTALL,true), DOMAIN_CURRENT_SITE, COOKIE_DOMAIN);'

hr "2. Network sites (expect blogs 1/2/3: mody.local, alpha, beta)"
$WP site list --fields=blog_id,url,domain

hr "3. Super admins (must list only the admin account)"
$WP super-admin list

hr "4. Site owners (member must be administrator on both subsites)"
$WP --site=alpha user list --fields=ID,user_login,roles
$WP --site=beta  user list --fields=ID,user_login,roles

hr "5. Stored URLs (must be https, port-free; siteurl carries the /wp suffix)"
$WP --site=alpha option get home
$WP --site=alpha option get siteurl
$WP --site=beta  option get home
$WP --site=beta  option get siteurl

hr "6. Active theme (must be 'theme' on both)"
$WP --site=alpha theme list --status=active --field=name
$WP --site=beta  theme list --status=active --field=name

hr "7. Certificate SANs (must include *.${HOST_NAME})"
openssl x509 -in config/ssl/local-cert.pem -noout -ext subjectAltName

hr "8. Live HTTPS with the port-free Host the proxy forwards (expect <title>Alpha</title> and <title>Beta</title>)"
curl -sk --resolve "alpha.${HOST_NAME}:${PORT}:127.0.0.1" -H "Host: alpha.${HOST_NAME}" "https://alpha.${HOST_NAME}:${PORT}/" \
  | grep -o '<title>[^<]*</title>' || echo "alpha: no <title> found (curl failed?)"
curl -sk --resolve "beta.${HOST_NAME}:${PORT}:127.0.0.1" -H "Host: beta.${HOST_NAME}" "https://beta.${HOST_NAME}:${PORT}/" \
  | grep -o '<title>[^<]*</title>' || echo "beta: no <title> found (curl failed?)"

printf '\n=== Done. Paste everything above back to the agent. ===\n'
