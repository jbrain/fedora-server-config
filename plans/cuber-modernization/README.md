# Cuber (Rubik's Cube) Animation Modernization

> **Status: Segment 3 strategy defined (2026-08-13).** Implementation has not started yet —
> see "Segment 3 execution strategy" for the sub-phase plan, review gates, and delegation rules
> before any code is written.

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

## Segment 3 execution strategy (2026-08-13)

Segment 3 is the largest, highest-judgment part of this project (a full engine rewrite), so it's
broken into small sub-phases with an explicit gate after each one — no sub-phase starts until the
previous one's gate passes. This mirrors the gate discipline already used elsewhere in this repo
(`plans/containerization-2026-08/`, `plans/wordpress-ampache-plugin/`) rather than inventing a new
process.

### Non-goals (locked for all of Segment 3 — anything here is scope creep, not a "nice to have")

- No solver, no keyboard-shortcut parity beyond what already ships (and see the aspect/keyboard
  bugs below — those get *fixed*, not carried forward as-is).
- No WebGL/Three.js/any 3D library — Pathway A is CSS transforms + WAAPI only, zero new
  dependencies, no bundler/build step introduced to support one.
- No visual redesign — palette, logo sticker, sizing, and behavior must match production, not
  "while we're in there" improvements to the look.
- No touching the live theme/server until Segment 3g explicitly, and even then only the enqueue
  chain — no unrelated theme/`functions.php` cleanup bundled in.
- No re-litigating Segment 2's Pathway A decision. If Pathway A turns out to be a dead end for
  some concrete technical reason, that's a stop-and-report event, not a silent pivot to Pathway B.

### Sub-phases and gates

| # | Sub-phase | Deliverable | Gate to pass before moving on | Status |
|---|---|---|---|---|
| 3a | Scaffold + test harness | Standalone `index.html`/CSS under `JS/cuber/` with zero WordPress dependency, a container div, and a visible (even if just a static placeholder cube) page for local browser testing | Opens directly in a browser with no server, no console errors | **PASSED (2026-08-13)** — built by subagent, independently verified: screenshot shows correctly proportioned/colored static cube, a fresh Playwright console capture shows exactly one log line and zero errors/warnings |
| 3b | State/model layer | ES module(s): cube state, cubelet addressing, slice/group logic, twist command parsing, direction relationships — conceptually mirrors `ERNO.Cube`/`Cubelet`/`Slice`/`Direction`/`Twist`/`Queue` but real `class` syntax, no prototype monkey-patching, no bundled math library | A plain-console/unit-test check reproduces upstream's own documented equalities (`FRONT.getOpposite() === BACK`, `FRONT.getClockwise() === RIGHT`, etc. from the README) with zero mutation of built-in prototypes | **PASSED (2026-08-13), corrected during 3d prep.** `src/color.js`, `src/direction.js`, `src/twist.js`, `src/cubelet.js`, `src/rotation.js`, `src/solved-color-map.js`, `src/cube.js`. All 16 required README equalities pass; `src/cube.test.mjs` (permanent regression test, run with plain `node`) validates the remapping logic against the original engine's actual live output for all 9 face/slice commands AND the 3 whole-cube commands (R/L/M/U/D/E/F/S/B/X/Y/Z) — full 27-cubelet exact match for R/U/F, targeted spot-checks for the rest, plus round-trip/inverse/shuffle sanity checks — 23/23 pass. See "Ground-truth findings" below. **Correction:** this row originally said whole-cube X/Y/Z rotation was dead keyboard-only code and was skipped — that was WRONG, caught while researching 3d: `ERNO.Locked` (production's actual drag control) commits real X/Y/Z twists when dragging outside the cube, so this is core production behavior, not a debug feature. Added back in, ground-truthed, and verified (see below). Also fixed `shuffle()`'s default move set to match production's actual `PRESERVE_LOGO` set (`R/L/U/D/S/B` only — deliberately excludes `F`/`M`/`E` because they'd rotate or move the front-center logo-sticker cubelet), which the original 3b pass had missed. |
| 3c | Render layer | CSS custom-property-driven transforms for all 27 cubelets, replacing `ERNO.renderers.CSS3D` | Static (non-interactive) cube renders correctly in the harness at the right size/perspective, matching a production screenshot side-by-side | **PASSED (2026-08-13)** — `src/render.js` builds 27 `.cubelet` divs (130px + 2px gap) from the live `Cube` state, reusing 3a's proven `.face--front/back/right/left/up/down` transform rules via inline-style overrides (no duplicate/competing CSS). First pass rendered flat-colored faces; a live side-by-side against `https://jackson-brain.com/about/` showed production actually insets each colored sticker inside a dark plastic face with a visible border — refined `render.js`/`style.css` to add a `.sticker` element (6% inset, `border-radius:10%`) per face instead of coloring the face directly. Re-verified after the fix: screenshot now closely matches production's real look (rounded, bordered stickers, not flat-colored faces), correct palette, correct tilt/perspective, zero console errors. |
| 3d | Interaction layer | Drag-to-twist a face + fixed hero-angle whole-cube orbit (matching `ERNO.Interaction` + `ERNO.Locked`'s behavior) | Manual test pass on desktop (mouse) and a real mobile device/touch emulation — must feel at least as good as production, not just "technically works" | **CORE MECHANISM VERIFIED, gate not fully closed.** `src/interaction.js` (new) implements both face-twist and background whole-cube rotation via the unified Pointer Events API (mouse+touch+pen through one code path — a real simplification over upstream's separate mouse/touchstart listener pairs). Hit-testing which face/cubelet was grabbed uses plain DOM `event.target.closest()` — no raycasting/Projector class needed at all, since cubelets are real DOM elements. Axis disambiguation projects candidate world axes through the live `.cube-group` CSS transform via the browser's native `DOMMatrix`, picking whichever axis's screen projection best aligns with the actual drag vector. **Verified with simulated pointer-event gestures** (not just code review): face-drag then exact reverse returns to solved; background-drag (whole-cube) then reverse returns to solved (24 of 27 cubelets move, matching the 3-cubelets-on-axis fixed-point math); a fast short swipe commits a full 90° turn via the velocity check; screenshot after a real drag-committed twist shows correct, undistorted rendering. **A real bug was caught and fixed by this testing, not by inspection**: a units mismatch (dividing a near-unit-length projected axis vector by its own squared length against pixel-scale drag deltas) produced a 12,000°+ rotation on the very first simulated drag — fixed by normalizing to a true unit vector before scaling by a fixed pixels-per-quarter-turn constant. **What's NOT yet done**: real mobile/touchscreen device testing (only synthetic `PointerEvent`s were used, which exercise the same code path as touch but can't fully validate real-world touch ergonomics/feel), and no live-preview-vs-committed-state visual smoothness check beyond a static before/after screenshot. Gate stays open until those are done. |
| 3e | Animation | WAAPI-driven twist tweening + shuffle-on-load + idle autorotate | Twist timing/easing feels equivalent to the `TWEEN.Easing.Quartic.Out` original side-by-side | **MOSTLY DONE.** `src/animation.js` (new) provides `animateSettle()` (WAAPI, 400ms, `cubic-bezier(0.25,0.46,0.45,0.94)` approximating `Quartic.Out`) and `cssSweepDegrees()`. Prompted directly by the owner noticing the rewrite lost the original's "smooth, slightly magnetized settle into place" feel — `interaction.js`'s drag release now animates from wherever the live preview left off to the final snapped position (or back to 0 if the drag didn't commit), instead of snapping instantly. Shuffle-on-load (`cube.shuffle(5, onEachTwist)`, matching production's own `cube.shuffle(5)` call) now plays each twist as an animated step rather than instantly. **Two more real bugs caught by testing, not inspection**: (1) the live-preview's sign convention and the final committed twist's sign convention were two independently-derived values that could disagree per axis/command — invisible before because the old code snapped instantly with no animation to expose it; a background-drag on the Y axis or a face-drag resolving to `M` (whose sign is opposite `R`'s despite sharing the X axis) would visibly reverse direction mid-settle. Fixed by exporting `getCommandAxisAndSign()` from `cube.js` and always converting through `cssSweepDegrees()` on both sides. (2) The "cancel" (settle-back-to-zero) path never reset the element's inline style after finishing, since WAAPI's default `fill:'none'` reverts to the pre-animation inline style (the stale live-preview value) once an animation ends — verified via `getComputedStyle` after the animation should have finished, not by eyeballing the screen. Verified via simulated drags on X-axis (`M`), Y-axis (up-face), and whole-cube commands: all round-trip to solved, `cube.isSolved()` confirms true. **Not yet done**: idle autorotate-until-interacted.
| 3f | Accessibility/perf | `prefers-reduced-motion` handling, `IntersectionObserver` pause-when-offscreen | Confirmed both behaviors trigger correctly (devtools media-feature override + scroll test) | Not started |
| 3g | Theme integration | Rewrite `functions.php`'s enqueue chain, mount into `#the-cube` directly (not `#container`), remove the old vendor files | Full feature-parity checklist (below) passes on the live About page; old files kept as instant rollback per the master roadmap's Segment 5 | Not started |

### Visual polish: rounded/beveled corners (owner request, 2026-08-13)

The owner asked for the cube's sharp black corners to be rounded/beveled so they don't look
like they're clipping into neighboring cubelets or the page background. `.face` now has a small
`border-radius: 4%` (in `style.css`) in addition to the `.sticker`'s existing `10%`. This has a
real limitation worth recording: flat CSS faces have no true 3D edge/corner bevel geometry, so
rounding a face's corner just reveals whatever sits spatially behind that exact pixel in the 3D
scene — usually an adjacent cubelet's differently-colored face — rather than dark plastic like a
real beveled cube edge would show. A larger radius (tried 14%, 10%, 6%) made this much more
visible as small colored triangles/flecks at internal seams; `4%` was chosen as a reasonable
compromise that softens the outer silhouette without a distracting reveal. A properly correct
fix would need real per-edge/per-corner bevel geometry (small angled strip elements along each
of a cubelet's 12 edges and 8 corners) — a real (small) additional chunk of work, not done here.
Also tried widening the cubelet gap (`GAP` in `render.js`, 2px → 8px) to see if it would hide the
reveal-through artifact; it didn't meaningfully help (the artifact comes from perpendicular faces
meeting at a shared edge, not spacing along one axis), and the owner asked to restore the
original tight 2px gap, which was done.

### Known limitation: shuffle-on-load vs. interaction race (not yet fixed)

`main.js`'s shuffle-on-load animates 5 twists in sequence over ~2 seconds using the same
`animateSettle()` as a real drag commit. `interaction.js`'s own `isSettling` guard only blocks a
*new* drag gesture while *its own* previous settle animation is still playing — it has no
awareness of the separate shuffle-on-load animation loop in `main.js`. If a user starts dragging
within the first ~2 seconds of page load (while the shuffle is still animating), the two could
write conflicting inline styles to the same cubelet elements. Not fixed yet; flagging it here
rather than leaving it silently unaddressed.

### Ground-truth findings from 3b (why empirical validation mattered here)

Built a temporary (now permanent) `JS/cuber/groundtruth.html` harness that loads the OLD vendor
bundle directly and exposes a `snapshot()`/`applyTwist()` API to inspect its real live behavior.
This was essential, not just a nice-to-have: reasoning about twist rotation signs from first
principles got the wrong answer for several commands. Confirmed by direct measurement:

- `R`, `U`, `F` (the three "positive" reference faces) share one rotation sign.
- `L`, `D`, `B` (their opposite faces) are the geometric **inverse** of R/U/F, even though all
  six are "clockwise" in their own uppercase notation — this matches real Rubik's-cube notation
  but is easy to get backwards if derived rather than measured.
- The three middle slices do **not** follow one consistent rule: `M` matches `L`'s direction
  (opposite of `R`), but `E` and `S` both match `U`/`F` (not `D`/`B`) — a genuinely asymmetric,
  non-obvious pattern that would have been very easy to get wrong by assuming symmetry with `M`.
- All of this is encoded in `src/cube.js`'s `COMMANDS` table with an explicit comment warning
  against "simplifying" it from assumed symmetry without re-measuring.

### Ground-truth findings for 3d — `ERNO.Locked` is not what Segment 1 assumed

Segment 1's read of `ERNO.Locked` was incomplete (the fetch got cut off mid-function) and led to
a wrong conclusion, corrected here before it caused a real implementation mistake:

- **It is not a soft camera-only orbit.** Dragging on empty space (outside the cube) tracks the
  drag distance/direction every frame, picks exactly one of the whole-cube `X`/`Y`/`Z` pseudo-
  slices to rotate (snapping to whichever cardinal axis best matches the drag direction — using
  a `getFace()` helper to disambiguate the ambiguous horizontal case between `X` and `Z`), and
  live-updates that slice's `rotation` for visual feedback while dragging. On release, it snaps
  to the nearest 90° multiple (or, if the gesture was fast enough to count as a swipe, commits
  exactly one 90° turn in the swipe direction) and calls `cube.twist(new ERNO.Twist(command,
  angle))` — a real, permanent, tween-animated state change, using the exact same twist/queue
  machinery as a face turn. This directly contradicts this project's own earlier (wrong)
  conclusion that whole-cube X/Y/Z rotation was unused/dead code — it is exactly what powers
  production's actual background-drag interaction. **Fixed in `src/cube.js`**: `X`/`Y`/`Z`
  commands added to the `COMMANDS` table, ground-truthed the same way as the face commands
  (`X` matches `R`'s sign, `Y` matches `U`'s, `Z` matches `F`'s — all confirmed by exact-match
  live comparison against cubelet 0's actual post-twist state, not assumed from the face-command
  pattern).
- **Dragging directly on the cube twists a face instead** (handled by the separate, always-on
  `ERNO.Interaction` class) — `ERNO.Locked` only activates when the initial press misses the
  cube's bounding box entirely (`projector.getIntersection(...) === null`), so the two
  interactions are mutually exclusive based on where the gesture starts, not a mode toggle.
- Also caught while reading the full source: production's actual default `cube.shuffle(5)` call
  uses `this.shuffleMethod = this.PRESERVE_LOGO = 'RrLlUuDdSsBb'` — deliberately excluding `F`,
  `M`, and `E` because all three would rotate or relocate the front-center logo-sticker cubelet.
  **Fixed in `src/cube.js`**: `shuffle()`'s default move set now matches this exactly, verified
  with a new regression-test assertion that the logo cubelet never leaves address 4 after
  repeated shuffles.
- `src/cube.test.mjs` updated accordingly: now 23/23 passing, including ground-truthed spot
  checks for `X`/`Y`/`Z` and the logo-preservation shuffle assertion.

### Feature-parity checklist (checked at every gate, not just the end)

Carried over from Segment 2 decisions — nothing proceeds if any of these regress:
drag-to-twist a face · fixed hero-angle orbit (not free 360°) · shuffle-on-load · idle
autorotate · the "purty" color palette · the logo sticker face · mobile/touch parity ·
`prefers-reduced-motion` · off-screen pause. Plus two *fixes* carried as requirements, not
optional extras: correct aspect ratio tied to the actual container (not `window.innerWidth`),
and cube controls scoped to the cube's own element (not global `document` listeners).

### Anti-drift / anti-bloat mechanisms

- **Checklist-gated, not vibes-gated**: a sub-phase's gate is the literal checklist row above —
  if it doesn't pass, the next sub-phase doesn't start, full stop.
- **Size discipline**: flag it explicitly if any single sub-phase's module ends up larger/more
  complex than the upstream code it replaces — the entire point is a smaller, simpler
  replacement, not a same-size rewrite in a different style.
- **"Zoom out" check at every gate**: does this still look and behave identically to production,
  and is the code doing less than what it replaced (fewer files, no vendored libraries, no
  global monkey-patching)? If a gate review can't answer both "yes," stop and report rather than
  continuing to the next sub-phase.
- **No silent scope changes**: if something in Segment 1/2's findings turns out to be wrong or a
  sub-phase reveals a new constraint, that gets written back into this document at the point it's
  discovered, not discovered again later.

### Delegation plan (what goes to a subagent vs. stays with this thread)

Mechanical, well-specified, low-ambiguity sub-phases are good subagent candidates; anything where
a subtle correctness or fidelity judgment call matters stays here for direct review:

| Sub-phase | Delegate? | Why |
|---|---|---|
| 3a (scaffold/harness) | **Yes** | Pure boilerplate, no correctness risk, easy to eyeball-verify |
| 3b (state/model) | **Partially** — delegate porting the static data (color constants, direction relationship table) with the upstream equalities as an explicit spec to satisfy; keep the twist/slice remapping math in this thread | The remapping math is the one place a subtle bug silently produces a visually-plausible-but-wrong cube; static data has a checkable spec |
| 3c (render layer) | **No** (own the CSS transform math), **but** the repetitive per-cubelet DOM/class scaffolding can be delegated once the transform approach is proven on one cubelet | Same pattern as 3b — prove the risky part first, delegate the repetitive part |
| 3d (interaction) | **No** | Owner explicitly called out current interaction quality as the bar to match; this needs hands-on testing, not a spec-and-check handoff |
| 3e (animation) | **Partially** — easing/timing values can be delegated once the WAAPI wiring pattern is proven | Low risk once the pattern exists |
| 3f (a11y/perf) | **Yes** | Small, well-defined, easy to verify pass/fail |
| 3g (theme integration) | **No** | Touches the live production theme directly |

### Immediate next action

Ready to start **3a** (scaffold + test harness) as the first, lowest-risk sub-phase — say the
word and it'll be delegated to a subagent per the table above, with the result reviewed against
3a's gate before 3b begins.

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
