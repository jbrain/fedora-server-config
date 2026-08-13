# Cuber (Rubik's Cube) Animation Modernization

> **Status: Segment 2 decisions made (2026-08-13).** Owner-approved direction below; Segment 3
> (implementation) has not started yet.

## Segment 2 decisions (owner, 2026-08-13)

1. **Pathway: owner deferred to the recommendation above — going with Pathway A** (drop
   Three.js/TWEEN entirely, rewrite as pure modern CSS 3D transforms + native Web Animations
   API). Same visual result, zero dependencies.
2. **Interaction**: open to changing the current drag-to-twist/orbit behavior if the rewrite
   genuinely improves it — but the current interaction (including on mobile/touch) already
   works well and is the bar to match or beat, not just preserve out of caution.
3. **`prefers-reduced-motion` + off-screen pause**: approved, conditioned on it being a real
   efficiency win (battery/CPU) rather than added complexity for its own sake — both qualify
   (skipping a rAF-less CSS animation loop and pausing offscreen work are net performance wins).
4. **Dead code cleanup**: approved to remove now rather than waiting for Segment 4. `main.js`
   deleted from the live server (see "Live cleanup performed" below). The IE-CSS3D renderer path
   inside `initCube.js`/`initCube.min.js` is left in place for now since it's entangled with the
   live bootstrap/controls code in the same file — surgically extracting just that path from the
   current minified bundle would be throwaway work the Segment 3 rewrite already makes moot, so
   it will simply not exist in the new engine rather than being hand-edited out of the old one.

## Background

`https://jackson-brain.com/about/` (WordPress page template `jbAbout.php` in the `jackbrain`
theme) embeds a small interactive 3D Rubik's Cube — drag a face to twist it, auto-shuffles on
load. It's built on a ~decade-old third-party library that still works but predates most of
today's browser-native 3D/animation capabilities. The owner started a modernization effort by
creating `JS/cuber/` in this repo (currently holding a raw copy of the vendor bundle,
`cuber.js`, and one legacy renderer module, `init_cuber.js`) and asked for a full review before
deciding how to proceed. This is a personal-page decorative/interactive piece, not a product —
scope and risk are both low, but the review below is thorough because the owner wants a real
understanding of the existing code before touching it.

## What "Cuber" actually is

The bundle is **[marklundin/cube](https://github.com/marklundin/cube)** ("Cuber" — MIT
licensed), by Mark Lundin and Stewart Smith at Google Creative Lab, 2013–2014 — the engine
behind the original Google Doodle Rubik's Cube. Upstream's own README states its design
philosophy, which is worth preserving conceptually in any rewrite:

- Strict separation of cube **state** from **rendering**, so the same state model could drive
  different renderers.
- One class per concern, one file per class: `erno.js`, `cube.js`, `cubelets.js`, `slices.js`,
  `directions.js`, `groups.js` (all controlled by `erno.js`).
- Deliberately exposes internals on the global scope (`cube`, `ERNO`, etc.) so the browser
  console can inspect/drive it directly (`cube.inspect()`, `cube.hasColors(RED,BLUE).showIds()`,
  chainable query API via `groups.js`).

## Confirmed live architecture (as deployed today, verified against the live server)

**Enqueue chain** — `wp-content/themes/jackbrain/functions.php`, `jackbrain_scripts()`,
gated `if ( is_page('about') )`:

1. `wp_enqueue_script('requirejs', .../js/require.js)`
2. `wp_add_inline_script('requirejs', 'var require = {baseUrl:get_template_directory_uri()."/js", urlArgs:"v="+filemtime(cube.js)}', 'before')` — the 2026-08-08 fix (see repo memory) that replaced a hardcoded absolute `baseUrl` and added cache-busting.
3. `wp_add_inline_script('requirejs', "requirejs(['cube']);")`
4. **`js/cube.js`** (AMD entry) — `requirejs(['cuber.min'], ...) -> requirejs(['initCube.min'], ...) -> jQuery ready -> cube.shuffle(5)`.
5. **`js/cuber.min.js`** (source of the local `JS/cuber/cuber.js` copy) — a Closure-compiled bundle of **THREE r66** (2014), **TWEEN.js r12** (2012), Three's `CSS3DRenderer`, and all `ERNO.*` cube state/render classes. **No WebGL renderer is present at all** — every visible "3D" element is a real DOM `<div>` positioned with CSS 3D transforms; Three.js is used purely as the math/scene-graph layer (`Object3D`, quaternions, camera), never for GPU rendering.
6. **`js/initCube.min.js`** (source: `initCube.js` — the fuller, unminified version of what's currently in the repo as `JS/cuber/init_cuber.js`) actually bundles **four distinct things** concatenated together:
   - `ERNO.renderers.IeCSS3D` + `ERNO.IeCss3DRenderer` — an Internet-Explorer-specific CSS3D shim, feature-detected via `navigator.userAgent` (`MSIE`/`Trident/`). **Dead weight today.**
   - `ERNO.Locked` — a **custom, site-specific replacement for the library's default whole-cube arcball orbit control** (`ERNO.Controls`), confirmed via the full upstream source review below. Face-twisting itself (`ERNO.Interaction`, raycasting via `ERNO.Projector`) is a separate, always-on mechanism built into the core bundle regardless of which orbit-control class is chosen — `ERNO.Locked` only keeps the *whole cube's* resting orientation pinned to a fixed, pleasant "hero" viewing angle (matching the `Math.PI*0.1`/`Math.PI*-0.25` offset also applied in the bootstrap) instead of allowing free 360° orbit like the stock `ERNO.Controls`. Still a real, deliberate customization worth preserving — just not the twist mechanic itself.
   - `deviceMotion()` — a gyroscope/device-orientation helper. **Present but never enabled** — its only call site in the bootstrap is commented out.
   - The actual page bootstrap:
     ```js
     window.cube = new ERNO.Cube({
         hideInvisibleFaces: true,
         controls: controls,               // ERNO.Locked (useLockedControls = true)
         renderer: isIe ? ERNO.renderers.IeCSS3D : null
     });
     var container = document.getElementById( 'container' );
     container.appendChild( cube.domElement );
     ```

## Notable findings / fragility

- **`js/main.js` is dead/orphaned code.** It still exists on disk and contains its own
  `requirejs.config({baseUrl: ...})` call, left over from before the 2026-08-08 fix (repo memory:
  the old integration used a raw `<script data-main="main">` tag). Nothing enqueues `main.js`
  anymore — `functions.php` now injects its own `require` config inline and calls
  `requirejs(['cube'])` directly. Safe to delete at any time; it currently has zero effect.
- **Fragile mount point.** The cube's DOM node is appended into `#container` — WordPress's
  whole-page wrapper `<div>` (opened in `header.php`) — **not** into the `<div id="the-cube">`
  placeholder that `jbAbout.php` actually renders. `#the-cube` is a purely CSS-driven empty
  spacer (`440px × 440px`, `margin: 40px auto`) that happens to sit at the right point in
  normal document flow for the appended `.cube` element (`width/height: 100%`, no absolute
  positioning) to visually land in the same place. This is an implicit, order-dependent coupling
  between the theme's markup and a hardcoded `getElementById('container')` call — a future theme
  change could silently break or reposition the cube. Should target `#the-cube` directly in any
  rewrite.
- **Outdated, unmaintained dependencies**, vendored inline (no `package.json`/build step
  anywhere in this repo): `THREE r66` (2014) and `TWEEN.js r12` (2012). No specific CVEs found
  for either, but both have had zero upstream security review in over a decade — an OWASP A06
  (Vulnerable and Outdated Components) consideration even without a concrete exploit.
- **No Subresource Integrity or version pinning** on the site-wide `jQuery 2.1.4`/`jQueryUI
  1.11.4` (Google CDN) or `recaptcha` script tags enqueued in the same `functions.php`. Not part
  of the cube itself, but adjacent, drive-by findings worth flagging (OWASP A06/A08).
- **Custom theming worth preserving in a rewrite**: a "purty" color palette
  (`faceFront #FFF`, `faceUp #E87000`, `faceRight #3D81F6`, `faceDown #DC422F`,
  `faceLeft #009D54`, `faceBack #F5B400`), a custom logo sticker face
  (`images/jbrown.png`), and a custom `RubikExtended` webfont — though the label/ID/text
  overlays that font serves (`.faceLabel`, `.id`, `.text`) are all `display:none` in production
  CSS; they're upstream debug-only features, never shown to real visitors.

## Research: modern rendering approaches (2026)

| Approach | Fit for this use case |
|---|---|
| **Pure CSS 3D transforms + Web Animations API** | Universal, unprefixed `transform-style: preserve-3d`/`perspective` support today; `element.animate()` gives GPU-composited animation with no JS animation-loop library needed at all. For ~27 small cubelets this needs no WebGL context and no dependency. Matches what the site **already visually is** (CSS3D, not WebGL) — a same-look, zero-dependency modernization. |
| **Modern Three.js (r17x) WebGL renderer** | Real lighting/materials/shadows/bevels instead of flat colored divs — a genuine visual upgrade, not just a modernization. ES module imports from a CDN (jsdelivr/unpkg), no build step required. Heavier than pure CSS but still trivial for any modern GPU at this scale. |
| **Hybrid** | Keep the well-designed `ERNO.*` state/model layer (cube logic, twist queue, groups/query API) but swap only the renderer (CSS3D → WebGL or a rewritten CSS3D) and the tweening (`TWEEN r12` → WAAPI). |

## Recommendation

Because the current production behavior is **already CSS3D-only** (no WebGL is even loaded
today), the lowest-risk, same-visual-result modernization is **Pathway A**: drop Three.js and
TWEEN.js entirely, replace with plain matrix/quaternion math (a tiny helper, or a small
dependency-free math module) driving native CSS custom properties, and use the Web Animations
API for twist tweening instead of `TWEEN r12`. This eliminates ~130KB of decade-old vendored
code, the entire dead IE-renderer path, and the `main.js`/mount-point fragility in one pass,
while looking identical to visitors.

**Pathway B** (modern Three.js WebGL) was the alternative considered if the owner wanted an
actual visual upgrade (real lighting/bevels) rather than a pure modernization — the owner chose
Pathway A (see "Segment 2 decisions" above).

## Proposed phased roadmap (segments)

- **Segment 1 (this document) — DONE.** Research + full live-architecture discovery, findings
  documented, no code changed.
- **Segment 2 — Decision + design — DONE (2026-08-13).** Owner picked Pathway A; see "Segment 2
  decisions" above. Feature-parity checklist for Segment 3 to satisfy: drag-to-twist (always-on
  `ERNO.Interaction` behavior), the `ERNO.Locked`-style fixed hero viewing angle (not free
  orbit), shuffle-on-load, the "purty" palette, the logo sticker face, mobile touch parity,
  `prefers-reduced-motion`, and `IntersectionObserver`-gated pause when scrolled off-screen.
- **Segment 3 — Implementation.** Build the new engine/state+render layer under `JS/cuber/`
  (or a clean rename), with a standalone local HTML test harness with no WordPress dependency
  for fast iteration.
- **Segment 4 — Theme integration.** Replace the `functions.php` enqueue chain, mount directly
  into `#the-cube` (not `#container`), delete confirmed-dead files (`main.js`, the IE-CSS3D
  path, the unused `deviceMotion` helper, the old vendor bundle).
- **Segment 5 — Staging verification + rollback.** Live-verify on `/about/`, keep the current
  `cuber.min.js`/`initCube.min.js`/`cube.js` as an instant server-side revert path during a soak
  period, then clean up.

## Deeper full-source code review (2026-08-13) — additional findings beyond Segment 1

Fetched the actual readable (non-minified) upstream `cuber.js` source
(`raw.githubusercontent.com/marklundin/cube/master/js/cube/cuber.js`) to review the real engine
internals rather than the Closure-compiled local copy. The core state/model design (`ERNO.Cube`,
`ERNO.Cubelet`, `ERNO.Slice`, `ERNO.Group`, `ERNO.Direction`, `ERNO.Queue`, `ERNO.Twist`) is
genuinely solid, well-commented engineering for its era and worth using as a conceptual reference
for the rewrite's state layer even though the code itself won't be reused verbatim. Concrete
issues found beyond Segment 1's findings:

- **Global prototype pollution**: the bundle monkey-patches `Number.prototype` (`.add`,
  `.scale`, `.lerp`, `.constrain`, ...), `String.prototype` (`.capitalize`, `.toCamelCase`, ...),
  and `Array.prototype` (`.shuffle`, `.first`, `.last`, `.middle`, ...) directly on JavaScript's
  built-in types. This is a well-known anti-pattern — a real risk of colliding with a future or
  third-party addition of the same name (TC39 has added several new `Array.prototype`/
  `Object` methods since 2014). The rewrite should use plain module-scoped helpers instead of
  patching built-ins.
- **Two overlapping math/utility layers bundled together**: `THREE.Math` (clamp, lerp, random
  helpers) and the custom `_` object + the `Number.prototype` patches above cover much of the
  same ground redundantly. A rewrite needs at most one small internal utility module (native
  `Math`/modern array methods cover most of this today without any helper at all).
- **The vendored THREE r66 itself carries its own historical deprecation baggage**: dozens of
  `console.warn('DEPRECATED: ...')` compatibility shims for even-older call patterns (e.g.
  `Vector3.add(a,b)` two-arg form, removed `Matrix4.rotateX/Y/Z` stubs). This is dead weight on
  top of the already-outdated version, not just "an old version" — it's an old version that was
  already carrying its own legacy cruft in 2014.
- **Real aspect-ratio bug**: `ERNO.Cube`'s constructor sets `camera.aspect` once from the full
  browser window (`WIDTH = window.innerWidth, HEIGHT = window.innerHeight`) at creation time —
  not from the actual `#the-cube` container box (440×440px). Meanwhile the CSS3D renderer's own
  render loop *does* continuously call `renderer.setSize(container.clientWidth,
  container.clientHeight)` from the real container size every frame. This mismatch (a
  perspective/aspect baked in from the whole window, vs. a render box that's actually a small
  fixed square) can visibly skew the cube's perspective depending on the browser window's aspect
  ratio at load time, and never corrects itself on resize. Worth fixing properly in the rewrite
  (derive aspect from the actual container, and update it on resize/`ResizeObserver`).
- **Unscoped global listeners**: keyboard twisting (`X`/`Y`/`Z`/`R`/`M`/`L`/... keys) is wired to
  a single `document`-level `keypress` listener, unconditionally enabled by default
  (`keyboardControlsEnabled: true`), and isn't scoped to cube focus/hover — pressing one of
  those keys anywhere on the About page (outside a text field) silently twists the cube. Not a
  security issue, just a surprising, easy-to-miss side effect worth scoping to the cube's own
  container in a rewrite. Similarly, `ERNO.Interaction`'s and `ERNO.Controls`' mouse/touch
  listeners are also bound at the `document` level rather than the cube's own container,
  meaning every click/touch anywhere on the page runs through the cube's hit-testing logic.
- **Minor inefficiency, not a bug**: `Cubelet.hasColor()` compares colors via a hex-string round
  trip (`_.hexToRgb`) instead of comparing against the shared `ERNO.Color` singleton constants
  directly — harmless today (small, infrequent), but unnecessary indirection to carry into a
  rewrite.
- Confirmed the upstream repo ships pre-built bundles only (`cuber.js`, `cuber.min.js`,
  `cuber.no3D.js` + minified/source-map variants under `js/cube/`) — there is no per-class
  unminified source tree published, despite the README describing one; the "no3D" naming lines
  up with this deployment's CSS3D-only, no-WebGL behavior confirmed in Segment 1.

## Live cleanup performed (2026-08-13)

`wp-content/themes/jackbrain/js/main.js` deleted from the live server (confirmed dead/orphaned
since the 2026-08-08 requirejs fix, zero effect beforehand — see Segment 1 findings). Backed up
first to `main.js.dead-20260813` in the same directory; verified via directory listing that
`main.js` is gone and the backup is present. No functional change to the live site (the file had
zero effect before deletion, since nothing enqueues it anymore).
