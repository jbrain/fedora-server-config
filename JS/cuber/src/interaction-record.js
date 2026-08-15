const ORBIT_COMMANDS = new Set(['X', 'Y', 'Z']);

export function timingBin(elapsedMs) {
  if (elapsedMs < 250) return 0;
  if (elapsedMs < 500) return 1;
  if (elapsedMs < 1000) return 2;
  if (elapsedMs < 2000) return 3;
  if (elapsedMs < 4000) return 4;
  return 5;
}

export function gestureToken(dx, dy, elapsedMs) {
  const angle = Math.atan2(dy, dx);
  const directionSector = (Math.round(angle / (Math.PI / 4)) + 8) % 8;
  const velocity = Math.hypot(dx, dy) / Math.max(elapsedMs, 1);
  const speedClass = velocity < 0.25 ? 0 : velocity < 0.75 ? 1 : 2;
  return { directionSector, speedClass };
}

export function committedInteraction({ command, quarterTurns, pointerType, dx, dy, elapsedMs, committedAt }) {
  const canonicalCommand = command.toUpperCase();
  const direction = command === canonicalCommand ? 1 : -1;
  const normalizedTurns = Math.abs(quarterTurns) % 4;
  if (normalizedTurns === 0) return null;

  const signedQuarterTurns = normalizedTurns === 2
    ? 2
    : direction * (normalizedTurns === 3 ? -1 : 1);

  return {
    kind: ORBIT_COMMANDS.has(canonicalCommand) ? 'orbit' : 'layer',
    command: canonicalCommand,
    signedQuarterTurns,
    pointerType: ['mouse', 'touch', 'pen'].includes(pointerType) ? pointerType : 'other',
    ...gestureToken(dx, dy, elapsedMs),
    committedAt,
  };
}