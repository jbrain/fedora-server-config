#!/usr/bin/env bash
# setup.sh - Run once (and safely re-run) as root to deploy the Nginx config tree on
# jackson-brain.com. Usage: sudo bash setup.sh
#
# This ONLY deploys config files + validates them (`nginx -t`). It does NOT start nginx or
# stop httpd — that's the deliberate, careful cutover step documented in
# ../plans/containerization-2026-08/04-apache-to-nginx.agent.md, done by hand in one pass.
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Installing nginx.conf..."
cp "${SRC_DIR}/nginx.conf" /etc/nginx/nginx.conf

echo "Installing snippets..."
mkdir -p /etc/nginx/snippets
cp "${SRC_DIR}"/snippets/*.conf /etc/nginx/snippets/

echo "Installing conf.d vhosts..."
cp "${SRC_DIR}"/conf.d/*.conf /etc/nginx/conf.d/

echo "Installing static assets (favicons)..."
mkdir -p /etc/nginx/static
cp "${SRC_DIR}"/static/*.ico /etc/nginx/static/

echo "Installing fail2ban jail.local (wordpress jail fix + new nginx-* jails)..."
cp "${SRC_DIR}/fail2ban/jail.local" /etc/fail2ban/jail.local

echo "Installing fail2ban wordpress filter override (adds XML-RPC failregex patterns)..."
cp "${SRC_DIR}/fail2ban/filter.d/wordpress.local" /etc/fail2ban/filter.d/wordpress.local

echo "Validating nginx config (nginx not started/reloaded yet)..."
nginx -t

echo ""
echo "Config deployed and valid. Next steps (see the Task 04 runbook for the full sequence):"
echo "  1. Confirm certs exist for jackson-brain.com, music.jackson-brain.com, ha.jackson-brain.com"
echo "  2. Cutover: systemctl disable --now httpd && systemctl enable --now nginx"
echo "  3. In the SAME window, flip Ampache's local_web_path/extra_hosts from musicbox to music"
echo "     and restart ampache."
echo "  4. Verify every vhost with a fresh request; certbot renew --dry-run; enable"
echo "     certbot-renew.timer."
echo "  5. sudo fail2ban-client reload && sudo fail2ban-client status"
