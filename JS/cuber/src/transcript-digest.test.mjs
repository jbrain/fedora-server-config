import { Cube } from './cube.js';
import {
  createTranscriptDigest,
  encodeInitialState,
  encodeRecord,
  toHex,
} from './transcript-digest.js';

let pass = 0;
let fail = 0;

function check(description, condition) {
  console.log(condition ? 'PASS' : 'FAIL', description);
  if (condition) pass++; else fail++;
}

const salt = new Uint8Array(Array.from({ length: 16 }, (_, index) => index));
const initialState = encodeInitialState(new Cube());
const recordA = {
  version: 1, sequence: 1, kind: 'layer', command: 'R', signedQuarterTurns: 1,
  pointerType: 'touch', timingBin: null, directionSector: 2, speedClass: 1,
};
const recordB = {
  version: 1, sequence: 2, kind: 'orbit', command: 'Y', signedQuarterTurns: -1,
  pointerType: 'mouse', timingBin: 3, directionSector: 0, speedClass: 2,
};

check('initial cube state has one fixed byte record per cubelet property', initialState.length === 27 * 8);
check('record encoding has a fixed width', encodeRecord(recordA).length === 12 && encodeRecord(recordB).length === 12);
check('hex encoding preserves leading zeroes', toHex(new Uint8Array([0, 1, 15, 255])) === '00010fff');

{
  const first = await createTranscriptDigest({ salt, initialState });
  const second = await createTranscriptDigest({ salt, initialState });
  check('identical context creates an identical initial digest', await first.digestHex() === await second.digestHex());
  check('SHA-256 display is 64 lowercase hexadecimal characters', /^[0-9a-f]{64}$/.test(await first.digestHex()));
  check('initial digest matches the canonical cross-runtime vector',
    await first.digestHex() === 'f299412215dd7c9ac20e7ef7118b46d21c78e1104ab0989601f349c7fdf95cdc');
  await first.append(recordA);
  check('first record matches the canonical cross-runtime vector',
    await first.digestHex() === '45f81649da5aa44b7fc6ea264607fd269fef9b05a7d8e010628f7f4f429f635c');
}

{
  const sequential = await createTranscriptDigest({ salt, initialState });
  await sequential.append(recordA);
  await sequential.append(recordB);

  const concurrent = await createTranscriptDigest({ salt, initialState });
  await Promise.all([concurrent.append(recordA), concurrent.append(recordB)]);
  check('concurrent append calls are serialized in invocation order',
    await concurrent.digestHex() === await sequential.digestHex());
}

{
  const original = await createTranscriptDigest({ salt, initialState });
  await original.append(recordA);

  const changed = await createTranscriptDigest({ salt, initialState });
  await changed.append({ ...recordA, speedClass: 2 });
  check('changing a record changes the digest', await original.digestHex() !== await changed.digestHex());

  const reordered = await createTranscriptDigest({ salt, initialState });
  await reordered.append({ ...recordB, sequence: 1 });
  await reordered.append({ ...recordA, sequence: 2 });
  check('changing record order changes the digest', await original.digestHex() !== await reordered.digestHex());
}

{
  let threw = false;
  try {
    await createTranscriptDigest({ salt: new Uint8Array(15), initialState });
  } catch (error) {
    threw = error instanceof TypeError;
  }
  check('invalid salt length is rejected', threw);
}

{
  const invalidRecords = [
    { ...recordA, version: 257 },
    { ...recordA, sequence: -1 },
    { ...recordA, sequence: 0x100000000 },
    { ...recordA, timingBin: 256 },
    { ...recordA, directionSector: 8 },
    { ...recordA, speedClass: 3 },
    { ...recordA, kind: 'toString' },
    { ...recordA, command: 'constructor' },
    { ...recordA, pointerType: 'valueOf' },
    { ...recordA, signedQuarterTurns: '1' },
    { ...recordA, kind: 'orbit' },
    { ...recordB, kind: 'layer' },
  ];
  check('out-of-range fixed-width fields are rejected instead of truncated',
    invalidRecords.every((record) => {
      try {
        encodeRecord(record);
        return false;
      } catch (error) {
        return error instanceof TypeError;
      }
    }));
}

console.log(fail === 0 ? `ALL ${pass} PASS` : `${fail} FAILED (${pass} passed)`);
process.exitCode = fail === 0 ? 0 : 1;