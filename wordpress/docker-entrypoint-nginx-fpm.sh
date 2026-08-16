#!/bin/sh
set -eu

mkdir -p /tmp/nginx/client_body /tmp/nginx/fastcgi /tmp/nginx/proxy /tmp/nginx/uwsgi /tmp/nginx/scgi
/usr/local/bin/docker-entrypoint.sh php-fpm --nodaemonize &
fpm_pid=$!
nginx -g 'daemon off;' &
nginx_pid=$!

cleanup() {
    kill "$fpm_pid" "$nginx_pid" 2>/dev/null || true
}

trap cleanup INT TERM EXIT

while kill -0 "$fpm_pid" 2>/dev/null && kill -0 "$nginx_pid" 2>/dev/null; do
    sleep 1
done

exit 1
