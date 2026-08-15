// Renders the cube state as 27 DOM cubelets. This module owns the coordinate-to-transform
// mapping and the per-face sticker rendering for each cubelet.

import { COLORLESS } from './color.js';

// The cubelet size is a fixed visual dimension. The CSS file must stay aligned with this value
// when the group dimensions are set.
export const CUBELET_SIZE = 100;
export const GAP = 2;
export const SPACING = CUBELET_SIZE + GAP;
const HALF = CUBELET_SIZE / 2;

// The logo sticker uses a configurable URL so the same cube renderer can be hosted in multiple
// environments without hard-coding a path to a specific theme or page.
const LOGO_URL = (typeof window !== 'undefined' && window.CUBER_LOGO_URL) || './images/jbrown.png';

// Index order matches ALL_DIRECTIONS / cubelet.faces (front/up/right/down/left/back).
const FACE_TRANSFORMS = [
  `translateZ(${HALF}px)`, // front
  `rotateX(90deg) translateZ(${HALF}px)`, // up
  `rotateY(90deg) translateZ(${HALF}px)`, // right
  `rotateX(-90deg) translateZ(${HALF}px)`, // down
  `rotateY(-90deg) translateZ(${HALF}px)`, // left
  `rotateY(180deg) translateZ(${HALF}px)`, // back
];
const FACE_NAMES = ['front', 'up', 'right', 'down', 'left', 'back'];

// Maps a cubelet coordinate to a CSS transform. The Y dimension is inverted to match the web
// coordinate system, where positive Y points downward on screen.
export function positionTransform(x, y, z) {
  return `translate3d(${x * SPACING}px, ${-y * SPACING}px, ${z * SPACING}px)`;
}

// Renders or refreshes the 27-cubelet DOM structure. Existing elements are reused so live drag
// previews can update transforms without removing the underlying cube structure.
export function renderCube(cube, containerElement) {
  let group = containerElement.querySelector('.cube-group');
  let cubeletEls = group ? null : new Map();

  if (!group) {
    group = document.createElement('div');
    group.className = 'cube-group';
    cubeletEls = new Map();

    cube.cubelets.forEach((cubelet) => {
      const cubeletEl = document.createElement('div');
      cubeletEl.className = 'cubelet';
      cubeletEl.dataset.cubeletId = cubelet.id;

      cubelet.faces.forEach((face, i) => {
        const faceEl = document.createElement('div');
        faceEl.className = `face face--${FACE_NAMES[i]}`;
        faceEl.dataset.directionId = i;
        faceEl.style.width = `${CUBELET_SIZE}px`;
        faceEl.style.height = `${CUBELET_SIZE}px`;
        faceEl.style.transform = FACE_TRANSFORMS[i];

        const sticker = document.createElement('div');
        sticker.className = 'sticker';
        faceEl.appendChild(sticker);

        cubeletEl.appendChild(faceEl);
      });

      group.appendChild(cubeletEl);
      cubeletEls.set(cubelet.id, cubeletEl);
    });

    containerElement.appendChild(group);
  }

  cube.cubelets.forEach((cubelet) => {
    const cubeletEl = cubeletEls ? cubeletEls.get(cubelet.id) : group.querySelector(`[data-cubelet-id="${cubelet.id}"]`);
    cubeletEl.style.transform = positionTransform(cubelet.x, cubelet.y, cubelet.z);

    cubelet.faces.forEach((face, i) => {
      const faceEl = cubeletEl.children[i];
      faceEl.style.background = '#111';
      const sticker = faceEl.firstElementChild;
      if (cubelet.isLogo && face.color !== COLORLESS) {
        sticker.style.background = `${face.color.hex} url("${LOGO_URL}") center / cover no-repeat`;
      } else {
        sticker.style.background = face.color.hex ?? 'transparent';
      }
    });
  });

  return group;
}
