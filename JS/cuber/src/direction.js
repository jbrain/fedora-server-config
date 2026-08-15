// The six face directions are represented as fixed objects with a name, a normal vector, and
// neighbor relationships. This gives the cube a consistent coordinate map without a 3D
// library dependency.
export class Direction {
  constructor(id, name, normal) {
    this.id = id;
    this.name = name;
    this.normal = normal;
    this.neighbors = [];
    this.opposite = null;
  }

  // Connects the face to its four neighbors and the opposite face.
  setRelationships(up, right, down, left, opposite) {
    this.neighbors = [up, right, down, left];
    this.opposite = opposite;
  }

  // Rotates one neighbor around the face ring by the requested number of steps. The vector
  // indicates clockwise (+1) or anticlockwise (-1) motion.
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

// The face adjacency graph is fixed and defines the cube's topology.
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
