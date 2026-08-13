// Renders the live Cube state model as 27 real DOM cubelets, reusing the exact
// per-face transform pattern proven by the 3a placeholder (.face--front/back/
// right/left/up/down in style.css) - just parameterized to this cubelet's
// half-size instead of the placeholder's hardcoded 100px, via inline style
// (which overrides the placeholder rules' --cube-size-based values).

export const CUBELET_SIZE = 130;
export const GAP = 2;
export const SPACING = CUBELET_SIZE + GAP;
const HALF = CUBELET_SIZE / 2;

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

// Cubelet position -> CSS translate3d, shared with interaction.js's live drag preview
// so both always agree on where a cubelet sits for a given (x, y, z).
export function positionTransform(x, y, z) {
  // State model's Y+ is "up" (math convention); CSS's Y+ is "down" (screen
  // convention) - negate Y or the cube renders upside-down.
  return `translate3d(${x * SPACING}px, ${-y * SPACING}px, ${z * SPACING}px)`;
}

// Returns the existing .cube-group element, creating the full 27-cubelet DOM
// structure on first call. Re-renders (twist/orbit completion) reuse the same
// elements and just update each cubelet's transform/colors, so interaction.js's
// live drag-preview transforms on those same elements aren't wiped out mid-drag.
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
      faceEl.firstElementChild.style.background = face.color.hex ?? 'transparent';
    });
  });

  return group;
}
