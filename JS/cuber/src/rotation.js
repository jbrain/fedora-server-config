// Rotates a vector by one quarter-turn around a cardinal axis. The sign mapping is defined
// explicitly for each axis; this keeps the cube state and the CSS preview in agreement.
export function rotateQuarter({ x, y, z }, axis, sign) {
  switch (axis) {
    case 'x': return sign === 1 ? { x, y: z, z: -y } : { x, y: -z, z: y };
    case 'y': return sign === 1 ? { x: -z, y, z: x } : { x: z, y, z: -x };
    case 'z': return sign === 1 ? { x: y, y: -x, z } : { x: -y, y: x, z };
    default: throw new Error(`Unknown axis: ${axis}`);
  }
}

// Applies a number of quarter-turns in one composed operation. The number of steps is reduced
// modulo four so repeated turns remain on the same rotational cycle.
export function rotateSteps(vector, axis, sign, steps) {
  let v = vector;
  const n = ((Math.round(steps) % 4) + 4) % 4;
  for (let i = 0; i < n; i++) v = rotateQuarter(v, axis, sign);
  return v;
}
