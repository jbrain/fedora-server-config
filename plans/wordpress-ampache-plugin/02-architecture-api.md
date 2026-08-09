# Component 02 - Architecture and API

## Integration choice

Use Ampache's native JSON endpoint:

`https://music.jackson-brain.com/server/json.server.php`

This API is selected over OpenSubsonic because it directly provides server counts, recent
statistics, timeline data, and structured Ampache errors. OpenSubsonic remains useful for music
players, but using two protocols would add adapters without improving this dashboard.

## Feature-to-endpoint map

| Need | Native API call | Notes |
|---|---|---|
| Connectivity and compatibility | `ping` | No-auth form detects server/API compatibility; authenticated form also supplies counts. |
| Initial compatibility probe | `handshake` | Gate A only: send the API key in the Bearer header, inspect the negotiated API version, and immediately discard the returned session token. |
| Library totals | Authenticated `ping` | Send the API key directly as Bearer auth; returns counts and catalog timestamps without session storage. |
| Now playing | `now_playing` | Returns only `id`, `type`, `client`, `expire`, and `user {id, username}`; never embeds song metadata, so every entry needs a follow-up `song` lookup by id. |
| Song metadata | `song` with `filter=<id>` | Supplies title, artist, album, duration, art, and last-played fields. |
| Recent tracks | `stats` with `type=song`, `filter=recent`, and bounded `limit` | Validate ordering and timestamps against Ampache 7.9.x before coding the adapter. |
| Broader history, optional | `timeline` with user filter and bounded `limit` | Use only when product scope includes general activity rather than plays. |
| Artwork, optional proxy | `get_art` with object ID/type and fixed size | Binary response; cache locally and validate type/size before serving. |

The running image is Ampache 7, while current upstream pages also describe API8. Gate A must
request API `6.9.0` explicitly, call `ping`, record the negotiated API version, and save sanitized
fixtures for every selected method. Ampache skipped API version 7; Ampache 7 normally serves the
API6 family. Do not omit the version parameter because current Ampache defaults to API8. The
adapter must target observed deployed behavior and fail closed on an incompatible major API.

## Authentication lifecycle

- The configured API key is the normal Bearer credential. Ampache explicitly permits Bearer
  authentication without first creating a session.
- Do not cache `handshake.auth`, `streamtoken`, or `session_expire` anywhere.
- A `4701` response means the static credential is invalid or rejected; it triggers a private
  authentication status, not an automatic handshake loop.
- Credential replacement increments the local configuration generation, clears health errors,
  and makes the next scheduled or manual refresh reauthenticate with the new key.
- The plugin never calls `goodbye`, because it does not retain an Ampache session.

## Request contract

- Send read calls with `wp_safe_remote_post()` to the fixed endpoint using a form-encoded body.
  This keeps action filters, usernames, and object IDs out of proxy/access URLs.
- Send `Authorization: Bearer <api-key>`; never append `auth` to a URL or request body.
- Set a distinct user agent/client name such as `JacksonBrain-WP-Ampache/<plugin-version>`.
- Set `timeout=3`, `redirection=0`, `sslverify=true`, `reject_unsafe_urls=true`, and
  `limit_response_size=262144` for JSON calls. The artwork path has a separate 2 MiB ceiling.
- Build `action`, `version`, `limit`, filters, and object IDs from typed internal values. The
  caller cannot supply a URL, arbitrary action string, headers, or raw request arguments.
- Ampache 7.9.0+ standardized `filter` as the id parameter across most methods, deprecating older
  per-method aliases (`id`, `username`, `catalog`); the client sends `filter`, matching the deployed
  Ampache 7.9.x behavior, not a legacy alias that API9 will remove.
- Allow only `ping`, `handshake` (probe service only), `now_playing`, `song`, `stats`, `timeline`
  (when enabled), and `get_art` (artwork service only).
- Check both `is_wp_error()` and the HTTP status, then parse the JSON `error.errorCode` field.
- The numeric-code-to-HTTP-status mapping (API8; API 3-6 always answer HTTP 200) is: `4700`->403,
  `4701`->401, `4702`->500, `4703`->403, `4704`->404, `4705`->400, `4706`->410, `4710`->400,
  `4742`->403. Ampache's own API-methods and API-errors pages describe `4703`'s meaning
  inconsistently (access-denied vs. a disabled/unavailable method), which is exactly why the
  adapter branches only on the numeric `errorCode` and never on `errorType`/`errorMessage` text.
- Require a JSON-compatible `Content-Type`, valid UTF-8/JSON, the expected envelope, and typed
  required fields before normalization. Reject truncated, HTML, login-page, and oversized bodies.
- Treat numeric Ampache error codes as stable; store `errorAction`/`errorType` only after
  allowlist validation, and do not branch on or persist `errorMessage`.
- Never retry `400`, `403`, `404`, or deprecated-method failures without a changed request.
- Permit one retry after 250-750 ms jitter for transport failures or `5xx` during scheduled
  refreshes. Manual tests do not retry, and a complete refresh remains within a 10-second budget.
- Cap recent items at 25 and metadata fan-out at the number of unique IDs returned by the bounded
  now-playing/recent calls. Deduplicate song lookups within one refresh.

## Snapshot model

Normalize remote data before storage. Rendering code should receive a small internal structure,
not raw Ampache responses:

```text
schema_version
configuration_generation
written_at
sections
  stats { refreshed_at, source_api_version, data { songs, albums, artists, genres, playlists, updated_at } }
  now_playing { refreshed_at, source_api_version, data[] { id, title, artist, album, duration, expires_at, artwork_ref, user_label, client_label } }
  recent { refreshed_at, source_api_version, data[] { id, title, artist, album, played_at, artwork_ref } }
```

Exclude stream URLs, filenames, catalog paths, email addresses, API tokens, session IDs, and
unused remote fields. Store timestamps as validated UTC Unix integers and format them only at
render time. `artwork_ref` is an internal `{type,id,size}` reference, never an upstream URL.

Snapshot schema migrations are explicit pure functions. An unknown future schema is unreadable,
not partially guessed. Preserve the old option until the migrated replacement has been validated
and written successfully.

## Cache and refresh model

- Store the normalized snapshot in one non-autoloaded option; it is durable last-known-good state,
  not merely an expiring transient. Validate the entire candidate before one option replacement.
  WordPress's own HTTP API guidance recommends a transient for caching remote responses; the
  durable snapshot deliberately does not use one, because a transient can be silently evicted under
  memory pressure or a persistent object-cache backend, which would break the last-known-good
  guarantee. The lightweight read-through object cache placed in front of it for public/editor
  reads still follows that same cache-and-invalidate-on-write idea for repeated reads.
- Use an atomic `add_option()` lock containing a random owner token and expiry, not a transient
  check-then-set. Recover/release it with a prepared, owner-and-value-conditional operation against
  the option row followed by option-cache invalidation. A plain read-then-delete is forbidden:
  an expired worker must not delete its successor's lock.
- Schedule separate refreshes: approximately 60 seconds for now playing and 10-30 minutes for
  library totals/history. Exact intervals are configurable and filterable.
- A manual refresh uses the same service path as cron.
- Replace only the sections that refreshed successfully. One failed endpoint must not erase a
  healthy section from the prior snapshot.
- Record a sanitized status option: last attempt, last success, API version, endpoint status,
  duration, consecutive failures, next scheduled run, and numeric error code. Bound retained
  status to the latest result per section; do not build an unbounded history. Never record headers,
  request bodies, URLs containing secrets, or remote response bodies.
- Public rendering reads the snapshot synchronously and never triggers an outbound request.
- On initialization, reconcile missing/duplicate events. Interval changes clear the old hook with
  exactly matching arguments before scheduling the replacement, and scheduling failures appear in
  Site Health/admin status.

Because WP-Cron runs on page traffic, a host systemd timer invoking WordPress cron every 60 seconds
is a production requirement whenever now-playing is enabled. Statistics/recent-only deployments
may use traffic-driven WP-Cron and make no sub-interval freshness guarantee. Site Health reports
timer/cron lag greater than two expected intervals.

Full-page caches are a separate freshness boundary. Pages containing now-playing must bypass the
full-page cache. Pages containing only statistics/recent data may be cached only with a TTL no
greater than the shortest enabled section refresh interval. The plugin documents this requirement
and reports a private warning when it detects a known incompatible cache configuration; it does
not claim to purge arbitrary cache/CDN products.

Gate D inventories every active cache layer (WordPress plugin, Nginx/FastCGI, reverse proxy, and
CDN) in an operations matrix with product/version, matching page URL, exact bypass/TTL rule, test
request, observed cache headers, and owner. Unknown layers or an unproven now-playing bypass block
publication and fail Gates D/E.

## Guarding the Ampache server from request volume

The refresh/lock model above already bounds background traffic to roughly one now-playing call
per 60 seconds and one stats/history call per 10-30 minutes, but keeping Ampache's request volume
low must be a structural property, not just a consequence of which code paths happen to call the
client:

- **Context guard.** `Ampache_Client` refuses to run outside two contexts: WP-Cron
  (`wp_doing_cron()`) or a manual admin action that has already passed `manage_options` and nonce
  verification earlier in the same request. Any other caller - a public template, a REST route, a
  future contributor's new admin screen that forgets the check - gets a typed "not permitted in
  this context" error before any request is built. This makes "no public request can reach
  Ampache" an enforced property of the client, not a promise about how the rest of the plugin
  happens to use it.
- **Manual-action throttling.** "Test connection" and "Refresh now" share one minimum-interval
  guard (30 seconds) tracked in the existing status option, independent of the nonce/capability
  check. A leaked nonce, a double form submission, or a script mistakenly calling the admin action
  in a loop cannot turn into a request burst against the music server.
- **Light internal object cache.** Public/editor rendering reads the snapshot option through
  `wp_cache_get()`/`wp_cache_set()` in a dedicated group, invalidated immediately on every write.
  This only avoids redundant `wp_options` lookups under public traffic; it adds no separate
  expiry/staleness model, since each section's own maximum-age setting still governs freshness.
  Deliberately not aggressive: no page-level output caching is added here, and nothing changes how
  fresh-vs-stale is decided.

Together these keep the caching layer intentionally modest - durable last-known-good storage plus
one lightweight read-through cache - while making it structurally impossible for internet-facing
traffic to add load onto Ampache.

## Retention and invalidation

- A section remains last-known-good for display according to its maximum-age policy, but the
  snapshot has a hard 30-day retention ceiling after its newest section success. Daily cleanup
  deletes it after that point and the views become unavailable.
- Keep at most 25 recent items. The plugin does not accumulate historical snapshots or health logs.
- Locally cached artwork records `last_referenced_at`; daily cleanup deletes files unreferenced for
  30 days and immediately deletes files no longer referenced after an origin change or explicit
  cache clear.
- Origin changes invalidate and purge the snapshot/artwork immediately. Credential changes
  increment the configuration generation, retain the old option for recovery, but make it
  unreadable to render; delete that backup after seven days or when Gate E closes, whichever is
  sooner.
- A stricter privacy setting applies at render time immediately and the next refresh rewrites the
  normalized snapshot. Snapshot schema backup options are retained for at most seven days after a
  successful migration.
- "Delete data on uninstall" removes snapshots, backups, status, locks, schedules, and local
  artwork. Without it, uninstall leaves them for manual recovery until an administrator clears
  them; the privacy-policy suggestion discloses that choice.

## Artwork decision path

1. Inspect live song and `get_art` behavior during Gate A.
2. MVP artwork is either locally cached by WordPress or omitted. Never put an Ampache artwork URL
   in public/editor markup, even when it appears token-free; that would disclose visitor IPs and
   create an independent cache/availability path.
3. Add an allowlisted server-side artwork fetcher with local cache,
  MIME plus magic-byte validation, a 2 MiB limit, maximum 512x512 decoded dimensions, generated
  cache keys, fixed dimensions, `X-Content-Type-Options: nosniff`, and no arbitrary URL input.
   Public markup references only a local attachment/cache route with an opaque generated key,
   never an upstream URL. The route serves an existing local file and never fetches synchronously.
4. Never expose a Bearer token, session token, or signed stream URL in page markup.
5. Cache misses are filled only by a scheduled/admin refresh, not while rendering a public page.