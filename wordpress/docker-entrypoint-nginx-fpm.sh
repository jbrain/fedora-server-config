#!/bin/sh
set -eu

mkdir -p /tmp/nginx/client_body /tmp/nginx/fastcgi /tmp/nginx/proxy /tmp/nginx/uwsgi /tmp/nginx/scgi
/usr/local/bin/docker-entrypoint.sh php-fpm --nodaemonize &
fpm_pid=$!

fpm_ready=0
for _ in $(seq 1 30); do
    if php -r '$socket = @fsockopen("127.0.0.1", 9000, $errno, $errstr, 1); exit($socket === false ? 1 : 0);'; then
        fpm_ready=1
        break
    fi
    if ! kill -0 "$fpm_pid" 2>/dev/null; then
        break
    fi
    sleep 1
done

if [ "$fpm_ready" -ne 1 ]; then
    echo 'PHP-FPM did not become ready on 127.0.0.1:9000' >&2
    exit 1
fi

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
