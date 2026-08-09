# Gate A report - Live Ampache API compatibility probe

- **Reviewer / approver:** (pending sign-off by repo owner)
- **Date probed:** 2026-08-08
- **Target:** Ampache 7.9.8 (image `docker.io/ampache/ampache:nosql7`), public origin
  `https://music.jackson-brain.com`, endpoint `/server/json.server.php`.
- **Dedicated account:** `wordpress_integration`, access level 25, `catalog_filter_group` 0
  (same group as every other account on this server).
- **Probe network path:** all authenticated/unauthenticated probes were run via
  `podman exec wordpress curl ...` (i.e. from inside the WordPress container itself, not the
  host), per 06-validation-deployment.md's requirement to use the real network path.
- **Credential handling:** the API key was generated via
  `admin:updateUser wordpress_integration --apikey` and captured directly to a root-only,
  0600 file on the server (`/tmp/jba_apikey.txt`, since deleted); it was substituted into
  requests via shell command substitution and was never echoed, logged, or pasted into this
  report or the conversation transcript.

## Server / API versions

- `server`: `7.9.8`
- Negotiated `version` (aka `api`): `6.9.1`, even when the request explicitly asked for
  `version=6.9.0`. **Deviation from plan**: Ampache does not do exact version pinning; it
  simply reports its own current API version within the requested major.minor family. The
  plugin should treat `6.9.x` as the compatibility family, not expect an exact echoed value.
- `compatible`: `350001` (legacy-numbered minimum-compatible-client indicator; unrelated to
  the requested version).

## Authentication

- Direct API-key Bearer authentication (`Authorization: Bearer <key>`) works for every method
  tried, with no `handshake` call required first - confirms the plan's assumption.
- **Bug found in our plugin (fixed)**: an invalid/revoked key does **not** produce an error on
  `ping` (or `handshake`) - it silently returns the same shape as a fully unauthenticated
  request (no `username`, no count fields). Only the presence of `username` in the response
  reliably indicates the key actually authenticated. `Admin_Page::handle_test_connection()`
  previously only checked `is_wp_error()` and would have reported "connection OK" for a wrong
  key. Fixed to also require `! empty( $result['username'] )`.
- All other authenticated actions tried (`stats`, `now_playing`) correctly reject an invalid
  key with `errorCode 4701` / `errorType account` / `"Session Expired"`, HTTP 200. Site Health
  was unaffected by the ping bug since it never calls the API directly.

## Error behavior

- Ampache always returns HTTP 200 with a JSON `{"error": {...}}` envelope, even for what would
  conventionally be 4xx conditions (missing object, invalid/expired session). Confirmed for:
  - Missing object: `action=song&filter=<nonexistent id>` -> `errorCode 4704`,
    `errorType filter`, `"Not Found: <id>"`.
  - Invalid/missing auth on `stats`/`now_playing`: `errorCode 4701`, `errorType account`,
    `"Session Expired"`.
- `Ampache_Client::parse_json_response()` already checks for the `error` key regardless of
  HTTP status and only trusts the numeric `errorCode` - no changes needed.

## now_playing

- Confirmed live (music-assistant genuinely playing at probe time): entries never embed song
  metadata - only `id`, `type`, `client` (plain string, e.g. `"Music Assistant"`), `expire`
  (unix timestamp), and `user.username`. Matches the plan and `normalize_now_playing()`'s
  design exactly; the required follow-up `song` lookup by id was confirmed to return full,
  correctly-typed metadata (`title`, `artist.name`, `album.name`, `time`, `has_art`, `art`).

## Recent stats / "Recently played"

- `action=stats&type=song&filter=recent&limit=N` returns full, rich song objects (title,
  artist, album, genre, has_art, art, playcount, etc.), correctly ordered by recency.
- **Deviation from plan/docs**: there is no `last_played` field anywhere on these song objects,
  confirmed on both the list response and a single-song `action=song&filter=<id>` lookup. The
  official Ampache API docs describe `last_played` as present; this server does not return it.
  Decision (user-approved): drop timestamps from Recently Played rather than pursue a
  `timeline`-based hybrid. No normalizer change was needed - `normalize_recent()` already
  defaults `played_at` to `0` when the field is absent, and `Renderer` already suppresses the
  time element when `played_at` is falsy.
- A real timestamp source does exist (`action=timeline&username=<user>`, returns a real unix
  `date` per play) but is per-username, only returns object IDs (needs a second lookup per
  item), and embeds user identity - matches the plan's already-documented reason for
  preferring `stats`+`recent` over `timeline` as the default source. Not adopted.

## Library statistics

- **Deviation from plan**: authenticated `ping`/`handshake` both return `songs`, `albums`,
  `artists`, `genres`, `playlists`, `catalogs`, `users`, etc. as `0`, and `max_song`/
  `max_album`/`max_artist` as stale/mismatched values (63576/7446/8290), despite the real
  library being non-trivial (song=48713, album=6174, artist=4060, confirmed via Ampache's own
  `update_info` cache table and directly via `COUNT(*)` on the underlying tables).
- Root-caused, not just observed:
  - Not an ACL/catalog-filter-group issue - every account (`admin`, `jack`, `music-assistant`,
    `wordpress_integration`) shares `catalog_filter_group 0`, and the one catalog is mapped to
    group 0 with `enabled=1`; access is identical for all of them, and the `catalogs`/`stats`
    actions correctly return real data for this user.
  - Not a stale-cache issue - ran `run:computeCache` (Ampache CLI, user-approved) on the
    production container; no change.
  - Conclusion: this is a genuine bug/limitation in Ampache 7.9.8's `ping`/`handshake` summary
    fields, not fixable from the plugin or from configuration.
- Decision (user-approved): treat any exactly-zero stat as "unavailable" rather than
  displaying a misleading zero; if every stat is zero, render the same "not available"
  message as a fully missing stats section. Implemented in
  `Renderer::build_stats_markup()`.

## Artwork

- `action=get_art` with our exact `Method_Policy` params (`filter=<song id>`, `type=song`,
  `size=512x512`) returns HTTP 200 `image/jpeg`, but **ignores the requested size** and
  returns the full-resolution original (confirmed: 1988x1830px, 3.66MB for one sample song).
- Investigated whether this was a missing-dependency problem: the Ampache container has the
  GD PHP extension (`extension_loaded('gd') === true`); the Imagick PHP extension is absent
  but the `convert` CLI binary is present. Found Ampache's own `resize_images` config
  preference disabled (`;resize_images = "true"`, commented out) and tested enabling it
  (timestamped backup taken first) - no effect on `get_art`'s output, even after a full
  container restart (byte-for-byte identical response). Reverted the config back to its
  original state; no lasting server-side change remains. Conclusion: `resize_images` does not
  apply to this API action on this version; this is an Ampache API limitation, not a
  dependency or configuration gap.
- Decision (user-approved): resize server-side in the plugin. `Artwork_Cache::store()` now
  accepts up to 8MB / 4000px (decompression-bomb guard) on ingest, and uses WordPress's own
  `wp_get_image_editor()` (GD/Imagick, no new dependency) to downscale anything over 512px
  before storing/serving it. `Ampache_Client::ART_SIZE_LIMIT` (the transport-layer
  `limit_response_size`) was raised from 2MB to 8MB to match, since the prior 2MB ceiling
  would have silently truncated the real ~3.66MB original before the resize step ever saw it.
- Artwork URLs (`art` field, and the `get_art` endpoint itself) require the same Bearer
  credential as any other API call; they do not embed a usable session/credential themselves
  for a third party to reuse (the `url`/`play` field does embed a `ssid` stream token scoped
  to that session, but the plugin never reads or stores that field).

## Source IP / ACL

- **Deviation from plan - not achievable as originally scoped.** Determined the real source IP
  Ampache sees for every WordPress-container-originated request:
  - nginx's own access log records these requests as coming from `10.89.1.10` (the WordPress
    container's real address on the podman bridge network).
  - Ampache's own `session` table records the connecting IP as `10.89.1.1` for the exact same
    sessions - not `10.89.1.10`.
  - Confirmed via `podman network inspect` that `10.89.1.1` is the podman bridge **gateway**
    address (subnet `10.89.1.0/24`), i.e. nginx (which runs on the host, not in a container)
    reaches Ampache's published port via the bridge gateway route. Every request that reaches
    Ampache through this nginx vhost - regardless of which real client or container
    originated it - looks identical at the IP level to Ampache.
  - Conclusion: an IP-based ACL restricting this API key to "only the WordPress container" is
    not enforceable in this deployment topology. This is documented here as a known
    limitation rather than implemented.
- The real security boundary for this credential remains, as already documented in
  03-security-privacy.md: the API key itself (kept in a root-readable secret file / container
  env, never exposed client-side), and the plugin's own closed `Method_Policy` allowlist -
  not Ampache's account access level or any IP restriction, both of which Ampache's own
  documentation already describes as incompletely enforced.

## Write/control capability probing

- **Deliberately skipped.** The plan requires this only against a staging clone with
  disposable objects and restoration evidence; no Ampache staging clone exists in this
  environment. Running write/control probes against the production instance was judged an
  unacceptable risk and was not attempted. This is a known, accepted limitation of this Gate A
  pass, not an oversight.

## Fixtures

Sanitized fixtures captured under
`wordpress/plugins/jackson-brain-ampache/tests/fixtures/ampache-7/`. All numeric IDs,
usernames, session/stream tokens, and timestamps were replaced with deterministic
placeholders; `filename`/`url`/`mbid`/etc. fields not read by any normalizer were dropped
entirely since they are not needed by tests and the real `url` field embeds a live stream
session token and real host directory structure. Song/artist/album/genre names were replaced
with clearly fictional placeholders rather than real catalog content.

| File | SHA-256 |
|---|---|
| `ping_unauthenticated.json` | `260F7FB4E6953BA852C4ACC609D16C2CDBBA9EBF3ABEE36949A2B1EF02BEF3A2` |
| `ping_authenticated.json` | `9C34CDFF1F84A905E95FEFD0F8221543512A4E1537963649CF91C7A4F9DAEE3F` |
| `now_playing.json` | `E810E8F6582A562CFF830A0A53DF24D87A4D8A42159325BFD895E2E3D4AAB746` |
| `song_for_now_playing.json` | `2612877E48A5B9839DF1A34403CA2A3619E1B352C6F5BF61CD3E03270ACC266C` |
| `stats_recent.json` | `743DEA23EEB9E619103C9F2964A3EFCEA7E675C12B94CC7AFAFA88C80DF4B5B5` |
| `error_not_found.json` | `9016BB2FF0F8C8D2C0180F285F2D2E731C7841B7FE4C09F3965DE23B13666AFC` |
| `error_session_expired.json` | `93D4A0FA76C83F772031CFEF2DF8018D8FACD0FEE2A6243ED5C3F1C79D7CB140` |

These fixtures still require reviewer approval of the diff before being considered final, per
06-validation-deployment.md ("A reviewer must approve the diff before fixtures enter the
repository").

## Code changes made as a direct result of this probe

1. `Renderer::build_stats_markup()` - treat any exactly-zero library stat as unavailable and
   omit it; if all are zero, show the same "not available" message as a missing section.
2. `Admin_Page::handle_test_connection()` - require `username` in the `ping` response, not
   just the absence of a `WP_Error`, to report a successful connection test.
3. `Artwork_Cache::store()` - resize oversized-but-reasonable originals (<=4000px) down to
   512px via `wp_get_image_editor()` instead of rejecting them; still rejects anything over
   4000px (decompression-bomb guard) or 8MB outright.
4. `Ampache_Client::ART_SIZE_LIMIT` - raised 2MB -> 8MB to match the above.

All 84 unit tests pass after these changes (was 80 at the start of this Gate A session).

## Unresolved deviations / carried-forward limitations

- Library statistics counts are unavailable from this Ampache server's `ping`/`handshake`
  regardless of user; the plugin degrades gracefully but cannot show real numbers unless a
  future Ampache release fixes this upstream.
- Recently Played never shows a play timestamp on this server (no `last_played` field);
  accepted per user decision rather than adopting the heavier `timeline`-based hybrid.
- No IP-based ACL protects the dedicated API key; the plugin's `Method_Policy` allowlist and
  the key's own secrecy are the only enforced boundaries.
- Write/control capability was not probed (no staging environment available).
- The `artists`/`songs` list actions were observed ignoring the `limit` parameter entirely
  during probing (one `artists&limit=1` call returned what appeared to be the full ~4000-row
  library) - noted here since it means these list actions should not be used for a
  cheap/paged "total count" query if ever considered as a Library Statistics workaround in the
  future; they were not adopted for any current code path.

## Approval

- [ ] Reviewer has reviewed this report and the fixture diff.
- [ ] Reviewer approves fixtures for inclusion in the repository.
- Reviewer: _______________________  Date: _______________
