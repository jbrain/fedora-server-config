import { positionTransform } from './render.js';
import { animateSettle, cssSweepDegrees } from './animation.js';
import { getCommandAxisAndSign } from './cube.js';

// Interaction model (ground-truthed against the original engine - see
// plans/cuber-modernization/README.md's "Ground-truth findings for 3d"):
// - Dragging directly on a cubelet face twists the slice that face belongs to.
// - Dragging on empty background performs a whole-cube X/Y/Z rotation instead -
//   NOT a soft camera orbit; it's a real, committed twist using the exact same
//   twist machinery, just affecting all 27 cubelets. The viewing tilt itself
//   (.cube-group's CSS transform) never changes - "orbiting" is simulated by
//   physically reorienting the cube's own state, which is what keeps the view
//   pinned to one fixed hero angle instead of allowing free 360-degree orbit.
const DRAG_THRESHOLD = 8; // px before a gesture commits to a rotation axis
const SWIPE_VELOCITY = 0.5; // px/ms - a fast flick commits a full turn regardless of distance
const PIXELS_PER_QUARTER_TURN = 150; // drag this many px along the resolved axis for 90 degrees

// For a grabbed face (by direction id, matching ALL_DIRECTIONS: front/up/right/
// down/left/back), the two axes perpendicular to that face's own normal - those
// are the only two a drag on that face could plausibly be rotating around.
const FACE_AXES = {
  0: ['x', 'y'], // front
  1: ['x', 'z'], // up
  2: ['y', 'z'], // right
  3: ['x', 'z'], // down
  4: ['y', 'z'], // left
  5: ['x', 'y'], // back
};

// Which twist command letter corresponds to a given axis + which layer along it
// (by the grabbed/rotating cubelet's own coordinate, -1/0/1) - reuses the exact
// same letters already ground-truthed in cube.js.
const LETTERS_BY_AXIS = {
  x: ['L', 'M', 'R'],
  y: ['D', 'E', 'U'],
  z: ['B', 'S', 'F'],
};

// Projects a world-space unit axis through the cube-group's CURRENT CSS transform
// into 2D screen space, using the browser's own DOMMatrix instead of reimplementing
// 3D projection math - a real simplification over the original engine's custom
// Vector3/Matrix4/Plane-based Projector class, made possible by rendering real DOM
// elements instead of an abstract Three.js scene graph.
function projectAxisToScreen(groupElement, axis) {
  const matrix = new DOMMatrixReadOnly(getComputedStyle(groupElement).transform);
  // Y is negated here for the same reason render.js negates it when positioning
  // cubelets: the state model's Y+ is "up", CSS's Y+ is "down".
  const origin = matrix.transformPoint(new DOMPoint(0, 0, 0));
  const tip = matrix.transformPoint(new DOMPoint(axis === 'x' ? 1 : 0, axis === 'y' ? -1 : 0, axis === 'z' ? 1 : 0));
  return { x: tip.x - origin.x, y: tip.y - origin.y };
}

// Same idea as projectAxisToScreen but keeps all 3 output components - the group's own
// CSS transform is a pure rotation (perspective is applied separately, to #the-cube
// itself), so this gives an accurate 3D-rotated vector, not just its flattened screen
// shadow. Needed for the real cross-product axis math below.
function transformVector3D(groupElement, vec) {
  const matrix = new DOMMatrixReadOnly(getComputedStyle(groupElement).transform);
  const origin = matrix.transformPoint(new DOMPoint(0, 0, 0));
  const tip = matrix.transformPoint(new DOMPoint(vec.x, -vec.y, vec.z));
  return { x: tip.x - origin.x, y: tip.y - origin.y, z: tip.z - origin.z };
}

const AXIS_VECTORS = { x: { x: 1, y: 0, z: 0 }, y: { x: 0, y: 1, z: 0 }, z: { x: 0, y: 0, z: 1 } };

// Absolute Direction-id-indexed face normals (front/up/right/down/left/back), matching
// ALL_DIRECTIONS/direction.js's own convention (state-model coordinates, Y+ up).
const FACE_NORMALS = [
  { x: 0, y: 0, z: 1 }, // front
  { x: 0, y: 1, z: 0 }, // up
  { x: 1, y: 0, z: 0 }, // right
  { x: 0, y: -1, z: 0 }, // down
  { x: -1, y: 0, z: 0 }, // left
  { x: 0, y: 0, z: -1 }, // back
];

function cross3D(a, b) {
  return { x: a.y * b.z - a.z * b.y, y: a.z * b.x - a.x * b.z, z: a.x * b.y - a.y * b.x };
}
function dot3D(a, b) {
  return a.x * b.x + a.y * b.y + a.z * b.z;
}

// Resolves which candidate axis a drag actually twists. Ground-truthed against the
// real ERNO.Interaction algorithm - and a real, reported bug fixed by this ground-
// truthing: the resolved axis must be PERPENDICULAR to the drag direction, not
// aligned with it (`axis = cross(faceNormal, dragDirectionOnPlane)` - a cross product
// is by definition perpendicular to both its inputs). An earlier version of this
// picked whichever candidate axis's OWN screen projection was most ALIGNED with the
// drag vector - backwards, confirmed both by re-deriving the cross-product math (for
// the front face, cross(normalZ, s*X+t*Y) = s*Y - t*X, i.e. the dominant component
// SWAPS from X to Y) and by the live report: "dragging left/right on any cube in the
// layer spins horizontally [Y-axis, U/E/D], dragging up/down rotates vertically
// [X/Z-axis, L/M/R or B/S/F]" - which is exactly the perpendicular relationship, not
// the aligned one this code previously computed.
function pickAxis(candidateAxes, groupElement, dragX, dragY, directionId) {
  const projected = candidateAxes.map((axisName) => ({ axisName, screen: projectAxisToScreen(groupElement, axisName) }));

  if (directionId !== null) {
    // FACE drag (exactly 2 candidates, which exactly span the clicked face's tangent
    // plane): decompose the 2D screen drag into that plane's own basis (s, t), then
    // reconstruct a true 3D drag-direction vector from the SAME basis using the 3D
    // (not just screen-projected) axis vectors, cross it with the face's real 3D
    // normal, and see which candidate axis that result actually matches.
    const [a, b] = projected;
    const det = a.screen.x * b.screen.y - b.screen.x * a.screen.y;
    const s = (dragX * b.screen.y - b.screen.x * dragY) / det;
    const t = (a.screen.x * dragY - dragX * a.screen.y) / det;

    const aVec3D = transformVector3D(groupElement, AXIS_VECTORS[a.axisName]);
    const bVec3D = transformVector3D(groupElement, AXIS_VECTORS[b.axisName]);
    const direction3D = {
      x: s * aVec3D.x + t * bVec3D.x,
      y: s * aVec3D.y + t * bVec3D.y,
      z: s * aVec3D.z + t * bVec3D.z,
    };
    const normal3D = transformVector3D(groupElement, FACE_NORMALS[directionId]);
    const rotationAxis3D = cross3D(normal3D, direction3D);

    const scoreA = dot3D(rotationAxis3D, aVec3D);
    const scoreB = dot3D(rotationAxis3D, bVec3D);
    return Math.abs(scoreA) >= Math.abs(scoreB)
      ? { axisName: a.axisName, projected, directionId, alongAxis: scoreA }
      : { axisName: b.axisName, projected, directionId, alongAxis: scoreB };
  }

  // BACKGROUND (whole-cube) drag: the "clicked plane" is the viewport itself, facing
  // the camera - so its normal is the fixed screen/view Z axis (0,0,1), untransformed
  // by the group's own rotation. cross((0,0,1), (dx,dy,0)) = (-dy, dx, 0): a plain 90-
  // degree rotation of the drag vector in screen space. Matches upstream's own
  // background-drag logic (`ERNO.Locked`), which also rotates the drag vector 90
  // degrees (`c.set(q.y*-1, q.x, 0)`) before matching it to the nearest cardinal axis.
  const rotated = { x: -dragY, y: dragX };
  let best = null;
  let bestAbsScore = -Infinity;
  for (const { axisName, screen } of projected) {
    const length = Math.hypot(screen.x, screen.y) || 1;
    const score = (screen.x * rotated.x + screen.y * rotated.y) / length;
    if (Math.abs(score) > bestAbsScore) {
      bestAbsScore = Math.abs(score);
      best = { axisName, projected, directionId, alongAxis: score };
    }
  }
  return best;
}

// Recomputes "how far the drag has moved along the resolved axis" (in screen-pixel-
// equivalent world units) for the CURRENT drag delta, using the same method pickAxis
// used to choose the axis in the first place - kept as one function so axis selection
// and the live preview/commit angle can never drift out of sync with each other.
function alongResolvedAxis(resolved, dragX, dragY, groupElement) {
  if (resolved.directionId !== null) {
    const [a, b] = resolved.projected;
    const det = a.screen.x * b.screen.y - b.screen.x * a.screen.y;
    const s = (dragX * b.screen.y - b.screen.x * dragY) / det;
    const t = (a.screen.x * dragY - dragX * a.screen.y) / det;

    const aVec3D = transformVector3D(groupElement, AXIS_VECTORS[a.axisName]);
    const bVec3D = transformVector3D(groupElement, AXIS_VECTORS[b.axisName]);
    const direction3D = {
      x: s * aVec3D.x + t * bVec3D.x,
      y: s * aVec3D.y + t * bVec3D.y,
      z: s * aVec3D.z + t * bVec3D.z,
    };
    const normal3D = transformVector3D(groupElement, FACE_NORMALS[resolved.directionId]);
    const rotationAxis3D = cross3D(normal3D, direction3D);
    const resolvedVec3D = resolved.axisName === a.axisName ? aVec3D : bVec3D;
    return dot3D(rotationAxis3D, resolvedVec3D);
  }

  const rotated = { x: -dragY, y: dragX };
  const { screen } = resolved.projected.find((p) => p.axisName === resolved.axisName);
  const length = Math.hypot(screen.x, screen.y) || 1;
  return (screen.x * rotated.x + screen.y * rotated.y) / length;
}

export function attachInteraction({ cube, containerElement, groupElement, onCommitted }) {
  let drag = null;
  let isSettling = false; // blocks starting a new gesture while a previous one's settle animation plays
  // Blocks ALL gestures outright - matches upstream's own
  // `mouseInteraction.enabled = mouseControlsEnabled && !finalShuffle`, which disables
  // interaction entirely while a shuffle sequence is running. Missing this exact guard
  // was a real bug: a user dragging during/around shuffle-on-load raced against it (both
  // independently call cube.twist()/write DOM styles), which could resolve a gesture's
  // command against a since-changed layer and leave the cube looking "corrupted" -
  // reported live as "not all drags complete" / "easy to corrupt everything".
  let enabled = true;

  function reset() {
    drag = null;
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
  }

  function cubeletElement(id) {
    return containerElement.querySelector(`[data-cubelet-id="${id}"]`);
  }

  // Resolves (once, the first time drag distance crosses the threshold) which
  // axis/slice/command this gesture is twisting, based on where it started and
  // its direction so far. Returns null until enough drag distance has occurred.
  function resolveAxis(dx, dy) {
    if (drag.resolved) return drag.resolved;
    if (Math.hypot(dx, dy) < DRAG_THRESHOLD) return null;

    const candidateAxes = drag.onFace ? FACE_AXES[drag.directionId] : ['x', 'y', 'z'];
    const picked = pickAxis(candidateAxes, groupElement, dx, dy, drag.onFace ? drag.directionId : null);

    let command;
    let affectedCubelets;

    if (drag.onFace) {
      const grabbed = cube.cubelets.find((c) => c.id === drag.cubeletId);
      const layer = grabbed[picked.axisName];
      command = LETTERS_BY_AXIS[picked.axisName][layer + 1];
      affectedCubelets = cube.cubelets.filter((c) => c[picked.axisName] === layer);
    } else {
      command = picked.axisName.toUpperCase();
      affectedCubelets = cube.cubelets;
    }

    drag.resolved = { ...picked, command, affectedCubelets };
    return drag.resolved;
  }

  // Continuously previews the in-progress rotation by applying a live CSS rotation
  // on top of each affected cubelet's normal position transform - the actual state
  // model isn't touched until the gesture ends (mirrors the original engine's own
  // "state and visuals stay separate" design, see plans/cuber-modernization/README.md).
  //
  // Returns degrees in the AXIS's own natural-sign convention (matching `rotateSteps`'s
  // `sign: 1` case for that axis) - NOT a specific command's sign, and NOT yet CSS
  // degrees (see cssSweepDegrees). `alongResolvedAxis` gives "how far the drag has moved
  // along the resolved axis" in screen-pixel-equivalent world units (via the same 2x2
  // basis solve/dot-product used to pick the axis - see pickAxis above), which is then
  // scaled by a fixed pixels-per-quarter-turn constant into degrees.
  function previewDegrees(resolved, dx, dy) {
    const projectedPixels = alongResolvedAxis(resolved, dx, dy, groupElement);
    return (projectedPixels / PIXELS_PER_QUARTER_TURN) * 90;
  }

  // `axisDegrees` is in the axis's own natural-sign convention (see previewDegrees) -
  // must go through cssSweepDegrees before use as an actual CSS rotate angle, or a
  // Y-axis drag renders backwards relative to what gets committed (ground-truthed:
  // CSS rotateY is inverted relative to the model's own sign, rotateX/rotateZ are not
  // - see animation.js). Getting this wrong here is invisible until the settle
  // animation exposes it as a direction reversal - it was caught exactly that way.
  function applyPreview(resolved, axisDegrees) {
    const rotateFn = { x: 'rotateX', y: 'rotateY', z: 'rotateZ' }[resolved.axisName];
    const cssDegrees = cssSweepDegrees(resolved.axisName, axisDegrees);
    resolved.affectedCubelets.forEach((cubelet) => {
      cubeletElement(cubelet.id).style.transform =
        `${rotateFn}(${cssDegrees}deg) ${positionTransform(cubelet.x, cubelet.y, cubelet.z)}`;
    });
  }

  function onPointerDown(event) {
    if (!enabled) return;
    if (event.button !== undefined && event.button !== 0) return;
    // Matches upstream's own touchstart preventDefault - belt-and-suspenders alongside
    // #the-cube's `touch-action: none` against the browser claiming this gesture for
    // native scroll/pan/zoom before it's even resolved into a cube rotation.
    event.preventDefault();
    if (isSettling) return; // don't let a new gesture fight an in-progress settle animation

    const faceEl = event.target.closest('.face');

    drag = {
      startX: event.clientX,
      startY: event.clientY,
      startTime: performance.now(),
      onFace: !!faceEl,
      cubeletId: faceEl ? Number(faceEl.closest('.cubelet').dataset.cubeletId) : null,
      directionId: faceEl ? Number(faceEl.dataset.directionId) : null,
      resolved: null,
    };

    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
  }

  function onPointerMove(event) {
    if (!drag) return;
    // Defense-in-depth alongside #the-cube's `touch-action: none` (matching upstream's
    // own `event.preventDefault()` in its touchmove handler) - stops a touch drag from
    // also scrolling/panning the page underneath the gesture.
    event.preventDefault();
    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;
    const resolved = resolveAxis(dx, dy);
    if (resolved) applyPreview(resolved, previewDegrees(resolved, dx, dy));
  }

  // On release: settles smoothly from wherever the live preview left off, rather than
  // snapping instantly - this is the "slightly magnetized" feel the owner specifically
  // asked to preserve. A committed twist settles onward to the nearest 90-degree
  // position; an abandoned (too-short, too-slow) drag settles BACK to 0 instead.
  async function onPointerUp(event) {
    if (!drag) return;
    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;
    const elapsed = performance.now() - drag.startTime;
    const resolved = drag.resolved;
    reset();

    if (!resolved) return;

    const axisDegrees = previewDegrees(resolved, dx, dy);
    const velocity = Math.hypot(dx, dy) / elapsed;
    let quarterTurns = Math.round(axisDegrees / 90);
    if (quarterTurns === 0 && velocity > SWIPE_VELOCITY) {
      quarterTurns = axisDegrees >= 0 ? 1 : -1;
    }

    isSettling = true;
    const fromCssDegrees = cssSweepDegrees(resolved.axisName, axisDegrees);

    if (quarterTurns === 0) {
      // Didn't commit to a turn - settle back to the original, unrotated position.
      await animateSettle({
        containerElement,
        axis: resolved.axisName,
        cubelets: resolved.affectedCubelets.map((c) => ({ id: c.id, x: c.x, y: c.y, z: c.z })),
        fromDegrees: fromCssDegrees,
        toDegrees: 0,
      });
    } else {
      // Convert "axis-natural-sign quarter turns" into the correctly-cased command
      // letter + always-positive degrees - the command's OWN sign (ground-truthed in
      // cube.js, e.g. M is opposite of R despite sharing the x axis) may or may not
      // match the axis's natural sign, so this can't just reuse `resolved.command`
      // and `quarterTurns * 90` directly (that was the actual bug: a Y-axis or
      // opposite-signed-letter drag would preview correctly but commit to the wrong
      // rotation, only visible once the settle animation exposed the mismatch).
      const { sign: commandSign } = getCommandAxisAndSign(resolved.command);
      const wantsPositiveCommandDirection = Math.sign(quarterTurns) === commandSign;
      const command = wantsPositiveCommandDirection
        ? resolved.command.toUpperCase()
        : resolved.command.toLowerCase();

      const result = cube.twist(command, Math.abs(quarterTurns) * 90);
      await animateSettle({
        containerElement,
        axis: result.axis,
        cubelets: result.cubelets,
        fromDegrees: fromCssDegrees,
        toDegrees: cssSweepDegrees(result.axis, result.modelDegrees),
      });
      onCommitted();
    }

    isSettling = false;
  }

  containerElement.addEventListener('pointerdown', onPointerDown);

  return {
    // Lets main.js disable interaction entirely while shuffle-on-load is running,
    // matching upstream's finalShuffle guard - see the note above on `enabled`.
    setEnabled(value) {
      enabled = value;
    },
  };
}
