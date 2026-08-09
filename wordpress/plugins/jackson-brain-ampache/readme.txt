=== Jackson Brain Ampache Integration ===
Contributors: jacksonbrain
Tags: ampache, music, now playing, library statistics
Requires at least: 6.9
Tested up to: 6.9.6
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A small, read-only integration that shows local Ampache library statistics, now playing, and
recently played tracks as WordPress blocks or a shortcode, without exposing Ampache credentials
or letting a public page load wait on Ampache being available.

**Status: live in production** on `https://jackson-brain.com/music/` since 2026-08-08.

== Description ==

This plugin fetches library totals, now-playing status, and recent activity from a local
Ampache server in the background, normalizes the result into a small internal snapshot, and
renders that snapshot on public pages. Public and editor rendering never make an outbound
request to Ampache; a temporary Ampache outage never slows or breaks a page.

Provided blocks:

* Ampache Library Statistics (`jackson-brain/ampache-library-stats`)
* Ampache Now Playing (`jackson-brain/ampache-now-playing`)
* Ampache Recently Played (`jackson-brain/ampache-recently-played`)

A compatibility shortcode is also available:

`[jba_ampache view="stats|now-playing|recent" limit="5" show_art="true"]`

Production credentials and the Ampache origin are configured through `wp-config.php` constants
(`JBA_AMPACHE_API_KEY`, `JBA_AMPACHE_ORIGIN`); a database-backed fallback exists only in an
explicit development/staging mode (`JBA_AMPACHE_DEV_MODE`).

This is a private site plugin, not intended for the WordPress.org plugin directory. Full design
documentation lives in the source repository under `plans/wordpress-ampache-plugin/`.

== Installation ==

1. Copy this directory into `wp-content/plugins/`.
2. Define `JBA_AMPACHE_ORIGIN` and `JBA_AMPACHE_API_KEY` in `wp-config.php` (see the repository's
   architecture and security documentation for the exact production requirements).
3. Activate the plugin, then use "Test connection" and "Refresh now" on the Ampache Integration
   settings page under Settings.
4. Add the blocks or the shortcode to a page.

== Changelog ==

= 0.1.0 =
* Initial development version: core services, admin settings page, blocks, shortcode, and the
  local artwork cache.
* Live production deployment (2026-08-08) and post-launch fixes:
  * Added a "Settings" link to the plugin's row on the Plugins list page, and a "how to use this
    on a page" quick-reference (block names + shortcode syntax) at the top of the settings page.
  * Fixed the "Test connection" action failing silently: WordPress's own SSRF guard
    (`wp_http_validate_url()`) was rejecting the configured Ampache origin because it resolves to
    a LAN/private IP address. Fixed with a narrow `http_request_host_is_external` filter scoped to
    exactly the configured origin's host.
  * Fixed the Library Statistics view showing nothing: Ampache's `ping`/`handshake` summary count
    fields always report 0 on this server (a genuine Ampache-side bug, not a WordPress issue).
    Switched to fetching real per-category totals from the `songs`/`albums`/`artists`/`genres`/
    `playlists` list actions' own `total_count` field instead, with each metric degrading
    independently rather than the whole section failing if one action errors.
  * Fixed album art rendering at full native resolution instead of a proper thumbnail size, and
    reworked the now-playing/recently-played track layout (fixed-size artwork, text truncation
    for long titles).
  * Fixed the block/shortcode CSS never actually loading on the live site: both are now enqueued
    from an early `wp_enqueue_scripts` hook (gated on `has_block()`/`has_shortcode()`) instead of
    relying on `block.json`'s built-in style auto-enqueue, which fires too late relative to this
    theme's `wp_head()` call to ever be printed.
  * Switched block/editor asset versioning from the fixed plugin version string to file
    modification time, so future CSS/JS-only edits bust browser caches automatically.

