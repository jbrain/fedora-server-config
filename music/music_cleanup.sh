#!/bin/bash
set -uo pipefail
LOG=/tmp/music_cleanup_log.txt
> "$LOG"
DRYRUN="${1:-1}"

run() {
  if [ "$DRYRUN" = "1" ]; then
    printf '[DRYRUN] %s\n' "$*" >> "$LOG"
  else
    printf '[EXEC] %s\n' "$*" >> "$LOG"
    "$@"
  fi
}

STRAY_COUNT=0
JUNK_COUNT=0
NORM_COUNT=0
PROMOTE_COUNT=0
EXTRA_REMOVED=0
MANUAL_REVIEW=/tmp/manual_review_covers.txt
> "$MANUAL_REVIEW"

echo '=== 1. stray files ===' >> "$LOG"
while IFS= read -r -d '' f; do
  run rm -f -- "$f"
  STRAY_COUNT=$((STRAY_COUNT+1))
done < <(find /media/Music -type f \( -iname '*.log' -o -iname '*.cue' -o -iname '*.m3u' -o -iname '*.m3u8' -o -iname '*.txt' -o -iname '*.checkinfo' \) -print0)

echo '=== 2. junk/system files ===' >> "$LOG"
while IFS= read -r -d '' f; do
  run rm -f -- "$f"
  JUNK_COUNT=$((JUNK_COUNT+1))
done < <(find /media/Music -type f \( -name '.DS_Store' -o -iname 'thumbs.db' -o -iname 'desktop.ini' \) -print0)

echo '=== 3. corrupt Aerosmith file ===' >> "$LOG"
AERO='/media/Music/Aerosmith/Toys In The Attic/07.No More No More.mp3'
if [ -f "$AERO" ]; then run rm -f -- "$AERO"; fi

echo '=== 4. Roykspp malformed filename ===' >> "$LOG"
RK_DIR='/media/Music/Röyksopp/True Electric'
RK_FILE="$RK_DIR/4403121-3322878.jpg?v=1770160743"
if [ -f "$RK_FILE" ]; then
  if [ -f "$RK_DIR/cover.jpg" ] || [ -f "$RK_DIR/cover.png" ] || [ -f "$RK_DIR/cover.jpeg" ]; then
    run rm -f -- "$RK_FILE"
  else
    run mv -n -- "$RK_FILE" "$RK_DIR/cover.jpg"
  fi
fi

echo '=== 5. normalize cover case/ext variants ===' >> "$LOG"
while IFS= read -r -d '' f; do
  d=$(dirname -- "$f")
  b=$(basename -- "$f")
  if [ "$b" != 'cover.jpg' ]; then
    run mv -n -- "$f" "$d/cover.jpg"
    NORM_COUNT=$((NORM_COUNT+1))
  fi
done < <(find /media/Music -type f \( -iname 'cover.jpg' -o -iname 'cover.jpeg' \) -print0)

echo '=== 6+7. promote alt art / dedupe extra images ===' >> "$LOG"
mapfile -d '' ALBUM_DIRS < <(find /media/Music -type f \( -iname '*.mp3' -o -iname '*.flac' -o -iname '*.m4a' \) -printf '%h\0' | sort -zu)

for d in "${ALBUM_DIRS[@]}"; do
  canonical=''
  [ -f "$d/cover.jpg" ] && canonical="$d/cover.jpg"
  [ -z "$canonical" ] && [ -f "$d/cover.png" ] && canonical="$d/cover.png"
  [ -z "$canonical" ] && [ -f "$d/cover.jpeg" ] && canonical="$d/cover.jpeg"

  if [ -z "$canonical" ]; then
    mapfile -d '' imgs < <(find "$d" -maxdepth 1 -type f \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' \) -print0)
    if [ "${#imgs[@]}" -gt 0 ]; then
      candidate=''
      for f in "${imgs[@]}"; do b=$(basename -- "$f"); if [[ "${b,,}" == cover* ]]; then candidate="$f"; break; fi; done
      if [ -z "$candidate" ]; then for f in "${imgs[@]}"; do b=$(basename -- "$f"); if [[ "${b,,}" =~ ^folder\.(jpg|jpeg|png)$ ]]; then candidate="$f"; break; fi; done; fi
      if [ -z "$candidate" ]; then for f in "${imgs[@]}"; do b=$(basename -- "$f"); if [[ "${b,,}" == album* ]]; then candidate="$f"; break; fi; done; fi
      if [ -z "$candidate" ]; then for f in "${imgs[@]}"; do b=$(basename -- "$f"); if [[ "${b,,}" == *front* ]]; then candidate="$f"; break; fi; done; fi
      if [ -z "$candidate" ] && [ "${#imgs[@]}" -eq 1 ]; then candidate="${imgs[0]}"; fi

      if [ -n "$candidate" ]; then
        ext="${candidate##*.}"; extlc="${ext,,}"; [ "$extlc" = 'jpeg' ] && extlc='jpg'
        run mv -n -- "$candidate" "$d/cover.$extlc"
        canonical="$d/cover.$extlc"
        PROMOTE_COUNT=$((PROMOTE_COUNT+1))
      else
        printf '%s\n' "$d" >> "$MANUAL_REVIEW"
      fi
    fi
  fi

  if [ -n "$canonical" ]; then
    while IFS= read -r -d '' f; do
      if [ "$f" != "$canonical" ]; then
        run rm -f -- "$f"
        EXTRA_REMOVED=$((EXTRA_REMOVED+1))
      fi
    done < <(find "$d" -maxdepth 1 -type f \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' \) -print0)
  fi
done

echo '=== 8. Counting Crows This Desert Life rename ===' >> "$LOG"
CC_DIR='/media/Music/Counting Crows/This Desert Life'
set_title() {
  local n="$1" title="$2"
  local src="$CC_DIR/$n - Counting Crows - This Desert Life - 1999.flac"
  local dst="$CC_DIR/$n - $title.flac"
  if [ -f "$src" ]; then run mv -n -- "$src" "$dst"; fi
}
set_title 01 "Hangingaround"
set_title 02 "Mrs. Potter's Lullaby"
set_title 03 "Amy Hit the Atmosphere"
set_title 04 "Four Days"
set_title 05 "All My Friends"
set_title 06 "High Life"
set_title 07 "Colorblind"
set_title 08 "I Wish I Was A Girl"
set_title 09 "Speedway"
set_title 10 "St. Robinson in His Cadillac Dream"

echo "SUMMARY: strays=$STRAY_COUNT junk=$JUNK_COUNT normalized=$NORM_COUNT promoted=$PROMOTE_COUNT extra_removed=$EXTRA_REMOVED manual_review=$(wc -l < "$MANUAL_REVIEW")" | tee -a "$LOG"
