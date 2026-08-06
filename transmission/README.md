# Transmission (BitTorrent daemon)

`transmission-daemon` is a native (RPM-packaged) systemd service used on-demand for
BitTorrent downloads. Unlike AirSonic/Ampache it is **intentionally not enabled at boot** —
start it manually when you need it, stop it when you're done.

## Install

Fedora RPMs (`dnf install transmission-daemon`), version confirmed live 2026-07-17:

```
transmission-4.1.3-1.fc43.x86_64
transmission-daemon-4.1.3-1.fc43.x86_64
transmission-common-4.1.3-1.fc43.x86_64
```

Runs as the dedicated `transmission` system user/group (created by the RPM).

## Paths

| Path | Purpose |
|---|---|
| `/var/lib/transmission/.config/transmission-daemon/settings.json` | Main config (see below) |
| `/var/lib/transmission/.config/transmission-daemon/resume/` | Per-torrent resume state |
| `/var/lib/transmission/.config/transmission-daemon/torrents/` | Added `.torrent` files |
| `/var/lib/transmission/.config/transmission-daemon/blocklists/` | IP blocklist cache |
| `/storage/Transmission/Downloads/` | `download-dir` — moved here 2026-07-17 (see below), owned `transmission:transmission` |

Config is **not tracked in this repo** (it's runtime state, not source-controlled — matches
the pattern for AirSonic's `/var/airsonic/` and Ampache's DB volume). This README documents
the live settings instead.

### Download directory — moved to `/storage` 2026-07-17
The default `download-dir` (`/var/lib/transmission/Downloads`) resolves to the **root
filesystem** (`/dev/mapper/fedora-root`, 50 GB total, only **12 GB free / 77% used**) — not
suitable for BitTorrent downloads, which can easily fill the remaining space and starve the OS.
Moved to `/storage/Transmission/Downloads` on `/dev/sdc3` (1.3 TB, 5% used, 1.3 TB free):
```bash
sudo mkdir -p /storage/Transmission/Downloads
sudo chown -R transmission:transmission /storage/Transmission
# live-apply without restart, then restart once to persist to settings.json:
SID=$(curl -s -D - http://127.0.0.1:9091/transmission/rpc -o /dev/null | grep -i x-transmission-session-id | awk '{print $2}' | tr -d '\r')
curl -s -H "X-Transmission-Session-Id: $SID" -d '{"method":"session-set","arguments":{"download-dir":"/storage/Transmission/Downloads"}}' http://127.0.0.1:9091/transmission/rpc
sudo systemctl restart transmission-daemon
```
Verified: `grep download-dir settings.json` shows the new path, daemon restarted cleanly, Web UI
still returns `200`. SELinux (Enforcing) was checked — `/storage` is `xfs` with the `seclabel`
mount option and the daemon runs as `unconfined_service_t` (no dedicated confined Transmission
policy on this host); a functional write test as the `transmission` user succeeded with zero
AVC denials in `ausearch -m avc`, so no `semanage fcontext`/`restorecon` work was needed.

## systemd Service

Unit file (packaged, not repo-managed): `/usr/lib/systemd/system/transmission-daemon.service`

```ini
[Service]
User=transmission
Type=notify-reload
ExecStart=/usr/bin/transmission-daemon -f --log-level=error
# + standard systemd sandboxing hardening (ProtectSystem, NoNewPrivileges,
#   RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6, SystemCallFilter=@system-service, etc.)
```

A stock Fedora drop-in also applies: `/usr/lib/systemd/system/service.d/10-timeout-abort.conf`
(sets `TimeoutStopFailureMode=abort` — unrelated to Transmission specifically, applies
system-wide to aid debugging services that hang on stop).

**By design this unit stays `disabled`** (confirmed: `Loaded: ... disabled; preset: disabled`).
It will NOT start automatically on boot. Start/stop it manually as needed:

```bash
sudo systemctl start transmission-daemon     # start for a session
sudo systemctl stop transmission-daemon      # stop when done
sudo systemctl status transmission-daemon    # check state
journalctl -u transmission-daemon -f         # follow logs
```

Do **not** `sudo systemctl enable` it unless you deliberately want it to survive reboots —
the disabled state is intentional, not an oversight.

## Ports / Firewall

| Port | Protocol | Purpose | firewalld scope |
|---|---|---|---|
| `51413` | TCP + UDP | BitTorrent peer traffic | Open to the whole internet (`ports:` list) — required for inbound peer connections/DHT to work correctly |
| `9091` | TCP | RPC / Web UI | **LAN-only** (`192.168.88.0/24`), rich rule — restricted 2026-07-17, see below |

Current `FedoraServer` zone config (relevant excerpt):
```
ports: 51413/udp 51413/tcp
rich rules:
    rule family="ipv4" source address="192.168.88.0/24" port port="9091" protocol="tcp" accept
```

### Security fix applied 2026-07-17
The RPC port (`9091/tcp`) was previously in the zone's global `ports:` list — reachable from
the **entire internet**, not just LAN — while `rpc-authentication-required` is `false` in
`settings.json` (see below), relying solely on Transmission's own `rpc-whitelist` IP allowlist
for access control. That's a thin single layer of defense for an internet-facing port. Fixed
by moving `9091/tcp` out of the global `ports:` list and into a LAN-restricted rich rule
(`192.168.88.0/24`), matching the existing pattern used for Cockpit/NUT/Netdata:
```bash
sudo firewall-cmd --permanent --zone=FedoraServer --remove-port=9091/tcp
sudo firewall-cmd --permanent --zone=FedoraServer --add-rich-rule='rule family="ipv4" source address="192.168.88.0/24" port port="9091" protocol="tcp" accept'
sudo firewall-cmd --reload
```
Verified working after reload (Web UI still returns `200` from the LAN).

## Key settings (`settings.json`)

| Key | Value | Note |
|---|---|---|
| `rpc-enabled` | `true` | RPC/Web UI on |
| `rpc-port` | `9091` | |
| `rpc-url` | `/transmission/` | |
| `rpc-bind-address` | `0.0.0.0` | Listens on all local interfaces; access is gated by the whitelist + firewall, not the bind address |
| `rpc-authentication-required` | `false` | No username/password prompt — access control relies on `rpc-whitelist` + firewall instead |
| `rpc-username` / `rpc-password` | `transmission` / *(hashed)* | Set but **unused** while `rpc-authentication-required` is `false`. Set the latter to `true` if you want an additional auth layer (e.g. before ever widening the firewall rule again). |
| `rpc-whitelist-enabled` / `rpc-whitelist` | `127.0.0.1,::1,192.168.88.213` | Only these source IPs may call the RPC API at all |
| `rpc-host-whitelist-enabled` | `true` (list empty) | DNS-rebinding protection — only `Host:` headers matching localhost/the bind IP are accepted |
| `bind-address-ipv4` | `192.168.88.251` | Peer/BitTorrent bind address (the host's LAN IP) |
| `peer-port` | `51413` | Fixed, matches the firewall rule (not randomized) |
| `download-dir` | `/var/lib/transmission/Downloads` | See Paths above — created on first use |
| `blocklist-enabled` | `true` | Uses `https://github.com/Naunter/BT_BlockLists/raw/master/bt_blocklists.gz` — see Blocklist section below |
| `encryption` | `1` (preferred) | Not required, but preferred when available |
| `speed-limit-*-enabled` | `false` | No bandwidth caps configured |

Everything else reviewed and found at sane, standard defaults (queueing, DHT/PEX/LPD all on,
umask `022`, `start-added-torrents: true`).

## Blocklist — configured correctly, but does NOT auto-update (verified 2026-07-17)
`blocklist-enabled: true` with a valid, reachable `blocklist-url`. However, Transmission has
**no built-in schedule** for refreshing it — it only updates when explicitly triggered (Web UI
"Update Blocklists" button, or the RPC `blocklist-update` method / `transmission-remote -b`).
No systemd timer or cron job exists on this host to do that automatically
(`systemctl list-timers --all` and `crontab -l -u transmission` both empty).

**Confirmed stale in practice**: the on-disk blocklist file
(`.../blocklists/blocklist`) had an mtime of **2026-03-21**, ~4 months old, until manually
refreshed during this review:
```bash
SID=$(curl -s -D - http://127.0.0.1:9091/transmission/rpc -o /dev/null | grep -i x-transmission-session-id | awk '{print $2}' | tr -d '\r')
curl -s -H "X-Transmission-Session-Id: $SID" -d '{"method":"blocklist-update"}' http://127.0.0.1:9091/transmission/rpc
# -> {"arguments":{"blocklist-size":462214},"result":"success"}, file mtime now current
```
**Decision (2026-07-17): left as manual-only, no timer added** — since the service itself is
intentionally only run on-demand (not enabled at boot), re-run `blocklist-update` manually
(via the command above, `transmission-remote -b`, or the Web UI button) each time before a
longer download session if an up-to-date blocklist matters.

## Using the HTTP RPC / Web UI

### Web UI (browser)
From a LAN machine: **http://192.168.88.251:9091/transmission/web/**
No login prompt (auth disabled) — the page loads directly for any LAN client.

### Raw RPC API (scripting / curl)
Transmission's RPC requires a CSRF-style session id handshake: the first request without a
valid `X-Transmission-Session-Id` header gets `409 Conflict` with the required id in the
response headers; retry the same request with that header attached.

```bash
HOST=192.168.88.251
SID=$(curl -s -D - "http://$HOST:9091/transmission/rpc" -o /dev/null \
  | grep -i x-transmission-session-id | awk '{print $2}' | tr -d '\r')

# Example: list torrents
curl -s -H "X-Transmission-Session-Id: $SID" \
  -d '{"method":"torrent-get","arguments":{"fields":["id","name","status","percentDone"]}}' \
  "http://$HOST:9091/transmission/rpc"

# Example: add a torrent by URL/magnet
curl -s -H "X-Transmission-Session-Id: $SID" \
  -d '{"method":"torrent-add","arguments":{"filename":"magnet:?xt=urn:btih:..."}}' \
  "http://$HOST:9091/transmission/rpc"
```

Any standard Transmission RPC client (e.g. `transmission-remote`, Transmission Remote GUI,
mobile apps) can point at `http://192.168.88.251:9091/transmission/rpc` the same way —
just be on the LAN (or VPN'd into it), since the firewall now blocks 9091 from the WAN.

`transmission-remote` (installed alongside the daemon) works directly from the server itself:
```bash
transmission-remote localhost:9091 -l          # list torrents
transmission-remote localhost:9091 -a URL      # add a torrent
```

## Verified operational (2026-07-17)

- `systemctl start transmission-daemon` → active (running), no errors (one harmless
  first-run `queue.json: No such file or directory` log line — expected before any torrent
  has ever been queued).
- `curl http://127.0.0.1:9091/transmission/web/` → `200`
- RPC session handshake + `session-get` → valid JSON response
- `systemctl is-enabled transmission-daemon` → `disabled` (confirmed will NOT auto-start on
  reboot)
- `systemctl is-active transmission-daemon` → `active` (left running per request after
  verification)
- Blocklist manually refreshed (462,214 rules, was stale since 2026-03-21) — auto-update
  intentionally left unconfigured, see Blocklist section above
- `download-dir` moved from the root filesystem to `/storage/Transmission/Downloads`
  (1.3 TB volume) — see Download directory section above
