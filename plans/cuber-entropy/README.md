# Cube Interaction Entropy Study

> **Status: COMPLETE AND DEPLOYED (2026-08-15).**
>
> Last checkpoint: 2026-08-15. This file is the durable project record and must be updated after each research, decision, implementation, validation, and deployment milestone.

## Objective

Extend the existing cube feature with an academic, on-device visualization of how a model-based estimate of user-interaction unpredictability changes during a solve attempt.

The project must distinguish behavioral unpredictability from cryptographically secure randomness. It must not claim that cube gestures produce validated entropy, key strength, or security. Any salt, nonce, or key used for an actual cryptographic operation must be generated independently by Web Crypto.

## Current conclusions

1. A cube session can provide educational observations about move choice, coarse timing, and coarse gesture variation.
2. A short solve cannot establish a NIST SP 800-90B entropy-source claim. User behavior is correlated, strategy-driven, device-dependent, observable, and potentially reproducible.
3. Min-entropy is the appropriate conceptual scale for attacker-focused unpredictability:

   $$H_\infty(X)=-\log_2\left(\max_x P[X=x]\right)$$

4. The naive ceiling $\log_2(k)$ for an alphabet of $k$ choices is valid only for independent, uniformly distributed choices. Cube moves do not satisfy those assumptions.
5. A cryptographic salt is a non-secret separation value, not accumulated entropy. The demo should generate a fresh 128-bit salt with `crypto.getRandomValues()` and display it separately from the interaction estimate.
6. Hashing or conditioning can concentrate existing entropy but cannot create additional entropy.
7. The solve path must not receive a separate additive entropy credit because it is already represented by the move sequence. A separate descriptive “path novelty” metric is possible.

## Approved product direction

- The primary presentation is a clearly labeled heuristic, not a validated entropy claim.
- The heuristic uses committed moves, immediately quantized coarse timing, and one coarse gesture token per committed move.
- Development is staged: transparent heuristic first, with optional population calibration as a later research phase.
- Measurement begins through an explicit start/reset control.
- The visible study includes the heuristic meter, independent salt status, and conditioned interaction digest.
- The study is opt-in on the public About page rather than visible by default.
- Version 1 is volatile and local to the active page. It has no persistence, transcript export/import, or automatic upload.
- Established PHP cryptography available in the WordPress container must be researched before architecture is finalized. Any server role must be justified against the local-only privacy model.
- Whole-cube `X/Y/Z` rotations are included in the digest and estimator context but receive zero demo-bit credit.
- The conditioned interaction digest visibly updates after every committed user move.
- Version 1 keyboard scope covers the study’s opt-in, start, reset, and details controls. Keyboard cube manipulation is a separate future accessibility project.
- Version 1 cryptography runs entirely in browser Web Crypto. PHP serves the page and assets only.

## Terminology contract

| Term | Meaning in this project |
| --- | --- |
| Estimated interaction unpredictability | Model-dependent estimate derived from accepted user input. Never described as validated or cryptographically secure entropy. |
| Demo bit | One unit on the project’s conservative educational scale. It is not a claim of key strength. |
| Salt | Fresh, public, non-secret 128-bit Web Crypto value used to separate session digests. |
| Conditioned interaction digest | SHA-256 digest of versioned session context and normalized records. Not a key, signature, proof of authorship, or entropy estimate. |
| Nonce | A value that must not repeat in a specified cryptographic context. Interaction data is not called a nonce. |
| Key material | Generated independently through Web Crypto. Cube input is never its sole security dependency. |

## Version 1 heuristic

Version 1 uses a transparent novelty heuristic, not an empirical probability estimate. The UI calls its output `demo bits`, never entropy bits or key strength.

For committed record $i$, define indicator function $\mathbf{1}[A]$ as 1 when condition $A$ is true and 0 otherwise.

For a layer turn:

$$m_i=0.5\mathbf{1}[\text{directed move token is new}]+0.5\mathbf{1}[\text{directed layer transition is new}]$$

For a whole-cube rotation, $m_i=0$. It updates orientation context and the digest but not the score.

Timing and gesture contributions are:

$$t_i=0.25\mathbf{1}[\text{coarse timing bin is new}]$$

$$g_i=0.25\mathbf{1}[\text{coarse gesture token is new}]$$

The first committed record has no inter-commit timing contribution. Whole-cube rotations receive no timing or gesture score because all of their demo-bit channels are context-only.

The displayed score is:

$$E_n=\min\left(32,\;0.5\sum_{i=1}^{n}(m_i+t_i+g_i)\right)$$

Properties of this policy:

- Monotonic: accepted records never reduce the displayed value.
- Bounded: at most 0.75 demo bits per scored layer move and 32 total.
- Repetition-sensitive: repeated move tokens, transitions, timing bins, and gesture tokens stop adding credit.
- Conservative by declaration: the 50% discount and 32-bit cap are project policy, not a measured confidence bound or NIST requirement.
- Transparent: the same transcript always produces the same value.
- Non-cryptographic: it is unsuitable for calculating key size, guessing resistance, or validated entropy.

### Normalization and bins

Directed move token:

- Layer base: `L M R D E U B S F`.
- Direction is encoded canonically as signed quarter turns modulo four.
- Two quarter turns have one canonical representation; zero/full rotations are not records.
- The transition token is `(previous scored layer token, current scored layer token)`. Whole-cube records do not replace the previous scored layer token.

Timing bin, based on elapsed time since the previous committed user record:

| Bin | Elapsed time |
| ---: | --- |
| 0 | under 250 ms |
| 1 | 250–499 ms |
| 2 | 500–999 ms |
| 3 | 1000–1999 ms |
| 4 | 2000–3999 ms |
| 5 | 4000 ms or more |

Gesture token:

- Direction sector: one of eight 45-degree sectors derived from the committed drag vector.
- Speed class: slow, medium, or fast based on fixed documented thresholds shared by mouse, touch, and pen.
- Pointer family is retained as estimator context but does not itself earn credit.
- Entry coordinates, path points, pressure, contact geometry, and pointer identifiers are discarded.

### Future calibrated model

A later research phase may replace novelty indicators with conservative population estimates:

$$p^U_{c,i}=\operatorname{UCB}_{99\%}\left(\max_x P_c(X_{c,i}=x\mid H_i,Z_i)\right)$$

and $h_{c,i}=-\log_2 p^U_{c,i}$, using the most pessimistic result across multiple predictors. That phase requires a separately approved, voluntary data protocol and representative calibration corpus. It must not silently reinterpret version 1 demo bits as calibrated min-entropy.

## Proposed indicator

The primary indicator is an unframed status band below the cube on phones and beside or below the cube on desktop, without overlapping the interaction surface.

Candidate content:

- `Estimated interaction unpredictability`
- Numeric heuristic formatted to one decimal place and suffixed `demo bits`
- Stable horizontal meter from 0 to 32, with labeled ticks at 0, 8, 16, 24, and 32
- Committed move count
- Full 128-bit session salt displayed as 32 lowercase hexadecimal characters in a wrapping monospace value
- Full conditioned digest displayed as 64 lowercase hexadecimal characters and updated after every committed move
- Persistent qualification: `Model-based educational estimate. Not NIST-validated entropy and not used as a key.`

The meter is not a security progress bar. It must not use lock/shield icons, “secure” states, green success thresholds, or comparisons such as “AES-ready.” Reaching 32 means only that the display cap was reached.

Interaction states:

1. Collapsed: an `Explore entropy` button is visible near, but not over, the cube.
2. Ready: the panel explains local processing concisely and offers `Start`.
3. Measuring: the explicit session is active; `Reset` is available; meter, salt, digest, and counts update.
4. Solved: values freeze with a solved status until reset.
5. Capped: the meter remains at 32 while move count and digest continue updating.

No feature explanation is placed over the cube. Desktop uses an unframed aligned region beside or below the mount according to available width. Phone uses a full-width band below the cube, preserving all current drag gutters and sticker hit areas.

Accessibility requirements:

- Text equivalent for every visual value.
- Stable dimensions so updates do not shift the cube.
- Color is not the sole encoding.
- Throttled `aria-live="polite"` announcements at meaningful milestones, not every move.
- Existing `prefers-reduced-motion` behavior remains authoritative.

## Privacy model

Default behavior is local, volatile, and session-scoped:

- No raw coordinates, pressure, contact size, pointer identifiers, device identifiers, predicted events, or coalesced events are retained.
- Startup shuffle, automated actions, canceled gestures, weak gestures, and settle previews are excluded.
- One exact previous-commit timestamp is held transiently in memory only until the next committed record can be quantized. Exact timestamps are never added to the transcript, estimator, digest, storage, or network payload; reset clears the transient reference.
- No cookies, `localStorage`, IndexedDB, service workers, analytics events, error-reporting payloads, WebSockets, `sendBeacon`, or unload submission.
- Reset clears salt, transcript, aggregates, hash, and indicator state.
- Export is not included unless explicitly approved.

Threat-model limits: same-page scripts, XSS, compromised first-party assets, browser extensions, developer tools, malware, and a hostile operating system can observe in-memory data. An unkeyed digest proves internal consistency only; it does not prove authorship, identity, time, or authenticity.

## PHP cryptography capability review

The live WordPress container was inspected read-only on 2026-08-15. It currently provides:

- PHP 8.4.21
- ext-random, including `random_bytes()` and `random_int()`
- ext-hash, including SHA-256, HMAC, incremental hashing, and `hash_hkdf()`
- ext-sodium backed by libsodium 1.0.18
- ext-openssl backed by OpenSSL 3.5.6

These are established cryptographic implementations and are sufficient for real server-side salts, challenges, hashes, MACs, key derivation, signatures, and authenticated encryption. No custom cryptographic primitive is needed or acceptable.

Appropriate PHP assignments, if a future server protocol is approved:

| Need | Established PHP primitive | Notes |
| --- | --- | --- |
| Salt, nonce, challenge, or secret bytes | `random_bytes()` | Returns raw binary. Encode explicitly for display or transport. |
| Transcript digest or hash chain | `hash('sha256', $bytes, true)` | Raw 32-byte output with `true`; default output is 64 lowercase hexadecimal characters. |
| Server-authenticated transcript | `hash_hmac('sha256', $bytes, $serverKey, true)` | Compare submitted tags with `hash_equals()`. Requires transcript submission and a dedicated server secret. |
| Domain-separated keys from real secret input | `hash_hkdf('sha256', ...)` | HKDF cannot create entropy. Input keying material must already be secret and non-empty. |
| Modern keyed hash, signature, or authenticated encryption | ext-sodium | Strong library, but BLAKE2b/Sodium formats complicate direct Web Crypto interoperability without adding value to v1. |
| X.509 or required OpenSSL interoperability | ext-openssl | Excessive for this project’s salt and digest needs. |

WordPress nonces and `wp_salt()` are explicitly unsuitable. WordPress nonces are time-window tokens that can repeat and do not independently prevent replay. `wp_salt()` exposes site authentication secrets and must never be sent to the browser. A future protocol would use a dedicated secret and direct standard PHP primitives.

### Recommended version 1 boundary

Keep all interaction-derived calculations in browser Web Crypto:

- `crypto.getRandomValues(new Uint8Array(16))` creates the independent public session salt.
- `crypto.subtle.digest('SHA-256', canonicalBytes)` creates the conditioned interaction digest and hash-chain values.
- Raw salt and digest bytes remain binary internally; hexadecimal is display-only.
- No interaction record, estimate, digest, or timing data is sent to PHP.

PHP should serve only the WordPress page and static bundle in version 1. A PHP challenge endpoint, MAC, signature, or verification step has no meaningful security property when the approved experience is volatile, local-only, and has no submitted result. Sending the transcript merely to use server cryptography would add network metadata, retention/logging risk, and protocol complexity while producing the same SHA-256 result already available through Web Crypto.

Server-side PHP becomes justified only if a later phase adds a real verifier requirement, such as server-attested completion, replay-resistant submissions, shared research collection, or signed result receipts. That would be a new privacy and protocol project, not an implementation detail of the local demo.

## Architecture fit

The cube engine already has suitable ownership boundaries:

- `JS/cuber/src/interaction.js` owns accepted user gesture resolution and commit.
- `JS/cuber/src/cube.js` owns state transitions and must remain free of telemetry concerns.
- `JS/cuber/src/main.js` owns lifecycle, startup shuffle, rendering refresh, and solved events.
- `JS/cuber/src/render.js` owns cube DOM and must not own estimator state.

Implemented modules:

- `src/interaction-record.js`: normalized committed-user records and immediate quantization.
- `src/entropy-estimator.js`: pure estimator state and formulas.
- `src/transcript-digest.js`: versioned encoding, session salt, and SHA-256 hash chain.
- `src/entropy-indicator.js`: behavior and state rendering bound to the theme-owned template.

Recommended contract: change the interaction commit callback to pass a semantic record such as `{ command, quarterTurns, pointerType, coarseTimingBin, coarseGestureToken }`. Do not expose raw pointer streams. `main.js` creates and connects the independent observer/estimator/indicator modules. Automated shuffle events never cross this contract.

### Canonical record schema

The in-memory semantic record is:

```text
{
  version: 1,
  sequence: uint32,
  kind: "layer" | "orbit",
  command: canonical command enum,
  signedQuarterTurns: -1 | 1 | 2,
  pointerType: "mouse" | "touch" | "pen" | "other",
  timingBin: 0..5 | null,
  directionSector: 0..7,
  speedClass: 0..2
}
```

Records are created only after the logical twist commits. A canceled drag, settle-back gesture, startup shuffle, preview frame, or failed interaction produces no record.

### Digest encoding

Use a fixed binary encoding rather than ordinary `JSON.stringify()`:

- Domain label: UTF-8 `jackbrain-cube-interaction/v1` with an explicit length prefix.
- Session salt: 16 raw bytes.
- Initial state: deterministic cubelet-id, address, and face-color enumeration captured when `Start` is pressed.
- Configuration: fixed estimator version and bin definitions.
- Record: fixed-width enum bytes plus a big-endian 32-bit sequence number.

Hash chain:

$$H_0=\operatorname{SHA256}(\text{domain}\parallel\text{salt}\parallel\text{initialState}\parallel\text{configuration})$$

$$H_i=\operatorname{SHA256}(\text{domain}\parallel H_{i-1}\parallel\text{record}_i)$$

Digest calls are serialized through one promise queue so asynchronous Web Crypto results cannot be displayed out of order. Raw bytes feed the next hash; hexadecimal is display-only.

The digest is an integrity fingerprint for the current in-memory sequence. It is not authenticated and does not prove identity, authorship, time, or genuine human input.

## Implementation phases

No phase begins until implementation is explicitly approved.

### Implementation progress

- **Phase 1 event contract: COMPLETE (2026-08-15).** Added a pure semantic-record helper and changed the interaction commit callback to provide canonical command, signed quarter turns, pointer family, quantized direction sector, quantized speed class, and release time. Raw pointer paths are not exposed. Focused test: 8/8 passing. Browser integration remains part of the regression gate after modules are wired together.
- **Phase 2 pure heuristic: COMPLETE (2026-08-15).** Implemented the deterministic novelty policy as a pure module. Focused test: 9/9 passing across first observation, repetition, orbit context, transition continuity, monotonicity, exact cap saturation, post-cap behavior, and reset.
- **Phase 3 salt and digest: COMPLETE (2026-08-15).** Added deterministic initial-state and record encodings, 128-bit Web Crypto salt support, and a serialized SHA-256 hash chain. Focused test: 12/12 passing, including hard-coded initial and first-record vectors, concurrent invocation ordering, tamper sensitivity, strict field validation, order sensitivity, and salt validation.
- **Phase 4 indicator and session lifecycle: COMPLETE (2026-08-15).** Added the opt-in panel, explicit start/reset lifecycle, stable 0–32 meter, move/context counters, full salt/digest display, qualification text, throttled live-region milestones, and a native accessible Methodology dialog with primary NIST, IETF, and W3C references. Desktop workflow verified end-to-end. Fresh Android emulation verified a 370px panel entirely below the cube with no overlap; existing gutter orbit and 9-cubelet face-preview controls remain intact.
- **Phase 5 integration and privacy verification: COMPLETE (2026-08-15).** Startup shuffle remains excluded by construction; inactive, weak, canceled, and post-solve input are excluded. Runtime interception during a measured interaction observed zero fetch, XHR, beacon, or WebSocket calls and no local/session storage or cookie changes.
- **Phase 6 deployment: COMPLETE (2026-08-15).** Deployed the generated JavaScript and CSS with the tracked `jbAbout.php` template. Backups share timestamp `20260815-141729`; WordPress restarted active. Public JS/CSS match local build hashes byte-for-byte, About returns 200 with the accessible template relationships, and the live opt-in/start/gesture/reset workflow passed.

### Independent review

An independent review identified six issues, all resolved locally on 2026-08-15:

1. The entropy panel is now excluded from document-wide orbit initiation, and non-cube pointer-down defaults remain available until a real orbit drag resolves.
2. Every fixed-width canonical record field now has strict range validation to prevent silent byte truncation aliases.
3. Digest initialization and append failures now publish a recoverable error state; estimator and timing state commit only after a successful digest append.
4. The panel width is responsive on narrow layouts and its long values wrap without internal overflow.
5. Theme-copy commands in `JS/cuber/README.md` now use the correct path from `JS/cuber` to the repository’s top-level WordPress tree.
6. Plan status, approval boundary, and resume instructions now reflect local implementation accurately.

Review-specific regression coverage was added for malformed encoding fields, crypto failure/recovery, concurrent records, solving-move ordering, session-generation isolation, exact cap saturation, post-cap behavior, multi-pointer isolation, panel pointer isolation, page-orbit preservation, and 320/360/390 device layouts. Current automated total is recorded in the latest decision-log entry below.

### Phase 1: Event contract

- Extend the existing commit callback with a normalized semantic gesture summary.
- Quantize timing and gesture values immediately; discard raw measurements.
- Prove automated shuffle, canceled gestures, and preview frames emit nothing.
- Keep `cube.js` unchanged.

### Phase 2: Pure heuristic

- Implement the versioned deterministic scoring policy as a pure module.
- Add fixed vectors for novelty, repetition, inverse moves, transitions, whole-cube context, saturation, and reset.
- Expose score plus contribution breakdown for UI and testing.

### Phase 3: Salt and digest

- Generate a 16-byte Web Crypto salt only after explicit `Start`.
- Canonically encode initial state, configuration, and records.
- Implement the serialized SHA-256 chain and fixed cross-runtime vectors.
- Keep all state in memory and clear it on reset.

### Phase 4: Indicator

- Add the opt-in control and responsive unframed panel.
- Implement stable meter geometry, values, details, status states, and accessible controls.
- Update the full digest after every committed move without shifting layout.
- Preserve current cube sizing and all desktop/Android interaction regions.

### Phase 5: Integration and privacy verification

- Wire modules in `main.js` only after the startup shuffle completes.
- Confirm no interaction-derived network request, storage write, analytics call, or error-report payload occurs.
- Validate reset, solved, cap, page navigation, reduced motion, and long sessions.

### Phase 6: Deployment

- Build and run all existing and new tests.
- Verify harness behavior on desktop, phone, and tablet before touching production.
- Deploy only after explicit approval, with timestamped backups and public byte-for-byte verification.

## Validation gates

No later gate begins until the previous gate passes.

1. **Research gate:** terminology, estimator assumptions, sample threshold, privacy mode, UI mode, and export decision approved.
2. **Contract gate:** normalized record schema and automated-shuffle exclusion tested without UI.
3. **Estimator gate:** fixed vectors, repeated/predictable sequences, adversarial sequences, caps, monotonicity policy, and reset behavior tested.
4. **Digest gate:** deterministic encoding, salt generation, hard-coded hash-chain vectors, and tamper tests pass in Node Web Crypto and Chromium. Firefox/WebKit vector checks are compatibility follow-ups, not release blockers for this standards-based SHA-256 encoding.
5. **UI gate:** responsive desktop/phone layout, semantic template relationships, no cube-control overlap, reduced motion, and stable dimensions verified. A real screen-reader pass remains a post-deployment accessibility follow-up.
6. **Privacy gate:** instrument network and storage APIs to confirm no interaction-derived egress or persistence.
7. **Regression gate:** existing 25 cube tests, all focused new tests, build, mouse, and emulated phone/tablet interaction pass. Owner-approved deployment uses the live release as the real Android/tablet acceptance surface, matching this theme's established iterative device-validation process.
8. **Deployment gate:** explicit approval, timestamped live backup, public hash comparison, and live browser verification.

## Primary references

- NIST SP 800-90B, *Recommendation for the Entropy Sources Used for Random Bit Generation*: Sections 3.1.1, 3.1.4, 3.1.5, 6.1, 6.2, 6.3, and Appendix D. The publication page notes two potential errata as of 2025-05-29.
  - https://doi.org/10.6028/NIST.SP.800-90B
- RFC 4086, *Randomness Requirements for Security*: Sections 2, 3.4, 3.5, 3.6, 5, 5.5, and 6.1.1.
  - https://www.rfc-editor.org/rfc/rfc4086
- NIST SP 800-63B-4: Sections 3.1.1.2, 3.2.12, 5.3, Appendix A, and glossary entries for entropy, nonce, and salt.
  - https://pages.nist.gov/800-63-4/sp800-63b.html
- RFC 5869, *HMAC-based Extract-and-Expand Key Derivation Function*: Sections 2.2, 3.1, 3.4, and 4.
  - https://www.rfc-editor.org/rfc/rfc5869
- W3C Web Cryptography API: security considerations, `getRandomValues`, `digest`, `generateKey`, SHA-256, and HKDF.
  - https://www.w3.org/TR/WebCryptoAPI/

## Approval boundary

Implementation and production deployment were approved and completed. Future production changes
remain separate actions requiring explicit approval after their review and regression gates pass.

## Resume instructions

On session resume:

1. Read this file before searching the repository or changing code.
2. Resume from the implementation progress list; do not restart research or reimplement completed phases.
3. Review the decision log below and ask only genuinely unanswered questions.
4. Update this document after every resolved question or changed assumption.
5. Do not deploy a future change without completing its review/regression gates and receiving explicit deployment approval.

## Decision log

- 2026-08-15: Project requested as academic exploration with accuracy and correctness prioritized.
- 2026-08-15: Owner requested research, questions, and a complete written project before implementation begins.
- 2026-08-15: Owner requested periodic documentation checkpoints to survive session termination or credit exhaustion.
- 2026-08-15: Approved a clearly labeled heuristic using moves, coarse timing, and coarse gesture channels, with later optional calibration.
- 2026-08-15: Approved explicit session start/reset; meter, independent salt, and digest display; opt-in About-page panel; and volatile local-only version 1 with no export.
- 2026-08-15: Requested research into established PHP cryptography in the WordPress container before architecture is finalized.
- 2026-08-15: Live container verified at PHP 8.4.21 with ext-random, ext-hash, ext-sodium/libsodium 1.0.18, and ext-openssl/OpenSSL 3.5.6.
- 2026-08-15: Research recommendation is browser Web Crypto only for volatile local version 1. PHP is capable but adds no useful property without a future server-verification protocol.
- 2026-08-15: Approved whole-cube rotations as digest/estimator context with zero demo-bit credit.
- 2026-08-15: Approved visible digest updates after every committed move.
- 2026-08-15: Approved keyboard-accessible study controls for version 1; keyboard cube manipulation deferred.
- 2026-08-15: Approved browser Web Crypto as the complete version 1 cryptographic boundary; no PHP endpoint or transcript submission.
- 2026-08-15: Research project specification completed. Awaiting explicit implementation approval.
- 2026-08-15: Implementation approved by owner (`proceed`). Phases 1–5 implemented locally and validated; no production deployment performed.
- 2026-08-15: Independent implementation review completed; all six findings repaired and regression-tested. Production deployment still pending explicit approval.
- 2026-08-15: Final lifecycle review caught solving-move omission and cross-session queued-record races. Both were repaired with generation-bound record transactions and queued solve finalization, with deterministic gated regressions.
- 2026-08-15: Final narrowed audit found cube input remained enabled during asynchronous study initialization and canonical turns accepted numeric strings. Start now disables cube interaction through completion/failure, and canonical turns require integers.
- 2026-08-15: Final local validation passed 70/70 automated checks, production build, diagnostics, diff formatting, desktop workflow, fresh Android workflow, panel/page pointer isolation, multi-pointer isolation, privacy interception, and true 320/360/390 viewport overflow checks.
- 2026-08-15: A final smoke attempt in a heavily reused browser tab could not advance its throttled `IntersectionObserver`; this is a tooling-state limitation already reproduced in earlier cube work, not a source error. Fresh-page Android and earlier desktop workflows passed. Residual pre/post-deployment checks remain real Android/tablet feel, Firefox/WebKit digest vectors, and a screen-reader pass.
- 2026-08-15: Owner reduced the panel heading visually. Final theme markup uses a semantic `h2` with a 16px component class, preserving the smaller appearance without skipping heading levels.
- 2026-08-15: Owner approved deployment after documentation and completeness review.
- 2026-08-15: Entropy HTML moved from JavaScript into tracked `jbAbout.php`; JavaScript now binds behavior to the theme-owned accessible template. The standalone harness mirrors the production markup.
- 2026-08-15: Deployed `dist/main.js`, `dist/style.css`, and `jbAbout.php` together. Server hashes: JS `13d28e55fea15874c0ff927e8571eea16b73d29af1e09fcacf73f5f02ea3624c`; CSS `1b71160228f907e8e30f87380704238cab389ffbd1086af4da4399d600f54dd3`; template `17b047806f056d01da71335e5fbb0a01beca68ba9a6a22f2f96c184ef47bb3fb`. Backup timestamp `20260815-141729`.
- 2026-08-15: Public acceptance confirmed asset hash equality, HTTP 200, one theme-owned entropy template, semantic 16px `h2`, correct ARIA relationships, 27 cubelets, a committed layer move (`0.4 demo bits`), 128-bit salt display, SHA-256 digest display, and complete Reset clearing. The browser tab was background-hidden, so the known throttled `IntersectionObserver` was bypassed only for the interactive acceptance step; public markup/assets and first-party behavior were otherwise exercised live.
- 2026-08-15: Added a theme-owned native Methodology dialog beside Start and Reset. It briefly describes the novelty heuristic, conservative discount and cap, SHA-256/Web Crypto processing, local-only privacy boundary, and links to NIST SP 800-90B, RFC 4086, and the W3C Web Cryptography API. Local PHP lint, 70/70 tests, production build, modal semantics, reference URLs, cube-input isolation, Escape dismissal, and focus restoration passed before synchronized redeployment.
- 2026-08-15: Methodology release deployed with synchronized backup timestamp `20260815-142528`. Server and cache-busted public hashes matched local: JS `66903cbdcfb62e09b204c58fb0b53c3f7d4d3173d0f432fc4808d2a08cc08a62`; CSS `c0be4a657911fc7bd25c5d8934b83642387cc23f80d6db3d5e4a9e703d8284e8`; template `95a94aca19e242fcc420aaf2cacc26ba0401933932f2652efc48550e46914a06`. WordPress restarted active; About returned 200 with one dialog, its matching control relationship, and all three references.
- 2026-08-15: Live 360px browser acceptance confirmed native modal semantics, heading association, three references, viewport fit, 27 intact cubelets, Escape and Close-button dismissal, and focus restoration to the Methodology control.
- 2026-08-15: Redeployed all five live theme inputs together under backup timestamp `20260815-150643`: `functions.php`, `js/jbrain.js`, `jbAbout.php`, `js/cuber/main.js`, and `js/cuber/style.css`. WordPress is active. Server hashes match local; cache-busted public JS/CSS hashes match; About returned 200 with the current dialog markup.
- 2026-08-15: Added an opt-in local QR result dialog. It encodes a `jb-cuber-entropy/v1` JSON payload containing score, cap, move/layer/orbit/transition counts, public salt, and conditioned digest. The QR is generated on a canvas with a locally bundled `qrcode` encoder; no network or browser storage path was added. Harness validation confirmed the pre-result button is disabled, the result QR opens, the canvas is nonblank, and the digest is present.
- 2026-08-15: Reviewed and deployed the current template and QR-enabled bundle under backup timestamp `20260815-152338`. All five live inputs were synchronized: `functions.php`, `js/jbrain.js`, `jbAbout.php`, `js/cuber/main.js`, and `js/cuber/style.css`. Verified hashes: functions `33fb24d700d7651b1a614449584a49eeb13bb408778567f1961f7d18b854bc92`; jbrain `2865549cdd070602bd6b3315370a2ac0721a9080b6702b8bcad0d26381898666`; template `b3aa507adc285e75cf5979eea22fe21342c7902f33172f71a29f0a108040b7bd`; JS `ea703710d111793a0ad4f937432dfd76b8b7688399c816dc7f2266490cca3b3d`; CSS `95b671e863d052d5b6ebd6dc8addb2a421e43ab41807b87eb20f5074b77d506a`. WordPress active; public About returned 200 and public JS/CSS hashes matched.
- 2026-08-15: Propagated the reduced Methodology dialog to `jbAbout.php` under backup timestamp `20260815-153057`. The dialog retains the three references and now uses explicit `aria-label="Methodology"` after its explanatory heading was intentionally removed. Public About returned 200 with the reduced dialog, QR dialog, and no stale heading reference; WordPress is active.
