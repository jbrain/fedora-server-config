# WordPress (`wordpress/`)

Podman container running a custom-built WordPress image, replacing the native RPM install
(`/usr/share/wordpress`, formerly served by Apache). Points at the **shared MariaDB container**
(`../database/`). **Nginx** is now the TLS-terminating reverse proxy (Apache was fully removed
host-wide 2026-08-03, Gate E of the [containerization plan](../plans/containerization-2026-08/README.md)).

> **Status: live and hardened (2026-08-08).** Core upgraded to a custom-built `6.9.6`, a real
> account-creation/webshell compromise was found and remediated twice, and the site now has
> multiple independent write-prevention/exploit-mitigation layers on top of the original Gate B
> deployment. See "Security incidents and hardening (2026-08-08)" below for the full summary —
> full blow-by-blow detail lives in repo memory (`/memories/repo/fedora-server-config.md`).

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

## WordPress 7 upgrade assessment (2026-08-16)

WordPress 7.0.4 is now an official stable security release, published by WordPress on August
12, 2026. Docker Hub's official `library/wordpress` image publishes the matching
`wordpress:7.0.4-php8.4-apache` tag. The amd64 manifest digest observed during this check-in is
`sha256:f047aeee171f821cca23eb6583df687958bb157a5967e19924fc82eb5367d56c`.

This confirms that the upgrade can move back to an official image and remove the custom core-swap
Dockerfile, but production has **not** been upgraded yet. The current custom `6.9.6` image remains
the deployed baseline until a staging clone passes the theme/plugin compatibility and compromise
regression gates. WordPress 7.1 is still a release candidate and is not a production candidate.

Required staging gates before changing `docker-compose.yml`:

- Clone the database and `wp-content` into an isolated test project; never test against production.
- Pin the official image by tag and digest, then verify the image's PHP extensions and Apache
  configuration against the current custom image.
- Exercise the custom theme, Contact Form 7, Flamingo, Google Sitemap Generator, wp-fail2ban,
  and the Jackson Brain Ampache Integration plugin.
- Re-run the compromise regression checks: disabled file modifications, uploads PHP denial,
  XML-RPC blocking, syslog/fail2ban path, HTTPS/admin redirects, and no unexpected writable core.
- Verify the cube/entropy page, conditional Contact Form 7 reCAPTCHA behavior, WP-Cron timer,
  media upload, permalinks, and database connectivity.
- Only after staging passes should the production image change be deployed with a backup,
  rollback target, and post-restart HTTP/service/hash checks.

**Current image: `localhost/wordpress:6.9.6-php8.4-apache`** — a locally custom-built image (see
`Dockerfile`), not an official Docker Hub tag. WordPress 6.9.4 (the original containerization
target, matching what was already running) was found to be **insecure** per WordPress's own
`stable-check` API during 2026-08-08 hardening; 6.9.6 is the fixed release on the *same* major
line (deliberately not jumping to 7.x — the live site's plugins/theme were never tested against a
new major, and Docker Hub had no `7.0.3`/`6.9.6`-tagged image published yet either way). The
Dockerfile rebuilds the official `6.9.4-php8.4-apache` base with the real 6.9.6 core from
wordpress.org, carefully preserving `wp-config-docker.php` (a Docker-image-specific file not in
the plain release tarball — losing it once already broke the site for real, see the Dockerfile's
own header comment). Switch back to an official `wordpress:<version>-php8.4-apache` tag once one
matching a current security release exists on Docker Hub.

## Image auto-updates

Opted in to `podman auto-update` (label `io.containers.autoupdate=registry` in
`docker-compose.yml`) — see [../podman-auto-update/README.md](../podman-auto-update/README.md)
for the full mechanism (daily timer + a DNF post-transaction hook). **Currently a no-op**: since
the image is a local custom build (`localhost/...`, no registry to check against), auto-update has
nothing to pull. Security patches to WordPress core require a manual rebuild via the `Dockerfile`
until this switches back to an official upstream tag.

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

Then confirm nginx is pointed at the backend (already the live config — see
`../nginx/conf.d/jackson-brain.com.conf`, `proxy_pass http://127.0.0.1:8082;`):
```bash
sudo nginx -t && sudo systemctl reload nginx
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

**Disaster-recovery gap, read before trusting this migration path today:** the
`/usr/share/wordpress/wp-content` rollback copy referenced above is a frozen snapshot from
2026-08-03 — it predates the cube engine rewrite (`plans/cuber-modernization/README.md`) and the
2026-08-14 jQuery removal from the `jackbrain` theme entirely. If `/storage/wordpress/wp-content`
is ever lost and this migration path runs again, the theme would silently revert to that stale,
pre-modernization state. Two of the theme's files (`functions.php`, `js/jbrain.js`) are tracked
in this repo specifically to guard against that — see
[`theme/jackbrain/README.md`](theme/jackbrain/README.md) for why, and for the required
post-recovery step of re-applying them (plus rebuilding/redeploying `JS/cuber/dist/`) before
considering any from-scratch restore complete.

## Reverse-proxy / HTTPS correctness

- `WORDPRESS_CONFIG_EXTRA` sets `WP_HOME`/`WP_SITEURL` to `https://jackson-brain.com` and
  `FORCE_SSL_ADMIN`, plus a shim so `$_SERVER['HTTPS']` reads `'on'` when nginx forwards
  `X-Forwarded-Proto: https` (prevents redirect loops behind the TLS-terminating proxy).
- The nginx vhost (`../nginx/conf.d/jackson-brain.com.conf`) sends `X-Forwarded-Proto: https`
  and `X-Forwarded-Host`, rate-limits `/wp-login.php`, blocks `/xmlrpc.php` entirely (403, added
  2026-08-08 after a real compromise), and denies PHP execution under `wp-content/uploads/`
  (case-insensitive, covers `.php`/`.phar`/`.phtml`/`.pht`/`.php[0-9]` variants).
- Upload limits: raised from the RPM install's stock `upload_max_filesize=2M` (never a
  deliberate choice, just Fedora's php.ini default) to **64M** via the mounted `uploads.ini`
  (`post_max_size=64M`, `memory_limit=256M` — WordPress's own documented recommended value).
  nginx's `client_max_body_size` is matched to this.

## WP-Cron timer for now-playing freshness

This repo now ships a dedicated host-side `wordpress-wp-cron.service` +
`wordpress-wp-cron.timer`, installed by `setup.sh`, to invoke WordPress cron every 60 seconds.
That is not an optional optimization for the Jackson Brain Ampache plugin when the now-playing
view is enabled; it is the production mechanism that keeps the plugin's background snapshot near
real time.

The timer deliberately hits the public hostname through local nginx, but forces it to `127.0.0.1`
with `curl --resolve jackson-brain.com:443:127.0.0.1 ...`. That keeps the request path identical
to the real site URL (correct host, HTTPS, and reverse-proxy headers) without depending on WAN
hairpin NAT or external DNS from the host itself.

Without this timer, WordPress falls back to traffic-driven WP-Cron, which is only best-effort.
That proved insufficient in production on 2026-08-11: `wp-cron.php` was firing only on sporadic
page traffic, with multi-minute gaps, and the Ampache plugin's "current" now-playing/recent data
lagged visibly even though both containers and the public site were otherwise healthy.

## wp-fail2ban — fixed (2026-08-04)

The **wp-fail2ban** plugin is active; the `[wordpress]` fail2ban jail (host-side,
`/etc/fail2ban/jail.local` + `filter.d/wordpress.local`) was reconfigured to read
`/var/log/messages` (where its PHP `syslog()` calls actually land on this host) and its filter
regex was broadened to also catch XML-RPC brute-force lines (previously silently uncounted —
see repo memory "fail2ban `[wordpress]` jail was silently blind to XML-RPC brute force"). As of
2026-08-08 it has banned 96 IPs over its lifetime.

## Jackson Brain Ampache Integration plugin — live and working

Deployed into this container's bind-mounted `wp-content/plugins/jackson-brain-ampache/` (no
separate compose change needed for the plugin directory itself — it's already covered by the
existing `wp-content` bind mount). It connects to the Ampache container via its public vhost,
**`https://music.jackson-brain.com`** — never the container's internal Podman network address,
since the plugin is a normal HTTPS client of Ampache's own public origin, not a same-network
service call. Full design/security rationale: `../plans/wordpress-ampache-plugin/`.

Active on the `music` page (blocks: Library Statistics, Now Playing, Recently Played). Setup:

1. Create `./secrets/jba-ampache-api-key` from `./secrets/jba-ampache-api-key.template` with the
   real key for a dedicated, level-25 Ampache user (never the admin or Music Assistant key).
   This file is bind-mounted read-only into the container and is never an env var, so it never
   appears in `docker-compose.yml`, `.env`, or `podman inspect` output.
2. `JBA_AMPACHE_ORIGIN` is already pinned to `https://music.jackson-brain.com` in
   `WORDPRESS_CONFIG_EXTRA` above — not a secret, but intentionally read-only in wp-admin.
3. Recreate the container (`sudo systemctl restart wordpress`) so it picks up the new bind mount.
4. Confirm `systemctl status wordpress-wp-cron.timer` shows the timer active; now-playing
  freshness depends on it.
5. Activate the plugin, then use "Test connection" and "Refresh now" on its Settings page
   (**Settings → Ampache Integration**, also linked directly from the plugin's row on the
   Plugins list page).

**Known Ampache-server-side quirks worked around in the plugin** (this specific Ampache 7.9.8
install, not a WordPress-side bug — see plugin code comments and repo memory for full detail):
- `ping`/`handshake`'s summary counts (songs/albums/artists/genres/playlists) always report `0`
  regardless of real library size — the plugin instead fetches real totals from each of the
  `songs`/`albums`/`artists`/`genres`/`playlists` list actions' own `total_count` field.
- The `artists` list action ignores the requested `limit` entirely and returns the full ~4,060-row
  list (~3.3MB, ~3.5s) regardless — the client's timeout/size budgets are sized to tolerate this
  for background refreshes (never a public page load).
- The `songs` list action reliably 500s on this server; that one metric is gracefully omitted
  rather than failing the whole stats section.
- WordPress's own SSRF guard (`wp_http_validate_url()`) rejects the configured origin by default
  since it resolves to a LAN/private IP — worked around with a narrow `http_request_host_is_external`
  filter scoped to exactly the configured origin host, not a blanket bypass.

## Security incidents and hardening (2026-08-08)

A real account-creation compromise (documented originally 2026-08-03) **recurred** on
2026-08-08, this time including a live defacement post and 5 PHP webshells uploaded via the
Media Library disguised with double extensions (`.php_.jpg` etc.). Both incidents were fully
contained (rogue accounts/posts/webshells deleted, admin password + DB password rotated). Full
incident detail, root-cause investigation, and the newly-found smoking-gun evidence (every rogue
account's first successful login happens within seconds of its own creation, from a public IP —
pointing at a vulnerable plugin endpoint that lets an attacker set the account's password
directly, not WordPress's normal random-password self-registration flow) live in repo memory.

**Hardening deployed as a direct result** (all verified live, see "Security checklist" below):
- WordPress core upgraded 6.9.4 → custom-built 6.9.6 (6.9.4 was flagged "insecure" by
  WordPress's own `stable-check` API).
- All 5 active plugins updated to latest (Google Sitemap Generator's update in particular fixed
  CVE-2025-64632, a broken-access-control bug and the strongest concrete lead for how the
  account-creation compromise actually happens).
- `xmlrpc.php` blocked entirely at nginx (403).
- PHP execution blocked in `wp-content/uploads/` at **two independent layers**: an Apache
  `<Directory>` block (`apache-uploads-no-php.conf`, `php_admin_flag engine off` + a
  `Require all denied` FilesMatch covering every PHP-executable extension variant) and a
  broadened, case-insensitive nginx regex — either layer alone would have blocked the
  2026-08-08 webshells.
- fail2ban `[wordpress]` jail fixed to actually catch XML-RPC brute force (previously silently
  uncounted).
- **Deliberately NOT done**: making `wp-content` read-only at the mount level. Tried and
  reverted live — the official image's entrypoint always re-syncs core files into
  `/var/www/html` on every single restart (not just first boot), and that step unconditionally
  touches `wp-content`'s own top-level directory entries; any part of `wp-content` being `:ro`
  crashes the container on every restart. See repo memory for full root-cause detail before ever
  attempting this again.

## Security checklist

## Security check-in (2026-08-16)

SSH connectivity was verified with the expected `jack` account, and the read-only Linux MCP
connector was verified through the canonical host `jackson-brain.com`. The MCP alias `linus` was
not resolvable by that connector and the LAN IP was rejected as an untrusted host key; use the
canonical hostname for MCP checks. Live `wordpress`, `nginx`, `firewalld`, `fail2ban`,
`shared-mariadb`, `sshd`, `wordpress-wp-cron.timer`, and `podman-auto-update.timer` are active.
The host reports Fedora 43, kernel `7.1.7-100.fc43.x86_64`, and approximately one week of uptime.

The 2026-08-03 and 2026-08-08 WordPress compromises remain the relevant historical incidents:
rogue accounts, a defacement post, and five double-extension PHP webshells. Existing mitigations
remain documented and live: WordPress 6.9.6 custom image, plugin updates, disabled file editors
and modifications, XML-RPC blocking, uploads PHP denial, fail2ban WordPress/Nginx jails, and
container read-only root filesystem. The current audit found no new compromise indicator in the
available service-status evidence. A theme warning from unconditional `WPCF7_LOAD_JS` definition
was fixed with a `defined()` guard.

Current review notes:

- The public listeners include SSH/22, RPC bind/111, LLMNR/5355, Cockpit/9090, and Netdata/19999;
  verify each is intentionally LAN-restricted in the active firewalld rules, not only in the
  historical Shorewall documentation.
- Direct non-interactive SSH can verify connectivity but cannot read privileged journals without
  sudo. MCP service checks work, while several journal queries returned no entries; treat that as
  an observability limitation, not proof that logs are empty.
- The repository's historical overview still contains older kernel/service wording in places;
  the live kernel value above is the current check-in baseline.

- [x] Container runs as the image's default non-root web user (`www-data`); `wp-content` owned
      by UID/GID 33, not world-writable.
- [x] DB user is the least-priv `wordpress` user, password in `600` `.env`, not committed
      (rotated 2026-08-08 after an accidental plaintext exposure in a terminal transcript).
- [x] Container root filesystem is `read_only: true` + `tmpfs: [/tmp]` (WP core/image layer
      cannot be tampered with in a way that survives a recreate).
- [x] `DISALLOW_FILE_EDIT`+`DISALLOW_FILE_MODS` set (blocks the wp-admin plugin/theme editor and
      any plugin/theme self-update/install attempt).
- [x] PHP execution denied in `wp-content/uploads` at **two independent layers** (Apache
      `<Directory>` block + nginx regex — see "Security incidents and hardening" above).
- [x] `xmlrpc.php` blocked entirely at nginx (403).
- [x] Admin only over HTTPS (`FORCE_SSL_ADMIN`).
- [x] fail2ban `[wordpress]` jail actively banning brute-force/XML-RPC attempts.
- [x] No secrets, no DB dumps committed.
- [ ] `wp-content` read-only at the mount level — **deliberately not done, see above**; the
      practical write-blocking for it is the `DISALLOW_FILE_EDIT`/`MODS` + uploads-specific
      PHP-execution denial instead.

## Verification (Gate B checklist) — all passed

- [x] `https://jackson-brain.com` loads (front page + a post) with correct HTTPS asset URLs.
- [x] `/wp-admin` login works; no redirect loop (a `reauth=1` bounce after a container restart is
      expected — restarts regenerate `wp-config.php`'s auth salts, invalidating existing login
      cookies; just log in again).
- [x] Media upload succeeds and displays (uploads volume persisted on `/storage`).
- [x] Existing theme (`jackbrain`) and plugins (Contact Form 7, Flamingo, Google Sitemap
      Generator, wp-fail2ban) present and active; permalinks resolve.
- [x] A new post/comment writes to the shared DB (confirm via `podman exec shared-mariadb`).
- [x] TLS served by nginx; other vhosts unaffected.
- [x] Native RPM WordPress no longer serving (package fully removed 2026-08-03, Gate E).

## Deliverables

- `wordpress/` (this folder): compose, `Dockerfile`, `htaccess`, `apache-uploads-no-php.conf`,
  `.env.template`, `uploads.ini`, systemd unit, `setup.sh`, `plugins/jackson-brain-ampache/`, README.
- `podman-auto-update/`: host-wide image auto-update mechanism (Podman timer + DNF hook,
  currently a no-op for this locally-built image).
- `nginx/conf.d/jackson-brain.com.conf` — reverse proxy to `127.0.0.1:8082`, xmlrpc block,
  broadened uploads PHP-execution block, wp-login rate limiting.
- Root `README.md` + repo memory updated.

## Rollback

`sudo systemctl stop wordpress`, restore `docker-compose.yml`/`Dockerfile` from a prior git
commit or the `.bak-<timestamp>` files on the server if a specific deployed change needs
undoing, `sudo systemctl start wordpress`. DB rollback: restore from
`/storage/backups/mysql/` (daily) or the pre-upgrade dumps in `/storage/backups/manual/`.
Native RPM WordPress is no longer installed at all (Gate E, 2026-08-03) — there is no native
fallback path anymore, only container rollback.

## Troubleshooting

- **Redirect loop on `/wp-admin`**: usually means `X-Forwarded-Proto` isn't reaching WordPress,
  or `WP_SITEURL`/`WP_HOME` don't match the actual public URL. Check
  `curl -I https://jackson-brain.com/wp-admin/` for the `Location` header's scheme.
- **`wp-admin` bounces to `wp-login.php?...&reauth=1`**: expected after a container restart —
  `wp-config.php`'s `AUTH_KEY`/`SECRET_KEY`/etc. salts regenerate fresh every recreate (the file
  isn't persisted), invalidating any existing login cookie. Just log in again; not a compromise.
- **Ampache blocks render but the data is visibly old**: first check `systemctl status
  wordpress-wp-cron.timer` on the host. The plugin's now-playing path is background-only by
  design; healthy public rendering does not imply the snapshot is being refreshed often enough.
  The 2026-08-11 live failure mode was exactly this: `wp-cron.php` still worked, but only when
  page traffic happened to trigger it, leaving multi-minute gaps and stale "current" music data.
  Also ensure the timer calls plain `/wp-cron.php` (no `doing_wp_cron=<manual-key>` query param);
  forcing an arbitrary cron key can return HTTP 200 yet still skip due jobs if the key does not
  match WordPress's own cron-lock flow.
- **Mixed content / `http://` asset URLs**: confirm `WORDPRESS_CONFIG_EXTRA` actually landed in
  the container's `wp-config.php` (`podman exec wordpress cat /var/www/html/wp-config.php`).
- **Upload fails silently past a certain size**: check both the PHP limits (`uploads.ini`) and
  nginx's `client_max_body_size`.
- **Ownership errors on `wp-content`**: this host's native `apache` user is UID/GID **48**, the
  container's `www-data` is UID/GID **33** — don't assume file ownership carries over as-is.
- **Plugin files silently fail to load after a manual `cp` update** (`Permission denied` on
  `include_once`, only a warning, not fatal): the `:Z`-mounted bind volume only gets relabeled to
  `container_file_t` at container *start* time — files added while it's already running keep
  their source SELinux context. Fix: `systemctl restart wordpress`.
- **Container crash-loops after touching `wp-content`'s mount options**: do NOT make any part of
  `wp-content` read-only at the mount level — see "Security incidents and hardening" above.
