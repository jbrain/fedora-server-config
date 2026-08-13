import { positionTransform } from './render.js';

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

// Of the candidate axes, picks whichever one's current screen-space projection is
// most aligned with the actual drag vector - i.e. whichever rotation the user's
// drag direction most plausibly intends, given the cube's current orientation.
function pickBestAxis(candidateAxes, groupElement, dragX, dragY) {
  let best = null;
  let bestAbsScore = -Infinity;

  for (const axisName of candidateAxes) {
    const screen = projectAxisToScreen(groupElement, axisName);
    const length = Math.hypot(screen.x, screen.y) || 1;
    const score = (screen.x * dragX + screen.y * dragY) / length;
    if (Math.abs(score) > bestAbsScore) {
      bestAbsScore = Math.abs(score);
      best = { axisName, screen, length, score };
    }
  }

  return best;
}

export function attachInteraction({ cube, containerElement, groupElement, onCommitted }) {
  let drag = null;

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
    const picked = pickBestAxis(candidateAxes, groupElement, dx, dy);

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
  // `resolved.screen` is the AXIS direction projected to screen space by a unit
  // vector, so its own magnitude (`resolved.length`) is <= 1, not in pixels - it
  // must be normalized to a true unit vector before dotting with the (pixel-scale)
  // drag delta, then scaled by a fixed pixels-per-quarter-turn constant. An earlier
  // version of this divided by `length * length` directly against pixel deltas,
  // producing wildly wrong (10,000+ degree) results - caught by testing an actual
  // simulated drag, not by inspection.
  function previewDegrees(resolved, dx, dy) {
    const unitX = resolved.screen.x / resolved.length;
    const unitY = resolved.screen.y / resolved.length;
    const projectedPixels = unitX * dx + unitY * dy;
    return (projectedPixels / PIXELS_PER_QUARTER_TURN) * 90;
  }

  function applyPreview(resolved, degrees) {
    const rotateFn = { x: 'rotateX', y: 'rotateY', z: 'rotateZ' }[resolved.axisName];
    resolved.affectedCubelets.forEach((cubelet) => {
      cubeletElement(cubelet.id).style.transform =
        `${rotateFn}(${degrees}deg) ${positionTransform(cubelet.x, cubelet.y, cubelet.z)}`;
    });
  }

  function clearPreview(resolved) {
    resolved.affectedCubelets.forEach((cubelet) => {
      cubeletElement(cubelet.id).style.transform = positionTransform(cubelet.x, cubelet.y, cubelet.z);
    });
  }

  function onPointerDown(event) {
    if (event.button !== undefined && event.button !== 0) return;
    if (cube.isAnimating) return; // hook for 3e's tween queue; Cube has no such flag yet

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
    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;
    const resolved = resolveAxis(dx, dy);
    if (resolved) applyPreview(resolved, previewDegrees(resolved, dx, dy));
  }

  function onPointerUp(event) {
    if (!drag) return;
    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;
    const elapsed = performance.now() - drag.startTime;
    const resolved = drag.resolved;

    if (resolved) {
      const degrees = previewDegrees(resolved, dx, dy);
      const velocity = Math.hypot(dx, dy) / elapsed;
      let quarterTurns = Math.round(degrees / 90);
      if (quarterTurns === 0 && velocity > SWIPE_VELOCITY) {
        quarterTurns = degrees >= 0 ? 1 : -1;
      }

      clearPreview(resolved);
      if (quarterTurns !== 0) cube.twist(resolved.command, quarterTurns * 90);
      onCommitted();
    }

    reset();
  }

  containerElement.addEventListener('pointerdown', onPointerDown);
}
