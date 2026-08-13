// Renders the live Cube state model as 27 real DOM cubelets, reusing the exact
// per-face transform pattern proven by the 3a placeholder (.face--front/back/
// right/left/up/down in style.css) - just parameterized to this cubelet's
// half-size instead of the placeholder's hardcoded 100px, via inline style
// (which overrides the placeholder rules' --cube-size-based values).

const CUBELET_SIZE = 130;
const GAP = 2;
const SPACING = CUBELET_SIZE + GAP;
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

export function renderCube(cube, containerElement) {
  containerElement.innerHTML = '';

  const group = document.createElement('div');
  group.className = 'cube-group';

  cube.cubelets.forEach((cubelet) => {
    const cubeletEl = document.createElement('div');
    cubeletEl.className = 'cubelet';
    // State model's Y+ is "up" (math convention); CSS's Y+ is "down" (screen
    // convention) - negate Y or the cube renders upside-down.
    cubeletEl.style.transform =
      `translate3d(${cubelet.x * SPACING}px, ${-cubelet.y * SPACING}px, ${cubelet.z * SPACING}px)`;

    cubelet.faces.forEach((face, i) => {
      const faceEl = document.createElement('div');
      faceEl.className = `face face--${FACE_NAMES[i]}`;
      faceEl.style.width = `${CUBELET_SIZE}px`;
      faceEl.style.height = `${CUBELET_SIZE}px`;
      faceEl.style.background = '#111';
      faceEl.style.transform = FACE_TRANSFORMS[i];

      // Colored sticker inset from the face, leaving a visible dark plastic border,
      // matching production's real appearance - not a flat-colored face.
      if (face.color.hex) {
        const sticker = document.createElement('div');
        sticker.className = 'sticker';
        sticker.style.background = face.color.hex;
        faceEl.appendChild(sticker);
      }

      cubeletEl.appendChild(faceEl);
    });

    group.appendChild(cubeletEl);
  });

  containerElement.appendChild(group);
}
