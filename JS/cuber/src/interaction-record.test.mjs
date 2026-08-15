import { committedInteraction, gestureToken, timingBin } from './interaction-record.js';

let pass = 0;
let fail = 0;

function check(description, condition) {
  console.log(condition ? 'PASS' : 'FAIL', description);
  if (condition) pass++; else fail++;
}

check('timing bins include every boundary',
  [0, 249, 250, 499, 500, 999, 1000, 1999, 2000, 3999, 4000]
    .map(timingBin).join(',') === '0,0,1,1,2,2,3,3,4,4,5');

check('gesture direction uses eight clockwise screen sectors',
  [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]]
    .map(([dx, dy]) => gestureToken(dx, dy, 100).directionSector).join(',') === '0,1,2,3,4,5,6,7');

check('gesture speed thresholds are stable',
  [gestureToken(24, 0, 100), gestureToken(25, 0, 100), gestureToken(75, 0, 100)]
    .map((token) => token.speedClass).join(',') === '0,1,2');

check('lowercase layer command becomes a signed canonical turn', (() => {
  const record = committedInteraction({
    command: 'r', quarterTurns: 1, pointerType: 'touch', dx: 0, dy: 100, elapsedMs: 200, committedAt: 1234,
  });
  return record.kind === 'layer' && record.command === 'R' && record.signedQuarterTurns === -1 &&
    record.pointerType === 'touch' && record.directionSector === 2 && record.committedAt === 1234;
})());

check('whole-cube command is classified as orbit context',
  committedInteraction({
    command: 'Y', quarterTurns: 1, pointerType: 'mouse', dx: 100, dy: 0, elapsedMs: 100, committedAt: 1,
  }).kind === 'orbit');

check('double turns have one direction-independent representation',
  committedInteraction({
    command: 'f', quarterTurns: 2, pointerType: 'pen', dx: 1, dy: 0, elapsedMs: 1, committedAt: 1,
  }).signedQuarterTurns === 2);

check('three turns normalize to the inverse directed quarter turn',
  committedInteraction({
    command: 'U', quarterTurns: 3, pointerType: 'mouse', dx: 1, dy: 0, elapsedMs: 1, committedAt: 1,
  }).signedQuarterTurns === -1);

check('full rotations do not produce records',
  committedInteraction({
    command: 'R', quarterTurns: 4, pointerType: 'mouse', dx: 1, dy: 0, elapsedMs: 1, committedAt: 1,
  }) === null);

console.log(fail === 0 ? `ALL ${pass} PASS` : `${fail} FAILED (${pass} passed)`);
process.exitCode = fail === 0 ? 0 : 1;