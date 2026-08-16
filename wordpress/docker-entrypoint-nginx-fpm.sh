#!/bin/sh
set -eu

/usr/local/bin/docker-entrypoint.sh true
php-fpm --nodaemonize &
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
