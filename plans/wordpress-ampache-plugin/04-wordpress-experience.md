# Component 04 - WordPress Experience

## Admin settings

Place one "Ampache Integration" page under Settings. Use core WordPress components and notices
rather than a custom dashboard framework.

Sections:

1. Connection: read-only production origin, credential source/status, and API client version. The
  origin is editable only when explicit development/staging mode is active.
2. Display: artwork, recent-item count, time format, user/client privacy toggles, and empty-state
   behavior.
3. Refresh: intervals, maximum ages, last attempt, per-section last success/age, next scheduled
  run, page-cache warning, and per-section health.
4. Actions: Test connection, Refresh now, Clear cached snapshot, and Remove stored key.

Connection testing is explicit. Saving settings should not block on a remote request. Test
results show the server version, negotiated API version, authenticated username, and available
counts, but redact secrets and raw payloads.

## Blocks

Provide three dynamic blocks:

- `jackson-brain/ampache-library-stats`
- `jackson-brain/ampache-now-playing`
- `jackson-brain/ampache-recently-played`

Each block uses `block.json`, `apiVersion: 3`, the `render` property (WordPress 6.1+) pointing at
its own `render.php` for server-side rendering, and minimal editor-only JavaScript. Block
attributes control presentation only, never connection details or credentials.

Shared block options should stay restrained: heading level, visibility of artwork, number of
recent items within the administrator-set maximum, and a compact/standard layout where useful.
Use native block supports for spacing, colors, typography, and alignment instead of duplicating
theme controls.

The editor preview uses only the current cached snapshot through an authenticated, `no-store`
endpoint. It labels fresh, stale, unavailable, and empty sections for editors while public
rendering remains quiet. Block assets and previews must be tested inside the API-version-3 iframed
post editor; code cannot rely on the parent document's globals, styles, or selectors.

## Shortcode compatibility

Register one namespaced shortcode:

```text
[jba_ampache view="stats|now-playing|recent" limit="5" show_art="true"]
```

The shortcode validates attributes, calls the same presenter/rendering services as the blocks,
returns markup without echoing, and applies the same administrator-defined privacy ceiling. It
must not permit a shortcode author to reveal usernames when the site setting forbids them.

## Rendering behavior

- Output semantic headings, lists, figures, `time` elements, and status text.
- Give artwork explicit width/height and useful alt text or an empty alt when adjacent text is
  equivalent.
- Do not auto-refresh via frontend JavaScript in the MVP. Subsequent uncached renders read the
  newest snapshot; full-page caches follow the explicit policy below.
- Pages containing now-playing bypass full-page cache. Statistics/recent-only pages use a cache TTL
  no longer than their shortest enabled refresh interval. Show this operational requirement in
  wp-admin rather than implying WP-Cron alone guarantees visitor freshness.
- Do not allow a now-playing block to be published to production until the deployment cache matrix
  proves bypass rules for every active cache/CDN layer and the 60-second host cron timer is healthy.
- Render stale last-known-good data without a public warning. Render a neutral fallback or no
  markup for unavailable data. A valid empty section renders its configured empty state and is not
  treated as a refresh error.
- Avoid layout shifts by reserving artwork dimensions and keeping empty states stable.
- Use WordPress date/time localization and translation functions for all user-facing strings.
- Respect reduced motion; the MVP does not require animation.
- Load block CSS only when a block is present. The shortcode can enqueue the same shared style.
- Keep markup theme-friendly and expose stable BEM-like classes without prescribing a heavy visual
  theme.

## Accessibility checks

- Meaning remains clear without artwork or color.
- Now-playing status is text, not an icon alone.
- No automatic live-region announcements on every background refresh.
- Links and controls have visible focus and descriptive accessible names.
- Heading levels are configurable so embedded blocks do not corrupt page hierarchy.
- Compact layouts remain readable at 320 CSS pixels and 200% zoom.
- Editor previews and controls work in the iframed post editor with no browser console errors.

## Privacy disclosure

Register suggested site privacy-policy text that states which track/activity fields may be public,
that data is copied from the private Ampache service into a bounded WordPress snapshot, how long
the last-known-good snapshot/artwork can remain, and that artwork is served locally rather than
loading Ampache in a visitor's browser. Username/client disclosure remains an explicit opt-in.

## Uninstall behavior

Deactivation unschedules plugin cron events and removes refresh locks, but keeps configuration and
the last snapshot. A separate uninstall routine removes plugin options and transients only after
an explicit "delete data on uninstall" setting. Externally managed constants and Ampache accounts
are never altered by WordPress. Daily cleanup enforces the 30-day snapshot/artwork ceiling and
seven-day migration/configuration backup ceiling while the plugin is active.