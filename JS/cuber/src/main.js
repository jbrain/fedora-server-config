import { Cube } from './cube.js';
import { renderCube } from './render.js';
import { attachInteraction } from './interaction.js';
import { animateSettle, cssSweepDegrees } from './animation.js';

console.log('Cuber harness loaded');

const cube = new Cube();
window.cube = cube; // console-inspectable, matching the original engine's own philosophy
const containerElement = document.getElementById('the-cube');
const groupElement = renderCube(cube, containerElement);

attachInteraction({
  cube,
  containerElement,
  groupElement,
  onCommitted: () => renderCube(cube, containerElement),
});

// Shuffle-on-load, matching production's own `cube.shuffle(5)` on the About page -
// animated one twist at a time rather than instantly, using the same settle
// animation as a real drag commit (see plans/cuber-modernization/README.md's
// Segment 3e notes).
cube.shuffle(5, async (result) => {
  await animateSettle({
    containerElement,
    axis: result.axis,
    cubelets: result.cubelets,
    fromDegrees: 0,
    toDegrees: cssSweepDegrees(result.axis, result.modelDegrees),
  });
  renderCube(cube, containerElement);
});
