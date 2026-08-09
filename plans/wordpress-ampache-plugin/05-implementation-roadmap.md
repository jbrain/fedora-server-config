# Component 05 - Implementation Roadmap

## Proposed plugin location

Develop the distributable plugin under:

`wordpress/plugins/jackson-brain-ampache/`

The deployment script or runbook copies that directory to
`/storage/wordpress/wp-content/plugins/jackson-brain-ampache`. Do not add the plugin by modifying
the WordPress container image or installing through wp-admin; `DISALLOW_FILE_MODS` is enabled.

## Proposed layout

```text
jackson-brain-ampache/
  jackson-brain-ampache.php
  uninstall.php
  readme.txt
  includes/
    class-plugin.php
    class-settings.php
    class-ampache-client.php
    class-method-policy.php
    class-response-normalizer.php
    class-snapshot-repository.php
    class-refresh-service.php
    class-scheduler.php
    class-site-health.php
    class-renderer.php
    class-shortcode.php
  blocks/
    library-stats/block.json
    library-stats/render.php
    now-playing/block.json
    now-playing/render.php
    recently-played/block.json
    recently-played/render.php
  assets/
    css/blocks.css
    js/editor.js
  tests/
    unit/
    integration/
    fixtures/ampache-7/
```

Use a small internal autoloader or explicit requires unless the repository adopts Composer for
development tooling. Do not introduce a runtime framework.

## Responsibilities

| Unit | Responsibility |
|---|---|
| Plugin | Bootstrap hooks and construct dependencies; no remote or rendering logic |
| Settings | Register, validate, retrieve, and redact options |
| Ampache client | Build allowlisted requests, convert transport/API failures into typed errors, and refuse to run outside WP-Cron or a verified manual admin action |
| Method policy | Define the closed read-method set and typed parameters; reject arbitrary actions |
| Response normalizer | Convert observed Ampache shapes into the versioned internal snapshot |
| Snapshot repository | Read/write non-autoloaded last-known-good data and health metadata |
| Refresh service | Coordinate endpoint calls, partial success, retries, and locking |
| Scheduler | Register intervals, schedule/unschedule hooks, and invoke refreshes |
| Site Health | Report private cron, freshness, API compatibility, and configuration problems |
| Renderer | Escape and render redacted view models shared by blocks and shortcode |
| Shortcode | Validate shortcode attributes and delegate to Renderer |

## Milestone 0 - API spike (Gate A)

- Create the dedicated Ampache User/API key and narrow API/RPC ACL.
- Probe unauthenticated `ping`, authenticated `handshake`/`ping`, `now_playing`, recent song
  `stats`, `timeline`, `song`, and artwork behavior.
- Request API 6.9 explicitly; prove direct API-key Bearer calls work without a stored session.
- Probe representative write/control methods to measure residual level-25 capability. The plugin's
  method policy remains the read-only boundary even if Ampache permits those calls.
- Record sanitized success, empty, malformed/error, and binary fixtures plus API/server versions,
  response headers/status behavior, timing, and size.
- Produce a Gate A report containing commands with secret placeholders, fixture checksums, ACL
  source address, decisions, unresolved deviations, reviewer, and approval date.
- Finalize recent-history and artwork decisions in the product scope.

## Milestone 1 - Core plugin (Gate B)

- Add plugin bootstrap, activation/deactivation hooks, and version constants.
- Implement settings and external-constant credential support.
- Implement the allowlisted HTTP client and structured Ampache errors.
- Implement the request-context guard (WP-Cron or verified manual action only) and the shared
  manual-action cooldown for Test Connection/Refresh Now, alongside a light read-through object
  cache for snapshot reads.
- Implement exact request budgets, response validation, and the closed method policy.
- Implement normalizers against Gate A fixtures.
- Implement durable snapshot storage, per-section partial updates, health metadata, and locking.
- Implement schema migration, configuration generation, an owner-token atomic lock, and recovery.
- Implement scheduled/manual refresh, event reconciliation, Site Health tests, and retry behavior.
- Implement daily snapshot/artwork/backup garbage collection and all configuration-generation
  purge/invalidation triggers.
- Unit-test all core services before adding presentation.

## Milestone 2 - Presentation (Gate C)

- Build the shared renderer and privacy filter.
- Add the three dynamic blocks with Block API version 3.
- Add the compatibility shortcode.
- Add editor previews from cached data and scoped styles.
- Test empty, active, stale, missing-art, long-title, and malicious-string fixtures.
- Assert render paths make zero HTTP calls and private/editor responses are non-cacheable.

## Milestone 3 - Staging integration (Gate D)

- Install into the container's persistent `wp-content/plugins` directory.
- Test against WordPress 6.9.4, PHP 8.4, the active `jackbrain` theme, and existing plugins.
- Exercise real scheduled refreshes and Ampache outages.
- Confirm page-cache freshness policy and that public requests make no Ampache or artwork calls.
- Produce the cache-layer operations matrix and verify the required 60-second host timer when
  now-playing is enabled; either failure blocks publication.
- Exercise upgrade from the prior snapshot schema, key rotation, lock-owner crash recovery, cron
  delay, simultaneous manual/cron refresh, and plugin rollback.
- Complete security, privacy, accessibility, and responsive checks.

## Milestone 4 - Production release (Gate E)

- Back up WordPress DB and plugin files.
- Deploy a versioned plugin artifact through the repository's manual process.
- Activate, configure the externally supplied key, test, refresh, and add blocks to the chosen page.
- Observe logs and refresh health through at least one normal interval and one forced Ampache outage.
- Record the deployed version, configuration location, and rollback result in WordPress operations
  documentation.
- Store the artifact checksum, Gate D evidence, backup identifier, and post-deploy smoke evidence.

## Change discipline

- Keep every milestone independently deployable or revertible.
- Do not add streaming or write features while implementing the read-only foundation.
- Treat changes to credential handling, allowed hosts, public REST output, or privacy defaults as a
  security review boundary.
- Add adapters for future Ampache API versions behind the normalizer rather than branching in
  block templates.