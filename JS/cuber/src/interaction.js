import { positionTransform } from './render.js';
import { animateSettle, cssSweepDegrees } from './animation.js';
import { getCommandAxisAndSign } from './cube.js';
import { committedInteraction } from './interaction-record.js';

// Dragging directly on a visible face rotates that face's layer. Dragging on empty space
// rotates the entire cube around a fixed viewing axis. The current tilt is not a free camera
// orbit; it is represented by the cube's state being rotated while the display angle remains
// constant.
const DRAG_THRESHOLD = 8; // px before the drag is considered a meaningful rotation
const SWIPE_VELOCITY = 0.5; // px/ms; a quick flick may commit a full turn even before the threshold is met
const PIXELS_PER_QUARTER_TURN = 150; // pixels of resolved drag per 90-degree turn
const TOUCH_PIXELS_PER_QUARTER_TURN = 100;
const INTERACTIVE_SELECTOR = '.entropy-study, a, button, input, textarea, select, option, label, [contenteditable="true"], [role="button"]';

// For a face drag, only the two axes perpendicular to that face normal are relevant.
const FACE_AXES = {
  0: ['x', 'y'], // front
  1: ['x', 'z'], // up
  2: ['y', 'z'], // right
  3: ['x', 'z'], // down
  4: ['y', 'z'], // left
  5: ['x', 'y'], // back
};

// The same move letters used by the logical cube model are also used here to resolve a drag
// into a specific layer of the appropriate axis.
const LETTERS_BY_AXIS = {
  x: ['L', 'M', 'R'],
  y: ['D', 'E', 'U'],
  z: ['B', 'S', 'F'],
};

// Projects a world-space axis through the cube group's current CSS transform into screen
// space. This relies on the browser's own matrix support rather than a custom 3D projection
// implementation.
function projectAxisToScreen(groupElement, axis) {
  const matrix = new DOMMatrixReadOnly(getComputedStyle(groupElement).transform);
  // Y is negated here for the same reason render.js negates it when positioning
  // cubelets: the state model's Y+ is "up", CSS's Y+ is "down".
  const origin = matrix.transformPoint(new DOMPoint(0, 0, 0));
  const tip = matrix.transformPoint(new DOMPoint(axis === 'x' ? 1 : 0, axis === 'y' ? -1 : 0, axis === 'z' ? 1 : 0));
  return { x: tip.x - origin.x, y: tip.y - origin.y };
}

// Like projectAxisToScreen, but preserves the full 3D vector. This is used when the drag
// direction must be related to a face normal and evaluated by cross-product logic.
function transformVector3D(groupElement, vec) {
  const matrix = new DOMMatrixReadOnly(getComputedStyle(groupElement).transform);
  const origin = matrix.transformPoint(new DOMPoint(0, 0, 0));
  const tip = matrix.transformPoint(new DOMPoint(vec.x, -vec.y, vec.z));
  return { x: tip.x - origin.x, y: tip.y - origin.y, z: tip.z - origin.z };
}

const AXIS_VECTORS = { x: { x: 1, y: 0, z: 0 }, y: { x: 0, y: 1, z: 0 }, z: { x: 0, y: 0, z: 1 } };

// Fixed face normals in state-model coordinates. These values align with the cube's own
// direction objects.
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

function isInteractiveTarget(target) {
  return target instanceof Element && target.closest(INTERACTIVE_SELECTOR) !== null;
}

// Resolves the actual turn axis from a drag. The correct relationship is perpendicular to the
// drag direction in the face plane, not parallel to it. The face drag case is computed by
// decomposing the screen drag into the local plane basis and comparing the result with the
// candidate axes.
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

  // For a whole-cube drag, the reference plane is the screen itself. The move direction is
  // therefore rotated by 90 degrees in screen space before matching it to the nearest cube
  // axis. The result is a large-surface orbit implemented as a real state rotation.
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

// Measures how far the drag has moved along the resolved axis in screen-space-equivalent
// units. This value is used to convert the drag into an angular preview and to keep the
// preview math aligned with the axis resolution.
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
  let isSettling = false; // Prevents a new drag from starting before the previous settle animation finishes.
  let enabled = true; // Interaction is disabled while an automated scramble is running.

  function reset() {
    drag = null;
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointercancel', onPointerCancel);
  }

  function cubeletElement(id) {
    return containerElement.querySelector(`[data-cubelet-id="${id}"]`);
  }

  // Determines the target axis and move once the gesture has moved far enough to be a real
  // turn rather than a slight wobble. The result is cached so the same drag stays consistent.
  function resolveAxis(dx, dy) {
    if (drag.resolved) return drag.resolved;
    if (Math.hypot(dx, dy) < DRAG_THRESHOLD) return null;
    if (drag.horizontalOnly && Math.abs(dy) >= Math.abs(dx)) return null;

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

  // Applies a live preview transform to the affected cubelets while the drag is in progress.
  // The logical state is not modified until the drag is committed. The returned angle is in
  // the axis's natural model convention before being converted to CSS coordinates.
  function previewDegrees(resolved, dx, dy, pointerType) {
    const projectedPixels = alongResolvedAxis(resolved, dx, dy, groupElement);
    const pixelsPerQuarterTurn = pointerType === 'touch'
      ? TOUCH_PIXELS_PER_QUARTER_TURN
      : PIXELS_PER_QUARTER_TURN;
    return (projectedPixels / pixelsPerQuarterTurn) * 90;
  }

  // The preview angle must be converted into CSS space before it is applied to the DOM. This
  // keeps the visual direction aligned with the underlying model when the axis sign differs.
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
    if (drag) return;
    if (event.button !== undefined && event.button !== 0) return;
    const faceEl = event.target.closest('.face');
    if (!faceEl && isInteractiveTarget(event.target)) return;

    if (isSettling) return; // Ignore new input while a previous settle animation is still running.

    const startsInsideCube = containerElement.contains(event.target);
    if (startsInsideCube) event.preventDefault();

    drag = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      startTime: performance.now(),
      onFace: !!faceEl,
      cubeletId: faceEl ? Number(faceEl.closest('.cubelet').dataset.cubeletId) : null,
      directionId: faceEl ? Number(faceEl.dataset.directionId) : null,
      pointerType: event.pointerType,
      horizontalOnly: event.pointerType === 'touch' && !startsInsideCube,
      resolved: null,
    };

    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerCancel);
  }

  function onPointerMove(event) {
    if (!drag || event.pointerId !== drag.pointerId) return;
    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;
    const resolved = resolveAxis(dx, dy);
    if (resolved) {
      event.preventDefault();
      applyPreview(resolved, previewDegrees(resolved, dx, dy, drag.pointerType));
    }
  }

  function onPointerCancel(event) {
    if (!drag || event.pointerId !== drag.pointerId) return;
    if (drag.resolved) {
      drag.resolved.affectedCubelets.forEach((cubelet) => {
        cubeletElement(cubelet.id).style.transform = positionTransform(cubelet.x, cubelet.y, cubelet.z);
      });
    }
    reset();
  }

  // When the drag ends, the cube settles from the previewed angle toward the nearest quarter-
  // turn. If the drag is too weak or too short, it settles back to the original orientation.
  async function onPointerUp(event) {
    if (!drag || event.pointerId !== drag.pointerId) return;
    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;
    const committedAt = performance.now();
    const elapsed = committedAt - drag.startTime;
    const resolved = drag.resolved;
    const pointerType = drag.pointerType;
    reset();

    if (!resolved) return;

    const axisDegrees = previewDegrees(resolved, dx, dy, pointerType);
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
      // Convert the previewed axis rotation into the canonical command form. The command's
      // sign may differ from the axis's natural sign, so the letter case and magnitude must be
      // mapped explicitly.
      const { sign: commandSign } = getCommandAxisAndSign(resolved.command);
      const wantsPositiveCommandDirection = Math.sign(quarterTurns) === commandSign;
      const command = wantsPositiveCommandDirection
        ? resolved.command.toUpperCase()
        : resolved.command.toLowerCase();

      const wasSolved = cube.isSolved();
      const result = cube.twist(command, Math.abs(quarterTurns) * 90);
      await animateSettle({
        containerElement,
        axis: result.axis,
        cubelets: result.cubelets,
        fromDegrees: fromCssDegrees,
        toDegrees: cssSweepDegrees(result.axis, result.modelDegrees),
      });
      onCommitted(committedInteraction({
        command,
        quarterTurns: Math.abs(quarterTurns),
        pointerType,
        dx,
        dy,
        elapsedMs: elapsed,
        committedAt,
      }));

      // Emits a solved event only when the cube transitions from unsolved to solved.
      if (!wasSolved && cube.isSolved()) {
        containerElement.dispatchEvent(new CustomEvent('cubesolved', { bubbles: true }));
      }
    }

    isSettling = false;
  }

  // Document-level initiation makes the page surrounding the cube an orbit control surface.
  // Interactive controls are excluded above so their default actions remain available.
  document.addEventListener('pointerdown', onPointerDown);

  return {
    // Allows the caller to disable input during automated moves such as an initial scramble.
    setEnabled(value) {
      enabled = value;
    },
  };
}
