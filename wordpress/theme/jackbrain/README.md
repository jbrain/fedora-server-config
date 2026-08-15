# jackbrain theme — partial source control

The live `jackbrain` WordPress theme (`/storage/wordpress/wp-content/themes/jackbrain/` on the
server) is **not fully version-controlled** — it's a legacy theme with templates/CSS/images that
predate this repo and were never brought in. These three files are the exception: they're tracked
here because they now encode load-bearing, hand-verified behavior (the cube engine integration
and, as of 2026-08-14, a jQuery-free rewrite) that must not be silently lost or reverted.

## Why these three files specifically

- **`functions.php`**: enqueues the theme's scripts/styles. No longer loads jQuery/jQueryUI from
  Google's CDN (removed 2026-08-14) and wires up the bundled cube engine (see
  `../../../plans/cuber-modernization/README.md`).
- **`js/jbrain.js`**: site-wide behavior (container height calc, notes-page icon hover
  animation, digital clock, etc.). Rewritten 2026-08-14 to use plain DOM APIs + the Web
  Animations API instead of jQuery/jQuery UI — see the "removed jQuery dependency" section of
  `/memories/repo/fedora-server-config.md` for the full before/after breakdown.
- **`jbAbout.php`**: owns the About page's cube mount and static, accessible entropy-study
  template, including its native Methodology dialog. The bundled JavaScript binds behavior to
  this markup; it does not inject panel HTML.

The rest of the theme (`style.css`, other template files, `images/`, `js/libs.js` (Raphael, vendored,
untouched), `js/particles/` (dead/orphaned, not enqueued)) remains live-server-only, matching
this repo's existing convention for the theme as a whole.

The entropy-enabled `jbAbout.php` + cube bundle release was deployed and verified on 2026-08-15.
The current synchronized QR-enabled release backup timestamp is `20260815-152338`; the earlier
Methodology release backup remains available under `20260815-142528`.

The QR result control is part of the entropy template and requires the matching generated cube
bundle. It is an opt-in local export of the displayed result; scanning it shares the encoded salt,
digest, and summary counters.

## Deployment (manual — same pattern as every other component in this repo)

The cube is a separately built theme feature. Changes under `JS/cuber/src/` or
`JS/cuber/style.css` must be tested and bundled before touching the live theme:

```bash
cd JS/cuber
npm test
npm run build
scp dist/main.js dist/style.css linus:/tmp/
scp ../../wordpress/theme/jackbrain/jbAbout.php linus:/tmp/
```

Back up and replace both generated files and the matching `jbAbout.php` together under
`/storage/wordpress/wp-content/themes/jackbrain/js/cuber/`, and replace `jbAbout.php` in the
theme root in the same deployment. Restart `wordpress` afterward so
Podman's `:Z` bind mount reapplies the correct SELinux label. Finally, fetch both public assets
with a cache-busting query and compare their SHA-256 hashes with `dist/`; also verify the About
page returns 200 and the cube initializes. The entropy-study design and validation record live in
`../../../plans/cuber-entropy/README.md`.

There is no build step for the tracked theme source files (unlike `JS/cuber/`, which has to be bundled first —
see `../../../JS/cuber/README.md` / `../../../plans/cuber-modernization/README.md`). To deploy all five
live inputs (`functions.php`, `js/jbrain.js`, `jbAbout.php`, `js/cuber/main.js`, and
`js/cuber/style.css`) from PowerShell:

```powershell
$root = (Get-Location).Path
$files = @(
  "$root/wordpress/theme/jackbrain/functions.php",
  "$root/wordpress/theme/jackbrain/js/jbrain.js",
  "$root/wordpress/theme/jackbrain/jbAbout.php",
  "$root/JS/cuber/dist/main.js",
  "$root/JS/cuber/dist/style.css"
)
scp $files linus:/tmp/
ssh -tt linus 'set -e; TS=$(date +%Y%m%d-%H%M%S); ROOT=/storage/wordpress/wp-content/themes/jackbrain; for file in functions.php js/jbrain.js jbAbout.php; do sudo cp "$ROOT/$file" "$ROOT/$file.bak-$TS"; done; sudo cp /tmp/functions.php "$ROOT/functions.php"; sudo cp /tmp/jbrain.js "$ROOT/js/jbrain.js"; sudo cp /tmp/jbAbout.php "$ROOT/jbAbout.php"; sudo cp /tmp/main.js "$ROOT/js/cuber/main.js"; sudo cp /tmp/style.css "$ROOT/js/cuber/style.css"; sudo chown 33:33 "$ROOT/functions.php" "$ROOT/js/jbrain.js" "$ROOT/jbAbout.php" "$ROOT/js/cuber/main.js" "$ROOT/js/cuber/style.css"; sudo chmod 0644 "$ROOT/functions.php" "$ROOT/js/jbrain.js" "$ROOT/jbAbout.php" "$ROOT/js/cuber/main.js" "$ROOT/js/cuber/style.css"; sudo systemctl restart wordpress; rm -f /tmp/functions.php /tmp/jbrain.js /tmp/jbAbout.php /tmp/main.js /tmp/style.css; sudo systemctl is-active wordpress'
```

The equivalent interactive Bash deployment is:

```bash
# from a machine with SSH access to the server:
scp wordpress/theme/jackbrain/functions.php linus:/tmp/functions.php
scp wordpress/theme/jackbrain/js/jbrain.js linus:/tmp/jbrain.js
scp wordpress/theme/jackbrain/jbAbout.php linus:/tmp/jbAbout.php

ssh -t linus
TS=$(date +%Y%m%d-%H%M%S)
sudo cp /storage/wordpress/wp-content/themes/jackbrain/functions.php /storage/wordpress/wp-content/themes/jackbrain/functions.php.bak-$TS
sudo cp /storage/wordpress/wp-content/themes/jackbrain/js/jbrain.js /storage/wordpress/wp-content/themes/jackbrain/js/jbrain.js.bak-$TS
sudo cp /storage/wordpress/wp-content/themes/jackbrain/jbAbout.php /storage/wordpress/wp-content/themes/jackbrain/jbAbout.php.bak-$TS
sudo cp /tmp/functions.php /storage/wordpress/wp-content/themes/jackbrain/functions.php
sudo cp /tmp/jbrain.js /storage/wordpress/wp-content/themes/jackbrain/js/jbrain.js
sudo cp /tmp/jbAbout.php /storage/wordpress/wp-content/themes/jackbrain/jbAbout.php
sudo chown 33:33 /storage/wordpress/wp-content/themes/jackbrain/functions.php /storage/wordpress/wp-content/themes/jackbrain/js/jbrain.js /storage/wordpress/wp-content/themes/jackbrain/jbAbout.php
sudo systemctl restart wordpress   # forces the :Z SELinux relabel on the bind-mounted volume
rm -f /tmp/functions.php /tmp/jbrain.js /tmp/jbAbout.php
```

**After every deploy of these files, re-pull them back into this repo** so the repo never drifts
from the live server:
```bash
scp linus:/storage/wordpress/wp-content/themes/jackbrain/functions.php wordpress/theme/jackbrain/functions.php
scp linus:/storage/wordpress/wp-content/themes/jackbrain/js/jbrain.js wordpress/theme/jackbrain/js/jbrain.js
scp linus:/storage/wordpress/wp-content/themes/jackbrain/jbAbout.php wordpress/theme/jackbrain/jbAbout.php
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
