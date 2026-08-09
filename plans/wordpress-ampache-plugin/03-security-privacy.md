# Component 03 - Security and Privacy

## Threat model

The plugin handles a credential capable of reading the Ampache catalog and activity. Its remote
host setting can also become an SSRF primitive if arbitrary URLs are accepted. Public activity
may reveal what a household member is listening to, when they are active, and which client they
use. Treat remote data as untrusted even though Ampache is locally managed.

## Ampache account and ACL

- Create a dedicated level-25 User account such as `wordpress_integration`.
- Generate a unique API key for this plugin. Do not reuse the administrator or Music Assistant
  key and do not generate a stream token for the MVP.
- Add the narrowest practical Ampache API/RPC ACL for that user, key, and source address.
- Ampache's API/RPC ACL type restricts access by IP + username + key (the key may not be blank)
  and defaults to deny-from-all. Ampache's own documentation notes that its numeric access-level
  enforcement "is not fully implemented" - concrete confirmation, not just caution, that the
  account level is not the read-only boundary; the plugin's closed method allowlist is.
- Do not mistake an API/RPC ACL or level-25 account for method-level read-only authorization.
  A normal User may still mutate its own playlists, ratings, flags, or play history.
- Enforce read-only behavior primarily with a closed plugin-side action allowlist and no generic
  request method. Probe representative write methods at Gate A to document the account's actual
  residual capability and blast radius, but only in a staging clone with disposable objects and
  explicit restoration checks. Never alter production play history to prove a denial. Any
  unexpected success is recorded, not hand-waved away.
- Disable unrelated account features/preferences where Ampache supports it, and ensure the account
  owns no valuable playlists or personal data.
- Rotate the key independently. Saving a replacement increments the configuration generation,
  clears obsolete health errors, and queues a fresh connection test.

## Credential storage

Required production mode is `JBA_AMPACHE_API_KEY` populated in `wp-config.php` from a dedicated
container secret file readable by the WordPress process, so the secret is absent from the database,
repository, compose YAML, and ordinary environment inspection. The admin page reports that the key
is externally managed without revealing its value, length, prefix, suffix, or hash.

The database-backed fallback is development/staging-only and is disabled when the production-mode
constant is set:

- Restrict read/write to administrators with `manage_options`.
- Store it in a dedicated non-autoloaded option, never in block attributes or post content.
- Render an empty password field on subsequent visits; a blank submission keeps the old value.
- Provide an explicit "Remove stored key" action.
- Do not claim encryption at rest unless a separate key outside the database actually encrypts it.
- Never use the key itself as a cache key, lock token, fingerprint, telemetry dimension, or
  comparison value. A boolean "configured" state is sufficient for display.

## URL and SSRF controls

- Require production constant `JBA_AMPACHE_ORIGIN` with the exact value
  `https://music.jackson-brain.com`; display it read-only in wp-admin.
- Permit an editable origin only behind an explicit development/staging mode constant. Production
  save attempts for a different/missing origin fail validation and preserve the current setting.
- Normalize the configured value to an origin and known base path; discard credentials, query,
  and fragment components.
- Require HTTPS outside an explicit development filter.
- Build endpoint paths internally. Administrators cannot enter arbitrary endpoint or artwork URLs.
- Use `wp_safe_remote_*` and disable redirects. Require the exact lower-cased host, port 443, no
  userinfo, and the exact `/server/json.server.php` path after canonicalization.
- Do not weaken TLS verification for the public certificate.
- Keep WordPress HTTP proxy/filter compatibility, but assert after all plugin filters that the
  final request still has the fixed origin, no redirect, TLS verification, and size limits.

## WordPress controls

- Register settings with the Settings API and strict sanitize callbacks.
- Require `manage_options` for settings, connection tests, refreshes, status details, and cache
  clearing. A nonce protects every state-changing admin action; a nonce never replaces the
  capability check.
- Validate enumerations and numeric ranges rather than merely cleaning arbitrary values.
- Escape remote data at the final output context with `esc_html()`, `esc_attr()`, or `esc_url()`.
- Avoid `wp_kses_post()` unless a field intentionally permits a documented HTML subset.
- Prefix hooks, option names, transients, REST routes, shortcode names, and global symbols.
- The only direct SQL allowed in the MVP is the prepared, exact-value conditional operation needed
  to recover/release the option-row refresh lock safely; all other option access uses WordPress APIs.
- Never log authorization headers, API keys, raw request URLs, full response bodies, or filenames.
- Keep the endpoint allowlist private and deny unknown actions before constructing the request.
- Add no unauthenticated AJAX actions. Authenticated REST/admin handlers use explicit
  `permission_callback`/capability checks and send `Cache-Control: no-store` for private status.
- Return generic public fallbacks; detailed transport/API failures are visible only to administrators.
- The Ampache client enforces its own request-context guard (WP-Cron or a capability-and-nonce-
  verified manual action only) plus an independent manual-action cooldown, so a future coding
  mistake in a template, REST route, or admin screen cannot let a public or unauthenticated request
  reach Ampache or drive up its request volume.

## Privacy defaults

- Hide Ampache username, user ID, and client name in public output.
- Show only track metadata needed for the selected block.
- Limit recent history to a small count and avoid exposing a long behavioral timeline.
- Make username/client disclosure an explicit opt-in setting with a warning in wp-admin.
- Do not expose the raw snapshot through a public REST endpoint.
- If editor previews need data, use an authenticated WordPress REST route with capability checks
  and return the same redacted view model used for public rendering.
- Document the feature in the site's privacy policy if listening activity is made public.
- Register suggested privacy-policy text with `wp_add_privacy_policy_content()` describing the
  Ampache source, public fields, retention, and artwork request behavior.
- The MVP stores no WordPress-user-linked personal data, so it needs no personal-data exporter or
  eraser callback. Revisit that decision before adding identity mapping, per-user preferences, or
  stored history.

## Security acceptance checks

- Searching generated HTML, WordPress REST responses, logs, options shown in wp-admin, and cached
  public pages finds no API key or Ampache session token.
- A Subscriber, Contributor, and Editor cannot read or change integration settings.
- DNS rebinding, alternate ports, redirects, and crafted artwork values cannot make WordPress
  request a non-allowlisted host.
- A canary secret used in automated tests is absent from every serialized exception, status option,
  HTTP debug hook capture, rendered response, and test log.
- Fuzzing the action input cannot produce a request for any write/control method.
- A fuzzed admin route, an editor-preview REST call, or a simulated public template call cannot
  cause the Ampache client to execute outside WP-Cron or a fresh, capability-and-nonce-verified
  manual action, and rapid repeated manual triggers are throttled rather than forwarded.
- Remote titles containing HTML or script payloads render as text.
- Disabling or deleting the Ampache account leaves cached public rendering intact and shows a
  private, actionable admin error.