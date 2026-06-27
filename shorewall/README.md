# Shorewall Firewall Configuration

This server uses **Shorewall 5.2.8** (iptables wrapper) instead of firewalld.

## Zones

| Zone | Interface | Description |
|---|---|---|
| `fw` | — | The firewall host itself |
| `net` | `eno1` | WAN / internet |
| `lan` | `eno2` | LAN (192.168.88.0/24) |
| `dock` | `br-+` (all docker-compose bridges) | Docker container networks |

## Policy summary

| Source | Destination | Policy |
|---|---|---|
| `fw` | all | ACCEPT |
| `lan` | `fw` | ACCEPT |
| `dock` | all | ACCEPT |
| `net` | `fw` | DROP |
| `net` | `lan` | DROP |
| all | all | REJECT (logged) |

## Docker integration

Shorewall has `DOCKER=Yes` set in `shorewall.conf`, which tells it to
cooperate with Docker's own iptables/DNAT rules instead of overwriting them.

The `dock` zone uses `physical=br-+` to match all `br-xxxx` bridge interfaces
created by docker-compose (each compose project gets its own bridge). The
`routeback=1` option is required so containers on the same bridge can reach
each other.

**Without the `dock` zone**, all traffic to/from containers falls through to the
`all → all REJECT` catch-all policy and is silently dropped, even from
`127.0.0.1`. This was the root cause of Ampache not being reachable on port 8081.

## Changes made (2026-06-27)

The following lines were uncommented in the Shorewall config files to enable Docker:

**`/etc/shorewall/zones`** — added:
```
dock ipv4
```

**`/etc/shorewall/interfaces`** — added:
```
dock    br              physical=br-+,routeback=1
```

**`/etc/shorewall/policy`** — added (before the catch-all REJECT):
```
dock              all             ACCEPT
```

Then reloaded with:
```bash
sudo shorewall reload
```

## Reload vs. restart

- `sudo shorewall reload` — recompiles and applies rules, keeps existing connections alive
- `sudo shorewall restart` — tears down and rebuilds all rules, drops existing connections

Always use `reload` for routine changes. Use `restart` only if reload reports errors.

## Known interaction: Shorewall reload wipes Docker iptables chains

When Shorewall reloads (or Docker is restarted), Docker's `DOCKER-FORWARD` iptables
chain can be destroyed, causing `docker compose up` to fail with:

```
iptables --wait -t filter -A DOCKER-FORWARD -i br-xxxx -j ACCEPT: iptables: No chain/target/match by that name.
```

**Fix:** restart Docker to rebuild its iptables chains, then start the affected services:

```bash
sudo systemctl restart docker
sudo systemctl start ampache
```

This can happen after any `shorewall reload` that runs while Docker containers are not
running (e.g., after a reboot or service stop). It does **not** affect running containers.

## Checking current state

```bash
sudo shorewall status
sudo shorewall show connections
sudo shorewall show zones
```
