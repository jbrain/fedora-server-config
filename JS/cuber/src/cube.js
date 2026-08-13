import { ALL_DIRECTIONS, getDirectionByNormal } from './direction.js';
import { Twist } from './twist.js';
import { Cubelet, positionToAddress } from './cubelet.js';
import { SOLVED_COLOR_MAP } from './solved-color-map.js';
import { rotateSteps } from './rotation.js';

// Per-command rotation spec: which cardinal axis it turns on, which cubelets it
// affects (by their CURRENT position, since that changes after every twist), and
// its rotation `sign`.
//
// `sign` is NOT simply "uppercase = clockwise" for every letter - it was
// ground-truthed command-by-command against the original engine's actual live
// behavior (see plans/cuber-modernization/README.md), because real Rubik's-cube
// notation is not fully symmetric: R/U/F share one sign, L/D/B are the opposite,
// and the three middle slices don't follow a single consistent rule either - M
// matches L (opposite of R), while E and S both match U/F (not D/B). The three
// whole-cube commands (X/Y/Z) each match their same-letter-family face (R/U/F
// respectively). Do not "simplify" this table from assumed symmetry without
// re-verifying against the original engine; that's exactly the kind of subtle
// bug this project is designed to avoid.
const COMMANDS = {
  R: { axis: 'x', sign: 1, filter: (c) => c.x === 1 },
  L: { axis: 'x', sign: -1, filter: (c) => c.x === -1 },
  M: { axis: 'x', sign: -1, filter: (c) => c.x === 0 },
  U: { axis: 'y', sign: 1, filter: (c) => c.y === 1 },
  D: { axis: 'y', sign: -1, filter: (c) => c.y === -1 },
  E: { axis: 'y', sign: 1, filter: (c) => c.y === 0 },
  F: { axis: 'z', sign: 1, filter: (c) => c.z === 1 },
  B: { axis: 'z', sign: -1, filter: (c) => c.z === -1 },
  S: { axis: 'z', sign: 1, filter: (c) => c.z === 0 },
  // Whole-cube rotations (all 27 cubelets) - these are NOT a dead keyboard-only
  // debug feature (an earlier pass in this project wrongly assumed that and
  // skipped them - see plans/cuber-modernization/README.md). Production's actual
  // drag-to-orbit interaction (ERNO.Locked) commits these as real twists when
  // dragging outside the cube. Ground-truthed: X matches R's sign, Y matches U's,
  // Z matches F's (all +1) - confirmed by exact-match live comparison, not assumed.
  X: { axis: 'x', sign: 1, filter: () => true },
  Y: { axis: 'y', sign: 1, filter: () => true },
  Z: { axis: 'z', sign: 1, filter: () => true },
};

// Production's actual default shuffle move set (ERNO.Cube's PRESERVE_LOGO) -
// deliberately excludes F/M/E because they'd rotate or move the front-center
// logo-sticker cubelet. Ground-truthed from the original engine's own source
// (this.shuffleMethod = this.PRESERVE_LOGO = 'RrLlUuDdSsBb'), not guessed.
const SHUFFLE_COMMANDS = ['R', 'L', 'U', 'D', 'S', 'B'];

export class Cube {
  constructor() {
    this.cubelets = SOLVED_COLOR_MAP.map((row, id) => new Cubelet(id, row));
    this._reindexByAddress();
  }

  _reindexByAddress() {
    this.byAddress = new Array(27);
    this.cubelets.forEach((c) => { this.byAddress[c.address] = c; });
  }

  // Applies one twist (either a Twist instance, or a command letter + optional degrees).
  twist(commandOrTwist, degrees) {
    const t = commandOrTwist instanceof Twist ? commandOrTwist : new Twist(commandOrTwist, degrees);
    const spec = COMMANDS[t.command.toUpperCase()];
    if (!spec) throw new Error(`Unknown twist command: ${t.command}`);

    const quarterTurns = Math.round((t.degrees ?? 90) / 90);
    const effectiveSign = spec.sign * t.vector;
    const affected = this.cubelets.filter(spec.filter);

    // Compute every affected cubelet's new position/orientation from the CURRENT
    // (pre-twist) state before mutating any of them, so cubelets never see each
    // other's already-updated state mid-rotation.
    const updates = affected.map((cubelet) => {
      const newPosition = rotateSteps(
        { x: cubelet.x, y: cubelet.y, z: cubelet.z },
        spec.axis,
        effectiveSign,
        quarterTurns,
      );

      const newFaces = new Array(6);
      ALL_DIRECTIONS.forEach((direction) => {
        const newNormal = rotateSteps(direction.normal, spec.axis, effectiveSign, quarterTurns);
        const newDirection = getDirectionByNormal(newNormal);
        newFaces[newDirection.id] = cubelet.faces[direction.id];
      });

      return { cubelet, newAddress: positionToAddress(newPosition), newFaces };
    });

    updates.forEach(({ cubelet, newAddress, newFaces }) => {
      cubelet.setAddress(newAddress);
      cubelet.faces = newFaces;
    });

    this._reindexByAddress();
    return t;
  }

  // Shuffles with `amount` random twists, never immediately reversing the previous one.
  shuffle(amount = 25) {
    let lastInverseCommand = null;
    for (let i = 0; i < amount; i++) {
      let command;
      do {
        command = SHUFFLE_COMMANDS[Math.floor(Math.random() * SHUFFLE_COMMANDS.length)];
        if (Math.random() < 0.5) command = command.toLowerCase();
      } while (command === lastInverseCommand);

      const twist = this.twist(command);
      lastInverseCommand = twist.getInverse().command;
    }
  }

  isSolved() {
    return ALL_DIRECTIONS.every((direction) => {
      const axisKey = direction.normal.x !== 0 ? 'x' : direction.normal.y !== 0 ? 'y' : 'z';
      const axisValue = direction.normal[axisKey];
      const names = this.cubelets
        .filter((c) => c[axisKey] === axisValue)
        .map((c) => c.faces[direction.id].color.name);
      return names.every((name) => name === names[0]);
    });
  }
}
