import { createEntropyEstimator, ESTIMATOR_CAP } from './entropy-estimator.js';

let pass = 0;
let fail = 0;

function check(description, condition) {
  console.log(condition ? 'PASS' : 'FAIL', description);
  if (condition) pass++; else fail++;
}

function layer(command, signedQuarterTurns, timingBin, directionSector, speedClass) {
  return { kind: 'layer', command, signedQuarterTurns, timingBin, directionSector, speedClass };
}

{
  const estimator = createEntropyEstimator();
  const first = estimator.add(layer('R', 1, null, 0, 1));
  check('first layer move credits move and gesture novelty after discount',
    first.score === 0.375 && first.lastContribution.move === 0.5 &&
    first.lastContribution.timing === 0 && first.lastContribution.gesture === 0.25);

  const repeated = estimator.add(layer('R', 1, 0, 0, 1));
  check('repeat credits only first timing-bin occurrence and new transition',
    repeated.score === 0.75 && repeated.lastContribution.move === 0.5 &&
    repeated.lastContribution.timing === 0.25 && repeated.lastContribution.gesture === 0);

  const fullyRepeated = estimator.add(layer('R', 1, 0, 0, 1));
  check('fully repeated observation adds no score',
    fullyRepeated.score === repeated.score && fullyRepeated.lastContribution.awarded === 0);
}

{
  const estimator = createEntropyEstimator();
  estimator.add(layer('R', 1, null, 0, 0));
  const beforeOrbit = estimator.snapshot();
  const orbit = estimator.add({
    kind: 'orbit', command: 'Y', signedQuarterTurns: 1, timingBin: 2, directionSector: 3, speedClass: 2,
  });
  const after = estimator.add(layer('U', -1, 1, 1, 1));
  check('orbit records receive zero credit but remain counted',
    orbit.score === beforeOrbit.score && orbit.orbitCount === 1 && orbit.recordCount === 2);
  check('orbit does not replace previous scored layer transition context',
    after.uniqueTransitions === 1 && after.lastContribution.move === 1);
}

{
  const estimator = createEntropyEstimator();
  let previous = 0;
  let monotonic = true;
  for (let i = 0; i < 200; i++) {
    const commands = ['L', 'M', 'R', 'D', 'E', 'U', 'B', 'S', 'F'];
    const result = estimator.add(layer(commands[i % commands.length], i % 2 ? -1 : 1, i % 6, i % 8, i % 3));
    if (result.score < previous) monotonic = false;
    previous = result.score;
  }
  check('score is monotonic and capped', monotonic && previous <= ESTIMATOR_CAP);
}

{
  const estimator = createEntropyEstimator();
  const tokens = [];
  for (const command of ['L', 'M', 'R', 'D', 'E', 'U', 'B', 'S', 'F']) {
    tokens.push([command, 1], [command, -1]);
  }
  for (const [fromCommand, fromTurns] of tokens) {
    for (const [toCommand, toTurns] of tokens) {
      estimator.add(layer(fromCommand, fromTurns, 0, 0, 0));
      estimator.add(layer(toCommand, toTurns, 0, 0, 0));
    }
  }
  const capped = estimator.snapshot();
  const afterCap = estimator.add(layer('R', 1, 5, 7, 2));
  check('score reaches the exact display cap', capped.score === ESTIMATOR_CAP);
  check('observations after saturation receive no displayed award',
    afterCap.score === ESTIMATOR_CAP && afterCap.lastContribution.awarded === 0);
}

{
  const estimator = createEntropyEstimator();
  estimator.add(layer('F', 1, 3, 4, 2));
  const reset = estimator.reset();
  check('reset clears score and every aggregate',
    reset.score === 0 && reset.recordCount === 0 && reset.uniqueMoveTokens === 0 &&
    reset.uniqueTransitions === 0 && reset.uniqueTimingBins === 0 && reset.uniqueGestureTokens === 0);
}

console.log(fail === 0 ? `ALL ${pass} PASS` : `${fail} FAILED (${pass} passed)`);
process.exitCode = fail === 0 ? 0 : 1;