# Nginx (`nginx/`)

Host-package Nginx (`dnf install nginx`), replacing Apache `httpd` as the TLS-terminating
reverse proxy for jackson-brain.com. Part of the
[containerization plan](../plans/containerization-2026-08/README.md) (Phase 4 / Task 04 — Gate D).

## Why host package, not a container

Owner decision: keeps Let's Encrypt renewal simple via the `certbot --nginx` plugin (installs
config + reloads automatically), and avoids adding a container in the direct internet-facing
path. Binds `0.0.0.0:80`/`443` directly; proxies to the `127.0.0.1:<port>` endpoints published
by the app containers (WordPress `:8082`, Ampache `:8081`) and to `https://homeassistant.local`
for Home Assistant.

## Layout

- `nginx.conf` — top-level config. `server_tokens off`, a hardened default/catch-all
  `server` block (`return 444` / `ssl_reject_handshake` for unmatched Host headers), the
  `$connection_upgrade` websocket map, and a `limit_req_zone` for WordPress login rate-limiting.
- `snippets/tls-intermediate.conf` — Mozilla "intermediate" TLS profile (TLS 1.2+1.3, modern
  cipher suite, OCSP stapling), included by every HTTPS vhost.
- `snippets/security-headers.conf` — baseline headers (`X-Content-Type-Options`,
  `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`). CSP and HSTS are set per-vhost
  instead, since correct values differ per app.
- `conf.d/jackson-brain.com.conf` — WordPress (`127.0.0.1:8082`).
- `conf.d/music.jackson-brain.com.conf` — **Ampache** (`127.0.0.1:8081`), replacing Airsonic
  and absorbing the retired `musicbox.jackson-brain.com` (see below).
- `conf.d/ha.jackson-brain.com.conf` — Home Assistant (`https://homeassistant.local`,
  self-signed backend, WebSocket support).
- `fail2ban/jail.local` — full deployed jail config (fixed `[wordpress]` jail + new
  `nginx-http-auth`/`nginx-botsearch`/`nginx-limit-req` jails; see below).
- `setup.sh` — deploys config + validates (`nginx -t`); does **not** start nginx or stop
  httpd (that's the deliberate, single-pass cutover — see the Task 04 runbook).

## music.jackson-brain.com: Airsonic → Ampache, musicbox retired

Per owner decision 2026-08-03 (containerization plan §8a): Airsonic containerization was
skipped (being decommissioned separately by the owner), and Ampache consolidates onto
**`music.jackson-brain.com`**, replacing Airsonic there. **`musicbox.jackson-brain.com` is
retired entirely** — no redirect kept. The new vhost's CSP/headers were carried over from the
more complete/current `musicbox.jackson-brain.com` Apache vhost (not the older `music` one),
including:
- `location /` bare `proxy_pass http://127.0.0.1:8081;` (no URI suffix) — forwards the
  original request URI byte-for-byte, nginx's equivalent of Apache's
  `AllowEncodedSlashes NoDecode` + `ProxyPass ... nocanon`, both required for Subsonic/REST
  paths containing encoded slashes.
- `proxy_read_timeout`/`proxy_send_timeout 3600s` (Apache's old `ProxyTimeout 3600`) for long
  audio streams.
- Subsonic `Authorization` header passthrough needs **no special config** under nginx (unlike
  Apache's `SetEnvIf Authorization` trick) — `proxy_pass` forwards it by default.

**Ampache's own self-referential config was flipped in the same cutover window** (not before —
doing it early would have broken streaming, since `music.jackson-brain.com` still routed to
Airsonic via Apache until this cutover):
- `local_web_path` in `/opt/ampache/config/ampache.cfg.php`: `musicbox` → `music`.
- `extra_hosts` in `ampache/docker-compose.yml`: `musicbox.jackson-brain.com` →
  `music.jackson-brain.com` (the NAT-hairpin fix — see `ampache/README.md`).

**External dependency the owner must update manually**: Home Assistant's **Music Assistant**
add-on has its Subsonic provider configured against `musicbox.jackson-brain.com` — repoint it
to `music.jackson-brain.com`. Can't be done from this repo (HA-side add-on config).

`musicbox.jackson-brain.com`'s Let's Encrypt cert is left in place but unused (harmless to
leave idle); its renewal `.conf` was **not** migrated to the `nginx` authenticator (no matching
vhost exists for certbot's nginx plugin to inject a challenge into) — its next renewal attempt
will fail once Apache is fully decommissioned at Gate E. This is an accepted, documented
consequence of retiring the hostname, not an oversight.

## Cert inventory (corrected finding, 2026-08-03)

Every vhost has its **own dedicated** Let's Encrypt cert — `jackson-brain.com`,
`music.jackson-brain.com`, and `ha.jackson-brain.com` each have a valid cert covering only
that domain. (Earlier repo notes assumed `music` reused `jackson-brain.com`'s fullchain —
that was wrong; verified via `certbot certificates` live on the server.)

## Cutover runbook (what was actually done, 2026-08-03)

1. Deployed config offline, validated with `nginx -t` (Apache still serving, zero live impact).
2. Single atomic window: `systemctl disable --now httpd` → `systemctl enable --now nginx`,
   immediately followed by flipping Ampache's `musicbox`→`music` config and
   `systemctl restart ampache`.
3. Verified every vhost with fresh requests: WordPress 200 + correct admin redirect, Ampache
   `/rest/ping.view` ok + `/rest/stream.view` 200 with correct byte count, HA 200 with real
   page title.
4. Migrated all 3 live certs' renewal `authenticator`/`installer` from `apache` to `nginx`;
   proved with `certbot renew --dry-run` (all 3 succeeded); enabled `certbot-renew.timer`.
5. Deployed the fixed `fail2ban` jail config; `fail2ban-client reload` — 5 jails active
   (`sshd`, `wordpress`, `nginx-http-auth`, `nginx-botsearch`, `nginx-limit-req`); LAN
   `ignoreip` whitelist preserved.
6. Left Apache installed but stopped/disabled for rollback through the soak (Gate E removes
   it). Native Airsonic left running as-is (owner decommissions it separately).

### Two real bugs found and fixed live during the cutover

1. **HA reverse proxy 502 (SNI rejection)**: nginx didn't send SNI matching the `proxy_pass`
   hostname to HA's self-signed backend, and HA's aiohttp server rejected the handshake
   (`SSL alert 112 unrecognized_name`). Fixed with `proxy_ssl_server_name on;` — needed
   **in addition to** `proxy_ssl_verify off;`, not instead of it.
2. **HA reverse proxy 502 (Host header)**: after fixing SNI, HA silently closed the connection
   before sending any response. Apache's old vhost never had `ProxyPreserveHost On`, so it was
   implicitly sending HA's own hostname as the `Host` header all along. Fixed by changing
   `proxy_set_header Host $host;` → `Host $proxy_host;` (nginx's equivalent of "don't
   preserve the client's Host header").

## The `[wordpress]` fail2ban jail — real root cause (not what the original plan assumed)

The original plan assumed this jail needed to read an **Nginx access log** for WordPress
auth failures. That assumption was wrong: the jail's filter (`wordpress.conf`) matches
messages like `Authentication failure for X from <HOST>`, which come from the
**wp-fail2ban plugin** logging via PHP's `syslog()` at the `LOG_AUTH` facility — **not** from
any web server's access/error log, Apache or Nginx. Two real, independent bugs were found and
fixed:

1. **Wrong `logpath`**: the jail inherited `[DEFAULT] logpath = /var/log/secure`, but this
   host's rsyslog routes plain `auth.*` (not `authpriv.*`) to **`/var/log/messages`** — a real
   pre-migration log line (`Authentication failure for admin from <IP>`, dated the day before
   this migration) was found there, proving wp-fail2ban had been working correctly all along;
   fail2ban was just watching the wrong file. Fixed: `logpath = /var/log/messages` set
   explicitly on the `[wordpress]` jail.
2. **Containerizing WordPress broke the syslog path entirely**: the container had no
   `/dev/log` at all, so `syslog()` calls silently went nowhere. Fixed by bind-mounting the
   host's real journal socket into the WordPress container (see `wordpress/docker-compose.yml`)
   — mounting the **resolved** `/run/systemd/journal/dev-log` target, not the `/dev/log`
   symlink itself (bind-mounting the symlink path produces a dead, disconnected socket node).
3. **Wrong action**: `iptables-multiport[...]` (raw iptables) on this firewalld/nftables host,
   replaced with `%(action_mwl)s` (uses the effective `$(banaction)s` = `firewallcmd-rich-rules`
   from `jail.d/00-firewalld.conf`, plus the original email-with-whois notification).

Also added the standard `nginx-http-auth`, `nginx-botsearch`, `nginx-limit-req` jails (these
genuinely are Nginx-log-based, unlike `[wordpress]`) — `nginx-limit-req` needed an actual
`limit_req`/`limit_req_zone` configured somewhere to have anything to match, so a basic
rate limit was added on WordPress's `wp-login.php`/`xmlrpc.php` (10 req/min, burst 5).

## Security checklist

- [x] TLS: Mozilla intermediate profile (TLS 1.2+1.3 only, modern ciphers), OCSP stapling
      configured (nginx warns "ignored, no OCSP responder URL" for these certs at `-t` time —
      harmless; stapling just won't activate for certs that don't support it).
- [x] `server_tokens off`; hardened default/catch-all vhost (444 / reject handshake).
- [x] Baseline security headers on every vhost; per-vhost CSP preserved/carried over exactly.
- [x] `client_max_body_size 64M` on WordPress (matches its PHP `uploads.ini`).
- [x] `proxy_ssl_verify off` scoped to the HA vhost only, not globally.
- [x] No new firewalld ports opened; Cockpit/Netdata/DB not internet-exposed; phpMyAdmin
      not recreated.
- [x] fail2ban: `[wordpress]` fixed (see above), 3 new Nginx jails, LAN `ignoreip` preserved.

## Rollback

`systemctl disable --now nginx && systemctl enable --now httpd` — Apache's config is untouched
and still valid. If Ampache's config was already flipped to `music.jackson-brain.com`, revert
`local_web_path`/`extra_hosts` back to `musicbox.jackson-brain.com` and restart Ampache
(otherwise its hairpin self-call targets a hostname Apache no longer routes to it). Revert each
cert's renewal `.conf` `authenticator`/`installer` back to `apache` if a real renewal is
imminent during the rollback window.
