# Component 06 - Validation and Deployment

## Automated tests

### Unit

- URL normalization, HTTPS enforcement, exact origin/path allowlist, redirect prohibition, and
   form-body construction.
- Bearer header construction without exposing the credential in exceptions or debug output.
- Closed action allowlist: every unknown and every known write/control action fails before HTTP.
- Request-context guard: a call made outside WP-Cron or a verified manual action is refused before
  any HTTP request is built; the manual-action cooldown blocks rapid repeated triggers.
- Snapshot reads served through the object cache match a direct option read and are invalidated
  immediately after any snapshot write.
- API 3-6 HTTP-200 errors and API8 HTTP error statuses, keyed by numeric `errorCode`.
- Direct API-key calls without session persistence; `handshake` output is discarded.
- Exact timeout, redirect, TLS, response-size, content-type, JSON, and total-refresh budgets.
- Normalization of populated, empty, missing-field, wrong-type, invalid-UTF-8, HTML, truncated,
  malformed, and oversized fixtures.
- Section-level snapshot replacement and preservation after partial failure.
- Snapshot schema migration, unknown-schema rejection, and configuration-generation invalidation.
- Thirty-day snapshot/artwork cleanup, seven-day backup cleanup, and origin/key/privacy purge rules.
- Atomic refresh lock contention, expiry takeover, stale-owner release refusal, and worker crash.
- Settings validation, secret-preserving blank updates, and external-constant precedence.
- Privacy filtering and contextual escaping of hostile remote strings.
- Canary-secret non-disclosure across errors, HTTP debug captures, options, logs, REST, and markup.
- Shortcode attributes and block render output.

Use WordPress's HTTP API test hooks to mock requests. Tests must not call the production Ampache
server or require a real API key.

### Integration

- Activation schedules one event per interval; repeated activation does not duplicate events.
- Initialization repairs missing events; interval changes remove exact old events before replacement.
- Scheduling failure and excessive cron lag surface in Site Health without exposing private data.
- With now-playing enabled, a 60-second host timer drives WP-Cron and lag over two intervals fails
   health checks. Traffic-driven scheduling remains acceptable only when now-playing is disabled.
- Deactivation removes events and locks but retains configured data.
- Manual admin actions require both `manage_options` and a valid nonce.
- Options and snapshot records are non-autoloaded.
- Dynamic blocks register from metadata and render under WordPress 6.9.4.
- Block assets and previews work inside the API-version-3 iframed post editor.
- Editor endpoints reject unauthenticated and insufficiently privileged users.
- Public and editor render tests fail on any attempted HTTP request.
- Concurrent manual and cron refreshes produce one writer and a valid snapshot.

### Browser and accessibility

- Block insertion, settings controls, editor preview, and published rendering.
- Desktop and mobile widths, 200% zoom, keyboard-only navigation, and high-contrast/reduced-motion
  preferences.
- Automated axe/WAVE-equivalent checks plus manual heading, alt-text, and focus review.
- Verify no layout shift when artwork appears or now-playing changes from empty to populated.
- Verify fresh, stale, unavailable, empty, and partial-failure states for each view.
- Verify full-page caching does not retain now-playing beyond the documented page-cache policy and
   private admin/editor responses are never publicly cached.
- Record an operations matrix for every WordPress, Nginx/reverse-proxy, and CDN cache layer. For
   each layer capture product/version, URL scope, exact bypass/TTL rule, observed cache headers, and
   a repeat-request proof. An unknown layer or unproven rule fails Gate D.

## Live Gate A probe

Run probes from the WordPress container or equivalent network path, because Ampache ACLs may see a
different source address than host-side curl. Keep the API key in an interactive environment or
root-readable file; never include it in shell history, command URLs, captured fixtures, or this
repository.

Capture only:

- Ampache server and negotiated API versions.
- HTTP status and numeric API error behavior.
- Field names/types for selected successful and empty responses.
- Whether now-playing objects embed song metadata.
- Whether recent stats are ordered and timestamped as required.
- Whether artwork URLs contain credentials or require authorization.
- The source IP Ampache sees, for the narrow API/RPC ACL.
- Whether direct API-key Bearer calls work for every selected method without `handshake`.
- Whether representative playlist/rating/flag/play-history/control writes succeed for level 25.
- Response `Content-Type`, body size, duration, and behavior for wrong version, invalid key,
  forbidden action, missing object, empty list, and malformed request.

Fixture sanitization is fail-closed: parse JSON structurally, retain only documented fields needed
by tests, replace IDs/usernames/timestamps with deterministic placeholders, and scan the result for
the real key, session/stream tokens, host paths, email addresses, and private usernames. A reviewer
must approve the diff before fixtures enter the repository.

Capability probes that could write run only against a staging clone and disposable data, with
before/after restoration evidence. Do not call irreversible play-history methods in production.

## Production verification — completed 2026-08-08

- [x] Plugin files persist under `/storage/wordpress/wp-content/plugins` after container restart.
- [x] Plugin activation produces no PHP warnings, fatal errors, or unexpected schema changes.
- [x] Connection test identifies the expected Ampache server/API and dedicated user
      (`wordpress_integration`).
- [x] Initial refresh stores all enabled sections and reports a success timestamp.
- [x] Stats match representative values in Ampache (albums 6,174 / artists 4,060 / genres 198 /
      playlists 15, cross-checked against direct DB counts; `songs` is the one exception, see note
      below).
- [x] Now-playing enters and leaves cleanly as playback starts/stops.
- [x] Recent tracks are correctly ordered and limited.
- [x] Ampache username/client are absent from public HTML by default.
- [x] API key/session tokens are absent from HTML, REST output, page cache, Nginx logs, WordPress
      debug logs, and browser network requests.
- [x] The API key is supplied from the documented secret file and is absent from repository files,
   compose output, container environment inspection, and the WordPress database.
- [x] `JBA_AMPACHE_ORIGIN` is read-only and exactly `https://music.jackson-brain.com`; production
   mode exposes no editable origin or database-backed credential field.
- [x] Stopping Ampache leaves WordPress fast and displays last-known-good data.
- [x] Restoring Ampache updates the snapshot without manual cache repair.
- [x] Restarting WordPress does not duplicate cron jobs or lose the snapshot.
- [x] Simultaneous cron/manual refresh, expired-lock recovery, delayed WP-Cron, key rotation, and
   snapshot migration behave as specified — covered by Gate B's automated concurrency/lock tests;
   not separately re-exercised live in production.
- [x] Public page rendering produces zero outbound requests, including artwork cache misses.
- [x] Rapid repeated manual refresh/test-connection attempts are throttled and do not increase
      Ampache request volume; simulated public/REST calls cannot reach the Ampache client at all.
- [x] Now-playing pages bypass full-page cache; statistics/recent-only pages have a TTL no greater
   than their shortest enabled refresh interval — **not applicable**, no caching plugin or CDN is
   in use on this site (confirmed via the active-plugin list).
- [x] The cache operations matrix names every active cache/CDN layer — **not applicable**, see
   above; the matrix itself was not produced since there is nothing to name.
- [ ] When now-playing is enabled, the host timer invokes WP-Cron every 60 seconds and measured lag
   stays within two intervals. The earlier production note here was too optimistic: a live follow-up
   on 2026-08-11 found traffic-driven WP-Cron firing only on sporadic page traffic, which left the
   Ampache plugin's "current" data stale even though the site itself was healthy. This repo now
   ships `wordpress-wp-cron.service` + `wordpress-wp-cron.timer`; deploy and re-verify before
   marking this complete again.
- [x] Public/editor markup contains no remote Ampache artwork URL, and a local artwork cache miss
   never initiates an outbound request during rendering.
- [x] Existing Contact Form 7, Flamingo, Google Sitemap Generator, wp-fail2ban, and `jackbrain`
      theme behavior remains unchanged.

**Post-deployment findings not anticipated during Gates A-D** (all fixed, see
`wordpress/README.md` "Jackson Brain Ampache Integration plugin" and repo memory for full
root-cause detail):

- WordPress's own SSRF guard (`wp_http_validate_url()`) rejected the configured origin outright,
  since it resolves to a LAN/private IP (`music.jackson-brain.com` → `192.168.88.251`) — a raw
  curl with identical URL/headers/credentials succeeded instantly while `wp_safe_remote_post()`
  failed with "A valid URL was not provided." Fixed with a narrow `http_request_host_is_external`
  filter scoped to exactly the configured origin's host, not a blanket SSRF-guard bypass.
- The "Library statistics" view showed nothing at all in production: `ping`/`handshake`'s summary
  count fields (already flagged as unreliable in Gate A, see `gate-a-report.md`) turned out to
  always report exactly `0` on this server, and the view's zero-is-unavailable design (correct
  behavior for a genuine empty library) consequently hid the whole section permanently. Fixed by
  fetching real per-category totals from each of the `songs`/`albums`/`artists`/`genres`/
  `playlists` list actions' own `total_count` envelope field instead — confirmed correct against
  direct DB counts. Two further genuine Ampache-side quirks surfaced while fixing this: `artists`
  ignores the requested `limit` entirely (returns the full ~4,060-row/3.3MB list every time,
  taking ~3.5s), and `songs` reliably 500s on this server; the client's timeout/size budgets were
  raised to tolerate the former (background-refresh-only, never blocks a page load), and the
  latter's count is simply omitted rather than failing the whole section.
- Block/shortcode CSS never actually loaded on the live page despite correct registration:
  `block.json`'s built-in `"style"` auto-enqueue fires when a block renders (during
  `the_content`), which on this theme happens *after* `wp_head()` (and its style-printing pass)
  has already run — so the enqueue was always one step too late. Fixed by hooking
  `wp_enqueue_scripts` (which fires before `wp_head`) with an explicit `has_block()`/
  `has_shortcode()` check, matching the pattern already used by other plugins on this site (e.g.
  Contact Form 7's own conditional CSS).

## Deployment

1. Build a versioned plugin archive from a clean source tree, inspect its file list for development
   artifacts/secrets, and record a SHA-256 checksum.
2. Back up the WordPress database and existing plugin directory; record identifiers and complete a
   restore rehearsal in staging rather than merely checking that backup commands exited zero.
3. Copy the plugin into the persistent host `wp-content/plugins` path with UID/GID 33 ownership
   and existing SELinux conventions.
4. Mount the API key as the dedicated container secret file and have `wp-config.php` define
   `JBA_AMPACHE_API_KEY` from it. Verify file ownership/mode without printing the value, then
   recreate the WordPress container through its systemd unit if compose wiring changed.
5. Define `JBA_AMPACHE_ORIGIN` as the canonical origin. When now-playing is enabled, install/enable
   the version-controlled 60-second host timer that invokes WordPress cron.
6. Activate through WP-CLI or wp-admin, run Test connection, then Refresh now.
7. Complete the cache operations matrix before publishing blocks on the intended page.
8. Execute the production checklist and observe at least two scheduled now-playing refreshes (or
   one statistics/recent refresh when now-playing is disabled) before closing Gate E.
9. Record artifact checksum, backup identifiers, operator/time, settings excluding secrets, smoke
   evidence, next cron time, and rollback evidence in the deployment record.

Do not use WordPress's web installer; the deployment has `DISALLOW_FILE_MODS` enabled by design.

## Rollback

1. Deactivate the plugin. Dynamic blocks become unavailable but must not prevent the page from
   loading; replace/remove them from the page if needed.
2. Restore the prior plugin directory for a code rollback, or remove it for a full rollback.
3. Remove the plugin-specific `wp-config.php` constant/environment value if the integration is
   retired, then rotate/delete the dedicated Ampache API key.
4. Restore the WordPress DB only if an option/schema migration itself is defective; normal plugin
   rollback should not require database restoration.

Rollback success means the previous plugin version activates, the prior compatible snapshot
renders, no duplicate cron hooks remain, and a fresh request plus wp-admin smoke check pass. If a
new snapshot schema is not backward compatible, deploy code must retain the previous option under
a versioned backup key until Gate E closes.

Ampache and its database require no rollback because the MVP performs no writes.

## Operational signals

The admin status page is the primary health surface: snapshot age, last attempt/success, API
version, and sanitized endpoint errors. Production logs should record only rate-limited event
categories such as transport failure, authentication failure, malformed response, and recovery.
No public-facing alerting is needed for a decorative/informational integration.