# Cuber (Rubik's Cube) Animation Modernization

> **Status: Segment 1 complete (2026-08-13) — research + full architecture discovery.**
> Nothing has been changed on the live site yet. This document is the findings report and
> proposed phased roadmap; implementation has not started. See "Open questions" at the bottom
> before Segment 2 begins.

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
   - `ERNO.Locked` — a **custom, site-specific drag/twist control scheme** (raycasting via an `ERNO.Projector`, mouse + touch), distinct from the library's own default orbit controls. This is what makes the cube interactive (dragging a face twists it), not merely decorative — important to preserve.
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

**Pathway B** (modern Three.js WebGL) is the alternative if the owner wants an actual visual
upgrade (real lighting/bevels) rather than a pure modernization — worth a quick "do you want
it to look better, or just be modern under the hood" decision before Segment 2 starts.

## Proposed phased roadmap (segments)

- **Segment 1 (this document) — DONE.** Research + full live-architecture discovery, findings
  documented, no code changed.
- **Segment 2 — Decision + design.** Owner picks a pathway (A / B / hybrid); write a feature-
  parity checklist (drag-to-twist via `ERNO.Locked`'s behavior, shuffle-on-load, the "purty"
  palette, the logo sticker face, mobile touch, and any accessibility/perf additions —
  `prefers-reduced-motion`, `IntersectionObserver`-gated pause when scrolled off-screen).
- **Segment 3 — Implementation.** Build the new engine/state+render layer under `JS/cuber/`
  (or a clean rename), with a standalone local HTML test harness with no WordPress dependency
  for fast iteration.
- **Segment 4 — Theme integration.** Replace the `functions.php` enqueue chain, mount directly
  into `#the-cube` (not `#container`), delete confirmed-dead files (`main.js`, the IE-CSS3D
  path, the unused `deviceMotion` helper, the old vendor bundle).
- **Segment 5 — Staging verification + rollback.** Live-verify on `/about/`, keep the current
  `cuber.min.js`/`initCube.min.js`/`cube.js` as an instant server-side revert path during a soak
  period, then clean up.

## Open questions for the owner (before Segment 2 starts)

1. **Pathway**: pure CSS + Web Animations API (zero dependencies, same look) vs. modern
   Three.js WebGL (visual upgrade) vs. hybrid?
2. Keep the drag-to-twist interaction exactly as today (`ERNO.Locked`'s behavior), or open to a
   different interaction model?
3. Add `prefers-reduced-motion` support and pause-when-off-screen as part of this effort, or
   out of scope for now?
4. OK to delete `main.js` and the IE-CSS3D code path immediately (confirmed dead/unused today),
   or hold until the new engine actually replaces the current one in Segment 4?
