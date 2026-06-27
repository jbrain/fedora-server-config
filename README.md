# fedora-server-config

Configuration and deployment files for **jackson-brain.com** (`192.168.88.251`), a Fedora 43 home server.

## Server Overview

| Item | Value |
|---|---|
| Hostname | `jackson-brain.com` |
| LAN IP | `192.168.88.251` |
| OS | Fedora Linux 43 |
| Kernel | 7.0.12-101.fc43.x86_64 |
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
| Apache httpd | 80 / 443 | — | Reverse proxy for all web services |
| AirSonic | 8080 (local) | `https://music.jackson-brain.com` | Java music server, managed by systemd |
| Ampache | 8081 (local) | `https://musicbox.jackson-brain.com` | Two Docker containers (`ampache` + `ampache-mariadb`), managed by systemd |
| Memcached | 11211 (local) | — | — |
| Netdata | 19999 | `http://192.168.88.251:19999` | System monitoring |
| NUT (ups) | 3493 (local) | — | UPS monitoring via USB |
| Home Assistant | proxied | `https://ha.jackson-brain.com` | Proxied to `homeassistant.local` |

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
├── apache/                   Apache vhost configs (source of truth)
│   └── vhosts/
├── airsonic/                 AirSonic service docs and config
├── ampache/                  Ampache container deployment
│   ├── docker-compose.yml
│   ├── .env.template
│   ├── ampache.service
│   ├── ampache.jackson-brain.com.conf
│   └── setup.sh
├── shorewall/                Firewall (Shorewall 5.2.8) — Docker zone docs
└── mcp/                      GitHub Copilot MCP server setup
```

## Known Issues

- `usbhid-ups` logs repeated `nut_libusb_get_report: Input/Output Error` — the NUT daemon is losing its USB connection to the UPS. Monitor and investigate USB bus stability.
