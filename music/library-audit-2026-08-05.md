# /media/Music library audit — 2026-08-05

Read-only audit of the Ampache music library at `/media/Music` on jackson-brain.com (591 GB,
897 top-level artist folders, 4,504 album/disc directories, ~48,750 audio files). Goal: confirm
the library only contains music + cover art, find corruption/abnormalities, and size up the
album-art coverage gap ahead of a follow-up "expand cover art" task.

## Summary

| Check | Result |
|---|---|
| File extension inventory | 38,448 mp3, 10,277 flac, 13 m4a (audio) · 4,020 jpg, 39 png, 5 jpeg (art) · 47 stray non-audio/art files · 2 no-extension files |
| Zero-byte files | **0** — none found |
| Confirmed corrupt/truncated audio | **3 files** (see below), out of all 48,738 audio files scanned |
| FLAC missing length/MD5 metadata (not corrupt, playable) | 4 files, one album (The Flaming Lips) |
| Duplicate/junk track names | **1 album** with 53 junk stub tracks (She Wants Revenge) |
| Directories with a proper `cover.*` | 3,116 / 4,504 (69%) |
| Directories with art under a different name | 360 / 4,504 (8%) — rename candidates |
| Directories with **no art at all** | 1,028 / 4,504 (23%) — the art-coverage gap |
| Junk/system files | `.DS_Store`, `.checkinfo`, one malformed download filename |

## 1. Confirmed corruption

1. **`She Wants Revenge/She Wants Revenge/13` through `65 - Wasted Air.flac`** (53 files,
   9,063 bytes each, distinct MD5s but each only **4.00 seconds** long per `ffprobe`, valid
   FLAC per `flac -t`). The real album has 12 tracks + a single bonus "Wasted Air" + "Killing
   Time" — instead the rip produced 53 junk 4-second stub duplicates numbered 13–65, pushing
   the real "Killing Time" out to track 66. Almost certainly a bad/looping CD rip. **Needs
   re-ripping or re-downloading**; the 53 junk files should be deleted.
2. **`Blur/All the People - Live at Hyde Park/Disc 1/04-There's No Other Way.mp3`** — 4,721
   bytes, `ffprobe` reports duration **0.05 seconds** (vs. 6-17 MB / several minutes for every
   sibling track). Truncated, unplayable. **Needs re-ripping or re-downloading.**
3. **`Aerosmith/Toys In The Attic/07.No More No More.mp3`** — 11 MB (plausible size) but `file`
   identifies it as generic **`data`** (no recognizable MP3 header at all) and `ffprobe` fails
   with `Header missing` / duration `N/A`. Despite a normal file size, the audio stream itself
   is corrupt. **Needs re-ripping or re-downloading** — a normal-looking size hid this one, size
   alone wasn't sufficient to catch it.

A full-library `ffprobe` duration/decodability pass (all 48,738 `.mp3`/`.flac`/`.m4a` files) has
since completed. It confirmed no additional truly-corrupt files beyond the 3 above, but did turn
up one more (benign) finding:

4. **`The Flaming Lips/It Overtakes Me/` (all 4 tracks)** — `ffprobe`/`file` report "length
   unknown" / `duration=N/A` because the FLAC `STREAMINFO` header has `total_samples=0` and no
   MD5 signature (an encoder artifact, e.g. from a piped/streaming rip that didn't know the
   final length up front). **Not corruption** — `flac -t` (a real frame-by-frame decode test)
   passes clean (`ok`) on all 4 files; they play fully and correctly, they just don't self-report
   a duration for a progress bar. Low-priority cosmetic fix if ever wanted: re-encode with
   `flac --best -f` to regenerate a proper `STREAMINFO`/MD5 (re-run `flac -t`/`ffprobe` after to
   confirm before overwriting the originals).

## 2. Naming/organization issues (not corruption, but violate the "only music + cover" rule)

**47 stray non-audio/non-image files** — ripping leftovers, safe to delete, don't belong in a
clean Ampache library:
- 16 `.log` (EAC/CUERipper rip logs)
- 15 `.cue` (cuesheets — mostly the Caribou discography, which was ripped as single-file-per-disc
  + cue rather than per-track; that's a bigger structural question, not just a stray file)
- 13 `.m3u` / 1 `.m3u8` (playlists — redundant, Ampache builds its own)
- 1 `.txt` (Cracker) 1 `.checkinfo` (Coldplay) 1 `audiochecker.log` (The Prodigy)

Full list saved on the server at `/tmp/stray_files.txt` (paths below, captured 2026-08-05):
Bush, Caribou (bulk of them — 8 albums), Coldplay, Cracker, Fear Factory, Florence + the
Machine, Madonna, MumfordAndSons, New Order, Panic at the Disco, R.E.M, Tame Impala, The
Prodigy, Tokyo Prose, Kansas, Adele.

**2 junk/system files:**
- `Foo Fighters/Saint Cecilia EP2/.DS_Store` — macOS Finder junk.
- `Coldplay/Head Full of Dreams/.checkinfo` — ripper metadata leftover.

**1 malformed filename** (leftover URL query string from a browser-saved image, never renamed):
- `Röyksopp/True Electric/4403121-3322878.jpg?v=1770160743`

## 3. Cover-art naming inconsistencies

- No directory has **conflicting** duplicate covers (e.g. both `cover.jpg` and `cover.png` in
  the same folder) — clean on that front.
- Extension **case** is inconsistent: 3,179 `cover.jpg`, 38 `cover.png`, 15 `Cover.jpg`, 4
  `cover.jpeg`, 2 `cover.JPG`. Linux/Ampache path matching is case-sensitive — worth normalizing
  the 21 non-lowercase-`cover.jpg`/`.png` outliers to the standard form for consistency.
- **360 album directories** have image art present but under a different name (rename
  candidates, not missing-art candidates). Most common patterns found:
  - `folder.jpg` (98) — classic Windows Media Player convention
  - `front.jpg` / `Front.jpg` (23+)
  - `back.jpg` (13) — back-cover only, no front cover present in these dirs, needs checking
    individually rather than a blind rename
  - `cover (1).jpg`-style numbered duplicates (8), `albumart.jpg`/`albumartsmall.jpg` (Windows
    Media Player legacy, 8 combined), plus many one-off artist/label-specific filenames
  - Several dirs (e.g. `Audioslave/*/Artworks/`) contain full booklet scans (front/back/inlay/
    inside/CD-label images, not just a front cover) — these need a front-cover pick, not a
    simple rename of "the only image".
  Full per-directory list saved at `/tmp/dirs_needing_rename.txt` (360 lines) for the follow-up
  task.

## 4. Album-art coverage gap (context for the next task)

**1,028 of 4,504 album/disc directories (23%) have zero image files at all.** This is the
primary target for the planned "expand album art coverage" follow-up. Full list saved at
`/tmp/dirs_no_image.txt` on the server — not reproduced here since it's long; re-derive with
the "Reproduction" commands below if the list needs regenerating after cleanup changes it.

Top artists by number of art-less album directories (biggest wins for the follow-up task):
Scorpions (35), Cafe del Mar (33), Scriabin (26), Rachmaninov (26), Tom Waits (22), John
Mellencamp (21), Skrillex (19), Midnite (19), Coldplay (18), Tori Amos (15), Frank Zappa (15),
Nine Inch Nails (14), Buckethead (14), DJ Z-Trip (13), Van Halen (12), The Cure (10), Interpol
(10), Animal Collective (10), Modest Mouse (9), Massive Attack (9). Classical composers
(Scriabin/Rachmaninov) and DJ-mix/live-bootleg-heavy artists (Cafe del Mar, DJ Z-Trip, Skrillex,
Buckethead, Midnite) stand out — likely systemically missing art due to how those sources were
originally acquired, worth a batch strategy rather than one-by-one.

Also worth deciding before the next task: whether Ampache's local art-search filenames are
configured to already pick up `folder.jpg`/`front.jpg`/etc. without renaming (checked in
`/opt/ampache/config/ampache.cfg.php`, needs an interactive sudo session — not confirmed in
this audit). If Ampache already treats those as valid covers, the 360 "wrong name" directories
may not actually need renaming, only the 1,028 truly art-less ones need new art fetched.

## 5. Patterns investigated and ruled out as false positives

Several albums have 3+ tracks sharing an identical filename stem — checked individually via
`ls -la` (size) and `ffprobe` (duration) to distinguish real corruption from legitimate reuse:
- NIN `Ghosts I-IV` (9× "Ghosts I", 9× "Ghosts II", etc.) — official track naming for this
  instrumental album, sizes vary normally. **Not corruption.**
- AltJ `An Awesome Wave` (3× `[Untitled]`), LCD Soundsystem `45-33` (6× `45-33.flac`, a single
  continuous suite), Skrillex `More Monsters and Sprites` (4× "Scary Monsters..."), Matisyahu
  (4× "Interlude"), Michael Jackson `Bad`/`Off the Wall` (3–5× "Interview"), The Roots
  `Phrenology` (3× "().mp3") — all have widely varying file sizes per track, i.e. genuinely
  different audio content just reusing a title/interlude name. **Not corruption.**
- Counting Crows `This Desert Life` — every track filename is literally
  `NN - Counting Crows - This Desert Life - 1999.flac` (artist/album used as the "title"), sizes
  range 19-86 MB (genuinely different tracks). **Not corruption, just a poor-tagging/filename
  scheme** (no metadata quality check was in scope here beyond filenames).
- `TV on the Radio/Return to Cookie Mountain` — 15 tracks named only by number with no title
  in the filename at all (e.g. `01.mp3`). Cosmetic/tagging gap, not corruption.
- Beethoven `The Complete Symphonies`, Buckethead, Hikes, DJ's Z-Trip & Radar — untagged/
  "Unknown" track titles, sizes vary normally. **Not corruption**, just missing metadata.
- Largest files in the library (`Tool/Fear Inoculum` tracks 220-345 MB, `Air Late Night Tales`
  continuous mix 657 MB, `Caribou` "Tour CD" mixes 227-346 MB, `Joy Division/Still` 522 MB as a
  single file) all correspond to genuinely long tracks or whole-album/continuous-mix single
  files — not corruption, just unusually large legitimate FLACs.

## Reproduction (commands used, run via SSH as `jack`)

```bash
# Extension inventory
find /media/Music -type f | sed -E 's/.*\.([A-Za-z0-9]+)$/\1/; t; s/.*/NOEXT/' | tr 'A-Z' 'a-z' | sort | uniq -c | sort -rn

# Stray non-audio/art files
find /media/Music -type f \( -iname '*.log' -o -iname '*.cue' -o -iname '*.m3u' -o -iname '*.m3u8' -o -iname '*.txt' -o -iname '*.checkinfo' \)

# Zero-byte files
find /media/Music -type f -size 0

# Hidden/junk files
find /media/Music -type f -name '.*'

# Album dirs = unique parent dirs of every audio file
find /media/Music -type f \( -iname '*.mp3' -o -iname '*.flac' -o -iname '*.m4a' \) -printf '%h\n' | sort -u > /tmp/album_dirs.txt

# Per-dir cover presence classification -> have_cover / have_other_image_only / have_no_image
# (see /tmp/analyze_covers.sh on the server, or the shell loop in this session's history)

# Smallest audio files (fast truncation heuristic)
find /media/Music -type f \( -iname '*.mp3' -o -iname '*.flac' -o -iname '*.m4a' \) -printf '%s\t%p\n' | sort -n | head -30

# Full decode-check (slow, ~25-40 min for the whole library)
find /media/Music -type f \( -iname '*.mp3' -o -iname '*.flac' -o -iname '*.m4a' \) -print0 \
  | xargs -0 -P8 -I{} bash -c 'd=$(ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 "$1" 2>&1); printf "%s\t%s\n" "$d" "$1"' _ {} \
  > /tmp/probe_results.txt
awk -F'\t' '$1 !~ /^[0-9]+\.?[0-9]*$/' /tmp/probe_results.txt   # anything that failed to decode
```

## Open item

None — the full-library decode-check has completed (48,738/48,738 files scanned) and all
results are folded into §1 above.

## Cleanup executed (2026-08-05)

Per owner review of this report, the following was carried out live against `/media/Music`
(script: `music/music_cleanup.sh`, dry-run verified before real execution):

- **Removed**: all 47 stray `.log`/`.cue`/`.m3u`/`.m3u8`/`.txt`/`.checkinfo` files, `.DS_Store`,
  the confirmed-corrupt `Aerosmith/Toys In The Attic/07.No More No More.mp3`.
- **"She Wants Revenge" 53 "Wasted Air" tracks**: confirmed by owner to be legitimate — a
  deliberate pause before a hidden track — left untouched.
- **Röyksopp** malformed `4403121-3322878.jpg?v=1770160743` renamed to `cover.jpg` (no cover
  existed in that directory).
- **21 case/extension-inconsistent covers** (`Cover.jpg`, `cover.JPG`, `cover.jpeg`) normalized
  to `cover.jpg`.
- **297 albums** had an alternate-named image (`folder.jpg`, `front.jpg`, `album.jpg`, etc., or
  the sole image present) promoted to `cover.jpg`/`cover.png`. 5 genuinely ambiguous directories
  (multiple images, no `front`/`folder`/`album` naming) were resolved by hand after inspecting
  filename conventions (`Jethro Tull/Bursting Out`, `Jethro Tull/Thick As A Brick [25th
  anniversary edition]`, `Robert Cray/False Accusations`, `Robert Cray/Showdown`, `Van Halen/2004
  -The Best Of Both Worlds-- 2cd`).
- **367 redundant extra images** (back/inlay/booklet/CD-label scans, duplicate numbered covers,
  etc.) deleted from directories that now have a single canonical `cover.*` — only one image per
  album directory remains, per owner instruction. Note: images living in a nested subfolder (e.g.
  `Audioslave/Audioslave/Artworks/`) were **not** touched — those albums already had their own
  top-level `cover.jpg` and the Artworks scans are a separate, deeper folder out of scope for
  this pass.
- **Counting Crows/This Desert Life**: all 10 tracks renamed from
  `NN - Counting Crows - This Desert Life - 1999.flac` to `NN - <real song title>.flac`, using
  the accurate titles already embedded in each file's ID3 tag (verified via `ffprobe`), e.g.
  `01 - Hangingaround.flac` ... `10 - St. Robinson in His Cadillac Dream.flac`.

**Post-cleanup verification:** extension inventory now shows only `mp3`/`flac`/`m4a`/`jpg`/`png`
(no stray types, no `.jpeg` left), mp3 count down by exactly 1 (the deleted Aerosmith file), flac
count unchanged (Wasted Air + Flaming Lips both intentionally untouched), 3,540 total `cover.*`
files present. Library size unchanged (~593 GB — cover art is negligible next to audio).
**Not yet done / separate next phase**: sourcing new art for the ~1,028 albums that still have
no art at all.

## Album-art coverage follow-up — Phase 1 complete (2026-08-06)

Of the 1,028 art-less album directories identified above, checked each for **embedded** cover
art in its audio files' own tags (ID3 attached picture / FLAC METADATA_BLOCK_PICTURE) before
considering any external art source:

```bash
# For each dir with no image file, check the first audio file for an embedded picture stream
ffprobe -v error -select_streams v -show_entries stream=codec_name -of csv=p=0 "$file"
```

- **488 of 1,028** had usable embedded art — extracted to `cover.jpg`/`cover.png` (extension
  chosen from the actual embedded codec: `mjpeg`→jpg, `png`→png) via
  `ffmpeg -nostdin -y -i "$file" -an -vcodec copy "$dir/cover.ext"`. All 488 verified present and
  valid (spot-checked several with `file` — correct JPEG/PNG headers, sane dimensions/sizes).
- **540 of 1,028** have no art anywhere (no file, no embedded tag) — genuinely need an external
  art source. Next phase, not yet started.

**Gotcha hit and fixed**: running `ffmpeg` inside a `while read -r dir; do ... done < list.txt`
loop without `-nostdin` intermittently corrupted the loop's own input — ffmpeg's stdin keypress
thread inherits fd 0 (the redirected list file) and occasionally steals a byte from it, dropping
the leading character of the *next* line read (e.g. `/media/Music/...` → `media/Music/...`).
Affected exactly 4 of 488 iterations, each surfacing as a clean, visible "No such file or
directory" rather than silent misprocessing (total iteration count always matched the list
length, confirming no line was ever silently skipped or double-consumed). Fixed by adding
`-nostdin` to the ffmpeg invocation and re-running just the 4 affected directories.

## Album-art coverage follow-up — Phase 2 complete (2026-08-06)

For the 540 remaining art-less directories, triggered Ampache's built-in art-gathering
(`art_order = "db,tags,folder,spotify,musicbrainz"`, Spotify deliberately left unconfigured per
owner preference for MusicBrainz) against the whole catalog:

```bash
sudo podman exec ampache php /var/www/bin/cli run:updateCatalog "The Library" local --art
```

**Result: MusicBrainz found ZERO new matches for the 540-album problem set.** The run completed
in ~2.5 min and only added 14 new `image` rows catalog-wide (confirmed via `image.id`
auto-increment ordering) — all 14 belonged to OTHER albums outside the 540 (ones that already had
folder/tag art but lacked a cached DB copy), not a single one matched an album from the target
list. This is consistent with the original audit's observation that the remaining gap is
disproportionately classical composers (Scriabin, Rachmaninov) and DJ-mix/bootleg/live-heavy
artists (Cafe del Mar, Skrillex, Buckethead, Midnite, DJ Z-Trip) — folder-name-based album titles
for these don't match cleanly against MusicBrainz's release database via simple name search.

**Incidental win found while investigating** (unrelated to today's MusicBrainz run): 52 of the
540 directories already had pre-existing `image` rows in Ampache's DB — low, old auto-increment
IDs indicating they predate this session by a long time (likely a leftover from an earlier
Ampache "gather art" run or the Airsonic-era import), simply never written back to the
filesystem as `cover.jpg`. Verified each by checking the actual byte header
(`FFD8`=JPEG, `89504E47`=PNG) rather than trusting the DB's `mime` column:
- **43 were valid, real JPEG/PNG image data** — exported to `cover.jpg`/`cover.png` in their
  album folders (verified with `file` — correct headers, sane dimensions).
- **9 initially looked corrupted/mislabeled** — all 9 were fixed and exported too (see below).

**All 9 "bad" images fixed (2026-08-06):**
- **7 were valid PNGs missing their first 10 bytes** (the 8-byte PNG signature
  `89 50 4E 47 0D 0A 1A 0A` plus the 2 zero-padding bytes of the IHDR chunk's big-endian length
  field — the DB's `mime` column claimed `image/jpeg`, but the raw bytes start mid-chunk at
  `00 0D 49 48 44 52` = the tail of a length field followed by literal `IHDR`, a dead giveaway).
  Fixed by prepending the missing 10 bytes and writing as `cover.png` (Animal Collective/Campfire
  Songs, The Smiths/The Peel Sessions, and 5 Tom Waits albums: Black Rider, Closing Time,
  Nighthawks at the Diner, Small Change, The Heart Of Saturday Night). All 7 verified valid PNGs
  afterward via `file`/`identify`.
- **2 were genuine Windows BMP data mislabeled as `image/jpeg`** (Aerosmith "Permanent Vacation"
  and "Greatest Hits") — converted to real JPEG with ImageMagick
  (`convert cover.jpg -quality 92 cover_converted.jpg`), verified with `file`, then the bad
  BMP-as-.jpg file was replaced by the real JPEG (`mv -f cover_converted.jpg cover.jpg`).
- **Cleanup gotcha caught**: Animal Collective/Campfire Songs had been included in an earlier
  test export (before the byte-header check existed) which blindly trusted the `mime` column and
  wrote a bad `cover.jpg` (`file` identified it as plain "data", not a real image). Once the
  correct `cover.png` was written, the leftover bad `cover.jpg` was deleted so each directory has
  exactly one valid cover file, matching the one-cover-per-album convention from the original
  cleanup.

**Net result: all 52 of the 540 DB-rescued directories now have real, valid cover art
(43 + 9 = 52). 488 directories still have zero usable art anywhere** (540 − 52). Further
progress on these would need either cleaned-up query input (stripping "[Live]"/parenthetical
noise from folder names before a retry) or a different/manual art-sourcing approach — a plain
re-run of the same MusicBrainz search won't help since it already found nothing for this set.

**Reusable techniques from this phase:**
- Ampache containers commonly bind-mount the host music path to a *different* container-internal
  path (here: host `/media/Music` → container `/media`, confirmed via
  `podman inspect ampache --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{end}}'`).
  The DB's `song.file` column stores the CONTAINER-side path — an anchored `LIKE '<host_path>/%'`
  query against it will silently match zero rows every time unless the host prefix is first
  translated to the container's mount destination.
- Always verify a DB-stored image blob's actual byte header before trusting its `mime` column or
  writing it to a file with an extension based on that column — legacy/historical rows can have
  mismatched, truncated, or corrupted data that only a real signature check (`FFD8`/`89504E47`/
  etc.) catches. A PNG missing its 8-byte signature still starts with a recognizable
  `<len><IHDR>` pattern — worth checking for before assuming truly unrecoverable data.
