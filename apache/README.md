# Apache HTTP Server — REMOVED (2026-08-03, Gate E)

> ## ⛔ FULLY REMOVED — Nginx is the live web tier
> Apache was replaced by Nginx (`../nginx/`) on 2026-08-03 (Task 04) and **fully removed**
> (`dnf remove httpd`, `/etc/httpd` deleted) as part of Gate E the same day — see
> [`../plans/containerization-2026-08/04-apache-to-nginx.agent.md`](../plans/containerization-2026-08/04-apache-to-nginx.agent.md)
> and [`../plans/containerization-2026-08/README.md`](../plans/containerization-2026-08/README.md).
> A full tarball of `/etc/httpd` (as it stood right before removal) is kept at
> `/storage/backups/decommission-2026-08-03/etc-httpd.tar.gz` on the server for reference.
> This file remains for historical documentation only — nothing below reflects the live system.
>
> **Incident note:** during the `dnf remove` of httpd's packages, `nginx` was pulled out as an
> unrelated dependency cascade (its install "reason" wasn't pinned to `User` at the time) and
> was briefly removed/killed too — caught and fixed within minutes (`dnf install nginx` +
> restore `nginx.conf` from the `.rpmsave` copy). See repo memory "Known gotchas" for the full
> writeup; the lesson is to always dry-run (`dnf remove --assumeno`) and read the *entire*
> removal list before confirming any `dnf remove` near a live service's dependency tree.

Apache (`httpd`) acted as a TLS-terminating reverse proxy for all web services on the server. All certificates are managed by **Let's Encrypt / Certbot**.

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
