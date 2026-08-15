# jackbrain theme — partial source control (`functions.php`, `js/jbrain.js`)

The live `jackbrain` WordPress theme (`/storage/wordpress/wp-content/themes/jackbrain/` on the
server) is **not fully version-controlled** — it's a legacy theme with templates/CSS/images that
predate this repo and were never brought in. These two files are the exception: they're tracked
here because they now encode load-bearing, hand-verified behavior (the cube engine integration
and, as of 2026-08-14, a jQuery-free rewrite) that must not be silently lost or reverted.

## Why these two files specifically

- **`functions.php`**: enqueues the theme's scripts/styles. No longer loads jQuery/jQueryUI from
  Google's CDN (removed 2026-08-14) and wires up the bundled cube engine (see
  `../../../plans/cuber-modernization/README.md`).
- **`js/jbrain.js`**: site-wide behavior (container height calc, notes-page icon hover
  animation, digital clock, etc.). Rewritten 2026-08-14 to use plain DOM APIs + the Web
  Animations API instead of jQuery/jQuery UI — see the "removed jQuery dependency" section of
  `/memories/repo/fedora-server-config.md` for the full before/after breakdown.

The rest of the theme (`style.css`, template files, `images/`, `js/libs.js` (Raphael, vendored,
untouched), `js/particles/` (dead/orphaned, not enqueued)) remains live-server-only, matching
this repo's existing convention for the theme as a whole.

## Deployment (manual — same pattern as every other component in this repo)

There is no build step for these two files (unlike `JS/cuber/`, which has to be bundled first —
see `../../../JS/cuber/README` / `plans/cuber-modernization/README.md`). To deploy a change:

```bash
# from a machine with SSH access to the server:
scp wordpress/theme/jackbrain/functions.php linus:/tmp/functions.php
scp wordpress/theme/jackbrain/js/jbrain.js linus:/tmp/jbrain.js

ssh -t linus
TS=$(date +%Y%m%d-%H%M%S)
sudo cp /storage/wordpress/wp-content/themes/jackbrain/functions.php /storage/wordpress/wp-content/themes/jackbrain/functions.php.bak-$TS
sudo cp /storage/wordpress/wp-content/themes/jackbrain/js/jbrain.js /storage/wordpress/wp-content/themes/jackbrain/js/jbrain.js.bak-$TS
sudo cp /tmp/functions.php /storage/wordpress/wp-content/themes/jackbrain/functions.php
sudo cp /tmp/jbrain.js /storage/wordpress/wp-content/themes/jackbrain/js/jbrain.js
sudo chown 33:33 /storage/wordpress/wp-content/themes/jackbrain/functions.php /storage/wordpress/wp-content/themes/jackbrain/js/jbrain.js
sudo systemctl restart wordpress   # forces the :Z SELinux relabel on the bind-mounted volume
rm -f /tmp/functions.php /tmp/jbrain.js
```

**After every deploy of these files, re-pull them back into this repo** so the repo never drifts
from the live server:
```bash
scp linus:/storage/wordpress/wp-content/themes/jackbrain/functions.php wordpress/theme/jackbrain/functions.php
scp linus:/storage/wordpress/wp-content/themes/jackbrain/js/jbrain.js wordpress/theme/jackbrain/js/jbrain.js
```

## Why this matters for disaster recovery — read this before trusting `setup.sh`

`../../setup.sh` migrates `wp-content` from `/usr/share/wordpress/wp-content` (the old native
RPM install) into the container's persistent bind mount **only if that bind mount is empty**
(see `../../README.md`'s "wp-content migration" section). That native-RPM copy is a frozen
snapshot from 2026-08-03 (the original containerization cutover) — it **predates the cube engine
rewrite and this jQuery removal entirely**. If the persistent volume
(`/storage/wordpress/wp-content`) is ever lost and `setup.sh` re-populates it from that stale
source, the theme would silently revert to the pre-modernization, jQuery-dependent version with
none of the work described here or in `plans/cuber-modernization/README.md`.

If that ever happens: restore `wp-content` from the normal host backup first (if available), and
regardless of that, re-apply `functions.php` and `js/jbrain.js` from **this repo directory**
(and rebuild+redeploy `JS/cuber/dist/` per its own README) as the last step of any recovery,
before considering the site fully restored. Don't trust the native-RPM copy for these two files.
