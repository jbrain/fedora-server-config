import { ALL_DIRECTIONS } from './direction.js';
import { COLORLESS, WHITE } from './color.js';

// Maps a cubelet's address (0-26) to its {x,y,z} position, each in {-1,0,1}.
// Ported from ERNO.Cubelet.setAddress's integer arithmetic (see the plan doc) -
// simplified naming (x/y/z instead of addressX/addressY/addressZ).
export function addressToPosition(address) {
  return {
    x: (address % 3) - 1,
    y: 1 - Math.floor((address % 9) / 3),
    z: 1 - Math.floor(address / 9),
  };
}

// Inverse of addressToPosition - both were verified against the original engine's
// actual address/position pairs during ground-truth testing.
export function positionToAddress({ x, y, z }) {
  return (1 - z) * 9 + (1 - y) * 3 + (x + 1);
}

// One cubelet of the 3x3x3 cube. `faces` is indexed by absolute Direction id
// (front=0, up=1, right=2, down=3, left=4, back=5) and always reflects what's
// CURRENTLY facing that direction - Cube#twist() rebuilds this array on every twist
// so faces[direction.id].color is always correct without needing to track rotation
// history separately (mirrors ERNO.Cubelet's own "faces reindexed by current
// direction" design - see the plan doc's "What Cuber actually is" section).
export class Cubelet {
  constructor(id, colorRow) {
    this.id = id;
    this.setAddress(id);
    this.faces = ALL_DIRECTIONS.map((_, i) => ({ color: colorRow[i] || COLORLESS }));

    const visibleFaces = this.faces.filter((f) => f.color !== COLORLESS).length;
    this.type = ['core', 'center', 'edge', 'corner'][visibleFaces];

    // Matches ERNO.Cubelet's isStickerCubelet: the one physical cubelet whose
    // solved-state front face is white, showing only one face (a face center),
    // gets the JB logo image instead of a flat white sticker. Set once here at
    // construction and never recomputed - it follows this physical cubelet
    // wherever it's twisted to, exactly like upstream's own one-time flag.
    this.isLogo = colorRow[0] === WHITE && this.type === 'center';
  }

  setAddress(address) {
    this.address = address;
    Object.assign(this, addressToPosition(address));
  }
}
