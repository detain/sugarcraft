# candy-vt graphics protocols — P3 strategic plan

**Status:** plan-only · post-1.0 candidate · **Owner:** product call → phase-Nn implementer · **Branch:** `ai/plan-candy-vt-graphics`

**Origin:** `plans/leftover_updates_later.md` §6 strategic-decision items ·
`plans/leftover_updates_later.md` §3 candy-vt P3 bullet ·
`docs/research/libraries/candy-vt-research.md` (P3 tier) ·
`plans/leftover/phase-11-strategic-plans/step-03-candy-vt-graphics.md`

> This document does **not** ship code. It is a strategic decision memo
> covering whether to add **receive-side** graphics-protocol support to
> `candy-vt`, what that would look like architecturally, and what
> conditions need to hold before phase-1 step files get drafted. Render
> *emission* already lives in `candy-mosaic`; this plan is about parsing
> graphics escape sequences a TUI emits *back* into a candy-vt-managed
> screen, which is a much harder problem and intentionally deferred.

---

## TL;DR

`candy-vt` is the in-memory VT500 terminal emulator. Today it parses
text, SGR, cursor moves, modes, scrollback, OSC 8 hyperlinks, OSC 52
clipboard, OSC 4 palette, and the Charm-relevant DEC private modes
(DECAWM, DECOM, DECSCUSR, DEC 2026 sync-output, focus events 1004). It
**does not** decode the three image-transmission protocols that
modern terminals understand: **Kitty graphics (APC)**, **iTerm2 / WezTerm
inline images (OSC 1337)**, and **DEC Sixel (DCS)**. The encoders
already live in `candy-mosaic/src/Renderer/{KittyRenderer, Iterm2Renderer,
SixelRenderer}.php` — they emit the bytes; nothing in this monorepo
parses them.

This memo proposes:

1. **Scope** — receive-side decode in `candy-vt` for the same three
   protocols `candy-mosaic` emits, with the decoded pixel buffer either
   stored on the `Screen` (as image-region metadata + raw image bytes)
   or handed off to `candy-mosaic`'s `ImageSource` / `PixelGrid` for
   downstream rendering by a host application.
2. **Architecture** — extend the existing parser dispatch surface (no
   new states needed; OSC / APC / DCS dispatch already lands at
   `Handler::oscDispatch()` / `Handler::sosPmApcDispatch()` /
   `Handler::dcsDispatch()`), add a new `Graphics` subtree under
   `candy-vt/src/Graphics/`, and wire three protocol-specific decoders
   that produce a common `ImagePlacement` value object that
   `ScreenHandler` records against the cell grid.
3. **Phases** — six phases, ~16–24 weeks total. **MVP is Kitty
   transmit-only (a=T) without virtual placement**, the simplest of the
   three. Sixel is the hardest; iTerm2 / WezTerm is the smallest in
   bytes-of-parser but largest in image-data semantics.
4. **Risks** — Sixel grammar is non-trivial (run-length encoding,
   colour introducers, repeat counts, raster attributes); Kitty image-
   data chunks span an unbounded number of escape sequences with
   stateful merge semantics; iTerm2 OSC 1337 carries opaque base64
   payloads with key=value preamble. Memory cost can be unbounded if
   the parser does not enforce limits.
5. **Decision criteria** — **do not start** until at least one of these
   is true: (a) a SugarCraft consumer needs to *record* a TUI session
   that includes inline images and replay it deterministically (the
   candy-vcr / Shirley path); (b) a candy-mosaic test wants to
   round-trip an emitted byte stream through a decoder; (c) a port of a
   Charm tool that ships images downstream of `candy-vt` (e.g. a `glow`
   integration that pipes through a virtual terminal) lands. **None of
   these conditions hold today.**

**Recommendation:** **defer past 1.0.** Keep the existing emit-side
support in `candy-mosaic`; do not start this work until at least one
concrete consumer needs it. Pre-allocate the namespace
(`SugarCraft\Vt\Graphics\`) and the handler-dispatch entry points so
nothing further-down in candy-vt has to be refactored to accept
graphics later — but leave the bodies as `// TODO: see plans/candy-vt-graphics.md`.

---

## 1. Current state — what shipped, what is missing

### 1.1 candy-vt parse surface today

Existing dispatch in `candy-vt/src/Parser/Parser.php` (230 LOC):

| Sequence family | Dispatch entry | Handler today |
|---|---|---|
| CSI (`\x1b[`) | `Handler::csiDispatch($final, $params, $prefix, $intermediate)` | `ScreenHandler` → CursorHandler / SgrHandler / EraseHandler / ScrollHandler / ModeHandler / TabHandler |
| OSC (`\x1b]`) | `Handler::oscDispatch(string $data)` | `OscHandler::apply($data, $screenHandler)` — routes by leading numeric: 0/1/2 title, 4 palette, 8 hyperlink, 52 clipboard |
| DCS (`\x1bP`) | `Handler::dcsDispatch($final, $params, $prefix, $intermediate, $data)` | **no-op today** (`ScreenHandler::dcsDispatch()` body absent / passthrough) |
| ESC (`\x1b<intermediate><final>`) | `Handler::escDispatch($byte, $intermediate)` | CursorHandler save/restore + a handful of ESC singles |
| SOS / PM / APC (`\x1bX`, `\x1b^`, `\x1b_`) | `Handler::sosPmApcDispatch('apc'|'pm'|'sos', $data)` | **no-op today** |

The state machine already collects the string payload across an
arbitrary number of bytes (the `OscString` / `DcsString` / `ApcString`
states accumulate into `Parser::$stringBuffer` until the ST terminator
arrives), so **a graphics decoder does not need to extend the parser
state machine** — it plugs into the dispatch surface.

### 1.2 candy-mosaic emit surface today

| Protocol | Renderer | LOC | Wire sequence family |
|---|---|---|---|
| Kitty graphics | `candy-mosaic/src/Renderer/KittyRenderer.php` | 204 | APC `\x1b_G<keys>;<base64chunk>\x1b\\` repeated until last `m=0` chunk |
| iTerm2 / WezTerm inline | `candy-mosaic/src/Renderer/Iterm2Renderer.php` | 85 | OSC `\x1b]1337;File=<keys>:<base64>\x07` |
| DEC Sixel | `candy-mosaic/src/Renderer/SixelRenderer.php` | 563 | DCS `\x1bPq<raster>#<color>...<sixeldata>\x1b\\` |
| Unicode half-block fallback | `candy-mosaic/src/Renderer/HalfBlockRenderer.php` | 120 | plain SGR + ▀ |
| Unicode quarter-block fallback | `candy-mosaic/src/Renderer/QuarterBlockRenderer.php` | 128 | plain SGR + ░▒▓█ |
| Chafa wrapper | `candy-mosaic/src/Renderer/ChafaRenderer.php` | 115 | shells out to `chafa` |

**Asymmetry:** every protocol that produces escape sequences has an
encoder, but no decoder. A `candy-vcr` cassette that recorded a
`candy-mosaic` Kitty render today would replay correctly on a real
Kitty-capable terminal, but `candy-vt` would silently drop the bytes —
they cross APC / OSC / DCS dispatch and land in handler methods that
no-op.

### 1.3 Why this gap exists

The Charm ecosystem inherited this asymmetry from upstream:
`charmbracelet/x/vt` is a screen-state emulator focused on text — it
does **not** decode Sixel / Kitty / iTerm2 protocols either. Image
output is the job of separate libs (`charmbracelet/x/mosaic`,
`charmbracelet/glamour` for image-in-markdown, etc.). The decode side
is a research-grade undertaking even in the Go ecosystem; only a few
projects (libsixel, libchafa, kitty itself) implement it. SugarCraft
inherits the gap — closing it is months of work.

### 1.4 Why "P3" — the candy-vt research backlog ranking

`docs/research/libraries/candy-vt-research.md` (and the §3 bullet in
`leftover_updates_later.md`) lists candy-vt features by priority. P0
items (DECSTBM / DECAWM / subparam parsing) have all shipped — see
`leftover/updates.md` Done log entries for steps 07.01–07.07. P1 / P2
(scrollback, SGR underline styles, BCE, sync output, focus events) are
shipped. **P3 is graphics, and it is the only remaining priority
tier.** It was explicitly carved out and flagged as a strategic item
in §6 because none of P0–P2 needed a product call; this does.

---

## 2. Scope

### 2.1 What this plan covers (in scope when authorized)

- **Decode** of three image-transmission protocols into a common
  internal representation:
  - **Kitty graphics protocol** — APC `\x1b_G<keys>;<base64>\x1b\\`,
    including chunked transmission (`m=1` continuation), virtual image
    placement (`a=p` + `i=<id>`), action delete (`a=d`), and the most
    common format keys (`f=24` truecolor, `f=32` truecolor+alpha,
    `f=100` PNG, `f=1` zlib-compressed PNG).
  - **iTerm2 / WezTerm inline images** — OSC `\x1b]1337;File=...` with
    base64 payload after the final `:`.
  - **DEC Sixel** — DCS `\x1bPq...` with raster attributes (`"<a>;<b>;<w>;<h>`),
    colour introducers (`#<idx>;<type>;<r>;<g>;<b>`), the sixel byte
    grid itself (`?`–`~` mapped to 6-bit columns of pixels), repeat
    counts (`!<count><byte>`), and graphics newline (`-`) / carriage
    return (`$`).
- **Common output type** — every decoder produces an `ImagePlacement`
  value object (immutable, namespace `SugarCraft\Vt\Graphics\`) carrying
  enough metadata for a consumer to render or replay: source bytes
  (or normalised PNG), cell origin row/col, cell width/height, image
  id (if present), z-index (Kitty), transparency hints, source
  protocol enum, and the SGR state at the time of placement.
- **Screen integration** — `ScreenHandler` records each `ImagePlacement`
  on a new `Screen::placements()` accessor (parallel to the existing
  `Screen::scrollback()`), without overwriting the underlying text
  cell grid. The placement carries `(row, col, cellW, cellH)` and
  consumers walk both the cell grid and the placement list to render
  a screen.
- **Diff API extension** — `Screen::diff(Screen $other)` already
  produces cell diffs; we add a parallel `placementsDiff()` so a UI
  layer can detect added / removed / moved images.
- **Memory limits** — `Terminal::withMaxImageBytes(int)` (default
  ~16 MB) and `Terminal::withMaxImageCount(int)` (default 256). Both
  enforced at `ImagePlacement` accept time; oversize / over-count
  payloads are dropped with an entry pushed to a debug log buffer.

### 2.2 What this plan explicitly excludes

- **Image rendering to ANSI / pixel surfaces.** That is the job of
  `candy-mosaic` (encode side) and host applications (display side).
  Decoded bytes stay as bytes (or PNG) on the placement; producing a
  cell-grid rendering of them is downstream.
- **Per-frame animation reassembly.** Kitty's `a=a` animation
  primitives, iTerm2's GIF support — out of scope. A consumer that
  needs animation reassembles frames itself from successive
  placements.
- **Capability negotiation (XTGETTCAP / DA / Secondary DA).** These
  are escape sequences a *terminal* responds to; they are emit-side,
  and any response logic belongs in candy-mosaic or candy-vcr, not
  here.
- **Receive-side keyboard / mouse protocol upgrades** — Kitty keyboard
  protocol, xterm modifyOtherKeys. Those are separate research items;
  they are not graphics.
- **OSC 8 v2 / OSC 9 / OSC 10/11/12 (foreground/background/cursor
  colour query).** All small, but distinct from graphics; they can ship
  in a separate small handler.
- **Performance optimisation for the decode hot path.** First land
  correctness; benchmark after. Sixel decode is intentionally O(N) in
  the byte stream; trying to be clever pre-correctness will burn
  weeks for little gain.

### 2.3 What "decode" means concretely

For each protocol, the decoder converts the escape-sequence payload
to:

- **A raw image-bytes buffer** (PNG-normalised when the source is
  Kitty `f=100`/`f=1`, or when the consumer requests PNG; raw RGBA
  otherwise).
- **Width and height in pixels** (always known by the time decode
  finishes; required by all three protocols).
- **Cell origin** (row, col where the cursor was when the sequence
  arrived) and **cell extent** (derived from the protocol's cell-grid
  hints — Kitty `c` / `r`, iTerm2 `width` / `height` with unit
  suffix, Sixel raster attributes / 6-row band count).
- **Image id** (Kitty `i=` if present; iTerm2 has no equivalent;
  Sixel has no equivalent — we synthesize a sequential id for the
  latter two).
- **Source-protocol enum** (`Protocol::Kitty | Iterm2 | Sixel`),
  retained so a downstream lib can round-trip the placement back
  through the matching encoder.

This is enough to let a consumer (e.g. candy-vcr replay, a
`candy-mosaic` integration test, a hypothetical `candy-vt-render`
shim) reconstruct what the original TUI emitted.

---

## 3. Architecture

### 3.1 Top-level layout

New subtree under `candy-vt/src/Graphics/`:

```
candy-vt/src/Graphics/
├── ImagePlacement.php          # immutable value object (final, readonly)
├── Protocol.php                # enum: Kitty | Iterm2 | Sixel
├── PlacementLimits.php         # bytes + count caps; immutable
├── PlacementStore.php          # ring-buffer-ish; owns the live placement list
├── GraphicsHandler.php         # orchestrator; dispatch entry point
├── Kitty/
│   ├── KittyDecoder.php        # APC payload → ImagePlacement
│   ├── KittyKeys.php           # key=value parser ("a=T,f=100,i=42")
│   ├── KittyChunkBuffer.php    # multi-APC chunk reassembly (m=1 chain)
│   └── KittyAction.php         # enum: Transmit | TransmitAndPlace | Place | Delete | Animate
├── Iterm2/
│   ├── Iterm2Decoder.php       # OSC 1337 File= payload → ImagePlacement
│   └── Iterm2Keys.php          # key=value parser for the preamble
└── Sixel/
    ├── SixelDecoder.php        # DCS q payload → ImagePlacement
    ├── SixelLexer.php          # byte-level RLE / colour / data tokenizer
    ├── SixelPalette.php        # mutable palette (256 indexed)
    ├── SixelRaster.php         # raster-attributes record
    └── SixelToPng.php          # raw pixel grid → PNG via GD (matches candy-mosaic encode path)
```

`Sgr\Sgr` / `Cell\Cell` are not touched. `Screen\Screen` gets one
new field — `private readonly PlacementStore $placements` — exposed via
`Screen::placements(): array`. `ScreenHandler` gets one new
collaborator (the `GraphicsHandler`) and one new field
(`PlacementStore $placements`).

### 3.2 Dispatch wiring

The parser already lands payloads at three handler methods. Here is
what each one needs to grow:

```
Parser → Handler::oscDispatch($data)
       → OscHandler::apply($data, $screen)
         → if numeric prefix == 1337: hand to GraphicsHandler::iterm2($data)
         → else: existing 0/1/2/4/8/52 routing unchanged

Parser → Handler::dcsDispatch($final, $params, $prefix, $intermediate, $data)
       → if $final == 'q' && $intermediate == 0: GraphicsHandler::sixel($params, $data)
       → else: existing no-op

Parser → Handler::sosPmApcDispatch('apc', $data)
       → GraphicsHandler::kitty($data)
       → sos / pm continue as no-op
```

Three small changes in three existing methods — no new state machine
edges, no new parser actions, no transition-table edits. The
`Transitions::*` byte table stays untouched.

### 3.3 ImagePlacement shape

```php
final class ImagePlacement
{
    public function __construct(
        public readonly Protocol $protocol,
        public readonly int $imageId,
        public readonly int $row,
        public readonly int $col,
        public readonly int $cellWidth,
        public readonly int $cellHeight,
        public readonly int $pixelWidth,
        public readonly int $pixelHeight,
        public readonly ?int $zIndex,                  // Kitty only; null otherwise
        public readonly string $imageBytes,            // PNG-normalised
        public readonly string $sourceBytes,           // protocol-specific raw payload, kept for round-trip
        public readonly ?Sgr $sgrAtPlacement,          // SGR active when sequence arrived
        public readonly ?Hyperlink $hyperlinkAtPlacement,
    ) {}

    public function withZIndex(?int $z): self { /* mutate() helper */ }
    public function withImageId(int $id): self { /* mutate() helper */ }
    // ... other with*() per the immutable + fluent rule
}
```

Follows the standard SugarCraft pattern (`final`, `readonly` props,
`with*()` via private `mutate()`). Mirrors no upstream — there is no
upstream class to cite; the doc-comment names the protocol specs
instead (Kitty graphics spec URL, iTerm2 docs URL, ECMA-035 / DEC
sixel spec).

### 3.4 PlacementStore + memory limits

```php
final class PlacementStore
{
    /** @var list<ImagePlacement> */
    private array $live = [];
    private int $totalBytes = 0;
    private int $nextId = 1;

    public function __construct(private readonly PlacementLimits $limits) {}

    public function add(ImagePlacement $p): ?ImagePlacement
    {
        $bytes = strlen($p->imageBytes);
        if ($bytes > $this->limits->maxBytesPerImage) {
            return null;                              // silently dropped, log entry only
        }
        while ($this->totalBytes + $bytes > $this->limits->maxTotalBytes
               && $this->live !== []) {
            $evicted = array_shift($this->live);     // FIFO eviction
            $this->totalBytes -= strlen($evicted->imageBytes);
        }
        while (count($this->live) >= $this->limits->maxImages
               && $this->live !== []) {
            $evicted = array_shift($this->live);
            $this->totalBytes -= strlen($evicted->imageBytes);
        }
        $this->live[] = $p;
        $this->totalBytes += $bytes;
        return $p;
    }

    public function delete(int $imageId): bool { /* Kitty a=d */ }

    /** @return list<ImagePlacement> */
    public function all(): array { return $this->live; }
}
```

Reuses the eviction pattern from `Screen\Scrollback` (`candy-vt/src/Screen/Scrollback.php`)
— same FIFO ring-buffer semantics.

### 3.5 Cell-grid coexistence

Decoded placements do **not** overwrite the underlying text cells. The
chosen model is the same as upstream Kitty: cells under an image
remain "live" (cursor can still be moved through them, text can still
be written under the image), the placement just records that an
overlay exists at those coordinates. Consumers that render the screen
walk both layers. This avoids the Sixel "burnt-in" cell ambiguity
where the cells under the image become unaddressable on some real
terminals (xterm Sixel mode in particular) — we side-step the
ambiguity by recording the placement explicitly and letting the
consumer decide what to do at render time.

A future flag (`Terminal::withSixelEats(bool)`) could opt into the
"sixel eats cells" behaviour if a consumer wants strict xterm parity.
Default: off (Kitty semantics).

### 3.6 Diff API

```php
final class PlacementDiff
{
    public function __construct(
        public readonly array $added,        // list<ImagePlacement>
        public readonly array $removed,      // list<int> image ids
        public readonly array $moved,        // list<array{0:int, 1:int, 2:int}> id, newRow, newCol
    ) {}
}

// Screen::placementsDiff(Screen $other): PlacementDiff
```

Mirrors `Screen::diff()` for cells. Used by candy-vcr replay and any
incremental-render consumer.

---

## 4. Dependencies

### 4.1 candy-mosaic (encode-side reuse)

The PNG-normalisation path in `candy-mosaic/src/Renderer/KittyRenderer.php`
(`ensurePng()`, 25 LOC) and the Sixel pixel-grid extraction path are
the *inverse* of what the decoders need. Worth lifting either:

- **Option A — shared util in candy-vt:** add
  `candy-vt/src/Graphics/Image/{PngCodec,RgbaCodec}.php` and let
  candy-mosaic depend on candy-vt (today it does not). Pulls
  candy-mosaic into the candy-vt dependency closure.
- **Option B — shared util in candy-core or a new tiny lib:** new
  `candy-pixel` (or `candy-img`) lib that both consume. Cleanest but
  adds a 7th lib to the foundation cluster.
- **Option C — duplicate the GD glue:** ~30 LOC duplicated between
  the two libs. Cheapest; matches the dual-foundation-SSOT pattern
  noted in `CALIBER_LEARNINGS.md` entry 38 (5 retained Foundation
  types in sugar-dash).

**Recommendation: Option C** until the decoder ships; revisit after.
Cross-lib dependencies always cost more than 30 LOC of well-commented
duplication.

### 4.2 GD / Imagick

candy-mosaic already requires `ext-gd`. The decoder needs the same
for PNG encode (Kitty `f=100` round-trip + Sixel-to-PNG
normalisation). Add `ext-gd` to `candy-vt/composer.json` `require`
**only when the decoder ships** — until then, candy-vt stays GD-free
(it currently has zero ext deps beyond stdlib, which is a deliberate
strength). The phase-1 step file MUST flag this as the first review
target.

### 4.3 candy-vcr round-trip

candy-vcr cassettes today record raw bytes. They will round-trip
graphics sequences correctly **as soon as the decoder ships** — no
candy-vcr change required. The decoder is purely additive on
candy-vt's side.

### 4.4 No new sugarcraft/* path-repo edits required at plan time

The plan-only step ships no composer changes. When phase 1 lands, the
implementer:

- Adds `"ext-gd": "*"` to `candy-vt/composer.json` `require`.
- Does NOT add `sugarcraft/candy-mosaic` as a dep (no Option A);
  per recommendation §4.1, decoder uses duplicated GD glue.
- Does NOT add candy-vt to candy-mosaic (no inverse dep either).

### 4.5 candy-vt → candy-vt only

The whole graphics subtree is internal to candy-vt. Public API
exposed: `Terminal::withGraphics(bool)` (default `false` to keep
backward-compat; opt-in), `Terminal::withPlacementLimits(PlacementLimits)`,
and `Screen::placements()`. Default disabled means existing consumers
see no behaviour change.

---

## 5. Phases

Six phases. Each is one PR per phase **except phase 3 (Sixel),
which is multi-PR.** Estimated calendar weeks are aggressive — assume
~50 % buffer for review-fix cycles + CALIBER findings.

### Phase 1 — Skeleton + Kitty transmit (a=T) MVP (3–4 weeks)

**Goal:** decode the simplest Kitty action — single-chunk transmit,
PNG payload, no virtual placement, no animation, no chunk
reassembly. End-to-end round-trip through candy-mosaic encode →
candy-vt decode in a single test.

**Touched:**

- `candy-vt/src/Graphics/ImagePlacement.php` (skeleton, all fields)
- `candy-vt/src/Graphics/Protocol.php` (enum, all three values)
- `candy-vt/src/Graphics/PlacementLimits.php`
- `candy-vt/src/Graphics/PlacementStore.php`
- `candy-vt/src/Graphics/GraphicsHandler.php` (stub for kitty/iterm2/sixel)
- `candy-vt/src/Graphics/Kitty/KittyKeys.php` (key=value parser, ~60 LOC)
- `candy-vt/src/Graphics/Kitty/KittyDecoder.php` (single-chunk only)
- `candy-vt/src/Handler/ScreenHandler.php` — wire `sosPmApcDispatch`
- `candy-vt/src/Screen/Screen.php` — `placements()` accessor
- `candy-vt/src/Terminal/Terminal.php` — `withGraphics()` / `withPlacementLimits()`
- `candy-vt/composer.json` — add `ext-gd: *`
- `candy-vt/tests/Graphics/KittyDecoderTest.php` — round-trip
  candy-mosaic-encoded PNG through decoder; assert dimensions, byte
  equality, cell origin.
- `candy-vt/README.md` — new "Graphics protocols" section
- `candy-vt/CALIBER_LEARNINGS.md` — new patterns

**Out of scope phase 1:** multi-chunk Kitty (m=1), virtual placement
(a=p), delete (a=d), iTerm2, Sixel. All stubbed; calling them returns
without placing.

**Acceptance:** the round-trip test passes; existing 467 candy-vt
tests still pass; `composer validate` clean; `Terminal::withGraphics(false)`
remains the default and behaviour change is zero for legacy
consumers.

### Phase 2 — Kitty full surface (4–5 weeks, multi-PR)

**Goal:** complete the Kitty graphics protocol decode surface.

**Sub-phases (one PR each):**

- 2a — multi-chunk reassembly (`KittyChunkBuffer`, `m=1` chain;
  imageBytes pieced from APC bursts).
- 2b — virtual image placement (`a=p` + `i=<id>` → place a previously
  transmitted image; PlacementStore lookup).
- 2c — delete action (`a=d` + `d=<scope>` where `d` is a / A / i / I
  / etc. per spec; full deletion-criterion table).
- 2d — additional format keys (`f=24` raw RGB → PNG-normalise via
  GD; `f=32` raw RGBA → PNG-normalise; `f=1` zlib → gzuncompress
  before PNG decode).
- 2e — z-index (`z=<int>`) preservation.

**Decision-driven:** drop animation (`a=a`, `a=c`) — out of scope per
§2.2; phase-2 ships without it.

**Acceptance:** round-trip every candy-mosaic KittyRenderer code
path (including step 07.13 useVirtual + compress + delete). Test
suite expands to ~50 new tests under `tests/Graphics/Kitty/`.

### Phase 3 — DEC Sixel (5–7 weeks, multi-PR)

**Goal:** decode DCS-q Sixel payload to a pixel grid → PNG → store
as ImagePlacement.

**Sub-phases:**

- 3a — `SixelLexer` (byte tokenizer): `#` colour-introducer,
  `!` repeat, `?` – `~` data byte, `-` newline, `$` CR, `"` raster
  attribs.
- 3b — `SixelPalette` (mutable indexed palette, parse HLS / RGB
  colour types per `#<idx>;<type>;<a>;<b>;<c>` syntax — type 1 = HLS,
  type 2 = RGB).
- 3c — `SixelRaster` + dimension inference (raster attribs gives w/h
  directly; absent raster attribs requires scanning the full data
  stream to infer band count × max-column, which is the protocol's
  worst-case parse).
- 3d — pixel-grid assembly: for each 6-row band, walk bytes,
  expand RLE, set 6 bits per column per colour.
- 3e — `SixelToPng` via GD `imagecreatetruecolor` +
  `imagecolorallocate` per palette index + `imagesetpixel`. Encode
  PNG into ImagePlacement.
- 3f — RLE round-trip with candy-mosaic SixelRenderer (each
  encode-side run should decode to the same expanded byte sequence).
- 3g — security: bound parse work by max-pixels (default 4 MP); 
  reject if exceeded.

**Acceptance:** five canonical Sixel images (single colour, 4-colour,
256-colour, RLE-heavy, raster-attribs-absent) round-trip via
candy-mosaic encode → decode → re-encode → byte-equal-after-
normalisation. New tests under `tests/Graphics/Sixel/`; expect ~40.

### Phase 4 — iTerm2 / WezTerm OSC 1337 (2 weeks)

**Goal:** decode OSC 1337 File= payloads.

**Touched:**

- `candy-vt/src/Graphics/Iterm2/Iterm2Keys.php` (key=value parser
  for the `name=...:size=...:width=...:height=...:inline=1` preamble)
- `candy-vt/src/Graphics/Iterm2/Iterm2Decoder.php` (split on first
  `:`, decode base64, PNG-normalise via GD)
- `candy-vt/src/Handler/OscHandler.php` — route `1337` to
  GraphicsHandler::iterm2
- `candy-vt/tests/Graphics/Iterm2DecoderTest.php`

**Acceptance:** round-trip every candy-mosaic Iterm2Renderer call
path; ~10 new tests.

### Phase 5 — Diff API + memory hardening (2 weeks)

**Goal:** `Screen::placementsDiff()`, `PlacementDiff` value object,
fuzz tests for memory-limit eviction under adversarial input
(image-bomb resistance).

**Touched:**

- `candy-vt/src/Graphics/PlacementDiff.php`
- `candy-vt/src/Screen/Screen.php` — `placementsDiff(Screen)`
- `candy-vt/tests/Graphics/PlacementDiffTest.php`
- `candy-vt/tests/Graphics/MemoryLimitsTest.php` (fuzz: 1000 images,
  oversized payloads, etc.)

### Phase 6 — Documentation, examples, VHS demo (1–2 weeks)

**Goal:** finish the docs surface.

**Touched:**

- `candy-vt/README.md` — "Graphics protocols" section per §3
  surface
- `docs/lib/candy-vt.html` — feature grid + API table updated
- `docs/end-user/candy-vt.md` (if it exists; otherwise skip)
- `candy-vt/examples/graphics-decode.php` — script that feeds a
  candy-mosaic-rendered image into a Terminal and dumps the
  decoded placement
- `candy-vt/.vhs/graphics-decode.tape` (if visual demo makes sense;
  graphics decode is largely non-visual — may opt out)
- `MATCHUPS.md` — note candy-vt graphics support ✅
- `CALIBER_LEARNINGS.md` — accumulated patterns

**Total estimate:** 17–23 weeks of focused implementation work.
With review-fix cycles, calendar realistically ~25–30 weeks.

---

## 6. Risks

### 6.1 Sixel grammar non-triviality

The DEC Sixel spec is small (~6 pages) but unforgiving:

- **Run-length encoding** with `!<count><byte>` where `<count>` is
  ASCII decimal, no fixed width, and applies to the *next* data
  byte. Single-byte-typo flips entire bands.
- **Palette introducer with two colour types** (HLS where H is
  0–360°, L/S are 0–100; RGB where R/G/B are 0–100, **not** 0–255).
  Off-by-one in the type-1 normalisation is easy and silent.
- **Raster attributes optional.** When absent, the decoder must
  *infer* the image width from the longest data row, which means a
  two-pass parse (count first, allocate second).
- **Band boundary at `-`.** Misplaced `-` produces silently
  truncated images.
- **The data stream itself can carry NUL / control bytes** that the
  outer parser is supposed to ignore until ST, but several real
  terminals tolerate garbage. Tolerance vs strictness is a per-bug
  decision and adds test surface.

**Mitigation:** start phase 3 with a hand-rolled corpus of 40+
known-good Sixel byte streams (canonical encoder output from
`candy-mosaic/src/Renderer/SixelRenderer.php` covers most of it).
Decode each; assert byte-perfect re-encode. Add adversarial fuzzy
corpus only after the canonical path is locked.

### 6.2 Kitty image-data chunk semantics

Kitty's APC chunks are not a simple "concatenate":

- Each chunk is its own APC sequence (`\x1b_G<keys>;<chunk>\x1b\\`).
- Only the first chunk carries the full key set; subsequent chunks
  carry only `m=1` (more) or `m=0` (last).
- Chunks for **different images** can interleave on the wire (Kitty
  spec section "interleaved data transmission"). Chunk reassembly
  needs to key on the `i=` image-id, not on receive order.
- A chunk can be the last one (`m=0`) on a payload that started in
  a previous chunk but the format keys (`f=`, `c=`, `r=`, etc.)
  arrived in the first chunk only. The chunk buffer must retain the
  format state.

**Mitigation:** `KittyChunkBuffer` is the riskiest single class in
the design. Build it test-first; ~20 tests for interleave + format
retention + terminator-after-empty-chunk edge cases.

### 6.3 iTerm2 / WezTerm divergence

OSC 1337 is iTerm2's native protocol; WezTerm implements most but not
all keys (`width=N`, `height=N`, `preserveAspectRatio=1`). A decoder
that strictly enforces iTerm2 keys will reject valid WezTerm payloads
and vice versa.

**Mitigation:** decoder treats unknown keys as ignorable (the spec
allows this); decoder asserts only `inline=1` and the presence of a
trailing payload. All other keys (`name=`, `size=`, `width=`,
`height=`) populate the placement when present; absent → default
inference rules.

### 6.4 Memory bombs

A malicious or buggy producer can emit:

- A 100 MB Kitty PNG payload in one APC.
- A Sixel band that decodes to 10 000 × 10 000 pixels.
- Interleaved chunks that never terminate (no `m=0`).

**Mitigation:** `PlacementLimits` (§3.4) bounds both per-image and
total live bytes. Phase 5 adds fuzz tests for the unterminated case
(chunk buffer must drop stale partials after N chunks without `m=0`
or after a configurable wall-clock timeout — implementer to decide).
The `Parser` state machine itself has no infinite-loop pathology
(it processes one byte per advance and always reaches `Ground` from
any valid input).

### 6.5 GD dependency footprint

candy-vt today has no ext-* deps. Adding `ext-gd` doubles the install
surface and breaks the "candy-vt runs on any PHP 8.3" promise. Risk
is small (`ext-gd` ships with most distros) but real.

**Mitigation:** `Terminal::withGraphics(false)` default. The `ext-gd`
require is conditional on opt-in. Phase 1 ships with the require in
`composer.json` but the decoder bodies short-circuit when graphics
is off, so the GD requirement is enforced only when actually used.
Alternatively, drop the hard require and `require-dev` instead with
runtime check + clear error message — implementer call at phase 1
review.

### 6.6 Performance

PHP byte-loop performance is bad. Sixel decode of a 1 MB payload
will be visibly slow on PHP 8.3 (10+ seconds is plausible for the
naive implementation). For interactive use this is unacceptable; for
candy-vcr replay it is acceptable (offline). Hot-path optimisation
(packed-integer byte arithmetic via `unpack()`, avoiding `chr()` /
`ord()` per byte) is post-phase-3.

**Mitigation:** explicitly punt performance to phase-7 (out of this
plan). Document expected order-of-magnitude in README. If a consumer
needs sub-second decode, they should pre-decode via a side process
and feed candy-vt the already-decoded PNG via a separate API (we'd
add `Terminal::feedImagePlacement(ImagePlacement)` if needed).

### 6.7 Test-surface explosion

Three protocols × multiple format keys × chunk-buffer reassembly ×
palette / RLE / raster edge cases adds ~120 tests on top of the 467
candy-vt already has. CI time budget needs to be re-checked after
phase 2.

**Mitigation:** golden-snapshot the encoded byte streams from
candy-mosaic at fixed input pixel grids; assert decode produces the
same pixel grid. The test surface grows linearly with input
diversity, not with code surface.

---

## 7. Decision criteria — when (or whether) to start

This work is multi-month and the gap is not blocking any current
SugarCraft consumer. Before authorizing phase 1, **at least one** of
the following must hold:

### 7.1 Hard triggers (any one starts the work)

1. **A SugarCraft TUI consumer needs to record a session including
   inline images and replay it through candy-vcr.** Today no consumer
   does. The candy-vcr cassette format will replay raw bytes against
   any real terminal, so end-user replay works; the gap only matters
   for *automated* replay where candy-vt is the playback target.
2. **A candy-mosaic test suite needs round-trip coverage.** Today
   candy-mosaic only tests encode — there is no decoder to round-trip
   through. If a future bug requires "encode then decode produces
   the same pixel grid" as the regression test, this work becomes
   blocking.
3. **A port lands that pipes through a virtual terminal and
   downstream surface includes images.** E.g. a `glow`-style markdown
   renderer that embeds images, run inside a `candy-pty` PTY whose
   output gets fed into a candy-vt for diff-based render — the
   images would be silently dropped today.
4. **A SugarCraft demo app wants to display inline images in a
   sub-region of a TUI rendered through candy-vt.** E.g.
   sugar-dash's WeatherModule wanting to embed a radar PNG. Today
   sugar-dash bypasses candy-vt and writes directly to the host
   terminal; this is the most plausible future trigger.

### 7.2 Soft triggers (raise priority but don't force start)

- An external SugarCraft user files a GitHub issue asking for the
  feature.
- Upstream `charmbracelet/x/vt` ships graphics decode (it does not
  today; check `MATCHUPS.md` annual review).
- The candy-vcr cassette format gains an "image" event type for
  efficient storage; would still require candy-vt decode for
  playback diff testing.

### 7.3 Do-not-start conditions

- The work is **not** unblocked by completing any P0/P1/P2 candy-vt
  feature. All of those are shipped.
- The work is **not** unblocked by any sugar-* or honey-* milestone.
- The work is **not** unblocked by 1.0 release readiness; 1.0 ships
  fine without it.
- Phase-11 strategic plans 11.01 (sugar-post) and 11.02 (candy-serve
  TUI) target user-visible value. **This plan targets internal
  capability completeness only.** If product time is constrained,
  defer this in favour of the other two.

---

## 8. Pre-implementation namespace reservation

Even though no code ships in this plan, two cheap "stake out the
land" actions can land safely as part of this plan's PR or a
follow-up cleanup. They are **not** required; flagged here so a
phase-1 implementer does not have to refactor existing code:

- Reserve the namespace `SugarCraft\Vt\Graphics\` — no code yet, but
  if a future PR accidentally claims it for something else, decoder
  work would have to namespace differently.
- Annotate the three handler-dispatch entry points
  (`OscHandler::apply`, `ScreenHandler::dcsDispatch`,
  `ScreenHandler::sosPmApcDispatch` — the latter two may be no-ops
  today) with `// TODO: see plans/candy-vt-graphics.md` referring to
  this file, so an implementer searching for the right hook lands
  here immediately.

Neither of these is in scope for the plan-only step file. They are
trivial follow-ups if the strategic decision goes "defer but
prepare".

---

## 9. Out of scope for this memo

- **Concrete pixel-buffer rendering of decoded placements to a host
  terminal.** That belongs in candy-mosaic (we have it for emit; the
  receive-side rendering would need a new `candy-mosaic`/Renderer
  back-end or a host-app component). The decoder hands consumers raw
  image bytes; what they do with them is downstream.
- **Captionable extensions to candy-vcr.** If candy-vcr wants
  efficient image-event storage post-decoder, that is its own plan.
- **Upgrading candy-mosaic encode to match decode coverage.** Today
  candy-mosaic supports a subset of each protocol's options on the
  encode side (e.g. Kitty `a=T` and `a=p` but not `a=a` animation,
  Sixel without raster attribs optional emission). The decoder
  inherits whatever upstream emit-side options it sees in the wild,
  including from third-party producers — but expanding encode is a
  separate effort.
- **Capability negotiation.** candy-mosaic detects terminal
  capabilities via `Capability::detect()` to pick a renderer; the
  decoder side has no analogous need (it parses whatever arrives).
- **Performance benchmarking baseline.** Defer to post-phase-3.

---

## 10. Source citations

- `candy-vt/src/Parser/Parser.php` — VT500 state machine; existing
  dispatch surface at L194–L228 (function `dispatch()` switches on
  `$from` state for OSC / DCS / SOS / PM / APC).
- `candy-vt/src/Parser/Action.php` — Action enum (no new value
  needed for graphics).
- `candy-vt/src/Parser/State.php` — State enum; `OscString` /
  `DcsString` / `ApcString` already collect string payloads.
- `candy-vt/src/Handler/OscHandler.php` — extension point for
  iTerm2 OSC 1337; today routes 0/1/2/4/8/52.
- `candy-vt/src/Handler/ScreenHandler.php` — top-level handler;
  gains a `GraphicsHandler` collaborator.
- `candy-vt/src/Screen/Screen.php` — `placements()` accessor lands
  here.
- `candy-vt/src/Screen/Scrollback.php` — eviction pattern reused
  in `PlacementStore`.
- `candy-vt/src/Terminal/Terminal.php` — `withGraphics()` /
  `withPlacementLimits()` factory methods.
- `candy-mosaic/src/Renderer/KittyRenderer.php` — emit-side
  reference for round-trip tests (L24–L204).
- `candy-mosaic/src/Renderer/Iterm2Renderer.php` — emit-side
  reference (L20–L85).
- `candy-mosaic/src/Renderer/SixelRenderer.php` — emit-side
  reference; algorithm comment at L13–L26 mirrors what decoder
  inverts (L1–L563).
- `plans/leftover_updates_later.md` §6 — strategic-decision item
  origin (L875–L887).
- `plans/leftover_updates_later.md` §3 candy-vt L237 — "Kitty
  graphics + Sixel decode (P3) — strategic, see §6".
- `plans/leftover/phase-11-strategic-plans/step-03-candy-vt-graphics.md`
  — step file driving this memo.
- `plans/candy-serve-tui.md` — structural template (multi-phase
  deferred plan format) referenced for shape.
- Kitty graphics protocol spec — https://sw.kovidgoyal.net/kitty/graphics-protocol/
- iTerm2 inline images proposal — https://iterm2.com/documentation-images.html
- DEC Sixel spec — https://vt100.net/docs/vt3xx-gp/chapter14.html

---

## 11. Open questions for the supervisor / phase-1 implementer

1. **GD as hard require vs require-dev.** Lock at phase-1 review.
   Hard require simplifies decode; require-dev keeps candy-vt
   ext-free for the no-graphics common case. Recommendation:
   require-dev + runtime check.
2. **Default `withGraphics(true)` vs `(false)`.** Default off
   preserves backward-compat but means consumers must opt in. Default
   on is more discoverable but changes the implicit contract.
   Recommendation: default off; flag prominently in README.
3. **Sixel "eats cells" mode.** Phase-3 default = Kitty semantics
   (cells under image stay live). Should the strict-xterm mode flag
   land in phase 3 or defer to a follow-up? Recommendation: defer.
4. **PNG normalisation vs raw passthrough.** Storing PNG bytes on
   every placement costs encode CPU at decode time. Alternative:
   store raw RGBA + width + height; let consumer encode. Cleaner but
   larger memory footprint (PNG compresses; raw RGBA does not).
   Recommendation: PNG by default; add `withRawPixels(true)` opt-in
   for memory-constrained replay scenarios.
5. **Animation.** §2.2 excludes Kitty animation. Verify with the
   triggering consumer (per §7.1) before locking — if the consumer
   that authorizes phase 1 needs animation, phase 2 expands.

---

## 12. Recommendation (summary)

**Defer past 1.0.** None of the §7.1 hard triggers hold today. The
work is well-scoped, well-bounded, and unblocked-by-anything when it
starts. Pre-allocate the namespace and dispatch hook comments
(§8 — optional cheap follow-up), document the gap in
`MATCHUPS.md` as "🟡 emit-only" against the upstream graphics
research entry, and revisit annually or whenever a §7.1 trigger
fires.

If a trigger does fire, phase 1 (Kitty MVP) is the right starting
point — it is the smallest, the easiest to test, and the protocol
candy-mosaic exercises most heavily. Phases 2 and 4 follow naturally;
phase 3 (Sixel) is the largest and most fragile and should not be
started until phase 2 is green and stable.
