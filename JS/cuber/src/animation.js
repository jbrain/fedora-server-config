import { positionTransform } from './render.js';

// A complete twist settles over a fixed 400 ms interval so the cube reads as a physical
// object rotating through space rather than snapping between states. This easing value is a
// stable approximation of a smooth quartic-out motion.
export const TWIST_DURATION_MS = 400;
export const TWIST_EASING = 'cubic-bezier(0.25, 0.46, 0.45, 0.94)';

const ROTATE_FN = { x: 'rotateX', y: 'rotateY', z: 'rotateZ' };

// The browser-level motion preference is checked at runtime so the effect can respond to a
// user or system preference change without needing a reload.
function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

// The model and the CSS transform system do not share a single sign convention for all axes.
// The Y-axis is inverted relative to the state model, while X and Z match directly.
const CSS_SIGN_FOR_AXIS = { x: 1, y: -1, z: 1 };

export function cssSweepDegrees(axis, modelDegrees) {
  return modelDegrees * CSS_SIGN_FOR_AXIS[axis];
}

// A settle animation sweeps each cubelet from its pre-twist position to the final aligned
// orientation. The source angle is retained during a drag preview so the object does not
// jump back to zero before the final settle completes. The state model remains unchanged;
// this only updates the live DOM transform of the affected cubelets.
export function animateSettle({ containerElement, axis, cubelets, fromDegrees, toDegrees }) {
  const rotateFn = ROTATE_FN[axis];
  const duration = prefersReducedMotion() ? 0 : TWIST_DURATION_MS;

  const animations = cubelets.map(({ id, x, y, z }) => {
    const el = containerElement.querySelector(`[data-cubelet-id="${id}"]`);
    const base = positionTransform(x, y, z);
    const anim = el.animate(
      [
        { transform: `${rotateFn}(${fromDegrees}deg) ${base}` },
        { transform: `${rotateFn}(${toDegrees}deg) ${base}` },
      ],
      { duration, easing: TWIST_EASING },
    );
    // Web Animations uses a fill value of 'none' by default, which removes the effect at the
    // end of the animation and reveals the pre-animation inline transform. The final state
    // must therefore be written explicitly so the element remains in the correct settled
    // orientation after the animation ends.
    return anim.finished
      .catch(() => {}) // a cancelled animation rejects; not an error here
      .then(() => { el.style.transform = `${rotateFn}(${toDegrees}deg) ${base}`; });
  });

  return Promise.all(animations);
}
