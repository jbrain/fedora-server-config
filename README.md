# fedora-server-config

Configuration and deployment files for **jackson-brain.com** (`192.168.88.251`), a Fedora 43 home server.

## Server Overview

| Item | Value |
|---|---|
| Hostname | `jackson-brain.com` |
| LAN IP | `192.168.88.251` |
| OS | Fedora Linux 43 |
| Kernel | 7.1.7-100.fc43.x86_64 |
| CPU | Intel Xeon E3-1271 v3 @ 3.60GHz (4C/8T) |
| RAM | 16 GB |
| Boot disk | 119 GB Kingston SSD (LVM: `/` 50 GB, `/home` 65 GB, swap 4 GB) |

## Storage

Both data volumes are presented by an **LSI MegaRAID MR9260-4i** controller.

| Device | Size | Mount | Usage |
|---|---|---|---|
| `/dev/sdb1` | 931 GB | `/media` | Music (591 GB), Pictures, Video |
| `/dev/sdc1` | 500 GB | `/samba_workstation` | Workstation Samba share |
| `/dev/sdc2` | 1 TB | `/samba_backups` | Backup Samba share |
| `/dev/sdc3` | 1.3 TB | `/storage` | General storage |

## Services

| Service | Port | URL | Notes |
|---|---|---|
|---|
| Nginx | 80 / 443 | — | Reverse proxy for all web services (host package, replaced Apache 2026-08-03; Apache fully removed 2026-08-03 Gate E) |
| WordPress | 8082 (local) | `https://jackson-brain.com` | Podman container, custom-built image `localhost/wordpress:6.9.6-php8.4-apache` (official `6.9.4-php8.4-apache` base + real 6.9.6 core, since Docker Hub has no official image for 6.9.5/6.9.6/7.0.3 yet), migrated from native RPM 2026-08. DB on shared MariaDB. Hardened 2026-08-08 after a real compromise (see `wordpress/README.md`). Jackson Brain Ampache Integration plugin live. |
| Ampache | 8081 (local) | `https://music.jackson-brain.com` | Podman container `ampache` (migrated from Docker 2026-07-16), managed by systemd; DB on the shared MariaDB container. Sole music server as of 2026-08-03 — `musicbox.jackson-brain.com` retired, AirSonic fully decommissioned (2026-08-03, see `airsonic/README.md`). |
| Shared MariaDB | internal only (no published port) | — | Podman container `shared-mariadb` (`database/`), hosts `wordpress`/`ampache` schemas on the internal `db-backend` network (the `airsonic` schema was dropped 2026-08-03 — never carried live traffic); admin via `podman exec` only (no phpMyAdmin) |
| Memcached | 11211 (local) | — | — |
| Netdata | 19999 | `http://192.168.88.251:19999` | System monitoring |
| NUT (ups) | 3493 (local) | — | UPS monitoring via USB |
| Home Assistant | proxied | `https://ha.jackson-brain.com` | Proxied to `homeassistant.local` via Nginx |
| Transmission | 9091 (LAN), 51413 (peer) | `http://192.168.88.251:9091/transmission/web/` | BitTorrent daemon, **disabled at boot** — start manually with `systemctl start transmission-daemon` |

## SSH Access

| User | Purpose | Key |
|---|---|---|
| `jack` | Admin / sudo | `~/.ssh/id_rsa` |
| `mcp-copilot` | GitHub Copilot MCP agent | `~/.ssh/fedora_mcp_ed25519` |

SSH config aliases (`~/.ssh/config`):
- `linus` → `jack@192.168.88.251`
- `linus-mcp` → `mcp-copilot@192.168.88.251`

## Repository Structure

```
fedora-server-config/
├── nginx/                    Live web tier (host-package Nginx, replaced Apache 2026-08-03)
├── apache/                   REMOVED 2026-08-03 (Gate E) — historical docs/rollback notes only
│   └── vhosts/
├── airsonic/                 DECOMMISSIONED 2026-08-03 — historical docs only, see README
├── wordpress/                Containerized WordPress (official image), DB on shared MariaDB
├── podman-auto-update/       Host-wide Podman image auto-update (daily timer + dnf hook)
├── ampache/                  Ampache container deployment (DB on shared MariaDB, see database/)
│   ├── docker-compose.yml
│   ├── .env.template
│   ├── ampache.service
│   ├── ampache.jackson-brain.com.conf
│   └── setup.sh
├── database/                 Shared MariaDB container (wordpress/airsonic/ampache schemas)
│   ├── docker-compose.yml
│   ├── .env.template
│   ├── conf.d/, initdb.d/, backup/
│   └── setup.sh
├── plans/containerization-2026-08/  Phased containerization + Nginx migration plan (in progress)
├── shorewall/                Firewall — migrated Shorewall 5.2.8 -> firewalld (2026-07-16)
├── transmission/              BitTorrent daemon docs (disabled at boot, manual start)
└── mcp/                      GitHub Copilot MCP server setup
```

## Known Issues

- `usbhid-ups` logs repeated `nut_libusb_get_report: Input/Output Error` — the NUT daemon is losing its USB connection to the UPS. Monitor and investigate USB bus stability.
- **Migrating a containerized service between Docker and Podman on this host can silently break
  specific features** (inbound port forwarding via firewall zones, outbound self-referential/NAT
  hairpin calls, SELinux volume labels) without any obvious error — see the "Docker → Podman
  migration checklist" in [ampache/README.md](ampache/README.md) for the full list of what to
  check and how each symptom presents. Docker itself has since been fully removed from this host
  (see Firewall section below) — all container workloads run under Podman.

## Firewall

This host runs **firewalld** (migrated from Shorewall 5.2.8 on 2026-07-16 — see
[shorewall/README.md](shorewall/README.md) for the full history, rationale, and the exact
cutover procedure). Default zone `FedoraServer`, bound to `eno1`. The migration also fixed a
confirmed IPv6 filtering gap (Shorewall was IPv4-only; firewalld's dual-stack `inet` nftables
tables filter IPv6 automatically) and replaced the old manually-maintained Docker/Podman
bridge-glob `dock` zone with firewalld's native Podman integration. SSH brute-force throttling
(replacing Shorewall's old per-source rate limit) is handled by `fail2ban`'s `sshd` jail, which
bans via firewalld rich rules (`fail2ban-firewalld` package). The `shorewall`/`shorewall-core`
packages and the Docker engine (`moby-engine`, `containerd`, etc., including `/var/lib/docker`
data) have both been fully uninstalled as of 2026-07-16.

A transient issue appeared right after the Docker/Shorewall removal — every request to Ampache
took a fixed ~10 seconds (always eventually succeeded, never a true failure) — which a full host
reboot resolved completely. See the Troubleshooting section in
[ampache/README.md](ampache/README.md) for the full diagnostic trail. Music streaming has been
confirmed working normally since the reboot.

**fail2ban jails (as of 2026-08-03, post-Nginx migration):** `sshd`, `wordpress` (fixed — see
[nginx/README.md](nginx/README.md) for the real root cause: it reads `/var/log/messages` via
the `wp-fail2ban` plugin's syslog output, not any web server log), `nginx-http-auth`,
`nginx-botsearch`, `nginx-limit-req` (new). LAN `ignoreip` (`192.168.88.0/24`) preserved on all.

