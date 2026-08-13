// A single quarter-turn (90°) rotation of a {x,y,z} vector around a cardinal axis.
// The two `sign` cases were ground-truthed against the original engine's actual live
// behavior (see plans/cuber-modernization/README.md) rather than derived from assumed
// clockwise/anticlockwise conventions - those turned out to vary per twist command in
// ways that aren't safe to guess (see rotationSign() in cube.js).
export function rotateQuarter({ x, y, z }, axis, sign) {
  switch (axis) {
    case 'x': return sign === 1 ? { x, y: z, z: -y } : { x, y: -z, z: y };
    case 'y': return sign === 1 ? { x: -z, y, z: x } : { x: z, y, z: -x };
    case 'z': return sign === 1 ? { x: y, y: -x, z } : { x: -y, y: x, z };
    default: throw new Error(`Unknown axis: ${axis}`);
  }
}

// Applies `steps` quarter-turns (any integer, negative allowed) in one composed call.
export function rotateSteps(vector, axis, sign, steps) {
  let v = vector;
  const n = ((Math.round(steps) % 4) + 4) % 4;
  for (let i = 0; i < n; i++) v = rotateQuarter(v, axis, sign);
  return v;
}
