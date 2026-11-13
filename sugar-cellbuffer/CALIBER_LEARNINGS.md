# sugar-cellbuffer — Caliber Learnings

## Accumulated Learnings

### 2026-11-12 — Buffer* alias spellings are the consumer contract
Pattern: consumers importing this package alongside a colliding class name alias it `BufferStyle`/`BufferRegion`/`BufferPosition` (seven aliased imports, five consumers, zero variance). The names are load-bearing sugar-style-side convention now — new exports that could collide should be documented with the same `Buffer*` alias suggestion in README.
Anti-pattern: Do not add a sugar-cellbuffer → sugarcraft/sugar-style require to "bridge" the two Style classes — sugar-style already requires this package (`src/Layout/RegionBridge.php`), so the reverse require is a package cycle; converters live on the sugar-style side of the seam.
Source: review-pipeline CB-19 (bridge option (b) deferred on this ground; option (a) shipped)

### 2026-07-01 — sugar-core dev-master dependency carries stability risk
Pattern: When a Sugarcraft lib depends on `sugarcraft/sugar-core`, prefer a stable version constraint once available. Using `dev-master` risks pulling breaking changes in CI.
Anti-pattern: Do not ship production code that depends on `@dev` dependencies without a plan to pin.
Source: Phase 6 Item 8.1 (findings/plan_sugar-cellbuffer.md)

### 2026-05-31 — DiffEncoder tracks cursor + SGR state across ops
Pattern: DiffEncoder carries running cursor position and SGR style between ops; transitions are only emitted when state actually changes — skip an unnecessary MoveCursorOp if already there, skip an SGR if style hasn't changed.
Anti-pattern: Don't reset cursor or SGR state between op emits; that discards the context the encoder needs to stay minimal. The optimiser is what makes the byte stream minimal; don't bypass it.
Source: step-26 ai/buffer-diff-impl

### 2026-05-28 — Buffer/Cell intentionally minimal
Pattern: Buffer/Cell are the shared cell-grid model — rich styling logic belongs in sugar-style, not here.
Anti-pattern: ~~Don't add rendering concerns, SGR emission, or terminal-specific behaviour to this package.~~ **SUPERSEDED 2026-11-03:** the package's own identity now includes the emission encoders — `SgrEmitter`, `DiffEncoder`, and `Buffer::toAnsi()` ARE SGR emitters and belong here (relocating them would break overlay/dashboard byte parity). The surviving law: no LAYOUT/PAINT policy (geometry, theming decisions, terminal I/O) in this package; byte-level encoding of already-decided state is in scope.
Source: step-02 ai/sugar-cellbuffer-new (flip: lib-reviews-1102 M16)

### 2026-10-03 — SGR colour operands clamp, never mask
Pattern: `Style::fromSgr()` (the parser `Buffer` delegates to) clamps 38;2/48;2 components and 38;5/48;5 indices into 0-255 (`channel()`), matching sugar-overlay's `penFromSgr()`; terminals saturate, so `300` is 255. A truncated extended colour (`38;5`, `38;2;1`) ends the parse.
Anti-pattern: Don't `& 0xFF` a colour component (300 wraps to 44, an unrelated colour), and don't let an unconsumed `5`/`2` operand fall through to the attribute branches (it set blink/faint).
Source: crush_libs follow-up (sugar-overlay #4 parity)
