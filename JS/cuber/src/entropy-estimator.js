export const ESTIMATOR_VERSION = 1;
export const ESTIMATOR_CAP = 32;

function initialState() {
  return {
    score: 0,
    recordCount: 0,
    layerMoveCount: 0,
    orbitCount: 0,
    previousLayerToken: null,
    seenMoveTokens: new Set(),
    seenTransitions: new Set(),
    seenTimingBins: new Set(),
    seenGestureTokens: new Set(),
  };
}

export function createEntropyEstimator() {
  let state = initialState();

  function snapshot(lastContribution = null) {
    return {
      version: ESTIMATOR_VERSION,
      cap: ESTIMATOR_CAP,
      score: state.score,
      recordCount: state.recordCount,
      layerMoveCount: state.layerMoveCount,
      orbitCount: state.orbitCount,
      uniqueMoveTokens: state.seenMoveTokens.size,
      uniqueTransitions: state.seenTransitions.size,
      uniqueTimingBins: state.seenTimingBins.size,
      uniqueGestureTokens: state.seenGestureTokens.size,
      lastContribution,
    };
  }

  return {
    add(record) {
      state.recordCount++;

      if (record.kind === 'orbit') {
        state.orbitCount++;
        return snapshot({ move: 0, timing: 0, gesture: 0, discounted: 0, awarded: 0 });
      }

      state.layerMoveCount++;
      const moveToken = `${record.command}:${record.signedQuarterTurns}`;
      const transition = state.previousLayerToken === null
        ? null
        : `${state.previousLayerToken}>${moveToken}`;

      const move = (state.seenMoveTokens.has(moveToken) ? 0 : 0.5) +
        (transition === null || state.seenTransitions.has(transition) ? 0 : 0.5);
      const timing = record.timingBin === null || state.seenTimingBins.has(record.timingBin) ? 0 : 0.25;
      const gestureToken = `${record.directionSector}:${record.speedClass}`;
      const gesture = state.seenGestureTokens.has(gestureToken) ? 0 : 0.25;

      state.seenMoveTokens.add(moveToken);
      if (transition !== null) state.seenTransitions.add(transition);
      if (record.timingBin !== null) state.seenTimingBins.add(record.timingBin);
      state.seenGestureTokens.add(gestureToken);
      state.previousLayerToken = moveToken;

      const discounted = 0.5 * (move + timing + gesture);
      const awarded = Math.min(discounted, ESTIMATOR_CAP - state.score);
      state.score += awarded;
      return snapshot({ move, timing, gesture, discounted, awarded });
    },

    reset() {
      state = initialState();
      return snapshot();
    },

    snapshot,
  };
}