import { ALL_DIRECTIONS, getDirectionByNormal } from './direction.js';
import { Twist } from './twist.js';
import { Cubelet, positionToAddress } from './cubelet.js';
import { SOLVED_COLOR_MAP } from './solved-color-map.js';
import { rotateSteps } from './rotation.js';

// Each move is defined by the axis it turns around, the layer(s) it affects in the current
// state, and the sign of that rotation. The sign table is explicit rather than inferred from
// a simple letter-case rule because the observed behavior is not completely symmetric across
// all move names.
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
  // Whole-cube turns rotate every cubelet. These are not special-case debug-only commands;
  // they correspond to the cube's large-surface drag behavior and are treated as real moves.
  X: { axis: 'x', sign: 1, filter: () => true },
  Y: { axis: 'y', sign: 1, filter: () => true },
  Z: { axis: 'z', sign: 1, filter: () => true },
};

// Exposes the move's axis and its intrinsic sign. This is used where a drag preview must be
// converted into the canonical command form without guessing at the sign convention.
export function getCommandAxisAndSign(command) {
  const spec = COMMANDS[command.toUpperCase()];
  if (!spec) throw new Error(`Unknown twist command: ${command}`);
  return { axis: spec.axis, sign: spec.sign };
}

// The default shuffle set deliberately avoids moves that would rotate or relocate the
// front-center logo sticker. This preserves the special sticker while still producing a
// visually varied scramble.
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

  // Applies a single move and returns the pre-twist positions of the affected cubelets. The
  // animation layer uses those coordinates to sweep a smooth motion from the previous layout
  // instead of snapping directly to the new orientation.
  twist(commandOrTwist, degrees) {
    const t = commandOrTwist instanceof Twist ? commandOrTwist : new Twist(commandOrTwist, degrees);
    const spec = COMMANDS[t.command.toUpperCase()];
    if (!spec) throw new Error(`Unknown twist command: ${t.command}`);

    const quarterTurns = Math.round((t.degrees ?? 90) / 90);
    const effectiveSign = spec.sign * t.vector;
    const affected = this.cubelets.filter(spec.filter);
    const preTwistPositions = affected.map((c) => ({ id: c.id, x: c.x, y: c.y, z: c.z }));

    // Each cubelet is repositioned from the current state before any mutation occurs, so the
    // rotation is computed from the original layout rather than a partially updated one.
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
    return { twist: t, axis: spec.axis, modelDegrees: effectiveSign * quarterTurns * 90, cubelets: preTwistPositions };
  }

  // Produces a scramble by applying a sequence of random legal turns without immediately
  // reversing the previous move. If a callback is supplied, it is awaited after each move so
  // a caller can animate the scramble step by step.
  async shuffle(amount = 25, onEachTwist) {
    let lastInverseCommand = null;
    const results = [];
    for (let i = 0; i < amount; i++) {
      let command;
      do {
        command = SHUFFLE_COMMANDS[Math.floor(Math.random() * SHUFFLE_COMMANDS.length)];
        if (Math.random() < 0.5) command = command.toLowerCase();
      } while (command === lastInverseCommand);

      const result = this.twist(command);
      results.push(result);
      lastInverseCommand = result.twist.getInverse().command;
      if (onEachTwist) await onEachTwist(result);
    }
    return results;
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
