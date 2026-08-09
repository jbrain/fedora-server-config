# Component 01 - Product Scope

## Objective

Give visitors a concise view into the music library without turning WordPress into another
Ampache client. The plugin should feel like a native part of the existing site, require little
ongoing administration, and fail quietly when the music service is unavailable.

## MVP

The first release provides three independently placeable views:

| View | Content | Default behavior |
|---|---|---|
| Library statistics | Song, album, artist, genre, and playlist totals; last catalog update when available | Public |
| Now playing | Artwork, title, artist, album, and progress/status when the API provides enough timing data | Public, usernames hidden |
| Recently played | A configurable list of recent tracks with artwork and relative or absolute play time | Public, limited to 5 items |

Empty states are legitimate states: "Nothing is playing" is not an error. If no successful
snapshot exists, public output omits the affected view by default. An administrator may instead
select one neutral, translatable fallback string; given the same saved setting and section state,
the output is deterministic. Detailed failures belong only in wp-admin.

Each view has an independent state:

- **Fresh:** within its configured maximum age; render normally.
- **Stale:** older than the maximum age but previously valid; render the data without a public
   error, and expose the age/status only to administrators and editor previews.
- **Unavailable:** no valid section has ever been stored or its schema is incompatible; omit the
   view by default, or render the configured neutral fallback when that explicit mode is selected.

Default maximum ages are five minutes for now playing and 24 hours for statistics/recent tracks.
These are display-health thresholds, not deletion timers.

## Configuration

- Ampache base URL, read from production constant `JBA_AMPACHE_ORIGIN` and locked to
   `https://music.jackson-brain.com`. An editable field exists only in explicit development/staging
   mode.
- API key source: production secret-file-backed constant. A protected database option is allowed
   only in explicit development/staging mode and cannot pass production Gate E.
- Refresh intervals for now playing and slower-changing statistics/history.
- Maximum stale age for each refresh group, constrained to documented safe ranges.
- Unavailable behavior: omit (default) or one neutral fallback string.
- Number of recent items, with a conservative upper bound.
- Whether to show artwork, timestamps, Ampache usernames, and client names.
- Optional link to the Ampache site. It must not contain an auth token.
- Manual "Test connection" and "Refresh now" actions.

## Non-goals for the MVP

- Audio streaming or proxying media through WordPress.
- Search or full library browsing.
- Ampache login, account linking, or WordPress-to-Ampache identity mapping.
- Playlist, favorite, rating, catalog, user, or play-history writes.
- Direct database queries or a shared WordPress/Ampache data model.
- Frontend polling directly against Ampache.
- A custom analytics warehouse or permanent history table.
- WordPress.org directory publication. The code should follow directory-quality conventions,
  but this is initially a private site plugin.

## Product decisions to confirm at Gate A

1. Define "recently played" as song statistics using `stats` with `filter=recent`, unless the
   live response proves it does not preserve the required ordering or timestamps.
2. Use `timeline` only if a broader activity feed is desired. Timeline includes non-play actions
   and user identity, so it is not the default history source.
3. Decide whether public now-playing data represents all Ampache users or only one chosen user.
   The API method returns all active users; filtering must happen in the plugin snapshot.
4. Confirm `get_art` response types and dimensions needed by the local WordPress artwork cache.
   Direct Ampache artwork URLs are not permitted in public/editor markup, even when token-free.

## Acceptance criteria

- An editor can add each view without writing PHP.
- A visitor never waits on a live Ampache request.
- A temporary Ampache outage does not slow or break the WordPress page.
- Each view behaves deterministically for fresh, stale, unavailable, and empty data.
- No playback identity is public unless an administrator explicitly enables it.
- Removing the plugin leaves post content readable and does not delete unrelated data.