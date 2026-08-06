# WordPress (`wordpress/`)

Podman container running the official WordPress image, replacing the native RPM install
(`/usr/share/wordpress`, served by Apache). Points at the **shared MariaDB container**
(`../database/`). Apache remains the TLS-terminating reverse proxy for now (Nginx is a later
phase). Part of the [containerization plan](../plans/containerization-2026-08/README.md)
(Phase 2 / Task 02 — Gate B).

## What was actually live before this (non-obvious — verified 2026-08-03)

The `jackson-brain.com` vhost's `DocumentRoot` (`/var/www/vhosts/jackson-brain.com`) was **not**
WordPress — it held an old static personal site from ~2016 (`particle/`, `cube/`,
`picam.html`, etc.). WordPress core was actually installed separately at
`/usr/share/wordpress` (RPM), aliased at `/wordpress`. The docroot's `index.php` was
WordPress's own front-controller, hand-edited to `require('/usr/share/wordpress/wp-blog-header.php')`
— the classic "WordPress in its own directory" technique, making the site render at the domain
root while the actual install directory was `/wordpress`. Confirmed via the DB:
`wp_options.home = https://jackson-brain.com` but `wp_options.siteurl =
https://jackson-brain.com/wordpress` (genuinely different values).

**Owner decisions (2026-08-03):**
- **Simplify** to a single URL (`https://jackson-brain.com` for both `WP_HOME` and
  `WP_SITEURL`) inside the container — the old subdirectory split existed only to avoid
  colliding with the static site at the docroot, which no longer applies since the container
  is 100% dedicated to WordPress. Admin is now at the standard `/wp-admin` instead of
  `/wordpress/wp-admin`.
- The old static-site files are **left behind, not migrated** (they looked abandoned).
- The vhost's log files, previously misnamed `musicbox.error.log`/`musicbox.access.log` (a
  copy-paste leftover from the Ampache vhost), are renamed to `jackson-brain.error.log`/
  `jackson-brain.access.log`.
- The vhost's `:80` block also had a dead redirect rule (checked `SERVER_NAME` against
  `music.jackson-brain.com` inside the `jackson-brain.com` vhost — never matched, meaning HTTP
  requests to this domain were never actually being redirected to HTTPS). Fixed to check its
  own `ServerName`.

## Image choice

`docker.io/library/wordpress:6.9.4-php8.4-apache` — **the same WordPress core version already
running** (6.9.4), not the newer 7.0.x line, with PHP 8.4 (matches the host's own PHP 8.4.23 /
Fedora 43 default — satisfies "at least what the current OS provides"). Deliberately not
jumping WordPress major versions during containerization: the live site has 4 active plugins
(**Contact Form 7, Flamingo, Google Sitemap Generator, wp-fail2ban**) and a custom theme
(**jackbrain**) that have only been tested against 6.9.x. This isolates "containerize
WordPress" from "upgrade WordPress core" — only one risky variable changes at a time. A
WordPress major-version upgrade can be done later as its own deliberate, separately-tested
change (and is easy since `podman auto-update` only re-pulls the *same* pinned tag — see
below — bumping to 7.x means intentionally editing the tag yourself).

## Image auto-updates

Opted in to `podman auto-update` (label `io.containers.autoupdate=registry` in
`docker-compose.yml`) — see [../podman-auto-update/README.md](../podman-auto-update/README.md)
for the full mechanism (daily timer + a DNF post-transaction hook). Because the image tag is
pinned to `6.9.4-php8.4-apache`, auto-update only pulls **rebuilds of that exact version**
(security patches to the same WordPress/PHP combo) — never an unexpected WordPress core
version jump.

## Deploy

```bash
cd wordpress
sudo bash setup.sh          # first run: migrates wp-content, creates .env, exits with next steps
# edit /opt/wordpress/.env — WORDPRESS_DB_PASSWORD must match WORDPRESS_DB_PASSWORD in
# ../database/.env (the shared MariaDB 'wordpress' user, created in Task 01)
sudo systemctl start wordpress
sudo podman ps --filter name=wordpress          # wait for "healthy"
curl -I http://127.0.0.1:8082/                  # smoke test, backend only
```

Then repoint Apache (once the backend is confirmed working):
```bash
sudo cp apache/vhosts/jackson-brain.com.conf /etc/httpd/conf.d/vhosts/jackson-brain.com.conf
sudo apachectl configtest && sudo systemctl reload httpd
```

## wp-content migration

`setup.sh` copies `/usr/share/wordpress/wp-content` (themes, plugins, **uploads** — the RPM
install's actual content, ~40 MB) into `/storage/wordpress/wp-content` on first run only (skips
if the destination already has content, so re-running is safe). Ownership is translated from
this host's native `apache` user (**UID/GID 48**) to the official image's `www-data`
(**UID/GID 33**) — these do **not** match, don't assume they do. The original
`/usr/share/wordpress/wp-content` is left untouched as a rollback copy.

Only `wp-content` is bind-mounted (`/storage/wordpress/wp-content:/var/www/html/wp-content:Z`)
— WordPress **core files** are managed by the image itself (the documented upstream-recommended
pattern), not bind-mounted.

If the site has taken writes (new posts/uploads) between the Task 01 DB copy and this
container going live, re-sync: re-dump `wordpress` from the system MariaDB and re-import into
`shared-mariadb` (see `../database/README.md`) immediately before cutover.

## Reverse-proxy / HTTPS correctness

- `WORDPRESS_CONFIG_EXTRA` sets `WP_HOME`/`WP_SITEURL` to `https://jackson-brain.com` and
  `FORCE_SSL_ADMIN`, plus a shim so `$_SERVER['HTTPS']` reads `'on'` when Apache forwards
  `X-Forwarded-Proto: https` (prevents redirect loops behind the TLS-terminating proxy).
- The Apache vhost sends `X-Forwarded-Proto: https` and `X-Forwarded-Host`.
- Upload limits: raised from the RPM install's stock `upload_max_filesize=2M` (never a
  deliberate choice, just Fedora's php.ini default) to **64M** via the mounted `uploads.ini`
  (`post_max_size=64M`, `memory_limit=256M` — WordPress's own documented recommended value).
  Apache itself has no `LimitRequestBody` set on this vhost, so PHP's limits are the effective
  ceiling; Nginx's `client_max_body_size` will need to match once Task 04 lands.

## wp-fail2ban (existing plugin — flagged for Task 04, not fixed here)

The **wp-fail2ban** plugin is already active on this site. It's the likely reason a
`[wordpress]` fail2ban jail exists on the host at all — but that jail is currently
misconfigured (stale `iptables-multiport` action; see the master plan and Task 04). Whether
wp-fail2ban's own logging (typically via PHP's `syslog()`, landing in `/var/log/secure` on
this host) still lines up correctly once Nginx replaces Apache is Task 04's concern — not
touched here. No regression versus the current (already broken) behavior from this task.

## Security checklist

- [x] Container runs as the image's default non-root web user (`www-data`); `wp-content` owned
      by UID/GID 33, not world-writable.
- [x] DB user is the least-priv `wordpress` user (Task 01), password in `600` `.env`, not
      committed.
- [x] PHP execution denied in `wp-content/uploads` (carried over via the vhost's
      `LocationMatch`).
- [x] Admin only over HTTPS (`FORCE_SSL_ADMIN`).
- [x] Pinned image tag (`6.9.4-php8.4-apache`); auto-updates scoped to that exact tag only
      (see Image auto-updates above) — not an open-ended "always latest" policy.
- [x] No secrets, no DB dumps committed.

## Verification (Gate B checklist)

- [ ] `https://jackson-brain.com` loads (front page + a post) with correct HTTPS asset URLs.
- [ ] `/wp-admin` login works; no redirect loop.
- [ ] Media upload succeeds and displays (uploads volume persisted on `/storage`).
- [ ] Existing theme (`jackbrain`) and plugins (Contact Form 7, Flamingo, Google Sitemap
      Generator, wp-fail2ban) present and active; permalinks resolve.
- [ ] A new post/comment writes to the shared DB (confirm via `podman exec shared-mariadb`).
- [ ] TLS still served by Apache; other vhosts unaffected.
- [ ] Native RPM WordPress no longer serving, but package/files still installed (rollback).

## Deliverables

- `wordpress/` (this folder): compose, `.env.template`, `uploads.ini`, systemd unit,
  `setup.sh`, README.
- `podman-auto-update/`: host-wide image auto-update mechanism (Podman timer + DNF hook).
- Updated `apache/vhosts/jackson-brain.com.conf` — now a reverse proxy to `127.0.0.1:8082`,
  with corrected log filenames and a fixed HTTP→HTTPS redirect.
- Root `README.md` + repo memory updated.

## Rollback

Re-point `jackson-brain.com`'s vhost back to `DocumentRoot /var/www/vhosts/jackson-brain.com`
(git history has the pre-migration version), `apachectl configtest && systemctl reload httpd`,
`sudo systemctl stop wordpress`. DB rollback per Task 01 — the system MariaDB still holds the
`wordpress` schema, untouched.

## Troubleshooting

- **Redirect loop on `/wp-admin`**: usually means `X-Forwarded-Proto` isn't reaching WordPress,
  or `WP_SITEURL`/`WP_HOME` don't match the actual public URL. Check
  `curl -I https://jackson-brain.com/wp-admin/` for the `Location` header's scheme.
- **Mixed content / `http://` asset URLs**: confirm `WORDPRESS_CONFIG_EXTRA` actually landed in
  the container's `wp-config.php` (`podman exec wordpress cat /var/www/html/wp-config.php`).
- **Upload fails silently past a certain size**: check both the PHP limits (`uploads.ini`) and
  (once Task 04 lands) Nginx's `client_max_body_size`.
- **Ownership errors on `wp-content`**: this host's native `apache` user is UID/GID **48**, the
  container's `www-data` is UID/GID **33** — don't assume file ownership carries over as-is.
