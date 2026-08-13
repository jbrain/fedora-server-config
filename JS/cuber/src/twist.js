// A single twist command. Deliberately minimal — no compound twist-string
// parsing (unused in production; see plans/cuber-modernization/README.md).
const AXIS_BY_COMMAND = {
  x: 'x', l: 'x', m: 'x', r: 'x',
  y: 'y', u: 'y', e: 'y', d: 'y',
  z: 'z', f: 'z', s: 'z', b: 'z',
};

function flipCase(command) {
  return command === command.toUpperCase()
    ? command.toLowerCase()
    : command.toUpperCase();
}

export class Twist {
  constructor(command, degrees) {
    if (typeof degrees === 'number' && degrees < 0) {
      command = flipCase(command);
      degrees = Math.abs(degrees);
    }

    this.command = command;
    this.degrees = degrees;
    this.axis = AXIS_BY_COMMAND[command.toLowerCase()];
    this.vector = command === command.toUpperCase() ? 1 : -1;
    this.isShuffle = false;
  }

  getInverse() {
    return new Twist(flipCase(this.command), this.degrees);
  }

  equals(otherTwist) {
    return this.command === otherTwist.command && this.degrees === otherTwist.degrees;
  }
}
