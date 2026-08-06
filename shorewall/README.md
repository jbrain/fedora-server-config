# Firewall Configuration

**As of 2026-07-16, this server runs `firewalld`, migrated from Shorewall 5.2.8.** The
Shorewall-specific sections below are kept for historical reference (and as a rollback guide,
since the `shorewall` package is still installed but disabled) but no longer reflect the live
configuration. See "Migration to firewalld (2026-07-16)" near the end of this file for the
current setup.

## Why migrate

- **IPv6 was completely unfiltered.** `shorewall6` was never installed on this host, and
  `eno1` carries a public, directly-routable IPv6 address (dual-stack ISP, no NAT). Confirmed via
  `sudo ip6tables -L INPUT -n -v` showing `policy ACCEPT` with zero rules and ~698K packets /
  ~3.9GB having passed through completely unfiltered. Worse, `rpcbind`/portmapper (111) and
  LLMNR (5355) were bound to the IPv6 wildcard (`[::]:111`, `[::]:5355`), not just loopback/LAN
  — meaning they were very likely directly internet-reachable over IPv6. firewalld's default
  nftables backend uses dual-stack `inet` tables, so IPv4 and IPv6 are filtered by the exact same
  ruleset with no separate `shorewall6`-equivalent config to forget.
- **Native Podman zone integration.** Shorewall required a manually maintained `dock` zone with
  interface glob patterns (`br-+` for Docker, `podman+` for Podman) that had to be updated by hand
  every time the container runtime changed — this already caused a real outage once (see the
  "Podman bridge not matched by `dock` zone" section below). firewalld doesn't need this at all.
- Docker itself was fully decommissioned as part of this migration (Ampache had already moved to
  Podman) rather than keeping two container runtimes' worth of firewall/network complexity around.

## Historical Shorewall zones (superseded)

| Zone | Interface | Description |
|---|---|---|
| `fw` | — | The firewall host itself |
| `net` | `eno1` | WAN / internet |
| `lan` | `eno2` | LAN (192.168.88.0/24) |
| `dock` | `br-+` (all docker-compose bridges) | Docker container networks |

> **Important — zone labels don't match physical reality:** As of 2026-07-16, `eno1` (the
> `net` zone) is the interface actually carrying `192.168.88.0/24` (`inet 192.168.88.251/24`),
> while `eno2` (the `lan` zone) has **no IP address configured** and carries no traffic. This
> means all real LAN client traffic is policy-matched as `net → fw`, which defaults to **DROP**
> — not the `lan → fw ACCEPT` policy the table below implies. This is why admin-facing ports
> (NUT `3493`, Netdata `19999`, Cockpit `9090`) are allow-listed as explicit `net:192.168.88.x`
> rules in `/etc/shorewall/rules` rather than relying on the `lan` zone/policy — any new rule
> intended to reach LAN clients must do the same (`net:192.168.88.0/24`, not `lan`).

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

**Podman bridges use a different naming convention (`podman+`, e.g. `podman0`, `podman1`, ...)
than Docker's (`br-+`)** and are NOT matched by the original `dock` zone entry. A second
`dock` interface entry for `physical=podman+` was added on 2026-07-16 (see below) after
migrating Ampache to Podman — without it, Podman container traffic isn't classified into
any zone and hits the `FORWARD` chain's DROP policy, causing connections to the published
port to time out (not "refused" — the host-side listener is up, but forwarded packets are
silently dropped).

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

## Changes made (2026-07-16) — Cockpit (port 9090)

Cockpit (`cockpit.socket`) was installed and enabled, but was unreachable from LAN clients
even though `lan → fw` policy is ACCEPT — because LAN traffic actually arrives via `eno1`/the
`net` zone (see the callout under Zones above), and `net → fw` defaults to DROP with no rule
for 9090. A pre-existing commented placeholder used the wrong zone (`lan`, which carries no
traffic) and would not have worked even if uncommented.

**`/etc/shorewall/rules`** — replaced:
```
#ACCEPT lan fw tcp 9090
```
with (restricting access to the home subnet only, matching the existing NUT/Netdata pattern):
```
ACCEPT net:192.168.88.0/24 fw tcp 9090
```

Then validated and reloaded:
```bash
sudo shorewall check
sudo shorewall reload
```

Verified with `Test-NetConnection 192.168.88.251 -Port 9090` from a LAN Windows client
(`TcpTestSucceeded : True`).

## Changes made (2026-07-16) — Podman bridge not matched by `dock` zone

After migrating Ampache from Docker to Podman (see ampache/README.md), `musicbox.jackson-brain.com`
and `http://127.0.0.1:8081` became unreachable (connection **timed out**, not refused —
`ss -tlnp` showed the port was listening). Cause: Podman's compose network created bridge
`podman1`, but the `dock` zone only matched Docker's `br-+` naming convention, so the traffic
fell through to the `FORWARD` chain's DROP policy — the same class of bug the original `dock`
zone was created to fix, just triggered by a different bridge-naming scheme.

**`/etc/shorewall/interfaces`** — added a second `dock` entry alongside the existing `br-+` one:
```
dock    podman          physical=podman+,routeback=1
```

Then validated and reloaded:
```bash
sudo shorewall check
sudo shorewall reload
```

Verified with `curl http://127.0.0.1:8081` (on the server) and `https://musicbox.jackson-brain.com`
(from a LAN client) both returning `HTTP 302` instead of timing out.

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

## Checking current state (historical Shorewall commands)

```bash
sudo shorewall status
sudo shorewall show connections
sudo shorewall show zones
```

## Migration to firewalld (2026-07-16)

### Current live configuration

firewalld's default zone is `FedoraServer` (pre-existing on this host from the original Fedora
Server install, before Shorewall was chosen on 2026-06-27 — check `/etc/firewalld/zones/*.xml`
for any stale config before assuming a from-scratch setup on any future rebuild). It's bound
automatically to `eno1` via `DefaultZone=FedoraServer` in `/etc/firewalld/firewalld.conf` (the
NetworkManager connection profile's `connection.zone` is left empty, which means "use the
default zone" — no manual interface-to-zone binding was needed).

```
$ sudo firewall-cmd --list-all
FedoraServer (default, active)
  interfaces: eno1
  services: dhcpv6-client http https ssh
  ports: 51413/udp 51413/tcp 9091/tcp
  icmp-blocks: echo-request
  rich rules:
        rule family="ipv4" source address="192.168.88.0/24" port port="9090" protocol="tcp" accept
        rule family="ipv4" source address="192.168.88.253/32" port port="19999" protocol="tcp" accept
        rule family="ipv4" source address="192.168.88.253/32" port port="3493" protocol="tcp" accept
```

This replicates the old Shorewall ruleset 1:1: `ssh`/`http`/`https` unrestricted (matching the
old unrestricted `net fw` rules — note the old per-source SSH rate limit, `SSH(ACCEPT) ... s:1/min:3`,
was **not** replicated here; firewalld rich-rule `limit` is a global token bucket, not
per-source like Shorewall's hashlimit, so it wouldn't be a faithful equivalent — if SSH
brute-force throttling is needed again, use `fail2ban` instead, which is already installed and
active on this host for other jails), Cockpit restricted to the `/24`, NUT and Netdata
restricted to the single monitoring host `192.168.88.253` (not the `/24` — don't conflate the
two), Transmission ports open (NOTE: `transmission-daemon.service` was inactive at migration
time — these may be safe to drop later), and inbound ping blocked via `icmp-block echo-request`.

Podman needs **no equivalent of the old `dock` zone at all** — its netavark backend detects
firewalld automatically and manages its own bridge/NAT rules dynamically; this was verified
working (Ampache reachable through its Podman container) immediately after cutover with zero
Podman-specific firewalld configuration.

### ⚠️ Critical gotcha if you ever need to redo this cutover: `shorewall stop` != `shorewall clear`

This tripped up the actual migration three times. `systemctl stop shorewall` (its `ExecStop`)
only puts Shorewall into a restrictive **stopped** state — a separate, legacy iptables (xtables)
ruleset with `policy DROP` and only `ctstate RELATED,ESTABLISHED` + loopback allowed. It does
**not** remove this ruleset. That legacy table coexists independently alongside firewalld's
nftables `inet firewalld` table (both hook into the same netfilter INPUT chain), and its DROP
policy silently killed every NEW inbound SSH connection even though firewalld's own ruleset
correctly showed `tcp dport 22 accept`. The symptom looked exactly like external
rate-limiting/flakiness — SYN packets visibly arriving on `eno1` via `tcpdump` but no SYN-ACK
ever sent back — and wasted real effort chasing Docker and upstream-router theories before
`sudo iptables -L INPUT -n -v` revealed the actual stale DROP-policy table.

**Correct order:**
```bash
sudo systemctl disable shorewall
sudo shorewall clear          # fully flushes Shorewall's legacy iptables rules to ACCEPT/no-rules
sudo systemctl enable --now firewalld
```
Do **not** run `systemctl stop shorewall` (or anything that triggers its `ExecStop`) after
`shorewall clear` — that re-applies the same restrictive stopped-state ruleset on top and
undoes the clear.

### Safe cutover procedure (verified working)

```bash
# 1. Build the full firewalld config OFFLINE first (firewalld stopped = zero live risk):
sudo firewall-offline-cmd --zone=FedoraServer --remove-service-from-zone=cockpit
sudo firewall-offline-cmd --zone=FedoraServer --add-rich-rule='rule family="ipv4" source address="192.168.88.0/24" port port="9090" protocol="tcp" accept'
# ...(see "Current live configuration" above for the full set)
# NOTE: the flag is --remove-service-from-zone, NOT --remove-service (that one errors with
# "Can't use lokkit options with other options").

# 2. Arm a dead-man's-switch BEFORE touching anything live, so a lockout self-heals:
sudo systemd-run --unit=fw-rollback --on-active=150 \
  --description="Auto-revert to shorewall if firewalld cutover fails" \
  /bin/sh -c 'systemctl stop firewalld; systemctl disable firewalld; systemctl start shorewall'

# 3. Cutover (clear LAST, nothing after it except starting firewalld):
sudo systemctl disable shorewall
sudo shorewall clear
sudo systemctl enable --now firewalld

# 4. Verify IMMEDIATELY with a genuinely FRESH connection from a separate process/session --
#    an already-established session survives either way and proves nothing about new ones.

# 5. If good, cancel the rollback timer right away -- it's real wall-clock time and keeps
#    ticking through however long your verification takes:
sudo systemctl stop fw-rollback.timer
```
Do the whole cutover+verify block in one uninterrupted pass. Long diagnostic detours between
arming the timer and confirming success can eat the whole window and trigger an unexpected
auto-revert mid-investigation.

### Checking current state (firewalld)

```bash
sudo firewall-cmd --state
sudo firewall-cmd --get-active-zones
sudo firewall-cmd --list-all
sudo firewall-cmd --list-all --zone=FedoraServer --permanent   # persisted config
```

