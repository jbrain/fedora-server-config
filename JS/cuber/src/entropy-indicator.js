import QRCode from 'qrcode';

export function createEntropyIndicator({ rootElement, onStart, onReset }) {
  if (!rootElement) throw new Error('Entropy study template is missing');
  const toggle = rootElement.querySelector('.entropy-study__toggle');
  const panel = rootElement.querySelector('.entropy-study__panel');
  const startButton = panel.querySelector('[data-action="start"]');
  const resetButton = panel.querySelector('[data-action="reset"]');
  const methodologyButton = panel.querySelector('[data-action="methodology"]');
  const methodologyDialog = rootElement.querySelector('.entropy-study__dialog');
  const qrButton = panel.querySelector('[data-action="qr"]');
  const qrDialog = rootElement.querySelector('.entropy-study__qr-dialog');
  const qrCanvas = qrDialog.querySelector('[data-qr-canvas]');
  const qrPayload = qrDialog.querySelector('[data-qr-payload]');
  const status = panel.querySelector('.entropy-study__status');
  const meter = panel.querySelector('progress');
  const live = panel.querySelector('.entropy-study__live');
  const values = Object.fromEntries(
    [...panel.querySelectorAll('[data-value]')].map((element) => [element.dataset.value, element]),
  );
  let available = false;
  let announcedMilestone = 0;
  let latestState = null;

  toggle.addEventListener('click', () => {
    const expanded = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', String(!expanded));
    panel.hidden = expanded;
  });

  startButton.addEventListener('click', async () => {
    startButton.disabled = true;
    await onStart();
  });

  resetButton.addEventListener('click', () => onReset());
  methodologyButton.addEventListener('click', () => methodologyDialog.showModal());
  methodologyDialog.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    event.preventDefault();
    methodologyDialog.close();
  });
  methodologyDialog.addEventListener('close', () => {
    setTimeout(() => methodologyButton.focus(), 0);
  });
  qrButton.addEventListener('click', async () => {
    if (!latestState?.digestHex) return;
    const { estimate } = latestState;
    const payload = JSON.stringify({
      format: 'jb-cuber-entropy/v1',
      score: Number(estimate.score.toFixed(1)),
      cap: estimate.cap,
      moves: estimate.recordCount,
      layers: estimate.layerMoveCount,
      orbits: estimate.orbitCount,
      transitions: estimate.uniqueTransitions,
      salt: latestState.saltHex,
      digest: latestState.digestHex,
    });
    qrButton.disabled = true;
    try {
      await QRCode.toCanvas(qrCanvas, payload, { errorCorrectionLevel: 'M', margin: 2, width: 240 });
      qrPayload.textContent = payload;
      qrDialog.showModal();
    } finally {
      qrButton.disabled = !latestState?.digestHex;
    }
  });
  qrDialog.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    event.preventDefault();
    qrDialog.close();
  });
  qrDialog.addEventListener('close', () => {
    setTimeout(() => qrButton.focus(), 0);
  });

  return {
    setAvailable(value) {
      available = value;
      startButton.disabled = !available;
      if (value && status.textContent === 'Waiting for cube') status.textContent = 'Ready';
    },

    render(state) {
      latestState = state;
      const { estimate } = state;
      const labels = { ready: 'Ready', measuring: 'Measuring', solved: 'Solved', error: 'Crypto unavailable' };
      status.textContent = labels[state.status];
      startButton.disabled = !available || state.status === 'measuring';
      resetButton.disabled = state.status === 'ready';
      qrButton.disabled = !state.digestHex;
      meter.value = estimate.score;
      meter.textContent = `${estimate.score.toFixed(1)} of ${estimate.cap} demo bits`;
      values.score.textContent = `${estimate.score.toFixed(1)} demo bits`;
      values.moves.textContent = estimate.recordCount;
      values.layers.textContent = estimate.layerMoveCount;
      values.orbits.textContent = estimate.orbitCount;
      values.transitions.textContent = `${estimate.uniqueTransitions} transitions`;
      values.salt.textContent = state.saltHex ?? 'Not started';
      values.digest.textContent = state.digestHex ?? 'Not started';

      const milestone = Math.floor(estimate.score / 4) * 4;
      if (milestone > announcedMilestone) {
        announcedMilestone = milestone;
        live.textContent = `${milestone} demo-bit milestone reached.`;
      }
      if (state.status === 'solved') live.textContent = `Cube solved at ${estimate.score.toFixed(1)} demo bits.`;
      if (state.status === 'error') live.textContent = 'Cryptographic processing failed. Reset or start a new session.';
      if (state.status === 'ready') announcedMilestone = 0;
    },
  };
}