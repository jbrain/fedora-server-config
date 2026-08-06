set -a
. /opt/shared-mariadb/.env
podman exec shared-mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" wordpress -e "SELECT ID,user_login,user_registered FROM wp_users;"
echo "---wp-config DB_USER---"
grep -E "DB_USER|DB_NAME|DB_HOST" /opt/wordpress/wp-config.php 2>/dev/null || sudo podman exec wordpress grep -E "DB_USER|DB_NAME|DB_HOST" /var/www/html/wp-config.php