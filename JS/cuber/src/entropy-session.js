import { createEntropyEstimator } from './entropy-estimator.js';
import { timingBin } from './interaction-record.js';
import {
  createSessionSalt,
  createTranscriptDigest,
  encodeInitialState,
  toHex,
} from './transcript-digest.js';

export function createEntropySession({
  cube,
  onUpdate,
  createDigest = createTranscriptDigest,
  createSalt = createSessionSalt,
}) {
  const estimator = createEntropyEstimator();
  let active = false;
  let generation = 0;
  let sequence = 0;
  let lastCommittedAt = null;
  let salt = null;
  let digest = null;
  let recordQueue = Promise.resolve();
  let state = {
    status: 'ready',
    saltHex: null,
    digestHex: null,
    estimate: estimator.snapshot(),
  };

  function publish(patch = {}) {
    state = { ...state, ...patch };
    onUpdate(state);
    return state;
  }

  return {
    async start() {
      const sessionGeneration = ++generation;
      active = false;
      recordQueue = Promise.resolve();
      sequence = 0;
      lastCommittedAt = null;
      estimator.reset();
      try {
        salt = createSalt();
        digest = await createDigest({ salt, initialState: encodeInitialState(cube) });
        const digestHex = await digest.digestHex();
        if (sessionGeneration !== generation) return state;
        active = true;
        return publish({
          status: 'measuring',
          saltHex: toHex(salt),
          digestHex,
          estimate: estimator.snapshot(),
        });
      } catch {
        if (sessionGeneration !== generation) return state;
        active = false;
        return publish({
          status: 'error',
          saltHex: null,
          digestHex: null,
          estimate: estimator.reset(),
        });
      }
    },

    async record(interaction) {
      if (!active || !interaction) return state;
      const requestedGeneration = generation;
      recordQueue = recordQueue.then(async () => {
        if (!active || requestedGeneration !== generation) return state;
        const sessionGeneration = requestedGeneration;
        const sessionDigest = digest;
        const { committedAt, ...summary } = interaction;
        const nextSequence = sequence + 1;
        const record = {
          version: 1,
          sequence: nextSequence,
          ...summary,
          timingBin: lastCommittedAt === null ? null : timingBin(committedAt - lastCommittedAt),
        };
        try {
          const digestHex = await sessionDigest.append(record);
          if (sessionGeneration !== generation) return state;
          sequence = nextSequence;
          lastCommittedAt = committedAt;
          const estimate = estimator.add(record);
          return publish({ estimate, digestHex });
        } catch {
          if (sessionGeneration !== generation) return state;
          active = false;
          return publish({ status: 'error' });
        }
      });
      return recordQueue;
    },

    solve() {
      const requestedGeneration = generation;
      recordQueue = recordQueue.then(() => {
        if (!active || requestedGeneration !== generation) return state;
        active = false;
        return publish({ status: 'solved' });
      });
      return recordQueue;
    },

    reset() {
      generation++;
      active = false;
      recordQueue = Promise.resolve();
      sequence = 0;
      lastCommittedAt = null;
      salt = null;
      digest = null;
      return publish({
        status: 'ready',
        saltHex: null,
        digestHex: null,
        estimate: estimator.reset(),
      });
    },

    snapshot() {
      return state;
    },
  };
}