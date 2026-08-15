import { Cube } from './cube.js';
import { createEntropySession } from './entropy-session.js';

let pass = 0;
let fail = 0;

function check(description, condition) {
  console.log(condition ? 'PASS' : 'FAIL', description);
  if (condition) pass++; else fail++;
}

function interaction(overrides = {}) {
  return {
    kind: 'layer', command: 'R', signedQuarterTurns: 1, pointerType: 'touch',
    directionSector: 0, speedClass: 1, committedAt: 1000, ...overrides,
  };
}

{
  const updates = [];
  const session = createEntropySession({ cube: new Cube(), onUpdate: (state) => updates.push(state) });
  await session.record(interaction());
  check('inactive session ignores committed input', session.snapshot().estimate.recordCount === 0 && updates.length === 0);

  const started = await session.start();
  check('explicit start creates volatile salt and initial digest',
    started.status === 'measuring' && /^[0-9a-f]{32}$/.test(started.saltHex) && /^[0-9a-f]{64}$/.test(started.digestHex));

  const first = await session.record(interaction());
  check('first committed input is recorded without a timing bin',
    first.estimate.recordCount === 1 && first.estimate.uniqueTimingBins === 0);

  const firstDigest = first.digestHex;
  const second = await session.record(interaction({ command: 'U', committedAt: 1600 }));
  check('later input is timing-binned and updates digest',
    second.estimate.recordCount === 2 && second.estimate.uniqueTimingBins === 1 && second.digestHex !== firstDigest);

  const orbit = await session.record(interaction({
    kind: 'orbit', command: 'Y', committedAt: 2600, directionSector: 2,
  }));
  check('orbit is digested and counted without heuristic credit',
    orbit.estimate.orbitCount === 1 && orbit.estimate.lastContribution.awarded === 0);

  const solved = await session.solve();
  await session.record(interaction({ committedAt: 3000 }));
  check('solved session freezes subsequent input',
    solved.status === 'solved' && session.snapshot().estimate.recordCount === 3);

  const reset = session.reset();
  check('reset clears all volatile study state',
    reset.status === 'ready' && reset.saltHex === null && reset.digestHex === null && reset.estimate.recordCount === 0);
}

{
  const session = createEntropySession({
    cube: new Cube(),
    onUpdate: () => {},
    createDigest: async () => { throw new Error('unavailable'); },
  });
  const failed = await session.start();
  check('digest initialization failure becomes a recoverable error state',
    failed.status === 'error' && failed.saltHex === null && failed.estimate.recordCount === 0);
  check('reset recovers from initialization failure', session.reset().status === 'ready');
}

{
  const session = createEntropySession({
    cube: new Cube(),
    onUpdate: () => {},
    createSalt: () => { throw new Error('random unavailable'); },
  });
  const failed = await session.start();
  check('salt generation failure becomes a recoverable error state',
    failed.status === 'error' && failed.saltHex === null && failed.estimate.recordCount === 0);
  check('reset recovers from salt generation failure', session.reset().status === 'ready');
}

{
  const session = createEntropySession({
    cube: new Cube(),
    onUpdate: () => {},
    createDigest: async () => ({
      digestHex: async () => '0'.repeat(64),
      append: async () => { throw new Error('append failed'); },
    }),
  });
  await session.start();
  const failed = await session.record(interaction());
  check('digest append failure does not commit estimator or timing state',
    failed.status === 'error' && failed.estimate.recordCount === 0);
  check('reset recovers from append failure', session.reset().status === 'ready');
}

{
  const appended = [];
  const session = createEntropySession({
    cube: new Cube(),
    onUpdate: () => {},
    createDigest: async () => ({
      digestHex: async () => '0'.repeat(64),
      append: async (record) => {
        appended.push(record);
        await Promise.resolve();
        return String(record.sequence).repeat(64);
      },
    }),
  });
  await session.start();
  await Promise.all([
    session.record(interaction({ committedAt: 1000 })),
    session.record(interaction({ command: 'U', committedAt: 1600 })),
  ]);
  check('concurrent records serialize complete sequence and timing transactions',
    appended.length === 2 && appended[0].sequence === 1 && appended[0].timingBin === null &&
    appended[1].sequence === 2 && appended[1].timingBin === 2);
}

{
  let releaseAppend;
  const appendGate = new Promise((resolve) => { releaseAppend = resolve; });
  const session = createEntropySession({
    cube: new Cube(),
    onUpdate: () => {},
    createDigest: async () => ({
      digestHex: async () => '0'.repeat(64),
      append: async () => {
        await appendGate;
        return '1'.repeat(64);
      },
    }),
  });
  await session.start();
  const solvingRecord = session.record(interaction());
  const solved = session.solve();
  releaseAppend();
  await Promise.all([solvingRecord, solved]);
  check('solve waits for the solving move to enter the digest and estimate',
    session.snapshot().status === 'solved' && session.snapshot().estimate.recordCount === 1 &&
    session.snapshot().digestHex === '1'.repeat(64));
}

{
  let releaseFirst;
  const firstGate = new Promise((resolve) => { releaseFirst = resolve; });
  const appended = [];
  let digestGeneration = 0;
  const session = createEntropySession({
    cube: new Cube(),
    onUpdate: () => {},
    createDigest: async () => {
      const digestId = ++digestGeneration;
      return {
        digestHex: async () => '0'.repeat(64),
        append: async (record) => {
          appended.push([digestId, record.sequence, record.command]);
          if (digestId === 1) await firstGate;
          return String(digestId).repeat(64);
        },
      };
    },
  });
  await session.start();
  const oldFirst = session.record(interaction({ command: 'R' }));
  const oldSecond = session.record(interaction({ command: 'U', committedAt: 1600 }));
  session.reset();
  await session.start();
  const current = session.record(interaction({ command: 'F', committedAt: 2000 }));
  releaseFirst();
  await Promise.all([oldFirst, oldSecond, current]);
  check('queued records cannot cross a reset and start generation boundary',
    !appended.some(([id]) => id === 1) &&
    appended.some(([id, sequence, command]) => id === 2 && sequence === 1 && command === 'F') &&
    session.snapshot().estimate.recordCount === 1);
}

console.log(fail === 0 ? `ALL ${pass} PASS` : `${fail} FAILED (${pass} passed)`);
process.exitCode = fail === 0 ? 0 : 1;