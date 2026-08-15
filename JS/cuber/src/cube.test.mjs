import { Cube } from './cube.js';

let pass = 0, fail = 0;
function check(desc, cond) {
  console.log(cond ? 'PASS' : 'FAIL', desc);
  if (cond) pass++; else fail++;
}

function faceNames(cubelet) {
  return cubelet.faces.map((f) => f.color.name === 'none' ? 'NA' : f.color.name);
}

function snapshotById(cube) {
  const out = {};
  cube.cubelets.forEach((c) => { out[c.id] = { address: c.address, faces: faceNames(c) }; });
  return out;
}

// --- Initial solved-state invariants ---
{
  const cube = new Cube();
  const snap = snapshotById(cube);
  check('initial cubelet 0', JSON.stringify(snap[0]) === JSON.stringify({ address: 0, faces: ['white', 'orange', 'NA', 'NA', 'green', 'NA'] }));
  check('initial cubelet 26', JSON.stringify(snap[26]) === JSON.stringify({ address: 26, faces: ['NA', 'NA', 'blue', 'red', 'NA', 'yellow'] }));
  check('initial isSolved()', cube.isSolved() === true);
}

// --- Full 27-cubelet reference data for R, U, F ---
const FULL_GROUND_TRUTH = {
  R: {"0":{"address":0,"faces":["white","orange","NA","NA","green","NA"]},"1":{"address":1,"faces":["white","orange","NA","NA","NA","NA"]},"2":{"address":20,"faces":["NA","white","blue","NA","NA","orange"]},"3":{"address":3,"faces":["white","NA","NA","NA","green","NA"]},"4":{"address":4,"faces":["white","NA","NA","NA","NA","NA"]},"5":{"address":11,"faces":["NA","white","blue","NA","NA","NA"]},"6":{"address":6,"faces":["white","NA","NA","red","green","NA"]},"7":{"address":7,"faces":["white","NA","NA","red","NA","NA"]},"8":{"address":2,"faces":["red","white","blue","NA","NA","NA"]},"9":{"address":9,"faces":["NA","orange","NA","NA","green","NA"]},"10":{"address":10,"faces":["NA","orange","NA","NA","NA","NA"]},"11":{"address":23,"faces":["NA","NA","blue","NA","NA","orange"]},"12":{"address":12,"faces":["NA","NA","NA","NA","green","NA"]},"13":{"address":13,"faces":["NA","NA","NA","NA","NA","NA"]},"14":{"address":14,"faces":["NA","NA","blue","NA","NA","NA"]},"15":{"address":15,"faces":["NA","NA","NA","red","green","NA"]},"16":{"address":16,"faces":["NA","NA","NA","red","NA","NA"]},"17":{"address":5,"faces":["red","NA","blue","NA","NA","NA"]},"18":{"address":18,"faces":["NA","orange","NA","NA","green","yellow"]},"19":{"address":19,"faces":["NA","orange","NA","NA","NA","yellow"]},"20":{"address":26,"faces":["NA","NA","blue","yellow","NA","orange"]},"21":{"address":21,"faces":["NA","NA","NA","NA","green","yellow"]},"22":{"address":22,"faces":["NA","NA","NA","NA","NA","yellow"]},"23":{"address":17,"faces":["NA","NA","blue","yellow","NA","NA"]},"24":{"address":24,"faces":["NA","NA","NA","red","green","yellow"]},"25":{"address":25,"faces":["NA","NA","NA","red","NA","yellow"]},"26":{"address":8,"faces":["red","NA","blue","yellow","NA","NA"]}},
  U: {"0":{"address":18,"faces":["NA","orange","NA","NA","white","green"]},"1":{"address":9,"faces":["NA","orange","NA","NA","white","NA"]},"2":{"address":0,"faces":["blue","orange","NA","NA","white","NA"]},"3":{"address":3,"faces":["white","NA","NA","NA","green","NA"]},"4":{"address":4,"faces":["white","NA","NA","NA","NA","NA"]},"5":{"address":5,"faces":["white","NA","blue","NA","NA","NA"]},"6":{"address":6,"faces":["white","NA","NA","red","green","NA"]},"7":{"address":7,"faces":["white","NA","NA","red","NA","NA"]},"8":{"address":8,"faces":["white","NA","blue","red","NA","NA"]},"9":{"address":19,"faces":["NA","orange","NA","NA","NA","green"]},"10":{"address":10,"faces":["NA","orange","NA","NA","NA","NA"]},"11":{"address":1,"faces":["blue","orange","NA","NA","NA","NA"]},"12":{"address":12,"faces":["NA","NA","NA","NA","green","NA"]},"13":{"address":13,"faces":["NA","NA","NA","NA","NA","NA"]},"14":{"address":14,"faces":["NA","NA","blue","NA","NA","NA"]},"15":{"address":15,"faces":["NA","NA","NA","red","green","NA"]},"16":{"address":16,"faces":["NA","NA","NA","red","NA","NA"]},"17":{"address":17,"faces":["NA","NA","blue","red","NA","NA"]},"18":{"address":20,"faces":["NA","orange","yellow","NA","NA","green"]},"19":{"address":11,"faces":["NA","orange","yellow","NA","NA","NA"]},"20":{"address":2,"faces":["blue","orange","yellow","NA","NA","NA"]},"21":{"address":21,"faces":["NA","NA","NA","NA","green","yellow"]},"22":{"address":22,"faces":["NA","NA","NA","NA","NA","yellow"]},"23":{"address":23,"faces":["NA","NA","blue","NA","NA","yellow"]},"24":{"address":24,"faces":["NA","NA","NA","red","green","yellow"]},"25":{"address":25,"faces":["NA","NA","NA","red","NA","yellow"]},"26":{"address":26,"faces":["NA","NA","blue","red","NA","yellow"]}},
  F: {"0":{"address":2,"faces":["white","green","orange","NA","NA","NA"]},"1":{"address":5,"faces":["white","NA","orange","NA","NA","NA"]},"2":{"address":8,"faces":["white","NA","orange","blue","NA","NA"]},"3":{"address":1,"faces":["white","green","NA","NA","NA","NA"]},"4":{"address":4,"faces":["white","NA","NA","NA","NA","NA"]},"5":{"address":7,"faces":["white","NA","NA","blue","NA","NA"]},"6":{"address":0,"faces":["white","green","NA","NA","red","NA"]},"7":{"address":3,"faces":["white","NA","NA","NA","red","NA"]},"8":{"address":6,"faces":["white","NA","NA","blue","red","NA"]},"9":{"address":9,"faces":["NA","orange","NA","NA","green","NA"]},"10":{"address":10,"faces":["NA","orange","NA","NA","NA","NA"]},"11":{"address":11,"faces":["NA","orange","blue","NA","NA","NA"]},"12":{"address":12,"faces":["NA","NA","NA","NA","green","NA"]},"13":{"address":13,"faces":["NA","NA","NA","NA","NA","NA"]},"14":{"address":14,"faces":["NA","NA","blue","NA","NA","NA"]},"15":{"address":15,"faces":["NA","NA","NA","red","green","NA"]},"16":{"address":16,"faces":["NA","NA","NA","red","NA","NA"]},"17":{"address":17,"faces":["NA","NA","blue","red","NA","NA"]},"18":{"address":18,"faces":["NA","orange","NA","NA","green","yellow"]},"19":{"address":19,"faces":["NA","orange","NA","NA","NA","yellow"]},"20":{"address":20,"faces":["NA","orange","blue","NA","NA","yellow"]},"21":{"address":21,"faces":["NA","NA","NA","NA","green","yellow"]},"22":{"address":22,"faces":["NA","NA","NA","NA","NA","yellow"]},"23":{"address":23,"faces":["NA","NA","blue","NA","NA","yellow"]},"24":{"address":24,"faces":["NA","NA","NA","red","green","yellow"]},"25":{"address":25,"faces":["NA","NA","NA","red","NA","yellow"]},"26":{"address":26,"faces":["NA","NA","blue","red","NA","yellow"]}},
};

for (const command of ['R', 'U', 'F']) {
  const cube = new Cube();
  cube.twist(command);
  const snap = snapshotById(cube);
  let allMatch = true;
  for (let id = 0; id < 27; id++) {
    const ok = JSON.stringify(snap[id]) === JSON.stringify(FULL_GROUND_TRUTH[command][id]);
    if (!ok) {
      allMatch = false;
      console.log(`  MISMATCH cubelet ${id} after ${command}: got ${JSON.stringify(snap[id])}, expected ${JSON.stringify(FULL_GROUND_TRUTH[command][id])}`);
    }
  }
  check(`full 27-cubelet match after twist('${command}')`, allMatch);
}

// --- Spot checks for L, M, D, B, S, E (the "non-obvious sign" commands) ---
const SPOT_CHECKS = [
  ['L', 3, { address: 15, faces: ['NA', 'NA', 'NA', 'white', 'green', 'NA'] }],
  ['L', 6, { address: 24, faces: ['NA', 'NA', 'NA', 'white', 'green', 'red'] }],
  ['M', 4, { address: 16, faces: ['NA', 'NA', 'NA', 'white', 'NA', 'NA'] }],
  ['D', 6, { address: 8, faces: ['green', 'NA', 'white', 'red', 'NA', 'NA'] }],
  ['D', 8, { address: 26, faces: ['NA', 'NA', 'white', 'red', 'NA', 'blue'] }],
  ['B', 18, { address: 24, faces: ['NA', 'NA', 'NA', 'green', 'orange', 'yellow'] }],
  ['S', 9, { address: 11, faces: ['NA', 'green', 'orange', 'NA', 'NA', 'NA'] }],
  ['E', 12, { address: 22, faces: ['NA', 'NA', 'NA', 'NA', 'NA', 'green'] }],
];

for (const [command, id, expected] of SPOT_CHECKS) {
  const cube = new Cube();
  cube.twist(command);
  const snap = snapshotById(cube);
  check(`twist('${command}') cubelet ${id}`, JSON.stringify(snap[id]) === JSON.stringify(expected));
}

// --- Whole-cube X/Y/Z checks ---
const WHOLE_CUBE_SPOT_CHECKS = [
  ['X', 0, { address: 18, faces: ['NA', 'white', 'NA', 'NA', 'green', 'orange'] }],
  ['X', 2, { address: 20, faces: ['NA', 'white', 'blue', 'NA', 'NA', 'orange'] }],
  ['Y', 0, { address: 18, faces: ['NA', 'orange', 'NA', 'NA', 'white', 'green'] }],
  ['Z', 0, { address: 2, faces: ['white', 'green', 'orange', 'NA', 'NA', 'NA'] }],
];

for (const [command, id, expected] of WHOLE_CUBE_SPOT_CHECKS) {
  const cube = new Cube();
  cube.twist(command);
  const snap = snapshotById(cube);
  check(`twist('${command}') cubelet ${id}`, JSON.stringify(snap[id]) === JSON.stringify(expected));
}

// --- Round-trip / inverse behavior ---
{
  const cube = new Cube();
  const solvedSnap = JSON.stringify(snapshotById(cube));
  cube.twist('R'); cube.twist('r');
  check("twist('R') then twist('r') returns to solved", JSON.stringify(snapshotById(cube)) === solvedSnap && cube.isSolved());
}
{
  const cube = new Cube();
  const solvedSnap = JSON.stringify(snapshotById(cube));
  for (let i = 0; i < 4; i++) cube.twist('U');
  check("four twist('U') returns to solved", JSON.stringify(snapshotById(cube)) === solvedSnap && cube.isSolved());
}
{
  const cube = new Cube();
  cube.twist('R');
  check('single twist unsolves the cube', cube.isSolved() === false);
}
{
  const cube = new Cube();
  const logoCubeletId = cube.cubelets.find((c) => c.x === 0 && c.y === 0 && c.z === 1).id;
  cube.shuffle(30);
  const stillLogoPosition = cube.byAddress.findIndex((c) => c && c.id === logoCubeletId) === 4;
  check('shuffle() default set never moves the front-center logo cubelet (matches PRESERVE_LOGO)', stillLogoPosition);
}
{
  const cube = new Cube();
  const logoCubelets = cube.cubelets.filter((c) => c.isLogo);
  const onlyOne = logoCubelets.length === 1;
  const isFrontCenter = onlyOne && logoCubelets[0].x === 0 && logoCubelets[0].y === 0 && logoCubelets[0].z === 1;
  check('exactly one cubelet is flagged isLogo, and it is the front-center cubelet', onlyOne && isFrontCenter);
}
{
  const cube = new Cube();
  const logoId = cube.cubelets.find((c) => c.isLogo).id;
  cube.twist('Y'); // whole-cube rotation moves the logo cubelet off front-center
  const logoCubelet = cube.cubelets.find((c) => c.id === logoId);
  check('isLogo flag stays with the physical cubelet after a whole-cube twist, not tied to a fixed address', logoCubelet.isLogo === true);
}
{
  const cube = new Cube();
  cube.shuffle(15);
  check('shuffle(15) leaves exactly 27 cubelets, all addresses unique 0-26', (() => {
    const addresses = cube.cubelets.map((c) => c.address).sort((a, b) => a - b);
    return addresses.length === 27 && addresses.every((a, i) => a === i);
  })());
}

console.log(fail === 0 ? `ALL ${pass} PASS` : `${fail} FAILED (${pass} passed)`);
process.exitCode = fail === 0 ? 0 : 1;
