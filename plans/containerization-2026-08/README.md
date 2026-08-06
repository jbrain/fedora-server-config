# Containerization & Nginx Migration — Master Plan (2026-08)

> ## Status: ALL GATES COMPLETE (2026-08-03) — plan fully executed
> Gate A (shared MariaDB) ✅, Gate B (WordPress) ✅, Gate C skipped (Airsonic consolidated onto
> Ampache instead, §8a) ✅, Gate D (Apache→Nginx) ✅, **Gate E (soak/decommission) ✅** — Apache
> and the native system MariaDB are **fully removed** (not just stopped), Airsonic is **fully
> decommissioned** (service, files, DB, OS user/group, its Java runtime — done same-day as an
> owner-approved extension of Gate E, ahead of the "owner's own timeline" originally implied in
> §8a), dead vhosts/certs/rollback data cleaned up. See the bottom of this file for the full
> Gate E report. This plan is now historical/reference documentation; the live system state is
> tracked in `/memories/repo/fedora-server-config.md` and the root `README.md`.

Orchestration plan for four related infrastructure changes on **jackson-brain.com**
(`192.168.88.251`, Fedora 43). Each work item has a dedicated, self-contained instruction
file in this folder intended to be handed to a **separate Sonnet agent**. This README defines
the **ordering, dependencies, review gates, shared standards, and rollback strategy** that tie
those four tasks together.

> Read this file first. Do **not** start any task file out of order — the sequence exists
> because of hard data dependencies (everything that stores data depends on the database
> layer, and the reverse proxy depends on the final backend topology).

---

## 1. Goals

1. Containerize **WordPress** under Podman (parity with the existing Ampache pattern).
2. ~~Containerize Airsonic Advanced~~ **SKIPPED (owner decision 2026-08-03)** — consolidating
   on **Ampache** as the single music server; Airsonic is being decommissioned separately,
   shortly, outside this plan. See §8a.
3. Migrate the web tier from **Apache httpd → Nginx**, preserving all current behavior
   (notably the Home Assistant reverse proxy incl. WebSocket/`/api/hassio_ingress` forwarding),
   discarding dead/disabled vhosts (**including the now-retired `musicbox.jackson-brain.com`**
   — Ampache moves to `music.jackson-brain.com` instead, see §8a), tightening security, and
   correctly re-integrating Let's Encrypt certificate renewal.
4. Containerize / consolidate the **system MariaDB** into a **shared, secure, resilient**
   database container that serves WordPress and Ampache (Airsonic's schema was still imported
   at Task 01 time but Airsonic itself won't be containerized — harmless, unused going forward).

Cross-cutting priority: **security** and **no unplanned downtime / data loss**. Every phase is
reversible until its review gate passes.

---

## 2. Verified current state (discovered live, 2026-08-03)

This is the ground truth the task files build on. Agents must **re-verify** before changing
anything (state may have drifted), but this is the starting map.

| Component | Reality on the box | Notes |
|---|---|---|
| **System MariaDB** | `mariadb.service` **10.11**, native, `127.0.0.1:3306` | Hosts **`wordpress`** and **`airsonic`** databases. Admin via phpMyAdmin (localhost only). |
| **Ampache** | Podman: `ampache` + `ampache-mariadb` (`mariadb:lts`), port `127.0.0.1:8081` | Already containerized. Has its **own** DB container. compose at `/opt/ampache`. |
| **Airsonic Advanced** | Native systemd Java, `/var/airsonic`, port `*:8080`, user `airsonic` | **Uses the system MariaDB** (`jdbc:mariadb://localhost:3306/airsonic`), *not* HSQLDB despite `DatabaseConfigType=embed`. Shares `/media/Music` + `/var/playlists`. `airsonic.properties` holds plaintext DB pw + JWT + encryption keys (keep `600`). |
| **WordPress** | RPM at `/usr/share/wordpress`, vhost DocumentRoot `/var/www/vhosts/jackson-brain.com` | Uses system MariaDB `wordpress` DB. Actual file layout / `wp-config.php` location must be reconciled by the agent. |
| **Web tier** | Apache `httpd`, TLS-terminating reverse proxy | Active vhosts: `jackson-brain.com` (WordPress), `music` (airsonic:8080 — **being retired, see §8a: Ampache takes over `music.jackson-brain.com`**), `musicbox` (ampache:8081 — **being retired, merged into `music`**), `ha` (Home Assistant, **wss** `/api/websocket` + `/api/hassio_ingress`, self-signed backend `homeassistant.local`, `SSLProxyVerify none`). Many **dead** vhosts (chat, jhgm\*, jitsi, music-v3) → discard. |
| **TLS / Certbot** | Let's Encrypt; renewal `authenticator = apache`, `installer = apache` | ⚠️ `certbot-renew.timer` is **disabled/inactive** — auto-renewal is not running via the timer. Per-domain certs in `/etc/letsencrypt/live`. Switching to Nginx **will break** the `apache` authenticator — renewal must move to `--nginx` or `webroot` + deploy-hook. |
| **Host** | firewalld (80/443 open), SELinux **Enforcing**, Cockpit `:9090` (`cockpit-podman`) | `/` only ~12 GB free (tight) — put bulk data on `/storage` (1.3 TB). |

**Port map (current):** `80/443` Apache · `8080` Airsonic (native, staying put — see §8a; not
being containerized) · `8081` Ampache · `3306` system
MariaDB · `9090` Cockpit · `9091` Transmission · `11211` memcached · `19999` Netdata.

---

## 3. Target architecture

```
                          Internet (v4 + v6)
                               │  80/443
                               ▼
                 ┌─────────────────────────────┐
                 │  Nginx (TLS termination)     │  ← reverse proxy, security headers,
                 │  host cert mounts + renew    │    HSTS, modern ciphers, HA wss passthrough
                 └─────────────────────────────┘
                    │           │            │
        wordpress:80│  ampache  │  HA (wss, self-signed backend on LAN)
                    ▼   :80 ▼      ▼  homeassistant.local
        ┌───────────┐ ┌─────────┐
        │ wordpress │ │ ampache │   (Podman app containers; Airsonic NOT containerized —
        └─────┬─────┘ └────┬────┘    owner is decommissioning it separately, see §8a)
              │            │
              │  db "proxy"/frontend network (published ports, per app)
              │            │
        ══════╪════════════╪══════════════════════ internal "db-backend" network (NO host port)
              ▼            ▼
                 ┌─────────────────────────────┐
                 │  shared MariaDB container    │  databases: wordpress · ampache · (airsonic,
                 │  (mariadb:lts)               │  imported but unused per §8a)
                 │  volume on /storage; backups │  per-db least-privilege users
                 │                              │  mariadb-dump timer → /storage/backups/mysql
                 └─────────────────────────────┘
```

Key network principle: the shared DB container is reachable **only** on an internal Podman
network shared with the app containers — it publishes **no host port** to the LAN or internet.
Each app container attaches to both the internal DB network and (if it needs inbound web
traffic) a front network where its HTTP port is published to `127.0.0.1` for Nginx to proxy.

---

## 4. Database architecture decision (DECIDED: one shared container)

**Decision (confirmed by owner 2026-08-03): one shared MariaDB container**, with a basic
performance review + tuning that uses the host's available memory (see Task 01 §"Performance
review & tuning"). Research summary that backs this:

**Two mainstream patterns:**

| | Shared single MariaDB instance (multiple DBs) | One DB container per app (sidecar, current Ampache) |
|---|---|---|
| Memory / overhead | ✅ Lowest (one buffer pool) | ❌ N× InnoDB buffer pools |
| Isolation / blast radius | ⚠️ One instance = shared fate; a crash/upgrade affects all | ✅ Fully independent lifecycle & version |
| Ops (backup, tuning, patching) | ✅ One place | ❌ N places |
| Version coupling | ❌ All apps share one MariaDB version | ✅ Per-app version |
| Security | ✅ Achievable with per-DB least-priv users + internal-only network | ✅ Naturally partitioned |

**Recommendation for this host (16 GB RAM, single-node home server, priority = simplicity +
resilience + security): ONE shared `mariadb:lts` container** serving three databases
(`wordpress`, `ampache`, `airsonic`), because:
- Resource efficiency matters on a single 16 GB node running many services.
- Consolidation is an explicit user goal ("this can/should be combined").
- Resilience is delivered through **backups + healthcheck + `restart` policy + a tested
  restore procedure**, not through running redundant DB engines (which a single-node box can't
  truly make HA anyway).
- Security is delivered through **per-database least-privilege users** (each app user granted
  only its own schema), an **internal-only Podman network** (no published port), and secrets
  in `600` env files.

**Scope note (owner):** the only data worth migrating out of the current host-package MariaDB is
the **`wordpress`** and **`airsonic`** databases — ignore any other schemas there. Ampache's
data comes from its existing `ampache-mariadb` container. **phpMyAdmin is being dropped** (owner:
no need to preserve it or its access), so DB administration is via `podman exec` / CLI only — the
shared DB never needs a host-reachable port.

**Non-negotiable safeguards baked into the DB task:**
- Distinct DB user per app, each `GRANT`ed on **only** its own database (no shared/`root` use
  by apps).
- Shared DB container has **no published host port** — internal Podman network only.
- Automated logical backups (`mariadb-dump --single-transaction`) on a systemd timer to
  `/storage/backups/mysql`, with retention + a **documented, tested restore**.
- Data volume on `/storage` (not `/`, which is tight), correct SELinux labels.
- Old system MariaDB + `ampache-mariadb` data **retained read-only as rollback** until Gate E.
- **Basic performance review + tuning** sized to available RAM (buffer pool, connections) — this
  is an explicit owner requirement, detailed in Task 01.

---

## 5. Sequencing, dependencies & review gates

```
Phase 1  Shared MariaDB          ──► GATE A ─┐
(01-shared-mariadb.agent.md)                 │ DB layer stable, all apps read/write it,
                                             │ backups+restore proven, rollback data kept
                                             ▼
Phase 2  WordPress container     ──► GATE B ─┐  (depends on Phase 1 DB)
(02-wordpress-container.agent.md)            ▼
Phase 3  SKIPPED (Airsonic)      ──►  n/a    ─┐  owner decision 2026-08-03: consolidating
(03-airsonic-container.agent.md, skipped)    ▼  on Ampache instead — see §8a
Phase 4  Apache → Nginx          ──► GATE D ─┐  (depends on final ports of Phases 1–2;
(04-apache-to-nginx.agent.md)                ▼   Ampache takes over music.jackson-brain.com)
Phase 5  Soak & decommission     ──► GATE E     (remove Apache/native services, delete rollback data)
```

**Why this order:**
- **DB first** — WordPress and Ampache both persist to MariaDB. Standing up the shared DB first
  means each app is pointed at its final endpoint exactly once.
- **WordPress before Nginx** — WordPress is the simpler, lower-risk containerization and
  validates the "app container ↔ shared DB" pattern before the internet-facing web-tier swap.
- **Airsonic containerization skipped** (owner decision 2026-08-03) — the owner is
  consolidating on **Ampache** as the sole music server and decommissioning Airsonic separately,
  shortly, outside this plan. See §8a for what this changes.
- **Nginx last** — the reverse proxy should be cut over once the upstream ports are final, so
  the cutover is a single clean change and the security review covers the finished topology.
  Nginx runs as a **host package** (owner decision), not a container — this keeps Let's Encrypt
  renewal simple (`certbot --nginx`) and proxies to the `127.0.0.1:<port>` endpoints the app
  containers publish.

### Review gates — what "review progress and look for breakage" means at each stop

Each gate is a **hard stop**: the agent finishes its task file, runs the verification checklist,
writes a short status report, and **waits for human review before the next phase begins**.
Keep the superseded component in place (stopped, not deleted) for fast rollback during a soak.

| Gate | Verify green before proceeding | Rollback if red |
|---|---|---|
| **A** (post-DB) | All DBs migrated & queryable in the shared container; Ampache still streams (`/rest/stream.view` 200); WordPress site + admin load; backup timer ran; **restore tested** into a scratch DB. | Repoint apps back to system MariaDB / `ampache-mariadb` (untouched rollback data). |
| **B** (post-WordPress) | Site, admin login, media uploads, permalinks, plugins all work via container; TLS still served by Apache; DB writes land in shared DB. | Re-enable native `/usr/share/wordpress` + Apache alias; stop WP container. |
| ~~C~~ | **SKIPPED** — Airsonic containerization not happening (owner decision). | — |
| **D** (post-Nginx) | **Every** vhost: WordPress, `music` (**now Ampache**, taking over from the retired Airsonic vhost and the retired `musicbox`), `ha` (**incl. `/api/websocket` + `/api/hassio_ingress` WebSocket upgrade**), HTTP→HTTPS redirect, HSTS/headers, TLS grade; fail2ban jails active against Nginx logs; **certbot renewal dry-run succeeds** with the new `nginx` authenticator + reload; `certbot-renew.timer` re-enabled. | `systemctl stop nginx && systemctl start httpd` (Apache kept installed, config intact). |
| **E** (soak/cleanup) | ≥ several days stable; renewal timer fires a real/dry renewal; backups rotating. Only now: `dnf remove`/disable superseded pieces (**including native Airsonic, once the owner separately decommissions it**), delete rollback DB data, remove dead vhosts. | N/A — final. |

---

## 6. Shared standards (every task file inherits these)

**Server access & etiquette**
- Admin work uses `ssh linus` (`jack@192.168.88.251`, sudo). Read-only diagnostics can use the
  `linux-mcp` MCP tools (user `mcp-copilot`, no sudo).
- **SSH connection rate limit: ~3 new connections/min.** Open **one** persistent `ssh -t linus`
  session at the start of a task (run `sudo -v` once) and reuse it for every command via that
  same terminal. Do **not** spawn a fresh `ssh` per command. If the passphrase-protected key is
  in play, set up `ssh-agent` first. Never send secrets through chat — only the terminal's own
  prompt.

**Container conventions (match the Ampache pattern in `ampache/`)**
- Rootful Podman (system `podman.socket`), managed by a **systemd unit per stack**, visible in
  Cockpit. Reuse `podman compose`.
- Bind mounts use SELinux relabel flags (`:z` shared / `:Z` private). Verify with `ls -laZ`.
- Pin image tags to a major version; never `:latest`. Set `restart: unless-stopped`,
  `healthcheck`, memory `limits`, and `logging` caps (json-file, max-size/max-file) as Ampache does.
- Secrets live in a `600` `.env` file (or Podman secrets), **never** committed. Repo ships a
  `.env.template` only. Generate values with `openssl rand`.
- Bulk/persistent data on **`/storage`** (1.3 TB), never `/` (~12 GB free).

**Repo deliverables (this repo is source-of-truth, deployed manually)**
- Each task adds/updates a service folder mirroring `ampache/`: `docker-compose.yml`,
  `.env.template`, `<service>.service`, `setup.sh`, and a `README.md` documenting deploy +
  troubleshooting. Update the root `README.md` service table and repo memory
  (`/memories/repo/fedora-server-config.md`) when done.
- Do **not** commit real secrets, certs, or DB dumps.

**Safety**
- Nothing destructive (dropping the old DB, `dnf remove` of superseded packages, deleting
  rollback data, removing dead vhosts) happens before **Gate E**.
- Take a fresh backup immediately before any data migration.
- Verify every change with a genuinely fresh request/connection, not a cached/established one.

**Firewall & fail2ban (owner: consider in every relevant task)**
- Host uses **firewalld** (zone `FedoraServer`, bound to `eno1`); `80/443` already open. App
  containers publish only to `127.0.0.1:<port>` (Nginx proxies them) — do **not** open new
  public ports. The shared DB publishes **no** port. Let Podman's netavark integration manage
  its own bridge/NAT rules (no manual zone edits needed).
- **fail2ban** is active with `banaction = firewallcmd-rich-rules` (bans via firewalld) and
  `ignoreip = 127.0.0.1/8 ::1 192.168.88.0/24` (LAN whitelisted). The `sshd` jail is enabled.
  There is an existing `[wordpress]` jail that is **currently misconfigured** (stale
  `iptables-multiport` action instead of the firewalld banaction, and no `logpath` override so
  it reads `/var/log/secure` where WordPress auth failures never appear). Task 04 fixes this to
  read the Nginx WordPress access log and use the firewalld banaction, and adds Nginx-oriented
  jails. Keep the LAN whitelist so admin/testing from `192.168.88.0/24` is never banned.

---

## 7. Risk register

| Risk | Mitigation |
|---|---|
| DB migration data loss | Backup before migrate; keep old data read-only until Gate E; test restore at Gate A. |
| Ampache hairpin/NAT + SELinux regressions when moving its DB | Follow the existing Docker→Podman checklist in `ampache/README.md`; keep `extra_hosts`. |
| Cert renewal silently broken after Nginx swap | Task 04 must reconfigure certbot (authenticator `apache`→`nginx`), **re-enable `certbot-renew.timer`**, and prove it with `certbot renew --dry-run`. |
| HA WebSocket proxy breakage | Explicit `/api/websocket` + `/api/hassio_ingress` `Upgrade`/`Connection` map in Nginx; test live WS upgrade at Gate D. |
| SSH lockout / rate-limit thrash | One persistent session per task; firewalld/fail2ban aware. |
| `/` filling up | All new volumes/backups on `/storage`. |
| Music Assistant (HA add-on) breaks after `musicbox` retirement | Its Subsonic provider currently points at `musicbox.jackson-brain.com`; owner must manually repoint to `music.jackson-brain.com` after Task 04 (§8a) — flag clearly, can't be fixed from this repo. |

---

## 8. Decisions (settled by owner 2026-08-03)

1. **DB topology** — ✅ **one shared MariaDB container** (multiple databases, per-DB least-priv
   users), plus a basic performance review + tuning sized to available RAM (Task 01).
2. **Nginx placement** — ✅ **host-package Nginx** (`dnf install nginx`), using `certbot --nginx`
   for renewal; proxies to the `127.0.0.1:<port>` endpoints published by the app containers.
3. **phpMyAdmin** — ✅ **dropped** — not preserved, no admin path required. Shared DB admin is via
   `podman exec` / CLI only.
4. **Source data scope (Task 01)** — ✅ only the **`wordpress`** and **`airsonic`** databases from
   the host-package MariaDB matter; ignore other schemas. Ampache data comes from its
   `ampache-mariadb` container.
5. **Airsonic DB** — ✅ **move the `airsonic` DB into the shared container** (owner-confirmed
   2026-08-03). Task 01 already imported the `airsonic` schema during migration; now unused
   since Airsonic itself won't be containerized (§8a), but harmless to leave imported.

## 8a. Airsonic containerization SKIPPED — consolidating on Ampache (owner decision 2026-08-03, round 3)

**Task 03 (Airsonic containerization) is SKIPPED.** The owner is consolidating on **Ampache**
as the sole music server; Airsonic is being shut down and decommissioned **separately, shortly,
outside this plan** — not now, and not as part of Gate E either (that's a manual step the owner
will do on their own timeline).

**What changes as a result:**
- **`music.jackson-brain.com` now belongs to Ampache** (replacing Airsonic as that vhost's
  backend) in Task 04's Nginx migration.
- **`musicbox.jackson-brain.com` is retired** (owner-confirmed) — Ampache no longer needs two
  hostnames. Task 04 discards it like the other dead vhosts (chat, jhgm\*, jitsi, music-v3).
- Ampache's own self-referential config must move from `musicbox.jackson-brain.com` to
  `music.jackson-brain.com`: `ampache.cfg.php`'s `local_web_path`, and the `extra_hosts`
  NAT-hairpin-fix entry in `ampache/docker-compose.yml` (see `ampache/README.md` for why this
  entry exists — without it, `/rest/stream.view` hangs indefinitely). **This must happen
  atomically with Task 04's Apache vhost cutover, not before** — until the `music.jackson-brain.com`
  vhost actually points at Ampache, that hostname still resolves (via Apache) to Airsonic;
  flipping Ampache's own config early would break its hairpin fix immediately (self-call would
  hit the wrong backend). Task 04 must do both halves (vhost + Ampache config) in the same
  cutover window and verify streaming immediately after.
- **External dependency the owner must update manually**: Home Assistant's **Music Assistant**
  add-on has its Subsonic provider configured against `musicbox.jackson-brain.com` — this needs
  to be repointed to `music.jackson-brain.com` once Task 04 lands (can't be done from this repo;
  it's HA-side add-on configuration).
- Task 01's shared MariaDB still imported the `airsonic` schema (already done, harmless) — it's
  simply unused going forward. Not worth reverting.
- `03-airsonic-container.agent.md` is left in place but marked SKIPPED at the top, for history.

---

## 9. Task file index

| Order | File | Owner scope | Depends on |
|---|---|---|---|
| 1 | [01-shared-mariadb.agent.md](01-shared-mariadb.agent.md) | Shared MariaDB container, migrate `wordpress`/`airsonic`/`ampache` DBs, backups | — |
| 2 | [02-wordpress-container.agent.md](02-wordpress-container.agent.md) | Containerize WordPress | Phase 1 |
| ~~3~~ | ~~[03-airsonic-container.agent.md](03-airsonic-container.agent.md)~~ | **SKIPPED** (owner decision 2026-08-03) — consolidating on Ampache, see §8a | — |
| 4 | [04-apache-to-nginx.agent.md](04-apache-to-nginx.agent.md) | Apache → Nginx, TLS/renewal, security; Ampache takes over `music.jackson-brain.com`, `musicbox` retired | Phases 1–2 |

---

## 10. Gate E report — soak/decommission + full Airsonic removal (2026-08-03)

Executed same-day as Gate D, at the owner's explicit request (an acceleration of the
"owner's own timeline" language in §8a — the owner asked for this rather than deferring it).

**Safety backups taken first**, all in `/storage/backups/decommission-2026-08-03/` on the
server: a fresh `mariadb-dump` of the still-live native `airsonic` schema, and tarballs of
`/var/airsonic` + `/home/airsonic`, `/etc/httpd`, and the system `/var/lib/mysql` data
directory (this last one also preserves several long-dead pre-2021 legacy schemas found during
discovery — see below).

**Airsonic — fully decommissioned** (not just left running, per owner's expanded request):
- `airsonic.service` stopped, disabled, unit file removed.
- Native `airsonic` MariaDB schema dropped (via its own credentials); the shared-mariadb
  container's `airsonic` schema also dropped (a Gate-A verification-only copy that never had
  live traffic, since Task 03 was skipped).
- `/var/airsonic`, `/var/podcast` (found empty), `/home/airsonic` deleted.
- OS user/group `airsonic` (uid 1007/gid 1009) removed via `userdel -r`; verified no other
  stray files owned by that uid/gid existed anywhere on the host (`find` across all
  filesystems) except one stray root-owned vim backup of `airsonic.properties` (deleted; the
  parent `/root/.vim/backup/` directory itself is a much larger, decades-spanning stash of
  config secrets unrelated to this task — flagged separately, not touched).
- `/media/Music`'s group ownership moved from `airsonic` to `jack` (cosmetic — the directory
  was already world-readable `755`, so this changes nothing about actual access; Ampache's
  container has no explicit `user:` override and never relied on the `airsonic` group).
- Its Java runtime (`java-25-openjdk-headless` + `crypto-adapter`, confirmed via `/proc/<pid>/exe`)
  removed, along with an unrelated ~2022-era Java 21 + Maven dev toolchain found alongside it
  (owner-approved removal, confirmed via `dnf repoquery --whatrequires` that nothing else
  needed it).

**System MariaDB — fully removed** (not just Airsonic's schema): once WordPress (Gate B) and
now Airsonic were both off it, nothing on the host used it any more (the shared-mariadb backup
script only talks to its own container via `podman exec`, confirmed before removal). Also
uncovered and removed **four long-dead legacy schemas predating this repo** (`musicbox`,
`musicbox@dev`, `musicbox@five`, `mbfive_totlarun` — 2019–2021 era, ~1.5GB total, dominated by
a 1.4GB `musicbox@dev` schema) that had nothing to do with the current Ampache/Airsonic setup.
`mariadb-server` (+ its SELinux policy module, unused Perl driver) removed via `dnf remove`
after a `--assumeno` dry-run confirmed no live-service collateral; kept the `mariadb`/
`mariadb-client-utils`/connector packages for ad-hoc host CLI diagnostics against the container.

**Apache — fully removed**: `dnf remove httpd` (+ `httpd-core`/`-filesystem`/`-tools`/
`fedora-logos-httpd`), `/etc/httpd` deleted after archiving. Also cascade-removed (as genuine
dependents, not collateral damage — verified via `dnf history info`): the leftover native
WordPress RPM and `phpMyAdmin` RPM (both long superseded, `phpMyAdmin` was an explicit owner
decision to drop back in Task 01), `php-fpm`, `mod_ssl`/`mod_geoip`/`mod_http2`/`mod_lua`,
`python3-certbot-apache`, and `nut-cgi`/`nut-devel` (NUT's core `nut-server`/`nut-monitor`
daemons were unaffected — verified active afterward).

**⚠️ Incident during this step (caught and fixed within minutes):** the same `dnf remove`
transaction also pulled out the **live `nginx` package** as an unrelated dependency cascade
(its "install reason" wasn't pinned to `User`), SIGABRT-killing `nginx.service` and causing a
brief real outage across all three vhosts. Fixed: `dnf install nginx`, restored
`/etc/nginx/nginx.conf` from the `.rpmsave` copy the removal left behind (`conf.d/`/`snippets/`
were untouched since they're unpackaged files), `nginx -t` clean, `systemctl enable --now
nginx`, verified 200 on all three vhosts within ~2 minutes of the failure. Re-installing nginx
by itself also correctly re-pinned its dnf "reason" to `User`, protecting it from a repeat.
**Lesson applied for the rest of this pass:** ran `dnf remove --assumeno` before the
`mariadb-server` removal and read the full list before confirming — no further incidents.

**Dead vhosts, certs, and rollback data cleaned up:**
- Dead vhost configs (`chat`, `jhgm*`, `jitsi`, `music-v3*`, `musicbox*`, the old Airsonic-era
  `music.jackson-brain.com.conf`, `rocketchat`, `rpi`) removed along with the rest of
  `/etc/httpd` (archived first).
- 7 dead Let's Encrypt certs deleted via `certbot delete --cert-name` (`chat`, `jhgm`,
  `jhgm-dev`, `jhgm-pi4`, `jitsi`, `music-v3`, `musicbox`) — these still had
  `authenticator = apache` and would have failed renewal (or hard-errored) once Apache was gone.
  Kept `ha`, `jackson-brain.com`, `music` (live, already migrated to the `nginx` authenticator
  in Task 04).
- `/opt/ampache/mariadb` (2.3GB, orphaned pre-Gate-A bind mount) and
  `/storage/backups/mysql/pre-migrate-*.sql.gz` (~2GB, redundant with the ongoing daily backup
  rotation) deleted.
- `database/backup/mariadb-backup.sh` updated (repo + deployed copy) to stop dumping the
  now-nonexistent `airsonic` schema from shared-mariadb.

**Result:** root filesystem went from 83% used (8.9GB free) to 55% used (23GB free) — roughly
14GB reclaimed. Final live-service check after everything (including a full reboot, owner
requested): `nginx`, `wordpress`, `ampache`, `shared-mariadb` all active; `httpd`, `mariadb`,
`airsonic` all gone/inactive; all three public vhosts verified 200 post-reboot.

**Deliberately left alone (out of scope for this pass, flagged for the owner):**
- `lighttpd` was removed (owner-approved, was inactive/disabled and unrelated to this repo).
- `/root/.vim/backup/` is a decade-spanning stash of `vim` swap-backups for nearly every config
  ever touched on this host (many containing live secrets — SSH keys, DB passwords, current
  `sshd_config`, `ddclient.conf`, etc., alongside genuinely ancient/dead-service files). This is
  a much bigger, separate hygiene topic than today's task and touches files for services still
  in active use — not touched beyond the one Airsonic-specific file, but worth a dedicated
  review pass if the owner wants it addressed.
- `fedora-logos-httpd` (branding-only noarch package) was removed then transitively reinstalled
  by the nginx recovery step; harmless (`rpm -q --whatrequires` shows nothing needs it), left
  as-is rather than risk another `dnf remove` for a purely cosmetic package.
