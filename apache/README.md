# Apache HTTP Server

Apache (`httpd`) acts as a TLS-terminating reverse proxy for all web services on the server. All certificates are managed by **Let's Encrypt / Certbot**.

## Service

```bash
sudo systemctl status httpd
sudo systemctl reload httpd     # apply config changes (no downtime)
sudo systemctl restart httpd    # full restart

sudo apachectl configtest       # validate config before reloading
```

Logs:
- Error log: `/var/log/httpd/error_log`
- Per-vhost logs: `/var/log/httpd/<name>-error_log` and `-access_log`

## Virtual Hosts

All vhosts live in `/etc/httpd/conf.d/vhosts/`. Each follows the same pattern:
- HTTP (port 80) → permanent redirect to HTTPS
- HTTPS (port 443) → reverse proxy or document root

| Vhost file | ServerName | Backend |
|---|---|---|
| `jackson-brain.com.conf` | `jackson-brain.com` | `/var/www/vhosts/jackson-brain.com` (WordPress) |
| `music.jackson-brain.com.conf` | `music.jackson-brain.com` | `http://localhost:8080` (AirSonic) |
| `ha.jackson-brain.com.conf` | `ha.jackson-brain.com` | `https://homeassistant.local` (Home Assistant) |
| `ampache.jackson-brain.com.conf` | `musicbox.jackson-brain.com` | `http://127.0.0.1:8081` (Ampache Docker) |

## TLS Certificates

Certificates are managed by Certbot and auto-renewed. All certs are in `/etc/letsencrypt/live/`.

| Domain | Certificate path |
|---|---|
| `jackson-brain.com` | `/etc/letsencrypt/live/jackson-brain.com/` |
| `music.jackson-brain.com` | `/etc/letsencrypt/live/music.jackson-brain.com/` |
| `ha.jackson-brain.com` | `/etc/letsencrypt/live/ha.jackson-brain.com/` |
| `ampache.jackson-brain.com` | `/etc/letsencrypt/live/ampache.jackson-brain.com/` |

Issue or renew a certificate:
```bash
# Issue new cert (DNS must already resolve to this server)
sudo certbot certonly --webroot -w /var/www/html -d <domain>

# Force renewal
sudo certbot renew --force-renewal -d <domain>

# List all certs and expiry dates
sudo certbot certificates
```

## Adding a New Reverse-Proxy Vhost

1. Copy an existing vhost config as a template:
   ```bash
   sudo cp /etc/httpd/conf.d/vhosts/music.jackson-brain.com.conf \
           /etc/httpd/conf.d/vhosts/<new>.jackson-brain.com.conf
   ```
2. Edit `ServerName`, `ProxyPass`/`ProxyPassReverse`, and log file names.
3. Obtain a certificate:
   ```bash
   sudo certbot certonly --webroot -w /var/www/html -d <new>.jackson-brain.com
   ```
4. Update `SSLCertificateFile` / `SSLCertificateKeyFile` paths.
5. Test and reload:
   ```bash
   sudo apachectl configtest && sudo systemctl reload httpd
   ```

## PHP / PHP-FPM

PHP-FPM is configured via a drop-in: `/etc/systemd/system/httpd.service.d/php-fpm.conf`

## Other Loaded Configs

- `/etc/httpd/conf.d/phpMyAdmin.conf` — phpMyAdmin
- `/etc/httpd/conf.d/wordpress.conf` — WordPress rewrite rules
- `/etc/httpd/conf.d/ssl.conf` — global SSL defaults
- `/etc/httpd/conf.d/geoip.conf` — GeoIP module
