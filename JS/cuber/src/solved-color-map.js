import { WHITE, ORANGE, BLUE, RED, GREEN, YELLOW } from './color.js';

// Static solved-state sticker layout, one row per cubelet id (0-26), each row
// [front, up, right, down, left, back] - transcribed exactly from the original
// engine's hardcoded map and cross-checked against its live ground-truth output
// (see plans/cuber-modernization/README.md). `undefined` entries become COLORLESS
// (interior, non-visible faces) in Cubelet's constructor. Written out explicitly
// (not with array elision like the original) to avoid any ambiguity over which
// slot is meant to be empty.
const W = WHITE, O = ORANGE, B = BLUE, R = RED, G = GREEN, Y = YELLOW, _ = undefined;

export const SOLVED_COLOR_MAP = [
  // Front slice
  [W, O, _, _, G, _], [W, O, _, _, _, _], [W, O, B, _, _, _],
  [W, _, _, _, G, _], [W, _, _, _, _, _], [W, _, B, _, _, _],
  [W, _, _, R, G, _], [W, _, _, R, _, _], [W, _, B, R, _, _],
  // Standing slice
  [_, O, _, _, G, _], [_, O, _, _, _, _], [_, O, B, _, _, _],
  [_, _, _, _, G, _], [_, _, _, _, _, _], [_, _, B, _, _, _],
  [_, _, _, R, G, _], [_, _, _, R, _, _], [_, _, B, R, _, _],
  // Back slice
  [_, O, _, _, G, Y], [_, O, _, _, _, Y], [_, O, B, _, _, Y],
  [_, _, _, _, G, Y], [_, _, _, _, _, Y], [_, _, B, _, _, Y],
  [_, _, _, R, G, Y], [_, _, _, R, _, Y], [_, _, B, R, _, Y],
];

