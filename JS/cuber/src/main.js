import { Cube } from './cube.js';
import { renderCube } from './render.js';
import { attachInteraction } from './interaction.js';

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
