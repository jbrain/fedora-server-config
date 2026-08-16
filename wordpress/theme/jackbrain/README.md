# jackbrain theme — partial source control

The live `jackbrain` WordPress theme (`/storage/wordpress/wp-content/themes/jackbrain/` on the
server) is **not fully version-controlled** — it's a legacy theme with templates/CSS/images that
predate this repo and were never brought in. These four files are the exception: they're tracked
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
- **`header.php`**: contains the legacy theme head markup and conditionally loads Contact Form 7's
  reCAPTCHA API only on pages containing an actual contact form.

The WordPress 7 compatibility pass also keeps this classic theme on core-managed document titles,
HTML5 theme support, an HTML5 doctype/viewport, and the `wp_body_open()` hook. The former custom
`wp_title` filter is removed; these changes are independent of the pending container image upgrade.

**Mobile regression from the viewport-meta addition, fixed 2026-08-16**: adding
`<meta name="viewport" content="width=device-width, initial-scale=1">` to `header.php` (above)
made phones render this fixed-780px-wide, pre-responsive-era design at the ACTUAL device width
instead of the old default zoomed-out "virtual viewport" (~980px) mobile browsers use when no
viewport meta is present. `#home-container` (the melting-cube background image's container, in
`jbHome.php`) had no width constraint of its own — unlike its child `#content`, which is a fixed
`780px` — so it stretched to the full (narrow) device width, making the background image's
percentage-based position (`90% 95%`) compute against a much smaller box and appear oversized/
off-center. Fixed in `style.css` (untracked, live-server-only) by capping `#home-container` to the
same `780px` + auto horizontal margins as `#content`, restoring a consistent design canvas
regardless of viewport width — verified visually at a 390px mobile viewport width post-fix.

Also fixed as part of the same WP7-compatibility review: `functions.php`'s `login_headertitle`
filter (deprecated by WordPress since 5.2.0) renamed to `login_headertext` (verified against this
image's own `wp-login.php` deprecation shim — cosmetic-only, no functional change, silences a
`_deprecated_hook()` notice that would otherwise appear if `WP_DEBUG` were ever enabled here).

The rest of the theme (`style.css`, other template files, `images/`, `js/libs.js` (Raphael, vendored,
untouched), `js/particles/` (dead/orphaned, not enqueued)) remains live-server-only, matching
this repo's existing convention for the theme as a whole.

The entropy-enabled `jbAbout.php` + cube bundle release was deployed and verified on 2026-08-15.
The current synchronized theme backup timestamp is `20260816-091859` (`functions.php`) /
`20260816-091906` (`style.css`); rollback copies now live under
`/storage/backups/wordpress-theme-jackbrain-bak/` on the server, not in the live theme directory
itself (moved 2026-08-16 — theme webroots must never carry rollback copies, even ones already
blocked from web access by nginx's `.bak` regex). The prior layout backup
remains available under `20260815-163604`.

The QR result control is part of the entropy template and requires the matching generated cube
bundle. It is an opt-in local export of the displayed result; scanning it shares the encoded salt,
digest, and summary counters.

Contact Form 7's reCAPTCHA v3 integration remains active, but the Google badge is hidden using
Google's permitted `visibility: hidden` treatment. Every page receives visible reCAPTCHA
attribution with links to Google's Privacy Policy and Terms of Service. The theme no longer
enqueues a second global reCAPTCHA script of its own.

The legacy `header.php` also contains a conditional reCAPTCHA API tag because the old theme had
hard-coded the script globally. It now emits that tag only when the current post contains a real
Contact Form 7 shortcode; this prevents About and other non-form pages from loading Google at all.

The attribution is inserted into `#footer`, or `#wrapper` for legacy templates that omit the
footer, with `clear: both`, full-width block styling. This keeps it at the bottom of the page when
the About page's entropy panel is expanded.

The reusable cube and entropy project is published separately at
https://github.com/jbrain/cuber-entropy under the MIT License. The source link appears only inside
the enabled entropy panel, beside the local-processing note, with a compact readable treatment;
the base About page remains free of
the link. This private adapter owns the WordPress markup and deployment; it consumes pinned
generated assets rather than loading source from GitHub at runtime. Public release tags and
deployed asset hashes belong in the entropy plan.

## Deployment (manual — same pattern as every other component in this repo)

### General theme file edits — `deploy-theme.ps1`

For any plain theme file edit (`style.css`, `functions.php`, `header.php`, other page templates,
images, etc. — anything that's just a file copy, not the separately-built cube engine below), use
`deploy-theme.ps1` (PowerShell, run from a machine with your own SSH key/agent already set up for
the `linus` host alias — same as every other manual deploy in this repo, no special credentials
needed):

```powershell
cd wordpress\theme\jackbrain

# Deploy every file currently in this local folder (only files that exist here are touched -
# most of the live theme is untracked and stays server-only, see above):
.\deploy-theme.ps1

# Deploy just one or two specific files:
.\deploy-theme.ps1 -Path style.css
.\deploy-theme.ps1 -Path functions.php,js\jbrain.js

# See what would be deployed without touching anything:
.\deploy-theme.ps1 -DryRun
```

It stages the selected file(s) locally, uploads them via `scp`, then runs
`deploy-theme-remote.sh` on the server (via `ssh -t ... sudo bash ...` — you'll be prompted for
your own sudo password directly in the terminal) to: back up any existing live file to
`/storage/backups/wordpress-theme-jackbrain-bak/` with a timestamp, copy the new file into place
with ownership/permissions matching the theme directory, restart the `wordpress` container (
required — the wp-content bind mount uses SELinux `:Z`, which only relabels to match the
container's current MCS category on a fresh start; files written while it keeps running can
otherwise become unreadable — see repo memory's "SELinux MCS mismatch" note), and finally curl
the homepage and About page to confirm both still return `200`. If verification fails, the script
exits non-zero and prints where the pre-deploy backups are for a manual rollback — see both
scripts' own header comments for the exact behavior.

If you edit an UNTRACKED file (e.g. `style.css`) directly on the live server instead (the older,
still-valid pattern used throughout this repo's history), it does **not** exist locally to deploy
from — pull it back into this folder afterward if you want future edits to go through
`deploy-theme.ps1` instead of ad hoc SSH edits.

### Cube engine (`JS/cuber/`) — separate build step

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
