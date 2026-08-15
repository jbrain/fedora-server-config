const DOMAIN = 'jackbrain-cube-interaction/v1';
const CONFIGURATION = 'novelty-v1|cap=32|discount=0.5|timing=250,500,1000,2000,4000|direction=8|speed=0.25,0.75';

const KIND = { layer: 0, orbit: 1 };
const COMMAND = { L: 0, M: 1, R: 2, D: 3, E: 4, U: 5, B: 6, S: 7, F: 8, X: 9, Y: 10, Z: 11 };
const POINTER = { mouse: 0, touch: 1, pen: 2, other: 3 };
const TURN = { '-1': 0, 1: 1, 2: 2 };
const COLOR = { none: 0, white: 1, orange: 2, blue: 3, red: 4, green: 5, yellow: 6 };

function hasOwn(object, key) {
  return Object.prototype.hasOwnProperty.call(object, key);
}

function concat(...arrays) {
  const length = arrays.reduce((total, array) => total + array.length, 0);
  const result = new Uint8Array(length);
  let offset = 0;
  arrays.forEach((array) => {
    result.set(array, offset);
    offset += array.length;
  });
  return result;
}

function uint16(value) {
  return new Uint8Array([(value >>> 8) & 0xff, value & 0xff]);
}

function uint32(value) {
  return new Uint8Array([
    (value >>> 24) & 0xff,
    (value >>> 16) & 0xff,
    (value >>> 8) & 0xff,
    value & 0xff,
  ]);
}

function text(value) {
  const bytes = new TextEncoder().encode(value);
  return concat(uint16(bytes.length), bytes);
}

async function sha256(bytes) {
  const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);
  return new Uint8Array(digest);
}

export function toHex(bytes) {
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

export function createSessionSalt() {
  return globalThis.crypto.getRandomValues(new Uint8Array(16));
}

export function encodeInitialState(cube) {
  const bytes = [];
  [...cube.cubelets]
    .sort((a, b) => a.id - b.id)
    .forEach((cubelet) => {
      bytes.push(cubelet.id, cubelet.address);
      cubelet.faces.forEach((face) => bytes.push(COLOR[face.color.name]));
    });
  return new Uint8Array(bytes);
}

export function encodeRecord(record) {
  const validVersion = record.version === 1;
  const validSequence = Number.isInteger(record.sequence) && record.sequence >= 1 && record.sequence <= 0xffffffff;
  const validTiming = record.timingBin === null ||
    (Number.isInteger(record.timingBin) && record.timingBin >= 0 && record.timingBin <= 5);
  const validDirection = Number.isInteger(record.directionSector) &&
    record.directionSector >= 0 && record.directionSector <= 7;
  const validSpeed = Number.isInteger(record.speedClass) && record.speedClass >= 0 && record.speedClass <= 2;
  const validTurn = Number.isInteger(record.signedQuarterTurns) &&
    hasOwn(TURN, String(record.signedQuarterTurns));
    const orbitCommand = ['X', 'Y', 'Z'].includes(record.command);
    const validKindCommand = (record.kind === 'orbit') === orbitCommand;
    if (!hasOwn(KIND, record.kind) || !hasOwn(COMMAND, record.command) ||
      !hasOwn(POINTER, record.pointerType) || !validTurn ||
      !validVersion || !validSequence || !validTiming ||
      !validDirection || !validSpeed || !validKindCommand) {
    throw new TypeError('Unsupported interaction record value');
  }

  return concat(
    new Uint8Array([record.version]),
    uint32(record.sequence),
    new Uint8Array([
      KIND[record.kind],
      COMMAND[record.command],
      TURN[String(record.signedQuarterTurns)],
      POINTER[record.pointerType],
      record.timingBin === null ? 0xff : record.timingBin,
      record.directionSector,
      record.speedClass,
    ]),
  );
}

export async function createTranscriptDigest({ salt, initialState }) {
  if (!(salt instanceof Uint8Array) || salt.length !== 16) {
    throw new TypeError('Session salt must be exactly 16 bytes');
  }

  let current = await sha256(concat(text(DOMAIN), salt, uint16(initialState.length), initialState, text(CONFIGURATION)));
  let queue = Promise.resolve();

  return {
    append(record) {
      queue = queue.then(async () => {
        current = await sha256(concat(text(DOMAIN), current, encodeRecord(record)));
        return toHex(current);
      });
      return queue;
    },

    digestHex() {
      return queue.then(() => toHex(current));
    },
  };
}