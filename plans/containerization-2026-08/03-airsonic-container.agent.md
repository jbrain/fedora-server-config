# Task 03 — Containerize Airsonic Advanced

> ## ⛔ SKIPPED (owner decision, 2026-08-03) — DO NOT EXECUTE
> The owner is **consolidating on Ampache** as the sole music server. Airsonic is being shut
> down and decommissioned **separately, shortly, outside this plan** — not via this task file.
> `music.jackson-brain.com` now belongs to **Ampache** instead (Task 04 handles this), and
> `musicbox.jackson-brain.com` is retired. See the master plan's **§8a** for the full
> rationale and what changed as a result. This file is kept for historical reference only —
> do not dispatch an agent against it.
>
> **Update 2026-08-03 (same day): Airsonic fully decommissioned as part of Gate E**, ahead of
> schedule at the owner's explicit request — service, `/var/airsonic`, `/var/podcast`,
> `/home/airsonic`, its DB (both native and the unused shared-mariadb copy), the `airsonic`
> OS user/group, and its Java runtime were all removed. See
> [`README.md` §10](README.md#10-gate-e-report--soakdecommission--full-airsonic-removal-2026-08-03)
> for the full report and `../../airsonic/README.md` for what was removed.

> **Agent role:** You are a separate agent executing **Phase 3** of the plan in
> [README.md](README.md). Read the master plan first. **Prerequisite: Gate A is green** (shared
> MariaDB with an `airsonic` database + least-privilege user on `db-backend`). This task can run
> after (or in parallel with) Task 02, but keep its own review gate clean. **Stop at Gate C and
> report.**

## Objective

Replace the native systemd Airsonic Advanced (Java `.war` at `/var/airsonic`, port 8080) with a
**Podman container**, pointed at the **shared MariaDB** `airsonic` database, sharing the
`/media/Music` library with Ampache. Apache still fronts TLS for now (Nginx is Task 04).

## Current state (verified — read carefully)

- Native systemd `airsonic.service` runs `java -jar /var/airsonic/airsonic.war`, `AIRSONIC_HOME=
  /var/airsonic`, port `8080`, `-Dserver.use-forward-headers=true`, user/group `airsonic`.
- **DB:** despite `DatabaseConfigType=embed`, `airsonic.properties` points at the **system
  MariaDB**: `jdbc:mariadb://localhost:3306/airsonic`, user `airsonic`. This is *not* HSQLDB.
  Its data was copied into the shared DB in Task 01 — re-sync if it changed since.
- **State to preserve** (in `/var/airsonic`): the search index/caches, and critically the
  security material in `airsonic.properties` — `JWTKey`, `EncryptionKeyPassword`,
  `EncryptionKeySalt` (losing these invalidates existing sessions / stored credentials). Keep
  the file `600`, owned by the container's runtime user.
- Media/paths shared: `/media/Music` (read library — Ampache mounts it **read-only**; Airsonic
  historically writes an `Incoming`/uploads folder — check `UploadsFolder`), `/var/playlists`.
- Feature surface to keep working: Subsonic API, **Sonos** (`SonosEnabled=true`,
  `SonosServiceName=Musicbox`, `SonosCallbackHostAddress=https://music.jackson-brain.com/`),
  DLNA (UPnP `1900` — see networking note), transcoding via bundled `ffmpeg` commands,
  WebSocket UI, playlist folder, cover-art scanning.

## Design to implement

Create repo folder `airsonic/` additions (the folder already exists with docs) mirroring
`ampache/`:

- `docker-compose.yml` — service `airsonic` using the official
  **`airsonicadvanced/airsonic-advanced:<pinned>`** image:
  - Publish to `127.0.0.1:8080:4040` (Airsonic Advanced listens on `4040` in-container;
    keep the host side on the port Apache proxies — currently `8080`. Verify and keep the
    external contract stable so the `music` vhost keeps working unchanged until Task 04).
  - Attach to the external `db-backend` network (shared DB) + front network.
  - Volumes (SELinux `:z` where shared with Ampache, `:Z` where private):
    - `/media/Music:/media/Music` — **match Ampache's sharing** (Ampache mounts `:ro`). Decide
      whether Airsonic needs write (uploads/`Incoming`); if not, mount `:ro` too. If it does,
      mount the library `:ro` and give uploads a **separate** writable path.
    - `/var/playlists:/var/playlists:z`
    - A persistent Airsonic home volume (config, DB is external now, but search index/caches):
      migrate the relevant bits of `/var/airsonic` into e.g.
      `/storage/airsonic/data:/var/airsonic:Z` (do **not** carry the old `airsonic.war*`
      artifacts — the image provides the app; carry `airsonic.properties` + index/caches).
  - `environment` / properties: set DB to the shared container. Airsonic Advanced reads DB
    config from `airsonic.properties` and/or `SPRING`/`AIRSONIC_` env vars — set
    `spring.datasource.url=jdbc:mariadb://shared-mariadb:3306/airsonic`,
    `spring.datasource.username=airsonic`,
    `spring.datasource.password=${AIRSONIC_DB_PASSWORD}`, and matching
    `DatabaseConfigEmbed*`/`DatabaseConfigType=embed`. Set `-Dserver.use-forward-headers=true`
    equivalent and context path `/`.
  - `restart: unless-stopped`, healthcheck (HTTP 200 on `/`), memory limit (~`768M`, Java heap
    was `-Xmx700m` — set container limit above heap), json-file log caps. Configure Java heap via
    the image's env (e.g. `JAVA_OPTS=-Xmx700m`).
  - **DLNA/Sonos networking:** these use UPnP/SSDP multicast (`1900/udp`) + Sonos callbacks from
    LAN devices. Bridged Podman networking often breaks multicast discovery. If Sonos/DLNA must
    keep working, evaluate `network_mode: host` for the Airsonic container (documenting the
    security trade-off) or a macvlan; otherwise document that DLNA discovery may need host
    networking. **Test Sonos/DLNA explicitly at Gate C.**
- `.env.template` — `AIRSONIC_DB_PASSWORD` (equal to Task 01's `airsonic` DB user password).
- `airsonic.service` — replace/param the existing unit to run `podman compose up` (rootful),
  like `ampache.service`. Keep the old native unit file archived, not deleted.
- `setup.sh` — create `/storage/airsonic/data`, migrate `airsonic.properties` + index/caches
  with correct ownership, install compose/env/unit, enable.
- Update `airsonic/README.md` — new container architecture, DB pointer, Sonos/DLNA networking
  decision, migration + rollback + troubleshooting.

## Migration steps (on the server)

1. **Stop native** `airsonic.service` (brief downtime) and take a final `airsonic` DB dump +
   `tar` of `/var/airsonic` to `/storage/backups/`.
2. Re-sync the `airsonic` schema into the shared DB if it changed since Task 01.
3. Migrate the preserved `/var/airsonic` bits into `/storage/airsonic/data`; edit
   `airsonic.properties` DB URL to `shared-mariadb:3306`.
4. Bring up the container; smoke-test on `127.0.0.1:8080` directly.
5. The existing `music.jackson-brain.com` Apache vhost already proxies `localhost:8080` — confirm
   it still works unchanged. (WebSocket rewrite rules stay.)
6. Verify streaming/transcoding, search rebuild, Sonos/DLNA, playlists, uploads.
7. `systemctl disable` the native unit (kept for rollback); do not `dnf remove`/delete
   `/var/airsonic` until Gate E.

## Shared-library coordination with Ampache (important)

Both Airsonic and Ampache mount `/media/Music`. Ampache mounts it **read-only** with SELinux
`:ro,z`. Ensure your Airsonic mount uses a **compatible** SELinux relabel (`:z` shared, not `:Z`
private — `:Z` would relabel with a private MCS category and could **break Ampache's access**).
Verify `ls -laZ /media/Music` before and after, and re-check Ampache still reads the library
after Airsonic starts. This is the single most likely cross-task breakage — test it.

## Security checklist

- [ ] `airsonic` DB user is least-privilege (own schema only), password in `600` `.env`.
- [ ] `airsonic.properties` (JWT/encryption keys, DB pw) stays `600`, not committed, not logged.
- [ ] Library mounted read-only unless writes are genuinely required; uploads path separated.
- [ ] SELinux relabel flag chosen so it does **not** break Ampache's shared `/media/Music`.
- [ ] `host` networking (if used for DLNA/Sonos) documented with its trade-off and no extra
      ports inadvertently exposed to the internet (firewalld still governs the host).
- [ ] Pinned image tag.

## Verification (Gate C checklist)

- [ ] `https://music.jackson-brain.com` loads; login works (sessions valid → encryption keys
      preserved).
- [ ] Browse library, **stream + transcode** a track, cover art shows.
- [ ] Trigger a scan/index rebuild; catalog matches.
- [ ] **Sonos** and **DLNA** discovery/playback tested (or documented as intentionally dropped).
- [ ] Playlists in `/var/playlists` intact; upload to `Incoming` works (if enabled).
- [ ] **Ampache still streams** (`/rest/stream.view` 200) — shared `/media/Music` not broken.
- [ ] Writes land in the shared DB `airsonic` schema.
- [ ] Native `airsonic.service` disabled but files retained (rollback).

## Deliverables

- Updated `airsonic/` folder: `docker-compose.yml`, `.env.template`, `airsonic.service`
  (container version), `setup.sh`, revised `README.md`.
- Root `README.md` service table + repo memory updated (esp. the DLNA/Sonos networking decision
  and the shared-media SELinux note).
- **Gate C status report.**

## Rollback

- `systemctl enable --now` the archived native `airsonic.service` (reads `/var/airsonic`,
  system MariaDB still holds `airsonic`), stop the container. The `music` vhost is unchanged.

**When done: STOP. Report Gate C results and wait for review before Task 04.**
