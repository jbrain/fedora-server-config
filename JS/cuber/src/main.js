import { Cube } from './cube.js';
import { renderCube } from './render.js';

console.log('Cuber harness loaded');

const cube = new Cube();
renderCube(cube, document.getElementById('the-cube'));
