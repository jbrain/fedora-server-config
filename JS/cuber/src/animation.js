import { positionTransform } from './render.js';

export const TWIST_DURATION_MS = 400; // matches the original engine's default twistDuration
// Approximates the original engine's TWEEN.Easing.Quartic.Out "settle" feel - fast start,
// gentle ease into the final aligned position (the "slightly magnetized" snap the owner
// specifically asked to preserve).
export const TWIST_EASING = 'cubic-bezier(0.25, 0.46, 0.45, 0.94)';

const ROTATE_FN = { x: 'rotateX', y: 'rotateY', z: 'rotateZ' };

// Ground-truthed against the browser's own DOMMatrix (see plans/cuber-modernization/README.md):
// CSS rotateX/rotateZ share the state model's rotation sign convention directly, but
// rotateY is inverted relative to it. Do not "simplify" this to a single sign without
// re-verifying - it was wrong the first time this was assumed.
const CSS_SIGN_FOR_AXIS = { x: 1, y: -1, z: 1 };

export function cssSweepDegrees(axis, modelDegrees) {
  return modelDegrees * CSS_SIGN_FOR_AXIS[axis];
}

// Animates a set of cubelets settling into their final rest position: sweeps a rotation
// around `axis`, relative to each cubelet's position BEFORE this twist, from `fromDegrees`
// to `toDegrees` (both in CSS terms, see cssSweepDegrees). `fromDegrees` is 0 for a fresh
// programmatic twist (shuffle, autorotate), or the live drag-preview's current angle for a
// user gesture already mid-rotation - passing the actual in-progress angle instead of
// resetting to 0 first is what avoids the instant "teleport" snap the owner flagged.
// `cubelets` is the `cubelets` array from Cube#twist()'s return value (pre-twist positions).
// Resolves once every cubelet has finished settling; does NOT touch the state model.
export function animateSettle({ containerElement, axis, cubelets, fromDegrees, toDegrees }) {
  const rotateFn = ROTATE_FN[axis];

  const animations = cubelets.map(({ id, x, y, z }) => {
    const el = containerElement.querySelector(`[data-cubelet-id="${id}"]`);
    const base = positionTransform(x, y, z);
    const anim = el.animate(
      [
        { transform: `${rotateFn}(${fromDegrees}deg) ${base}` },
        { transform: `${rotateFn}(${toDegrees}deg) ${base}` },
      ],
      { duration: TWIST_DURATION_MS, easing: TWIST_EASING },
    );
    // WAAPI's default fill ('none') means the animation's effect is removed the
    // instant it finishes, reverting the element to whatever inline style it had
    // BEFORE the animation started (the live drag-preview's last value) - not the
    // animation's own end state. Explicitly setting the final style after finishing
    // is required, not optional; without it, a cancelled/reverted drag left a stale
    // rotation in place indefinitely - caught by checking computed style after the
    // animation should have finished, not by eyeballing the screen.
    return anim.finished
      .catch(() => {}) // a cancelled animation rejects; not an error here
      .then(() => { el.style.transform = `${rotateFn}(${toDegrees}deg) ${base}`; });
  });

  return Promise.all(animations);
}
