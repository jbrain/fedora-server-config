// One of the 6 faces of a cube. Ported conceptually from ERNO.Direction
// (see plans/cuber-modernization/README.md) with zero 3D-library dependency.
export class Direction {
  constructor(id, name, normal) {
    this.id = id;
    this.name = name;
    this.normal = normal;
    this.neighbors = [];
    this.opposite = null;
  }

  // Wires this direction's 4 clockwise neighbors and its opposite face.
  // Called once, after all 6 singleton instances exist.
  setRelationships(up, right, down, left, opposite) {
    this.neighbors = [up, right, down, left];
    this.opposite = opposite;
  }

  // Rotates `from` (one of this direction's 4 neighbors) by `steps` positions
  // around this direction's neighbor ring; `vector` is +1 clockwise, -1 anticlockwise.
  getRotation(vector, from, steps = 1) {
    if (from === undefined) from = this.neighbors[0];
    if (from === this || from === this.opposite) return null;

    const idx = this.neighbors.indexOf(from);
    if (idx === -1) return null;

    const newIndex = (((idx + steps * vector) % 4) + 4) % 4;
    return this.neighbors[newIndex];
  }

  getClockwise(from, steps) {
    return this.getRotation(1, from, steps);
  }

  getAnticlockwise(from, steps) {
    return this.getRotation(-1, from, steps);
  }

  getDirection(direction, up) {
    return this.getRotation(1, up, direction.id - 1);
  }

  getUp(up) {
    return this.getDirection(UP, up);
  }

  getRight(up) {
    return this.getDirection(RIGHT, up);
  }

  getDown(up) {
    return this.getDirection(DOWN, up);
  }

  getLeft(up) {
    return this.getDirection(LEFT, up);
  }

  getOpposite() {
    return this.opposite;
  }
}

export const FRONT = new Direction(0, 'front', { x: 0, y: 0, z: 1 });
export const UP = new Direction(1, 'up', { x: 0, y: 1, z: 0 });
export const RIGHT = new Direction(2, 'right', { x: 1, y: 0, z: 0 });
export const DOWN = new Direction(3, 'down', { x: 0, y: -1, z: 0 });
export const LEFT = new Direction(4, 'left', { x: -1, y: 0, z: 0 });
export const BACK = new Direction(5, 'back', { x: 0, y: 0, z: -1 });

// Exact relationship data transcribed from the upstream source — do not change.
FRONT.setRelationships(UP, RIGHT, DOWN, LEFT, BACK);
UP.setRelationships(BACK, RIGHT, FRONT, LEFT, DOWN);
RIGHT.setRelationships(UP, BACK, DOWN, FRONT, LEFT);
DOWN.setRelationships(FRONT, RIGHT, BACK, LEFT, UP);
LEFT.setRelationships(UP, FRONT, DOWN, BACK, RIGHT);
BACK.setRelationships(UP, LEFT, DOWN, RIGHT, FRONT);

export const ALL_DIRECTIONS = [FRONT, UP, RIGHT, DOWN, LEFT, BACK];

export function getDirectionByNormal({ x, y, z }) {
  const rx = Math.round(x);
  const ry = Math.round(y);
  const rz = Math.round(z);

  for (const direction of ALL_DIRECTIONS) {
    const n = direction.normal;
    if (n.x === rx && n.y === ry && n.z === rz) return direction;
  }

  return null;
}
