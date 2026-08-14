import { Cube } from './cube.js';
import { renderCube } from './render.js';
import { attachInteraction } from './interaction.js';
import { animateSettle, cssSweepDegrees } from './animation.js';

console.log('Cuber harness loaded');

const cube = new Cube();
window.cube = cube; // console-inspectable, matching the original engine's own philosophy
const containerElement = document.getElementById('the-cube');
const groupElement = renderCube(cube, containerElement);

const interaction = attachInteraction({
  cube,
  containerElement,
  groupElement,
  onCommitted: () => renderCube(cube, containerElement),
});

// Placeholder for future extension (celebration animation, message, etc.) - fires
// once, exactly when a user's twist results in a solved cube (see interaction.js).
containerElement.addEventListener('cubesolved', () => console.log('Cube solved!'));

// Shuffle-on-load, matching production's own `cube.shuffle(5)` on the About page -
// animated one twist at a time rather than instantly, using the same settle
// animation as a real drag commit (see plans/cuber-modernization/README.md's
// Segment 3e notes). Deferred until the cube actually scrolls into view - the
// About page's cube sits below the fold, so animating it immediately on load
// would burn CPU/GPU work no one can see yet.
async function shuffleOnLoad() {
  // Matches upstream's `mouseInteraction.enabled = mouseControlsEnabled &&
  // !finalShuffle` - interaction MUST stay disabled for the whole shuffle
  // sequence, not just during each individual twist's animation, or a drag
  // started mid-shuffle races against it (both call cube.twist()/write DOM
  // styles independently) and can resolve against a since-changed layer -
  // this was a real, reported bug ("easy to corrupt everything").
  interaction.setEnabled(false);
  await cube.shuffle(5, async (result) => {
    await animateSettle({
      containerElement,
      axis: result.axis,
      cubelets: result.cubelets,
      fromDegrees: 0,
      toDegrees: cssSweepDegrees(result.axis, result.modelDegrees),
    });
    renderCube(cube, containerElement);
  });
  interaction.setEnabled(true);
}

const visibilityObserver = new IntersectionObserver((entries) => {
  if (entries[0].isIntersecting) {
    shuffleOnLoad();
    visibilityObserver.disconnect();
  }
});
visibilityObserver.observe(containerElement);
