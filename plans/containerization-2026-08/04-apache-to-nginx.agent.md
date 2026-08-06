# Task 04 — Migrate Apache httpd → Nginx (with TLS/renewal + security hardening)

> **Agent role:** You are a separate agent executing **Phase 4** (final build phase) of the plan
> in [README.md](README.md). Read the master plan first. **Prerequisite: Gates A–B are green** —
> WordPress is containerized and its final backend port is known; the shared DB is stable.
> **Task 03 (Airsonic containerization) was SKIPPED** (owner decision, see master plan §8a) —
> Ampache now takes over `music.jackson-brain.com` (replacing Airsonic) and
> `musicbox.jackson-brain.com` is retired entirely. This is the **internet-facing** cutover: do
> it carefully, keep Apache installed and rollback-ready. **Stop at Gate D and report.**

## Objective

Replace Apache `httpd` with **Nginx** as the TLS-terminating reverse proxy, **preserving all
current behavior**, discarding dead/disabled vhosts, hardening security, and correctly
re-integrating Let's Encrypt certificate renewal (which currently uses the **Apache**
authenticator and whose **timer is disabled**).

## Behavior that MUST be preserved (parity contract)

Translate each active Apache vhost to Nginx exactly:

| Host | Backend | Must keep |
|---|---|---|
| `jackson-brain.com` | WordPress container (`127.0.0.1:8082`, per Task 02) | HTTPS asset URLs, `X-Forwarded-Proto/Host`, deny PHP in `uploads`, upload size limits, CSP/headers, `Authorization` header passthrough. |
| `music.jackson-brain.com` | **Ampache** (`127.0.0.1:8081`) — **taking over from Airsonic** (owner decision, see master plan §8a; Airsonic containerization was skipped and is being decommissioned separately) | `ProxyPreserveHost` equivalent, `X-Forwarded-Proto/Host`, Subsonic `Authorization` passthrough, CSP, HSTS. Carry over the behavior/headers from the old `musicbox` vhost (below), since Ampache is moving here. |
| `ha.jackson-brain.com` | Home Assistant `https://homeassistant.local` (**self-signed**) | **`/api/websocket` + `/api/hassio_ingress` wss**, `proxy_ssl_verify off` (self-signed backend), full WebSocket `Upgrade`/`Connection` handling, HTTP→HTTPS redirect. |

**`musicbox.jackson-brain.com` is retired** (owner-confirmed 2026-08-03) — fold its config into
the new `music.jackson-brain.com` vhost above (same Ampache backend, same header/CSP behavior),
then drop `musicbox` entirely, same as the other dead vhosts below. Do **not** keep a redirect
from `musicbox` → `music` unless the owner asks for one later — default is a clean retirement.

> **Note:** Home Assistant's Music Assistant add-on currently points its Subsonic provider at
> `musicbox.jackson-brain.com` — this is an **external dependency the owner must update
> manually** once this cutover lands (repoint to `music.jackson-brain.com`). Flag this clearly
> in the Gate D report; it's outside this repo's control.

**Discard** the dead vhosts (`chat`, `jhgm*`, `jitsi`, `music-v3`, and any other disabled ones —
confirm the active set first with the current Apache config). Do **not** recreate them.
**phpMyAdmin is being dropped** (owner decision) — do **not** create any `/phpMyAdmin` location
or proxy in Nginx.

Also preserve:
- HTTP (80) → HTTPS (443) permanent redirect for every host.
- Existing per-vhost CSP and `Strict-Transport-Security` headers.

## Nginx placement: host package (DECIDED by owner)

Run Nginx as a **host package** (`dnf install nginx`), **not** a container:
- Binds `0.0.0.0:80` + `0.0.0.0:443` directly on the host (after Apache is stopped).
- Proxies to the `127.0.0.1:<port>` endpoints published by the app containers
  (WordPress `:8082`, Ampache `:8081`) and to `https://homeassistant.local`
  for HA.
- Cert renewal is native and simple via the **`certbot --nginx`** plugin (installs config +
  reloads Nginx automatically) — this is the main reason for choosing the host package.
- Managed by the standard `nginx.service`; config lives in `/etc/nginx/` (source-of-truth in the
  repo `nginx/` folder, deployed by `setup.sh`).

## TLS / certificate renewal (must fix — currently broken-ish)

Findings to address:
- Renewal `authenticator = apache`, `installer = apache` in
  `/etc/letsencrypt/renewal/*.conf` — **will fail once Apache stops**. Reconfigure to the new
  method.
- `certbot-renew.timer` is **disabled/inactive** — auto-renewal isn't running via the timer.
  **Re-enable it** (`systemctl enable --now certbot-renew.timer`) and confirm the schedule.
- Certs are per-domain in `/etc/letsencrypt/live` (`jackson-brain.com`, `ha`, `musicbox`;
  `music` reuses `jackson-brain.com`'s fullchain — **re-verify this**: confirm
  `music.jackson-brain.com` is actually covered as a SAN on that cert before relying on it for
  Ampache's traffic; if not, issue it its own cert via `certbot --nginx -d music.jackson-brain.com`).
  Since `musicbox` is being retired (§8a of the master plan), its dedicated cert becomes
  unused — leave the cert files in place (Let's Encrypt certs aren't harmful to leave idle) but
  don't reference it from any live Nginx config; don't request its renewal be skipped either
  (harmless to keep renewing an unused cert until Gate E cleanup).

Implement with the **`certbot --nginx`** plugin:
- Install `python3-certbot-nginx`. Migrate each renewal `.conf` `authenticator`/`installer` from
  `apache` to `nginx` (either edit the `.conf` files or re-run
  `sudo certbot --nginx -d <domain>` to let certbot rewrite them).
- `certbot --nginx` edits the live Nginx config to serve the ACME challenge and reloads Nginx on
  success — no manual deploy-hook needed.

Prove it: `sudo certbot renew --dry-run` must succeed for **every** cert, and the
`certbot-renew.timer` must be **enabled** with a scheduled next run.

## Security hardening (priority)

- TLS: Mozilla **intermediate** profile — TLS 1.2+1.3 only, modern cipher suite,
  `ssl_prefer_server_ciphers off` (1.3) / on (1.2), `ssl_session_tickets off`,
  OCSP stapling (`ssl_stapling on` + resolver), HSTS (`max-age` ≥ 15768000; consider preload
  once confident — currently only `604800`).
- `server_tokens off`; drop the `Server` version. Set security headers globally where safe:
  `X-Content-Type-Options nosniff`, `X-Frame-Options`/frame-ancestors (respect existing CSP),
  `Referrer-Policy`, `Permissions-Policy`. Keep the **existing per-vhost CSP** values.
- Sensible `client_max_body_size` (WordPress uploads), timeouts, and `limit_req`/`limit_conn`
  for basic abuse protection (coordinate with fail2ban, which already jails sshd).
- Proxy hardening: pass only needed headers; `proxy_ssl_verify off` **only** for the HA
  self-signed backend, not globally.
- Do **not** expose Cockpit, Netdata, or the DB to the internet (phpMyAdmin is being removed).

## Firewall (firewalld) & fail2ban integration (owner: consider explicitly)

**firewalld:**
- Zone `FedoraServer` on `eno1`; `80/443` are **already open** — no firewalld change needed for
  the web tier. Since Nginx is a host package binding `80/443` directly, ensure Apache is fully
  stopped/disabled first so the ports are released (only one process can bind them).
- App containers publish only to `127.0.0.1:<port>`; the shared DB publishes nothing. Do **not**
  open any new public ports. Leave Podman's netavark-managed rules alone.

**fail2ban (currently active, `banaction = firewallcmd-rich-rules`, `ignoreip` includes
`192.168.88.0/24`):**
- The existing **`[wordpress]` jail is misconfigured** — it uses a stale
  `action = iptables-multiport[...]` (raw iptables, wrong on this firewalld/nftables host) and
  has **no `logpath`**, so it inherits the `[DEFAULT] logpath = /var/log/secure` and never sees
  WordPress auth failures. **Fix it:** point `logpath` at the Nginx access log for the WordPress
  vhost (e.g. `/var/log/nginx/jackson-brain.access.log`), remove the explicit
  `iptables-multiport` action so it inherits the firewalld `banaction`, and use a filter that
  matches `POST .*/wp-login.php` / `xmlrpc.php` failures (fail2ban ships a `wordpress` filter;
  verify its regex matches Nginx's combined log format).
- **Add Nginx-oriented jails** reading the new logs, with the firewalld banaction:
  `nginx-http-auth` (401s), `nginx-botsearch`, and `nginx-limit-req` (pairs with the
  `limit_req` you configure). Keep them consistent with `banaction_allports`/rich-rules.
- Preserve the `ignoreip` LAN whitelist so admin/testing from `192.168.88.0/24` is never banned.
- After changes: `sudo fail2ban-client reload`, then `sudo fail2ban-client status <jail>` to
  confirm each jail is active and reading the right log. Trigger a deliberate failed login from a
  non-whitelisted context only if safe to test; otherwise verify via log parsing
  (`fail2ban-regex <log> <filter>`).

## Cutover steps (single careful pass, like the firewalld migration)

1. Build the full Nginx config **offline** and validate (`nginx -t`) while Apache still serves
   — zero live impact (`nginx.service` not started yet). Build the new `music.jackson-brain.com`
   vhost proxying to Ampache (`127.0.0.1:8081`), not Airsonic.
2. Confirm certs in `/etc/letsencrypt/live` are readable by Nginx (host process runs as `nginx`
   with root-owned cert access via the service, as certbot expects). Verify whether
   `music.jackson-brain.com` is actually a SAN on the reused `jackson-brain.com` cert (see TLS
   section above) — issue it its own cert first if not, **before** the cutover window.
3. **Cutover window (do all of this together, in one pass):**
   - `systemctl disable --now httpd` → `systemctl enable --now nginx`. Because only one process
     can bind `80/443`, Apache must be stopped first.
   - **In the same window**, flip Ampache's self-referential config from `musicbox` to
     `music.jackson-brain.com`: update `local_web_path` in `/opt/ampache/config/ampache.cfg.php`
     and the `extra_hosts` entry in `ampache/docker-compose.yml` (repo + deployed copy), then
     `sudo systemctl restart ampache`. Doing this in the same window as the vhost cutover avoids
     a gap where either the old or new hostname resolves to the wrong backend (see master plan
     §8a — this is NOT safe to do ahead of time).
4. Immediately verify **every** host with a **fresh** request (see checklist), including
   Ampache's `/rest/stream.view` specifically (proves both the vhost AND the hairpin-NAT fix
   are correct together). Test the HA WebSocket upgrade specifically.
5. Run `certbot renew --dry-run`; confirm it reloads Nginx; `systemctl enable --now
   certbot-renew.timer` and confirm the next scheduled run.
6. Reload/verify fail2ban jails against the now-live Nginx logs.
7. Leave Apache **installed but stopped/disabled** for rollback through the soak (Gate E removes it).
   Native Airsonic is left running as-is (owner decommissions it separately, outside this plan).

> Mirror the firewalld-migration discipline from repo memory: do the cutover + verification in
> one uninterrupted pass; consider arming a short auto-rollback timer
> (`systemd-run --on-active=...` that stops nginx and restarts httpd) in case the new tier fails
> to serve, and cancel it once verified. Keep the persistent SSH session; mind the 3-conn/min limit.

## Verification (Gate D checklist)

- [ ] `https://jackson-brain.com` (WordPress) — pages, admin, uploads, HTTPS asset URLs.
- [ ] `https://music.jackson-brain.com` (Ampache — now serving here, replacing Airsonic) — UI +
      Subsonic `/rest/ping.view` + `/rest/stream.view` 200.
- [ ] `https://ha.jackson-brain.com` — HA UI loads and the **`/api/websocket`** live connection
      stays up; `/api/hassio_ingress` add-on ingress works.
- [ ] HTTP→HTTPS redirect on all hosts; HSTS + security headers present; CSP unchanged per host.
- [ ] TLS grade check (e.g. `testssl.sh`/SSL Labs) — no weak protocols/ciphers; OCSP stapling ok.
- [ ] `certbot renew --dry-run` succeeds for every cert; `certbot-renew.timer` enabled & scheduled.
- [ ] fail2ban: `[wordpress]` jail fixed (reads Nginx log, firewalld banaction) and Nginx jails
      active (`fail2ban-client status` clean); LAN `ignoreip` preserved.
- [ ] No new public firewalld ports opened; Cockpit/Netdata/DB not internet-exposed.
- [ ] Apache stopped/disabled but still installed (rollback).
- [ ] No dead vhosts recreated (and no phpMyAdmin location); discarded set documented.

## Deliverables

- New repo folder `nginx/` (host config tree deployed to `/etc/nginx/`): a `conf.d/` (or
  `sites-available` + `sites-enabled`) file per active host, a shared TLS-hardening snippet, a
  security-headers snippet, and a WebSocket `map` snippet; plus a `setup.sh` that installs the
  config, and the fail2ban jail/filter changes (e.g. `nginx/fail2ban/`).
- `README.md` for `nginx/`: architecture, `certbot --nginx` renewal integration, per-host proxy
  rationale (esp. HA WebSocket + self-signed backend), security choices, firewalld/fail2ban
  notes, cutover + rollback runbook.
- Update `apache/README.md` to note it's superseded (kept for rollback until Gate E).
- Root `README.md` (services/firewall sections) + repo memory updated: new web tier, renewal
  method (`nginx` authenticator), timer re-enabled, fail2ban jails.
- **Gate D status report.**

## Rollback

- `systemctl disable --now nginx` → `systemctl enable --now httpd`. Apache config is untouched
  and still valid. Revert each renewal `.conf` `authenticator`/`installer` back to `apache` if it
  was changed and a renewal is imminent.
- If Ampache's config was already flipped to `music.jackson-brain.com` before the rollback,
  revert `local_web_path`/`extra_hosts` back to `musicbox.jackson-brain.com` and
  `sudo systemctl restart ampache` — otherwise its hairpin self-call will target a hostname
  Apache no longer routes to Ampache (once `httpd` is back, `musicbox`'s original vhost is
  still there and works, but `music`'s vhost reverts to proxying Airsonic again).

**When done: STOP. Report Gate D results. Gate E (soak, then decommission Apache/native services,
remove dead vhosts, delete rollback DB data) is a separate, human-approved step.**
