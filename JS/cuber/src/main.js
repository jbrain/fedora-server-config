import { Cube } from './cube.js';
import { renderCube } from './render.js';
import { attachInteraction } from './interaction.js';
import { animateSettle, cssSweepDegrees } from './animation.js';
import { createEntropyIndicator } from './entropy-indicator.js';
import { createEntropySession } from './entropy-session.js';

console.log('Cuber harness loaded');

const cube = new Cube();
window.cube = cube;
const containerElement = document.getElementById('the-cube');
const groupElement = renderCube(cube, containerElement);
let indicator;
let interaction;
const entropySession = createEntropySession({
  cube,
  onUpdate: (state) => indicator.render(state),
});

indicator = createEntropyIndicator({
  rootElement: document.getElementById('entropy-study'),
  onStart: async () => {
    interaction.setEnabled(false);
    try {
      return await entropySession.start();
    } finally {
      interaction.setEnabled(true);
    }
  },
  onReset: () => entropySession.reset(),
});

interaction = attachInteraction({
  cube,
  containerElement,
  groupElement,
  onCommitted: (record) => {
    renderCube(cube, containerElement);
    void entropySession.record(record);
  },
});

containerElement.addEventListener('cubesolved', () => {
  void entropySession.solve();
  console.log('Cube solved!');
});

// Runs a brief scramble on first view. The cube is only animated once it intersects the
// viewport, which keeps the initial page load light while still presenting a visible start state.
async function shuffleOnLoad() {
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
  indicator.setAvailable(true);
}

const visibilityObserver = new IntersectionObserver((entries) => {
  if (entries[0].isIntersecting) {
    shuffleOnLoad();
    visibilityObserver.disconnect();
  }
});
visibilityObserver.observe(containerElement);
