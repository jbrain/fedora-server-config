# Cuber build and theme integration

This folder contains the source logic for the cube and the production bundle used by the jackbrain theme. The source remains in `src/`; the built files are emitted into `dist/` and deployed to the live theme. The About-page markup is tracked separately in `../../wordpress/theme/jackbrain/jbAbout.php`.

## Build the dist bundle

From this folder:

```bash
npm install
npm run build
```

The build command performs the production bundling and minification:

```bash
esbuild src/main.js --bundle --minify --outfile=dist/main.js
esbuild style.css --minify --outfile=dist/style.css
```

This populates:

- `dist/main.js`
- `dist/style.css`

## Theme deployment inputs

After the bundle is generated, stage the built assets and tracked About template:

```bash
scp dist/main.js dist/style.css linus:/tmp/
scp ../../wordpress/theme/jackbrain/jbAbout.php linus:/tmp/
```

The live theme expects:

- `wordpress/theme/jackbrain/js/cuber/main.js`
- `wordpress/theme/jackbrain/js/cuber/style.css`
- `wordpress/theme/jackbrain/jbAbout.php`

The WordPress loader enqueues the bundle on the About page, whose template provides both
`#the-cube` and `#entropy-study`. Follow `../../wordpress/theme/jackbrain/README.md` for backup,
ownership, restart, and verification commands.

## Verification

To confirm the cube logic still matches the expected state behavior after a build, run:

```bash
npm test
```

This runs the original 25 cube-state checks plus the interaction-record, entropy heuristic,
transcript digest, and volatile session-lifecycle suites.

## Entropy study

The optional `Explore entropy` panel is an academic visualization of interaction novelty. It
uses committed layer turns, coarse timing bins, and coarse gesture categories to produce a
transparent score capped at 32 `demo bits`. Whole-cube rotations contribute transcript context
but no score. The value is explicitly not validated entropy or cryptographic key strength.

Starting a study creates an independent 128-bit public salt with Web Crypto and a versioned
SHA-256 digest chain over normalized committed moves. All study state is volatile and remains
inside the active page: there is no upload, browser storage, cookie, or analytics event. Reset or
navigation discards the session.

The adjacent `Methodology` control opens a native keyboard-accessible dialog summarizing the
novelty rules, 50% discount, 32-bit display cap, Web Crypto processing, privacy boundary, and
primary NIST, IETF, and W3C references. Escape and the Close button dismiss it and restore focus
to the control.

After a result exists, `QR result` opens a local canvas-generated QR code containing a versioned
JSON payload with the displayed score, session counts, public salt, and conditioned digest. The
export is opt-in; scanning it shares those values with the scanner and does not send data from
the page itself.

The QR encoder is bundled locally with esbuild from the `qrcode` development dependency. The
standalone harness loads `dist/main.js`, matching the production bundle, because a `file:` URL
cannot resolve the package import in `src/main.js` directly.

The full model, privacy boundary, encoding, test gates, and research sources are documented in
`../../plans/cuber-entropy/README.md`.

Before deployment, always run:

```bash
npm test
npm run build
```

Then deploy both generated files together. JavaScript and CSS versions must not be mixed.

The production About page also depends on the matching tracked
`../../wordpress/theme/jackbrain/jbAbout.php` template. The entropy-enabled release was deployed
and verified on 2026-08-15; see the entropy plan's decision log for exact hashes and rollback
timestamp.
