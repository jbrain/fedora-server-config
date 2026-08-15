import { ALL_DIRECTIONS } from './direction.js';
import { COLORLESS, WHITE } from './color.js';

// Maps the flattened 0-26 address to the cube's coordinate system. Each axis value is kept in
// the range {-1, 0, 1} and is used as the canonical position of the cubelet.
export function addressToPosition(address) {
  return {
    x: (address % 3) - 1,
    y: 1 - Math.floor((address % 9) / 3),
    z: 1 - Math.floor(address / 9),
  };
}

// Inverse of addressToPosition. Position and address are kept in sync to preserve a single
// canonical model of the cube's geometry.
export function positionToAddress({ x, y, z }) {
  return (1 - z) * 9 + (1 - y) * 3 + (x + 1);
}

// A single cubie in the 3x3x3 structure. Each face slot is indexed by a fixed direction ID,
// and the visible sticker for that slot is refreshed during each move so the cubelet always
// reflects its current orientation.
export class Cubelet {
  constructor(id, colorRow) {
    this.id = id;
    this.setAddress(id);
    this.faces = ALL_DIRECTIONS.map((_, i) => ({ color: colorRow[i] || COLORLESS }));

    const visibleFaces = this.faces.filter((f) => f.color !== COLORLESS).length;
    this.type = ['core', 'center', 'edge', 'corner'][visibleFaces];

    // One physical cubelet is marked as the logo cubelet. It is identified by its center-face
    // color in the solved state and retains that flag as it moves through the cube.
    this.isLogo = colorRow[0] === WHITE && this.type === 'center';
  }

  setAddress(address) {
    this.address = address;
    Object.assign(this, addressToPosition(address));
  }
}
