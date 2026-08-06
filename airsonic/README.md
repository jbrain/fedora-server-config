# AirSonic Advanced — DECOMMISSIONED (2026-08-03)

> Airsonic has been **fully removed** from this host. Ampache is now the sole music server
> (`https://music.jackson-brain.com`, see `../ampache/`). This file is kept for historical
> reference only.

## What was removed (2026-08-03)

- `airsonic.service` (native systemd, `java -jar /var/airsonic/airsonic.war`) — stopped,
  disabled, unit file deleted.
- `/var/airsonic` (config/DB-cache/logs/search-index), `/var/podcast` (empty), `/home/airsonic`.
- System MariaDB `airsonic` schema (dropped via its own credentials) and the shared-mariadb
  container's `airsonic` schema (a Gate-A verification copy that never carried live traffic —
  Task 03 containerization was skipped, see `../plans/containerization-2026-08/README.md` §8a).
- OS user/group `airsonic` (uid 1007/gid 1009) via `userdel -r`.
- The Java runtime it ran on (`java-25-openjdk-headless` + `crypto-adapter`) and an unrelated
  ~2022-era Java 21 + Maven dev toolchain found alongside it (owner-approved removal).
- `/media/Music`'s group ownership was `jack:airsonic` — rechowned to `jack:jack` (cosmetic
  only; the directory is `755`, so "other" already had read access and Ampache's container
  never relied on the `airsonic` group).

**Final safety backups** (in case of disaster recovery) live in
`/storage/backups/decommission-2026-08-03/` on the server: a fresh `mariadb-dump` of the live
`airsonic` schema, and tarballs of `/var/airsonic` + `/home/airsonic` and (from the same pass)
`/etc/httpd` and the system `/var/lib/mysql` data directory (which also captured several
long-dead legacy schemas from 2019-2021 that predate this repo, deleted in the same pass).

## Historical config (for reference)

- Ran as user/group `airsonic`, JVM heap `-Xmx700m`, context path `/`, port `8080`.
- DB: despite `DatabaseConfigType=embed`, actually used the **system MariaDB**
  (`jdbc:mariadb://localhost:3306/airsonic`), not HSQLDB.
- Shared `/media/Music` with Ampache; `PlaylistFolder=/var/playlists` (this path never actually
  existed on disk — likely never used).
- Was proxied via Apache at `https://music.jackson-brain.com` until the 2026-08-03 Nginx
  cutover (Task 04), after which `music.jackson-brain.com` pointed at Ampache instead and
  Airsonic had no public vhost at all, pending this decommission.
