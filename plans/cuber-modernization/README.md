# Cuber (Rubik's Cube) Animation Modernization

> **Status: PROJECT COMPLETE (2026-08-13).** All 5 segments done. The new zero-dependency
> engine is live on `https://jackson-brain.com/about/`, confirmed working on real desktop mouse
> and a real phone (touch) after four rounds of post-deployment fixes (documented below: size,
> the hero-angle tilt, the core axis-perpendicularity bug, and mobile touch support). Segment 5
> cleanup is done - all old vendor files (`cuber.min.js`, `initCube.min.js`, `cube.js`,
> `require.js`, `main.js`) permanently deleted from the live server; the original vendored
> source stays checked into this repo (`JS/cuber/cuber.js`, `JS/cuber/init_cuber.js`) as
> historical reference. See the "Final comparison" section below for the technology/size/
> resource comparison between the old and new implementations. Update (2026-08-14): production
> now deploys an esbuild bundled+minified build instead of the raw per-file source - see
> "Production build/bundling step" below.

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
| 3d | Interaction layer | Drag-to-twist a face + fixed hero-angle whole-cube orbit (matching `ERNO.Interaction` + `ERNO.Locked`'s behavior) | Manual test pass on desktop (mouse) and a real mobile device/touch emulation — must feel at least as good as production, not just "technically works" | **DONE - gate closed (2026-08-13).** Owner confirmed live on a real phone touch works as expected, after fixing a missing `touch-action: none`/`preventDefault()` (see "Fourth round" below) and a real axis-perpendicularity bug (see "Third round" below). `src/interaction.js` (new) implements both face-twist and background whole-cube rotation via the unified Pointer Events API (mouse+touch+pen through one code path — a real simplification over upstream's separate mouse/touchstart listener pairs). Hit-testing which face/cubelet was grabbed uses plain DOM `event.target.closest()` — no raycasting/Projector class needed at all, since cubelets are real DOM elements. Axis disambiguation projects candidate world axes through the live `.cube-group` CSS transform via the browser's native `DOMMatrix`, picking whichever axis's screen projection best aligns with the actual drag vector. **Verified with simulated pointer-event gestures** (not just code review): face-drag then exact reverse returns to solved; background-drag (whole-cube) then reverse returns to solved (24 of 27 cubelets move, matching the 3-cubelets-on-axis fixed-point math); a fast short swipe commits a full 90° turn via the velocity check; screenshot after a real drag-committed twist shows correct, undistorted rendering. **A real bug was caught and fixed by this testing, not by inspection**: a units mismatch (dividing a near-unit-length projected axis vector by its own squared length against pixel-scale drag deltas) produced a 12,000°+ rotation on the very first simulated drag — fixed by normalizing to a true unit vector before scaling by a fixed pixels-per-quarter-turn constant. **What's NOT yet done**: real mobile/touchscreen device testing (only synthetic `PointerEvent`s were used, which exercise the same code path as touch but can't fully validate real-world touch ergonomics/feel), and no live-preview-vs-committed-state visual smoothness check beyond a static before/after screenshot. Gate stays open until those are done. |
| 3e | Animation | WAAPI-driven twist tweening + shuffle-on-load | Twist timing/easing feels equivalent to the `TWEEN.Easing.Quartic.Out` original side-by-side | **DONE**, and one wrong assumption corrected. `src/animation.js` (new) provides `animateSettle()` (WAAPI, 400ms, `cubic-bezier(0.25,0.46,0.45,0.94)` approximating `Quartic.Out`) and `cssSweepDegrees()`. Prompted directly by the owner noticing the rewrite lost the original's "smooth, slightly magnetized settle into place" feel — `interaction.js`'s drag release now animates from wherever the live preview left off to the final snapped position (or back to 0 if the drag didn't commit), instead of snapping instantly. Shuffle-on-load (`cube.shuffle(5, onEachTwist)`, matching production's own `cube.shuffle(5)` call) now plays each twist as an animated step rather than instantly. **Two more real bugs caught by testing, not inspection**: (1) the live-preview's sign convention and the final committed twist's sign convention were two independently-derived values that could disagree per axis/command — invisible before because the old code snapped instantly with no animation to expose it; a background-drag on the Y axis or a face-drag resolving to `M` (whose sign is opposite `R`'s despite sharing the X axis) would visibly reverse direction mid-settle. Fixed by exporting `getCommandAxisAndSign()` from `cube.js` and always converting through `cssSweepDegrees()` on both sides. (2) The "cancel" (settle-back-to-zero) path never reset the element's inline style after finishing, since WAAPI's default `fill:'none'` reverts to the pre-animation inline style (the stale live-preview value) once an animation ends — verified via `getComputedStyle` after the animation should have finished, not by eyeballing the screen. Verified via simulated drags on X-axis (`M`), Y-axis (up-face), and whole-cube commands: all round-trip to solved, `cube.isSolved()` confirms true. **Correction: "idle autorotate" was never a real production feature.** This was a carried-forward assumption from Segment 2 that was never actually ground-truthed (same category of mistake as the earlier X/Y/Z one). Verified directly against the live page before building anything for it: `window.cube.autoRotate === false`, and `cube.rotation` was bit-for-bit identical across a 3+ second gap with zero interaction — the cube only ever holds the fixed hero angle (`Math.PI*0.1`/`Math.PI*-0.25`) until a user drags it. Removed from the feature-parity checklist below; nothing needs to be built for it.
| 3f | Accessibility/perf | `prefers-reduced-motion` handling, `IntersectionObserver` deferred shuffle-on-load | Confirmed both behaviors trigger correctly (devtools media-feature override + scroll test) | **DONE**, scope corrected first. Originally scoped as "`prefers-reduced-motion` + off-screen **pause**", but off-screen pause was written against the (wrong, since-corrected) assumption of a continuous idle-autorotate loop needing to be paused - confirmed against the live page there is no such loop (see 3e's correction above), so there is nothing continuous to pause once idle. Re-scoped to what's actually real: (1) `src/animation.js`'s `animateSettle()` now checks `window.matchMedia('(prefers-reduced-motion: reduce)')` live (not cached at module load, so a mid-session OS toggle is respected) and uses `duration: 0` instead of 400ms when set - collapses both the twist-settle animation and the shuffle-on-load sequence to instant snaps, with zero other code path changes (same easing/keyframes, just zero-length). (2) `src/main.js`'s shuffle-on-load is now deferred behind an `IntersectionObserver` on `#the-cube` and only starts once the cube actually scrolls into view (disconnects after first fire) - the About page's cube sits below the fold, so this avoids animating 5 twists nobody can see yet. Verified: (a) stubbing `window.IntersectionObserver` before module load and manually firing a synthetic `isIntersecting:true` entry confirms the shuffle stays dormant (`cube.isSolved()===true`) until the callback fires, then runs (`isSolved()===false` after); (b) `page.emulateMedia({reducedMotion:'reduce'})` + reload shows the full 5-move shuffle resolving in ~17ms instead of the ~2000ms five 400ms settles would otherwise take. **One verification gap, honestly noted, not glossed over**: could not directly re-confirm the *normal* (non-reduced-motion) 400ms animation still completes end-to-end in this same browser session, because the automation page had fallen into a backgrounded `document.visibilityState:'hidden'` state (a session/tooling artifact, not a code issue) where Chromium suspends real-duration Web Animations' `finished` promise entirely - a `duration:0` animation still resolved fine in that same state (since it doesn't depend on the frame timeline advancing), which is exactly the differential signature of a throttled background tab, not a regression. The normal-duration code path is untouched other than the value now coming from a ternary instead of a constant, and the 400ms mechanism itself was already exhaustively verified earlier in Segment 3e (matrix-decomposed angle progression across multiple time samples) - low risk, but flagging this gap rather than silently claiming full re-verification.
| 3g | Theme integration | Rewrite `functions.php`'s enqueue chain, mount into `#the-cube` directly (not `#container`), remove the old vendor files | Full feature-parity checklist (below) passes on the live About page; old files kept as instant rollback per the master roadmap's Segment 5 | **DONE and deployed live (2026-08-13).** Found and fixed a real feature-parity gap first: the rewrite never actually rendered the JB logo sticker image (`images/jbrown.png`) - only preserved its POSITION via the shuffle-move-set restriction. Fixed by porting `ERNO.Cubelet`'s `isStickerCubelet` logic exactly: `Cubelet` now gets a one-time `isLogo` flag (true iff its solved-state front face is white and it's a face-center cubelet), set at construction and never recomputed, so it follows the physical cubelet through any twist - matches upstream's own one-time-flag semantics precisely (ground-truthed from the real `isStickerCubelet` assignment in `cuber.js`, not guessed). `render.js` renders that cubelet's one visible sticker with the logo image (`background: <hex> url(...) center/cover`) instead of a flat color; the URL is injected from PHP (`window.CUBER_LOGO_URL`) since a JS-set `url(...)` in an inline style resolves against the DOCUMENT's base URI, not the script's own URL - a relative path baked into render.js would have resolved wrong once deployed under `/about/`. Added 2 new regression tests (25/25 passing) confirming exactly one cubelet is flagged and that the flag survives a whole-cube twist.<br><br>**Deployment**: fetched the live `functions.php`/`style.css`/`jbAbout.php` fresh (not from memory) to confirm the current enqueue chain and exact mount point (`<div id="the-cube">`, confirmed still a bare empty div). Deployed the new engine to `wp-content/themes/jackbrain/js/cuber/` (11 JS files + `style.css`), rewrote `functions.php`'s about-page block to drop the entire RequireJS/`cube.js`/`cuber.min.js`/`initCube.min.js` chain, and removed the old "CUBE CSS" block from the theme's own `style.css` (867 lines kept, ~215 lines of old `.cube`/`.cubelet`/`.face`/`.sticker` rules removed) - **required, not just cleanup**: the new engine reuses those exact same class names, so leaving the old rules in place would have created real, hard-to-diagnose cascade conflicts. Old vendor files (`cube.js`, `cuber.min.js`, `initCube.js`, `initCube.min.js`, `require.js`) renamed `*.dead-20260813` rather than deleted, matching the existing `main.js.dead-20260813` rollback convention; original `functions.php`/`style.css` backed up with a timestamp suffix before any edit.<br><br>**Two real bugs hit and fixed during deployment, not caught by code review**: (1) Initially tried `wp_enqueue_script_module()` (WP 6.5+ Script Modules API) - assumed available since core is 6.9.6, but empirically confirmed via `function_exists()` inside the actual running container that this specific install does NOT have it (not a version-gate issue, genuinely absent) - switched to the long-standing, version-safe `script_loader_tag` filter pattern instead (plain `wp_enqueue_script()` + a filter rewriting the tag to `type="module"`). (2) That filter's first version used `str_replace(' src=', ' type=\"module\" src=', $tag)`, which APPENDS a second `type` attribute rather than replacing WP's own `type=\"text/javascript\"` - per the HTML parsing spec, a duplicate attribute keeps only the FIRST occurrence, so the browser silently loaded it as a classic script and threw `Uncaught SyntaxError: Cannot use import statement outside a module` on the live page (caught by the owner testing in a real browser, not by my own curl-based checks, which only looked for the tag's presence, not its actual number of `type=` attributes). Fixed with `preg_replace('/type=([\"\\'])text\\/javascript\\1/', 'type=\"module\"', $tag, 1)`. Also found and fixed a THIRD issue mid-deployment: `wp_enqueue_script(..., true)` (footer placement) never printed the script tag at all even though `footer.php` does call `wp_footer()` - root cause not fully chased down (something in this theme/plugin stack isn't printing the footer script-queue group), worked around by using `in_footer=false` (head placement), which is functionally identical for a `type=\"module\"` script since the browser defers module execution until after HTML parsing regardless of its position in the document.<br><br>**Verified live, not just deployed**: `curl` confirms the About page returns `200` and the script tag has exactly one `type=\"module\"` attribute; a real Playwright browser session against `https://jackson-brain.com/about/` shows zero console/page errors, `window.cube` exists with 27 cubelets, `cube.isSolved()` reflects real state, a simulated drag gesture changes cube state (interaction confirmed live), and a screenshot confirms correct palette/tilt/proportions AND the logo sticker rendering correctly in its production position. |

### Post-deployment fixes from live owner testing (2026-08-13, same day as 3g deploy)

Real user feedback on the just-deployed live page: "the cube is a little too large" and "the
drag controls don't work correctly, the axis are off and the control areas dont match the
structure". Investigated and fixed both as concrete, ground-truthed bugs rather than vague
"feel" tweaks:

1. **Size bug**: the rewrite used `CUBELET_SIZE = 130` (with a 2px gap), guessed rather than
   ground-truthed. Reading the real upstream constructor (`cuber.js`) shows `ERNO.Cube`'s
   `textureSize` option actually defaults to **120** (`this.cubeletSize = this.size / 3 =
   textureSize`), with cubelet spacing of `size + 0.1` (an effectively invisible sub-pixel gap,
   not a visible 2px one). 130px was ~8% larger than production - exactly matching "a little too
   large". Fixed: `render.js`'s `CUBELET_SIZE` -> 120 (kept the small 2px gap, already
   owner-approved separately); `style.css`'s `.cube-group` (394px -> 364px, margin recalculated)
   and `.cubelet` (130px -> 120px, centering margin recalculated) updated to match - these two
   files have to be kept in sync manually since render.js sets cubelet SIZE inline per-face but
   the `.cubelet`/`.cube-group` container dimensions live only in the CSS file.
2. **Axis-resolution bug**: fetched and read the real, non-minified `ERNO.Interaction` source in
   full (previously only browsed piecemeal) - it resolves the twist axis via a genuine 3D
   `axis = cross(clickedFace.normal, dragDirectionOnPlane)` computation (both vectors obtained
   via real ray-plane intersection through the camera), not a 2D screen-space heuristic. The
   rewrite's `pickBestAxis` instead scored each of a face's 2 candidate axes INDEPENDENTLY (dot
   product of each axis's own screen-projected unit vector against the raw 2D drag delta, take
   whichever has the larger magnitude) - mathematically wrong once projected 2D axes aren't
   orthogonal, which they generally aren't under this cube's fixed 30°/45° tilt. Confirmed
   numerically: computed both methods' pick across a full 360° sweep of drag angles on the front
   face and found real disagreements (e.g. at 145°/325°) - the independent-scoring method can
   legitimately choose the wrong axis. **Fixed properly, not patched**: since exactly 2 candidate
   axes exactly span the 2D screen-projected plane for a face drag, the mathematically correct
   method is an exact 2×2 basis decomposition (solve `s*axisA + t*axisB = dragVector`, whichever
   of `|s|`/`|t|` is larger wins) - implemented as `pickAxis()`/`alongResolvedAxis()` in
   `interaction.js`, replacing the old independent-dot-product `pickBestAxis()`. The 3-candidate
   whole-cube background-drag case keeps the old independent-scoring approach, since 3 axes in 2D
   have no unique exact decomposition anyway (upstream's own `snapVectorToBasis` is itself a
   "closest match" heuristic in that case too, not an exact solve). Verified via a systematic
   sweep of simulated drags in the 4 cardinal screen directions across all 6 faces of a corner
   cubelet: antipodal drags now reliably resolve to opposite-case commands on the *same* axis
   (e.g. right-drag -> `R`, left-drag -> `L` on the front face; right -> `B`, left -> `F` on the
   right face) - internally consistent behavior that wasn't reliably true before.
   **Testing-environment gotcha hit again while verifying this**: sequential simulated drags in
   the same Playwright page silently stopped registering after the first one, because the test
   page was backgrounded (`document.visibilityState === 'hidden'`), which suspends real-duration
   WAAPI `finished` promises - `interaction.js`'s `isSettling` flag then never reset, blocking
   every subsequent `pointerdown`. Not a product bug (a real user's foreground tab never hits
   this) - worked around for testing via `page.emulateMedia({reducedMotion:'reduce'})`, which
   makes `animateSettle` use `duration:0`, resolving instantly regardless of page visibility.

Both fixes redeployed live (`render.js`, `interaction.js`, `cubelet.js`, `style.css` updated in
`wp-content/themes/jackbrain/js/cuber/`), verified via a real Playwright session against
`https://jackson-brain.com/about/`: zero console errors, `window.cube` has 27 cubelets, visibly
smaller cube in a screenshot, logo sticker still renders correctly. Re-tested `cube.test.mjs`
(25/25 still passing - these fixes didn't touch the state model). **Still needs the owner's own
live re-test** to confirm the drag feel is actually correct now, since this class of bug is only
fully confirmable through real interactive use, not automated checks alone.

### Second round of post-deployment fixes (2026-08-13, same day, owner re-tested live)

The owner tested again and reported the size/feel was better but interaction was still broken:
"it looks good until you try to drag any of the sections or cubes. If you click on the top
corner cubes and drag down, the top section spins horizontally. Not all drags complete and it's
easy to corrupt everything - we seem to be missing several key concepts from the original
version." Investigated further and found two MORE real, concrete bugs:

1. **Shuffle-on-load vs. interaction race condition (the previously-known, unfixed gap - this was
   the primary cause of "not all drags complete" / "easy to corrupt everything").** Confirmed by
   reading the real upstream source: `this.mouseInteraction.enabled = this.mouseControlsEnabled
   && !this.finalShuffle` - production explicitly DISABLES all mouse/touch interaction for the
   entire duration of a shuffle sequence (`finalShuffle` stays truthy until the last shuffle
   twist's tween completes). The rewrite never ported this guard at all - `interaction.js`'s
   `isSettling` flag and `main.js`'s `shuffleOnLoad()` were two completely independent state
   machines with zero awareness of each other. A user interacting during (or racing against) the
   ~2-second shuffle-on-load window would have their gesture's `resolveAxis`/`cube.twist()`
   calls interleave with the shuffle's own concurrent `cube.twist()`/`renderCube()` calls -
   `cube.twist(command)` always operates on whichever cubelets CURRENTLY satisfy that command's
   filter at call time, so a command resolved against the layer assignment at drag-START could
   easily rotate a completely different/wrong set of cubelets by drag-END if the shuffle moved
   things in between - exactly matching "not all drags complete" (the visible result doesn't
   match the gesture) and "easy to corrupt everything" (the cube ends up in configurations that
   don't obviously trace back to the user's actual actions). **Fixed** by porting the same guard:
   `attachInteraction()` now returns a `setEnabled(bool)` API; `main.js`'s `shuffleOnLoad()`
   calls `interaction.setEnabled(false)` before starting and `setEnabled(true)` only after
   `await cube.shuffle(5, ...)` fully resolves (the function was already `async`/awaits each
   step, so no new plumbing was needed to know when shuffle is truly done).
2. **The hero-angle tilt was wrong since Segment 3a - a bigger root cause than initially
   assumed.** `style.css`'s `.cube-group`/`.cube` used `rotateX(-30deg) rotateY(-45deg)`, a value
   that was guessed for the very first static placeholder and never re-verified against the real
   bootstrap math. Ground-truthed properly this time by running the ACTUAL production bootstrap
   sequence from `init_cuber.js` in the real engine and reading out the resulting rotation:
   `cube.object3D.lookAt(cube.camera.position)` (resolves to identity for this camera/cube
   arrangement) followed by `rotation += Euler(Math.PI*0.1, Math.PI*-0.25, 0)`, i.e. exactly
   **(18deg, -45deg, 0)** in Three.js's Y-up convention. Confirmed the CSS-equivalent sign
   empirically (not guessed): rendered the real engine at this exact angle with real "purty"
   colors attached (groundtruth.html has no stylesheet by default, so a minimal one was added
   just for this comparison) and screenshotted it side-by-side against several candidate CSS
   values - `rotateX(-18deg) rotateY(-45deg)` was the one that visually matched (CSS's Y-down
   convention flips the X-rotation sign relative to Three's Y-up convention, consistent with
   `render.js` already negating Y for cubelet position for the same reason). The OLD `-30deg`
   value showed a dramatically more steeply-downward "bird's eye" view (a large, prominent UP
   face) compared to production's actual near-level angle (barely a sliver of UP visible) -
   confirmed unambiguously via screenshot comparison. Since `interaction.js`'s entire axis-
   resolution math reads this SAME `.cube-group` transform to project candidate axes to screen
   space, this single wrong constant meant EVERY axis-resolution decision for the whole session
   was being computed against the wrong 3D basis - independent of (and likely compounding) the
   2x2-solve fix from the first round of post-deployment fixes. This is very likely the deeper
   root cause of "the axis are off."
   **Verification limitation, noted honestly**: attempted to directly ground-truth the exact
   "front face of a top corner, drag down" gesture against the real engine with real mouse
   controls enabled (`ERNO.Locked`) to definitively settle whether an axis-resolution answer like
   `u` (a Y-axis "horizontal spin") is actually correct for that specific gesture - hit a wall
   where constructing a second `ERNO.Cube` instance with mouse controls enabled in this same test
   page never populated any DOM elements (0 divs rendered, no thrown errors, `requestAnimationFrame`
   apparently never invoked by the library's own internal loop) - a real, reproducible harness
   limitation that time did not permit fully root-causing, distinct from and not blocking the two
   confirmed fixes above. The corrected tilt was verified to visually match production exactly;
   the axis-resolution math was independently verified for internal consistency (antipodal-drag
   symmetry) in the first round of fixes. Both are strong, ground-truthed corrections on their
   own merits even without this last piece of direct behavioral ground-truthing.

Both fixes redeployed live (`interaction.js`, `main.js`, `style.css`). Verified via a real
Playwright session against `https://jackson-brain.com/about/`: zero console errors, cube renders
at the visually-confirmed-correct tilt, a real drag gesture changes cube state cleanly with all
27 cubelet addresses remaining unique afterward (no corruption). `cube.test.mjs` re-run, 25/25
still passing. **Still needs the owner's own live re-test** - this is now the third round of
size/tilt/race-condition fixes, all ground-truthed against real production source rather than
guessed, and the owner should specifically re-try the exact gesture that prompted this report
("grab a top corner, drag down") to confirm it now feels correct.

### Third round of post-deployment fixes (2026-08-13, same day) - the actual core interaction bug

The owner re-tested and gave the exact, concrete expected behavior: **"the original version
would allow rotation of any section - dragging left or right on any of the cubes in the layer
would spin the section horizontally, dragging up or down would rotate the section vertically."**
This precisely pinpointed the real, remaining bug - and it was a genuine geometry mistake, not a
tuning/feel issue:

**The resolved rotation axis must be PERPENDICULAR to the drag direction, not aligned with it.**
The real `ERNO.Interaction` formula is `axis = cross(faceNormal, dragDirectionOnPlane)` - a cross
product is by definition perpendicular to both of its inputs. The rewrite's axis-picking (both
the original independent-dot-product version AND the "corrected" 2x2-basis-decomposition version
from the first round of fixes) instead picked whichever candidate axis's own screen projection
was most ALIGNED with the drag vector - the exact opposite relationship. Proved this two ways:
(1) re-derived the cross-product algebra directly: for the front face (normal on the Z axis),
`cross(Z, s*X + t*Y) = s*Y - t*X` - the dominant coefficient SWAPS from whichever axis the drag
aligns with to the OTHER one; (2) computed it numerically in a live browser session against the
corrected tilt: a purely horizontal drag's implied 3D direction (~aligned with X) produces a
cross-product axis whose dot product with Y is `-1.0` and with X is `~0` - i.e. a horizontal drag
unambiguously resolves to the Y axis (a horizontal "lazy-susan" spin, matching "spins
horizontally"), and a vertical drag resolves to X (a vertical "wheel" tumble, matching "rotates
vertically") - exactly the owner's description, and exactly the opposite of what the rewrite was
computing. **Fixed properly** (not patched): `pickAxis()` now does the full 3D version of the
real algorithm for face drags - decomposes the 2D screen drag into the clicked face's 2 candidate
axes' basis (still needed, but now just to *reconstruct* a true 3D drag-direction vector using
the axes' full 3D-transformed vectors, not to pick a winner directly), crosses that with the
face's real 3D normal (also obtained via a new `transformVector3D()` helper that keeps all 3
transformed components instead of flattening to 2D screen space), then finds which candidate axis
that result actually matches via a 3D dot product. For BACKGROUND (whole-cube) drags, applied the
same perpendicular principle more simply: since the "clicked plane" is the viewport itself facing
the camera, `cross((0,0,1)_view, (dx,dy,0)_view) = (-dy, dx, 0)` reduces to a plain 90-degree
rotation of the raw 2D drag vector - matches upstream's own `ERNO.Locked` background-drag code,
which independently also rotates the drag vector 90 degrees (`c.set(q.y*-1, q.x, 0)`) before
matching it to the nearest cardinal axis, confirming the same principle applies there too.
Verified via isolated (fresh-reload-per-test, no cross-contamination between sequential twists)
drag tests on the front face: right-drag -> `u`, left-drag -> `U`, down-drag -> `r`, up-drag ->
`R` - exactly the owner's stated pattern, with correct antipodal case-symmetry preserved. Also
re-verified a whole-cube background drag: forward `U` then reverse `u` returns to solved.
`cube.test.mjs` re-run, 25/25 still passing (state model untouched, only `interaction.js`
changed). Redeployed live, re-verified via Playwright against the real page: zero console
errors, a live horizontal drag on the front face gives `u` as expected.

**Lesson for this class of bug going forward**: when a rewrite's math "looks reasonable" and
passes internal-consistency checks (e.g. antipodal symmetry) but a real user still reports the
result feels wrong, don't just re-tune the SAME approach - re-derive the ORIGINAL algorithm's
actual geometric relationship (aligned vs. perpendicular, in this case) from its real source
formula and check whether the rewrite's approach is testing the same relationship at all, not
just whether it's internally self-consistent.

The owner confirmed this round fixed the interaction feel ("this works much better") and asked
for two follow-up visual tweaks: reduce the cube size slightly further, and lower its position so
it reads more clearly as sitting below the paragraph text. `render.js`'s `CUBELET_SIZE` reduced
120px -> 100px (below the already-ground-truthed-correct 120px, per explicit owner request this
time, not a bug fix); `style.css`'s `#the-cube`/`.cube-group`/`.cubelet`/`.cube` dimensions
recalculated to match (370px container, 304px group, 100px cubelets), and `#the-cube`'s top
margin increased from 40px to 90px to push it further down the page. Verified visually in the
harness and live on `https://jackson-brain.com/about/` (screenshot confirms the cube now sits
clearly below the full paragraph of text, smaller than before, logo sticker unaffected).
`cube.test.mjs` re-run, 25/25 still passing (pure visual/CSS change, no state/interaction logic
touched). Redeployed live, zero console errors.

### Fourth round: touch input on mobile didn't work (owner request, 2026-08-13)

The owner reported: "mouse control on a desktop seems to work well, but touch control on a phone
does not." A classic, well-known Pointer-Events gotcha, found immediately by checking for it:
**neither `touch-action` CSS nor any `event.preventDefault()` call existed anywhere in this
codebase.** Without `touch-action: none` on the interactive element, a touch-drag is claimed by
the browser's own native scroll/pan gesture recognizer before `interaction.js`'s `pointermove`
listener ever sees a complete gesture - mouse input has no equivalent native gesture to compete
with, so it worked fine while touch didn't, exactly matching what was reported. Confirmed the
original engine actually handles this explicitly too (`ERNO.Interaction`'s real touchstart/
touchmove handlers both call `event.preventDefault()`), so this wasn't a "nice to have," it was a
dropped requirement from the rewrite. **Fixed**: added `touch-action: none;` to `#the-cube` in
`style.css` (the modern, standards-based way to tell the browser upfront not to intercept touch
gestures on this element - lets pointer events fire completely and continuously without racing
the browser's own scroll detection), plus `event.preventDefault()` in `interaction.js`'s
`onPointerDown`/`onPointerMove` as belt-and-suspenders matching upstream's own defensive pattern.
Verified `touch-action: none` is present in computed style both in the harness and live on
`https://jackson-brain.com/about/`; a simulated drag still resolves correctly with the added
`preventDefault()` calls in place; `cube.test.mjs` re-run, 25/25 still passing. **Honest
limitation**: Playwright's synthetic `PointerEvent` dispatch bypasses real OS-level touch gesture
capture entirely, so this specific class of fix can't be end-to-end verified by automated tooling
the way the axis-resolution fix could be - it relies on `touch-action: none` being the correct,
well-established mechanism for this exact problem, not on a test proving it.

**Owner confirmed live on a real phone (2026-08-13): "This appears to work as expected."** This
closes Segment 3d's previously-open real-device-testing gate - touch and mouse both now verified
working on the live production page, not just via synthetic PointerEvents.

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
drag-to-twist a face · fixed hero-angle orbit (not free 360°) · shuffle-on-load · the "purty"
color palette · the logo sticker face · mobile/touch parity · `prefers-reduced-motion` ·
deferred shuffle-on-load until scrolled into view (re-scoped from "off-screen pause" - see 3f's
note above, there's no continuous loop to pause). Plus two *fixes* carried as requirements, not optional extras: correct aspect
ratio tied to the actual container (not `window.innerWidth`), and cube controls scoped to the
cube's own element (not global `document` listeners). ~~idle autorotate~~ removed 2026-08-13 —
ground-truthed against the live page and confirmed it was never a real production feature (see
the 3e row's correction above); nothing to build or preserve for it.

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
  period, then clean up. **DONE (2026-08-13).** The soak period was the four rounds of live
  owner testing/fixes documented above, ending in explicit confirmation on both real desktop
  mouse and a real phone. All six `*.dead-20260813` old vendor files (`cube.js`, `cuber.min.js`,
  `initCube.js`, `initCube.min.js`, `main.js`, `require.js`) permanently deleted from
  `wp-content/themes/jackbrain/js/` on the live server. Verified after deletion: About page
  still returns `200`, zero console errors, `window.cube` has 27 cubelets, `isSolved()` reads
  correctly - confirms the new engine has zero runtime dependency on the deleted files (it only
  ever loaded from its own `js/cuber/` subdirectory). The original vendored source
  (`JS/cuber/cuber.js`, `JS/cuber/init_cuber.js`) stays checked into this repo as historical
  reference/rollback source, matching this repo's established convention for other decommissioned
  components (e.g. `apache/`, `airsonic/`).

### Final comparison: old vendor bundle vs. new engine (2026-08-13)

| | Old (removed) | New (live) |
|---|---|---|
| **Core dependencies** | Three.js r66 (2014) + TWEEN.js r12 (2012) + RequireJS (AMD loader), Closure-Compiler-built into one minified bundle (no build config in this repo - a black box) | Zero dependencies - plain native ES modules (`import`/`export`), no build step, real source is what ships |
| **Live-loaded JS (actual browser transfer)** | `require.js` + `cube.js` + `cuber.min.js` + `initCube.min.js` = **150,542 bytes (~147 KB)**, all minified | 11 plain `.js` files = **44,967 bytes (~44 KB)** unminified/uncompiled |
| **CSS** | Embedded in the theme's global `style.css` (~215 lines of cube-specific rules, since removed) | Dedicated `js/cuber/style.css`, **4,844 bytes** |
| **Total cube-specific footprint** | ~147 KB JS + embedded CSS | ~48.6 KB JS+CSS combined - **roughly a 67% reduction**, despite having zero minification at all |
| **DOM nodes per cubelet face** | 6 (`.face` + `.wireframe` + `.id`+`.underline` + `.text` + `.sticker`, most `display:none` debug-only elements) | 2 (`.face` + `.sticker`) - about **1/3 the element count**, ~972 -> ~324 elements across all 27 cubelets |
| **3D math** | Full `THREE.Object3D`/`Quaternion`/`Matrix4`/`Euler` machinery for every cubelet, every frame | Plain integer `{x,y,z}` coordinate arithmetic - no matrix/vector/quaternion library needed at all, since every twist is an exact 90°-multiple rotation around a cardinal axis |
| **Rendering** | Custom `CSS3DRenderer` re-serializes a full `matrix3d(...)` string from a THREE matrix for every object, every frame, inside a `requestAnimationFrame` loop that **runs forever, even fully at rest** | Plain `transform: translate3d()/rotateX/Y/Z()` strings written directly, no serialization step - **zero continuous per-frame JS work**; only runs during an actual twist/settle |
| **Animation** | `TWEEN.js` mutates plain JS numbers every animation frame; the render loop above must independently notice the change and re-render | Native Web Animations API (`element.animate()`) - GPU-composited, scheduled entirely by the browser, not JS; supports `prefers-reduced-motion` natively via `duration:0` |
| **Global scope hygiene** | Monkey-patches `Number.prototype`/`String.prototype`/`Array.prototype` with dozens of custom methods; unscoped `document`-level keyboard listener twists the cube from a keypress anywhere on the page | Zero prototype modification; all listeners scoped to the cube's own container element (plus `window` only for the duration of an active drag); no keyboard control (out of scope per Segment 2) |
| **Known bugs inherent to the old architecture** | Real camera-aspect-ratio bug (computed once from `window.innerWidth/innerHeight` at construction, never the real container box, never recalculated on resize) | No camera/aspect-ratio concept exists at all in a flat-CSS-perspective architecture - this entire bug class is structurally impossible now |
| **Accessibility** | No `prefers-reduced-motion` support, no visibility-based deferral | `prefers-reduced-motion` collapses animations to instant; shuffle-on-load deferred via `IntersectionObserver` until actually scrolled into view |
| **Debuggability** | Minified/Closure-compiled bundle - real source not available in devtools without external source maps (none shipped) | Plain, unminified ES module source is exactly what ships and what devtools shows - no source maps needed |

### Solved-state event (owner request, 2026-08-13)

Owner asked whether a scrambled cube is always solvable - yes, structurally guaranteed: the
shuffle (and every user twist) only ever applies legal moves from the solved state, so any
reachable state is by construction solvable back to identity via the inverse move sequence (a
basic Rubik's-cube group-theory fact, not something requiring a runtime check). What the owner
actually wanted: a hook to build on later, not a solver. Added a `cubesolved` `CustomEvent`,
dispatched on `#the-cube` exactly once on the transition into a solved state after a user's
committed twist (not on every commit while already solved, e.g. a twist immediately undone -
tracked via an `isSolved()` snapshot taken immediately before the twist). `main.js` wires up a
placeholder `console.log('Cube solved!')` listener as a starting point for a future celebration/
notification UI. Verified via simulated drag+reverse: `cubesolved` fires exactly once, only on
the actual return to solved, not before. `cube.test.mjs` re-run, 25/25 still passing (state
model untouched). Redeployed live, zero console errors.

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

## Production build/bundling step (owner request, 2026-08-14)

Owner wanted commented, unminified source kept in the repo but a minified build deployed to
prod. Added [esbuild](https://esbuild.github.io/) as a dev-only build tool rather than hand-
rolling minification — it's a single static binary (no transitive dependency tree to audit),
had zero vulnerabilities once pinned to `^0.25.0` (0.24.x had a moderate-severity advisory,
GHSA-67mh-4wv8-2f99, in its dev-server's CORS handling — irrelevant to this project's pure CLI
bundling use, but pinned to the patched version anyway).

- **`JS/cuber/package.json`** (new): declares `esbuild` as the sole devDependency and one
  `"build"` script: `esbuild src/main.js --bundle --minify --outfile=dist/main.js && esbuild
  style.css --minify --outfile=dist/style.css`. `"type": "module"` added so Node doesn't need to
  guess/reparse `src/*.js` when running `cube.test.mjs`.
- **`JS/cuber/dist/`** (build output, gitignored — see below): `main.js` (bundled+minified IIFE,
  11,841 bytes) + `style.css` (minified, 1,588 bytes). Regenerate any time with `npm install &&
  npm run build` from `JS/cuber/`.
- **`.gitignore`**: added `JS/cuber/node_modules/` and `JS/cuber/dist/` — both are fully
  regenerable build artifacts (same treatment as PHP's `vendor/`), so they're not committed;
  `npm run build` must be re-run locally before every future deployment.
- **Size, three-tier comparison**: old vendor bundle ~147 KB (minified, Segment 5 baseline) →
  new unminified source ~48.6 KB (11 files + CSS, what's actually in `src/`) → new
  bundled+minified ~13.2 KB (11.6 KB JS + 1.6 KB CSS) — roughly **91% smaller than the original
  old bundle**, and about **73% smaller than the unminified rewrite**, while `src/` keeps 100%
  of its comments and file boundaries for ongoing development.
- **`functions.php` simplified**: since esbuild's browser-platform output (no `--format` flag)
  is a plain self-executing IIFE, not an ES module, the previous `script_loader_tag` filter
  workaround (forcing `type="module"` because this WP 6.9.6 build lacks
  `wp_enqueue_script_module()`) is no longer needed at all and was removed. A plain
  `wp_enqueue_script('cuber-main', ...)` call is now sufficient for the module/type concern.

### Real bug found on first bundled deploy: missing defer caused `null.querySelector`

First live check after swapping in the bundle produced a real page error: `TypeError: Cannot
read properties of null (reading 'querySelector')` from inside `main.js`. Root cause: an ES
module script (`type="module"`) is *automatically* deferred by the HTML spec regardless of
where its `<script>` tag sits in the document — but a plain classic script (no `type="module"`,
no `defer`/`async` attribute) runs synchronously, immediately, at parse time. This theme's
`wp_enqueue_script(..., in_footer)` boolean was set to `false` (head placement) as a
long-standing workaround for a separate, earlier-discovered bug where `in_footer => true`
silently prints nothing at all in this theme. Once the bundle stopped being a module, it lost
its automatic deferral, so it started running in `<head>`, before `#the-cube` existed in the
DOM — `document.getElementById('the-cube')` returned `null`, and the next `.querySelector()`
call on it threw.

**Fix**: use WP 6.3+'s script-loading-strategy array form of `wp_enqueue_script()` — replace
the trailing `in_footer` boolean with `array('strategy' => 'defer', 'in_footer' => false)`.
This adds a real `defer` attribute (confirmed live: `<script ... defer="defer"
data-wp-strategy="defer">`), keeping head placement (working around the footer-print bug) while
restoring defer-until-DOM-ready timing equivalent to what `type="module"` gave for free.
Verified via Playwright against the live page: zero console/page errors, `window.cube` exists
with 27 cubelets, and a simulated drag on the live bundle still resolves correctly. This is a
generalizable gotcha: **switching a `type="module"` script to a bundled classic/IIFE script
loses automatic deferral — always add `defer` explicitly (or move to true `in_footer`
placement) when doing this kind of module-to-bundle migration.**

### Deployment procedure going forward

1. Edit source in `JS/cuber/src/*.js` / `JS/cuber/style.css` as normal (comments intact).
2. `cd JS/cuber && npm install && npm run build` (regenerates `dist/main.js` + `dist/style.css`).
3. `node JS/cuber/src/cube.test.mjs` — confirm 25/25 still pass (state-model regression check).
4. Deploy `dist/main.js` + `dist/style.css` (not the raw `src/` files) to
   `wp-content/themes/jackbrain/js/cuber/`, matching ownership (`33:33`) and following the
   existing backup-before-overwrite pattern used throughout this project.
5. Restart the WordPress container (`sudo systemctl restart wordpress`) — required for the
   `:Z` SELinux relabel of newly bind-mounted files, an established gotcha from earlier in this
   project.
6. Verify live: curl 200 check, then a real Playwright pass (zero console errors, `window.cube`
   exists with 27 cubelets, a simulated drag resolves correctly) — a superficial curl-only check
   is not sufficient, as this session's `defer` bug demonstrates.
