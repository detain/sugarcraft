# candy-flip vs candy-mosaic — image-output split strategic plan

**Status:** plan-only · **Owner:** product call → phase-7/phase-9 implementers · **Branch:** `ai/plan-candy-flip-mosaic-split`

**Origin:** `plans/leftover_updates_later.md` §6 strategic-decision items ·
`plans/leftover_updates_later.md` §3 candy-flip P10 + candy-mosaic P2 #8 ·
`plans/leftover/phase-11-strategic-plans/step-04-candy-flip-mosaic-split.md`

> This document does **not** ship code. It is a decision memo defining
> the responsibility boundary between `candy-flip` (frame source) and
> `candy-mosaic` (terminal-protocol output) so that step 07.15
> (candy-mosaic animation support) and steps 09.01–09.03 (candy-flip
> quality + adaptive sizing) can land without re-litigating the
> overlap. The single decision below unblocks step 07.15.

---

## TL;DR

**Decision (for step 07.15): mosaic animation lives in `candy-mosaic`.**
The animation type, frame timing, and per-frame renderer dispatch all
land under `candy-mosaic/src/`. `candy-flip` does **not** grow a
Kitty / iTerm2 / WezTerm / Sixel output path; its `Renderer` stays as
the ASCII-block fallback emitter it already is.

**Direction of the dependency:** `candy-mosaic` does **not** depend on
`candy-flip`. Step 07.15's draft suggested adding
`"sugarcraft/candy-flip": "@dev"` to `candy-mosaic/composer.json`
"IF the split says so" — the split says **no**. Reverse coupling
(`candy-flip` → `candy-mosaic`) is fine for a future "play this GIF
through the best available protocol" demo and does not affect the
07.15 scope.

**Shape of the contract:** step 07.15 creates a frame-agnostic
`Animation` value object in `candy-mosaic` whose constructor takes
an ordered list of `ImageSource` + per-frame delay (ms). The
animation does not know or care that the frames came from a GIF,
an MP4 export, or a procedural generator. A trivial bridge function
in `candy-flip` (next-phase step 09.04, not 07.15) can adapt a
`candy-flip\Frame[]` list to `candy-mosaic\Animation` when someone
wants to play a GIF through Kitty / iTerm2 / Sixel — but the bridge
lives in `candy-flip` (the upstream-of-mosaic lib) and is purely
additive.

The 30-second test: if a downstream consumer wants to play an MP4
export, a procedural CA fractal, or a real-time video feed through
Kitty graphics, they should not be forced through a GIF-decoder
detour. `Animation` is therefore decoupled from `Frame`.

---

## 1. Current state — what shipped

### 1.1 candy-flip today (the GIF viewer)

`candy-flip/src/` (348 LOC over six files):

| File | LOC | Role |
|---|---|---|
| `Decoder.php` | 119 | Reads GIF bytes, walks image-descriptor offsets, downsamples per frame via GD nearest-neighbor, returns `list<Frame>` capped at 256 |
| `Frame.php` | 29 | Immutable value: 2-D `list<list<array{0:int,1:int,2:int}>>` of cell-space RGB triples |
| `Renderer.php` | 48 | ANSI emitter. Two presets: `solid` (`█` painted in 24-bit truecolor SGR) or `density` (ASCII luminance ramp) |
| `Player.php` | 109 | `SugarCraft\Core\Model` impl — index/paused/preset state, `Cmd::tick($interval)` for frame advance, keys: space/←/→/d/q |
| `TickMsg.php` | 12 | `Msg` for the frame-tick |
| `Lang.php` | 31 | i18n facade — wraps `SugarCraft\Core\I18n\T` |

Composer surface: `sugarcraft/candy-flip` requires `php:>=8.3`,
`sugarcraft/candy-core`, `sugarcraft/candy-sprinkles`, `ext-gd`.
Ships a `bin/candy-flip` binary that loads frames, instantiates a
`Player`, and runs it through `SugarCraft\Core\Program`.

What `candy-flip` is **not**: there is no Kitty path, no iTerm2 path,
no Sixel path, no protocol detection. The README and `leftover_updates_later.md`
§3 list "P10 — Kitty/WezTerm graphics-protocol output path" as a
research backlog item — that item is the subject of this memo.

### 1.2 candy-mosaic today (the protocol-output renderer)

`candy-mosaic/src/` (3,236 LOC over 21 files; 7 of those under `Renderer/`):

| File | LOC | Role |
|---|---|---|
| `Mosaic.php` | 407 | Public facade — `probe() / halfBlock() / sixel() / kitty() / iterm2() / chafa() / builder()`; the `Picker` from `ratatui-image` |
| `MosaicBuilder.php` | (in same file) | Fluent builder with `withRenderer/withResize/withDither/withScale` |
| `ImageSource.php` | 243 | Decoded image value: raw bytes + format MIME + pixel W/H; `fromFile() / fromString() / crop() / resize() / aspectRatio()` |
| `PixelGrid.php` | 171 | RGB grid extraction from `ImageSource` for half-block / quarter-block paths |
| `Capability.php` | 77 | Detected terminal capability snapshot (sixel/kitty/iterm2/halfblock/chafa + cellSize + inTmux) |
| `CellSize.php` | 16 | Cell pixel dimensions tuple |
| `Detect.php` | 457 | DA1 / env-var / XTWINOPS capability probing; `Detect::cached()` caches per-process |
| `KittyOptions.php` | 161 | Virtual-image placement (`a=p`) + chunked transmit + zlib compression options |
| `Scale.php` | 137 | `Fit / Crop / Stretch / None` scale enum + dimension math |
| `Dither.php` | 36 | Floyd-Steinberg / Stucki / None enum |
| `AdaptiveImage.php` | 154 | Memoises render output per (w,h) bucket; re-encodes on cell-size change |
| `PrecomputedImage.php` | 36 | Frozen render result for one specific (w,h) |
| `TmuxPassthroughDecorator.php` | 164 | Wraps DCS/APC/OSC in tmux passthrough envelope |
| `SyncAsyncRenderer.php` | 49 | Wraps a `Renderer` so callers can await it as a ReactPHP `PromiseInterface` |
| `AsyncRenderer.php` | 23 | Marker interface for async renderers |
| `Lang.php` | 32 | i18n facade |
| `Renderer/Renderer.php` | 48 | Renderer interface — `render(ImageSource, w, h)` / `name()` / `supportsAlpha()` / `delete(imageId)` |
| `Renderer/KittyRenderer.php` | 204 | Kitty graphics APC chunked transmit + `renderWithOptions(KittyOptions)` for virtual placement + compression |
| `Renderer/Iterm2Renderer.php` | 85 | OSC 1337 `File=` inline images |
| `Renderer/SixelRenderer.php` | 563 | DCS-q full Sixel encoder: median-cut quantize + dither + RLE bands + 256-color fallback |
| `Renderer/HalfBlockRenderer.php` | 120 | `▀` + 24-bit fg/bg fallback; transparent-pixel-aware (step 07.14) |
| `Renderer/QuarterBlockRenderer.php` | 128 | `░▒▓█` 2×2 fallback (step 07.11) |
| `Renderer/ChafaRenderer.php` | 115 | Shells out to `chafa` |

Composer surface: `sugarcraft/candy-mosaic` requires `php:^8.3`,
`sugarcraft/candy-core`, `sugarcraft/candy-sprinkles`, `ext-gd`,
`ext-mbstring`, `react/promise`. Library type (no `bin/`).

What `candy-mosaic` is **not**: it is single-frame only today.
Nothing iterates frames, nothing schedules ticks, nothing diffs frame
N against frame N-1 to issue a per-protocol delete-and-redraw cycle.
`leftover_updates_later.md` §3 lists "Animation/GIF support (P2 #8)"
as a research backlog item — that item is the subject of this memo.

### 1.3 The visible overlap

Both libs claim some piece of image-to-terminal:

- `candy-flip\Renderer` emits truecolor SGR + Unicode blocks — that is
  exactly what `candy-mosaic\Renderer\HalfBlockRenderer` (universal
  fallback) does. The two implementations are independent, came from
  different upstream sources (`namzug16/gifterm` vs
  `charmbracelet/x/mosaic`), and differ in details (gifterm uses `█` +
  background SGR per cell; HalfBlock uses `▀` + paired fg/bg per
  2-row band for 2× vertical resolution).
- `candy-flip\Player` is a `SugarCraft\Core\Model` already wired up
  for frame-advance ticks. `candy-mosaic` has no analogue.
- `candy-mosaic\ImageSource` knows PNG / JPEG / static GIF. It does
  not iterate animated GIF frames. `candy-flip\Decoder` does.

The first-order question: when someone wants to play a GIF through
Kitty graphics protocol, which lib's surface do they call?

---

## 2. The three split options

### 2.1 Option A — mosaic depends on flip (flip emits frames, mosaic owns protocol output)

This is the recommendation from `leftover_updates_later.md` §6 and the
sketch in step 07.15's draft.

```
candy-flip  ── exposes ── list<Frame>  ─→  bridge  ─→  candy-mosaic.Animation
                                                        └─→ Renderer.render(frame, w, h)
                                                            for each frame, then sleep delay
```

**Pros:**
- Maps to the existing lib boundaries: candy-mosaic already owns every
  ANSI / DCS / APC / OSC protocol path. Adding animation extends an
  existing surface rather than spawning a parallel one.
- Reuse is real: `KittyRenderer::delete()`, `Iterm2Renderer::delete()`
  (step 07.12), `KittyOptions::place()` (step 07.13), and the existing
  `AdaptiveImage` memoiser all become directly useful inside the
  animation loop.

**Cons (load-bearing):**
- Forces `candy-mosaic` to require `candy-flip`. That is the *wrong*
  direction. candy-flip is a higher-level lib that already requires
  candy-core + candy-sprinkles + ext-gd — making mosaic depend on
  flip pulls a binary-shipping app into the dependency closure of
  every consumer of candy-mosaic (sugar-charts, sugar-dash, hypothetical
  candy-glow image-in-markdown, etc.). That is a dependency-direction
  inversion the monorepo has been careful to avoid.
- The `Animation` type only consumes `Frame` if it ties itself to
  GIF semantics. Future MP4 export, procedural animation, real-time
  video feed all want the same animation surface but don't have a
  `Frame` to give it.

**Verdict:** rejected — the dependency points the wrong way.

### 2.2 Option B — flip absorbs mosaic's image protocols (flip becomes the animation hub)

Move Kitty / iTerm2 / Sixel rendering into candy-flip. candy-mosaic
becomes a pure single-image lib; candy-flip handles both static and
animated cases.

**Pros:**
- Single home for all "image to terminal" work.
- candy-flip's existing `Player` Model becomes the universal frame
  scheduler.

**Cons (load-bearing):**
- Wrecks the existing architecture. candy-mosaic ships
  3,236 LOC across 21 files specifically *because* protocol output
  is hard and benefits from focused ownership. Bolting that subtree
  onto candy-flip means every single-image consumer of mosaic now
  pulls in GIF decoders, frame schedulers, Player tick machinery, etc.
- candy-flip's namespace is `SugarCraft\Flip\` — that is the GIF-
  viewer namespace from upstream `namzug16/gifterm`. Promoting it to
  "the image lib" mis-credits the upstream.
- Multiple consumers (sugar-dash preview pane, hypothetical glow port)
  want single-image rendering with zero animation overhead. Forcing
  them through a flip dep is worse than the current state.

**Verdict:** rejected — it inverts the lib boundary the wrong way.

### 2.3 Option C — neither depends on the other; animation is intrinsic to mosaic; flip stays a separate consumer (RECOMMENDED)

candy-mosaic grows an `Animation` type and an `AnimationDriver`
**without** consuming `candy-flip`. The `Animation` constructor takes
`ImageSource[]` + per-frame delays (the types it already understands).
candy-flip's `Decoder` already produces an in-cell-space `Frame[]` —
that representation is GIF-viewer specific and does **not** flow into
mosaic. If someone wants to play a GIF through Kitty graphics, they
write the bridge themselves (and the bridge lives in candy-flip, not
mosaic, because `candy-flip` is downstream of `candy-mosaic`'s
dependency closure).

```
candy-flip ── (independent path) ── Decoder.frames ─→ Renderer (truecolor blocks)  ─→ Player Model ─→ Program

candy-mosaic ── (independent path) ── ImageSource[] ─→ Animation ─→ AnimationDriver ─→ Renderer ─→ stdout
                                                                                 (Kitty/iTerm2/Sixel/HalfBlock)

(optional future bridge, in candy-flip, not in mosaic:)

candy-flip ── Decoder.frames → ImageSource[] (re-encode each cell-grid frame as PNG via GD)
            ─→ candy-mosaic.Animation
```

**Pros:**
- No dependency-direction inversion. candy-mosaic stays a foundation-
  tier lib; candy-flip stays an end-user GIF viewer.
- `Animation` is reusable for any frame source: GIF (via bridge),
  MP4 export, procedural generation, video stream. Each non-GIF
  source provides its own `ImageSource[]` adapter and never touches
  candy-flip.
- step 07.15 ships entirely inside candy-mosaic — no composer.json
  edits to add a flip dep, no path-repo closure ripple, no new
  transitive requirements landing on sugar-dash / sugar-charts /
  whoever else consumes mosaic.
- candy-flip's GIF-specific code path keeps its own optimisation
  story (the planned step 09.01 `imagecreatefromstring()` + per-frame
  GCE timing work). That work is *also* useful for the bridge later,
  so it's not wasted.

**Cons:**
- A subtle duplication risk: if both libs evolve a "per-frame ANSI
  output" path, they could drift. This is the same risk the existing
  dual-foundation SSOT pattern (CALIBER entry 38) already manages —
  we document the boundary and accept it.
- A demo that says "play this GIF through the best protocol" needs
  the bridge to be written first. The bridge is ~40 LOC of GD glue
  and is purely additive in step 09.04 (not gating step 07.15).

**Verdict:** **recommended.**

---

## 3. The decision (for step 07.15)

For the step 07.15 blocker specifically, the answers are:

| Question step 07.15 asks | Answer |
|---|---|
| Where does the `Animation` class live? | `candy-mosaic/src/Animation.php` |
| Where does the driver live? | `candy-mosaic/src/AnimationDriver.php` |
| Does `candy-mosaic/composer.json` add `sugarcraft/candy-flip: "@dev"`? | **No.** Drop that bullet from step 07.15's "Modify" list. |
| What does `Animation::__construct()` accept? | `array<int, ImageSource>` (ordered frames) + `array<int, int>` (per-frame delay ms, same length) — or a single shared `int $delayMs` for fixed-cadence animations |
| What does `AnimationDriver` do? | Holds an `Animation` + a `Renderer` + cell W/H. Owns the frame index. Emits `Cmd::tick($delay, fn() => new FrameTickMsg($next))`. On each `update(FrameTickMsg)`, returns `[new driver state, $renderer->delete($prevId) . $renderer->render($nextFrame, w, h) . newId]` |
| Does `KittyRenderer` need new methods? | One small addition: `renderFrame(ImageSource, int $width, ?int $height, int $imageId): string` — wraps `renderWithOptions()` with a stable id so `delete()` can target the same id. Existing `renderWithOptions` is the underlying impl. `Iterm2Renderer`, `SixelRenderer`, `HalfBlockRenderer`, `QuarterBlockRenderer` keep their existing `render()` + `delete()` signatures; the driver just calls them in sequence. |

Step 07.15's draft already gets most of this right — the one bullet
to drop is the composer.json edit.

---

## 4. API contract

### 4.1 `candy-mosaic\Animation` shape

```php
<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * An ordered sequence of frames with per-frame delays.
 *
 * Frame-source-agnostic: the constructor takes ImageSource[], which
 * means a GIF decoder, an MP4 frame extractor, a procedural generator,
 * or a live video feed adapter can all produce an Animation.
 *
 * Mirrors no upstream — there is no equivalent class in
 * charmbracelet/x/mosaic. The driver layer (see {@see AnimationDriver})
 * is the SugarCraft addition that turns this passive value object into
 * a running animation through {@see Renderer}.
 */
final class Animation
{
    /**
     * @param list<ImageSource> $frames     Ordered frames, length >= 1
     * @param list<int>         $delaysMs   Per-frame delay in milliseconds, length == count($frames)
     */
    public function __construct(
        public readonly array $frames,
        public readonly array $delaysMs,
    ) {
        if ($frames === []) {
            throw new \InvalidArgumentException(Lang::t('animation.empty'));
        }
        if (count($frames) !== count($delaysMs)) {
            throw new \InvalidArgumentException(Lang::t('animation.delay_count_mismatch'));
        }
    }

    /** Convenience: same delay for every frame. */
    public static function fixed(array $frames, int $delayMs): self
    {
        return new self($frames, array_fill(0, count($frames), $delayMs));
    }

    public function frameCount(): int { return count($this->frames); }
    public function totalDurationMs(): int { return array_sum($this->delaysMs); }

    public function withFrame(int $index, ImageSource $frame, int $delayMs): self
    {
        // Standard mutate() helper omitted for brevity — same pattern as
        // candy-sprinkles/Style.php.
    }
}
```

Follows the standard SugarCraft pattern: `final`, `readonly` props,
`with*()` via private `mutate()`. Factory methods mirror nothing
upstream (no upstream exists for this surface).

### 4.2 `candy-mosaic\AnimationDriver` shape

```php
<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Mosaic\Renderer\Renderer;

/**
 * Drives an {@see Animation} onto a {@see Renderer}. Implements
 * candy-core's Model contract so it can be embedded directly in a
 * Program, or composed into a parent Model via Composite.
 *
 * Frame timing comes from Cmd::tick(); per-frame delete-and-redraw
 * uses the Renderer::delete() API added in step 07.12.
 */
final class AnimationDriver implements Model
{
    public function __construct(
        public readonly Animation $animation,
        public readonly Renderer  $renderer,
        public readonly int       $cellWidth,
        public readonly ?int      $cellHeight = null,
        public readonly int       $index = 0,
        public readonly bool      $paused = false,
        public readonly int       $imageId = 1,
    ) {}

    public function init(): ?\Closure
    {
        if ($this->paused) return null;
        return Cmd::tick(
            $this->animation->delaysMs[$this->index] / 1000.0,
            static fn(): Msg => new FrameTickMsg(),
        );
    }

    public function update(Msg $msg): array { /* tick → advance index, schedule next tick */ }

    /**
     * For Renderers that support deletion (Kitty, iTerm2), the view
     * emits delete(prev id) + render(current frame) so the screen
     * shows only the current frame. For Renderers that do not
     * (Sixel, HalfBlock, QuarterBlock, Chafa), delete() returns ''
     * and the existing cell grid is naturally overwritten on next
     * render — this is correct per step 07.12.
     */
    public function view(): string
    {
        $delete = $this->renderer->delete((string) $this->imageId);
        $frame  = $this->animation->frames[$this->index];
        return $delete . $this->renderer->render($frame, $this->cellWidth, $this->cellHeight);
    }

    // with*() omitted; same pattern.
}

/** Frame-advance message — internal. */
final class FrameTickMsg implements Msg {}
```

### 4.3 `candy-mosaic\Renderer\KittyRenderer::renderFrame()` addendum

```php
/**
 * Render a single animation frame with a stable image id. The id
 * is what {@see delete()} later targets, so calling delete($id)
 * followed by renderFrame(..., $id) yields a clean per-frame redraw
 * on Kitty-capable terminals.
 *
 * Internally calls {@see renderWithOptions()} with
 * KittyOptions::transmit($id) — the existing surface from step 07.13.
 */
public function renderFrame(ImageSource $image, int $width, ?int $height, int $imageId): string
{
    return $this->renderWithOptions(
        $image,
        $width,
        $height,
        KittyOptions::transmit($imageId),
    );
}
```

`Iterm2Renderer` already returns its OSC 1337 image as a self-
contained sequence; no `renderFrame()` is strictly needed — the
driver calls plain `render()`. `Iterm2Renderer::delete()` already
emits the OSC 1337 Pop (step 07.12). The driver issues delete before
the next render so the stack stays at depth 1.

`SixelRenderer`, `HalfBlockRenderer`, `QuarterBlockRenderer`,
`ChafaRenderer` — no new methods. The driver issues a cursor-up +
cursor-to-column-1 sequence between frames (`\x1b[<rows>A\r`) so
the next frame overwrites the previous block-grid cleanly. That
cursor-juggling lives in `AnimationDriver::view()`, not in the
renderers, so each renderer stays single-frame.

### 4.4 Wire flow

```
            ┌─────────────┐
Program ────► AnimationDriver.init() ── Cmd::tick(delay[0]) ──┐
            └─────────────┘                                    │
                  ▲                                            │
                  │                                            ▼
            ┌─────┴────┐                              ┌────────────────┐
            │ update() │ ◄────────── FrameTickMsg ────│ event loop     │
            └─────┬────┘                              └────────────────┘
                  │
                  ▼ returns [new AnimationDriver, Cmd::tick(delay[next])]
                  │
            ┌─────┴────┐
            │  view()  │ ──► delete(id) . render(frame[n]) ──► stdout
            └──────────┘
```

No new types in candy-core. No new types in candy-sprinkles. The
animation surface is entirely additive to candy-mosaic.

---

## 5. Migration plan

This memo proposes no migration — neither lib gives anything up.

### 5.1 What stays in candy-flip (unchanged by this plan)

- `Decoder` — GIF decode + downsample.
- `Frame` — cell-grid RGB triples.
- `Renderer` — block + density ASCII presets.
- `Player` — interactive GIF viewer Model.
- `TickMsg` — frame-tick message.
- `bin/candy-flip` — viewer binary.

candy-flip's scheduled work in phase 09 (steps 09.01 `imagecreatefromstring()`
+ per-frame timing, 09.02 dithering + transparency, 09.03 adaptive
cell size + frame cache) is **unaffected** by this memo — it lands
on the existing Decoder / Renderer / Player surface. None of those
steps touch candy-mosaic.

### 5.2 What lands in candy-mosaic (new, step 07.15)

- `candy-mosaic/src/Animation.php` — value object (see §4.1).
- `candy-mosaic/src/AnimationDriver.php` — Model + per-frame dispatch
  (see §4.2).
- `candy-mosaic/src/FrameTickMsg.php` — internal Msg (or co-located
  in `AnimationDriver.php`).
- One small method on `KittyRenderer::renderFrame()` (see §4.3).
- One example: `candy-mosaic/examples/animation.php` — animate a 5-
  frame procedurally-generated colour cycle through the probed
  protocol.
- One test: `candy-mosaic/tests/AnimationDriverTest.php` — fixture
  animation with 5 procedurally-generated `ImageSource` frames,
  assert `view()` emits delete + render for each tick, snapshot the
  exact escape sequences for the HalfBlock + Kitty paths.

**No edits to `candy-mosaic/composer.json`.** No new requires. No
new path-repo entries.

### 5.3 The optional GIF→Mosaic bridge (deferred, NOT step 07.15)

For a future "play this GIF through the best protocol" demo, add
a bridge in candy-flip:

```php
// candy-flip/src/MosaicBridge.php — NEW IN A LATER STEP (sketched 09.04 or 09.05)
namespace SugarCraft\Flip;

use SugarCraft\Mosaic\Animation;
use SugarCraft\Mosaic\ImageSource;

final class MosaicBridge
{
    /**
     * Re-encode a candy-flip Frame[] as candy-mosaic ImageSource[]
     * via GD truecolor PNG. Each Frame is its own PNG; the bridge
     * is intentionally lossless (no quantization) so the receiving
     * renderer (Kitty / Sixel / iTerm2) can do its own optimisation.
     *
     * @param list<Frame> $frames
     */
    public static function toAnimation(array $frames, int $delayMs): Animation
    {
        $sources = [];
        foreach ($frames as $f) {
            $sources[] = self::frameToImageSource($f);
        }
        return Animation::fixed($sources, $delayMs);
    }

    private static function frameToImageSource(Frame $f): ImageSource { /* GD truecolor → PNG bytes */ }
}
```

This bridge lives in candy-flip (which already requires
candy-sprinkles + candy-core + ext-gd; it just needs candy-mosaic
added). **candy-mosaic does not depend on candy-flip; candy-flip
depends on candy-mosaic only IF and WHEN the bridge ships.** This
dependency direction is fine — candy-flip is downstream of
candy-mosaic in the foundation graph.

The bridge is out of scope for step 07.15 and out of scope for
this memo's acceptance criteria. It's mentioned only so future
implementers can see the obvious extension and not invent a
different one.

### 5.4 What does NOT happen

- ❌ No Kitty / iTerm2 / Sixel / WezTerm renderer added to candy-flip.
- ❌ No `Frame` class added to candy-mosaic.
- ❌ No animation Model in candy-flip beyond the existing `Player`.
- ❌ No `sugarcraft/candy-flip` require added to candy-mosaic.

---

## 6. Dependencies and ripple

### 6.1 Composer

| Lib | composer.json change for step 07.15 |
|---|---|
| `candy-mosaic` | **none** (no new requires; the animation code is internal and uses only existing deps) |
| `candy-flip` | **none** (step 07.15 does not touch candy-flip) |
| Any consumer of `candy-mosaic` (sugar-dash, sugar-charts, etc.) | **none** (existing constraints already cover the new types) |

### 6.2 Path-repo closure

No new transitive `sugarcraft/*` deps land. The closure stays clean.
`php tools/check-path-repos.php` should pass on the step 07.15 PR
without any edits beyond the lib itself.

### 6.3 PHP extensions

candy-mosaic already requires `ext-gd` for the existing Sixel / Kitty
PNG path. The animation code does not add a new extension dep.

### 6.4 Downstream impact

The 07.15 PR is purely additive to candy-mosaic's public API. No
existing renderer changes shape. No constructor signature changes.
sugar-dash's existing image-preview integration (which uses
`ImageSource` + a `Renderer` directly) is unaffected.

---

## 7. Test plan (for step 07.15, derived from this memo)

Phase-1 (step 07.15) PR ships:

- `candy-mosaic/tests/AnimationTest.php` — Animation value object
  invariants: empty-frames throws, delay-count-mismatch throws,
  `fixed()` builds correct delays, `frameCount()`,
  `totalDurationMs()`, `withFrame()` returns new instance.
- `candy-mosaic/tests/AnimationDriverTest.php` — Driver Model:
  - `init()` returns `Cmd::tick(delay[0])` when not paused
  - `init()` returns `null` when paused
  - `update(FrameTickMsg)` advances index modulo frameCount, returns
    next tick Cmd
  - `view()` emits `delete($id) . render($frame[idx])` in that order
  - Snapshot test against `HalfBlockRenderer` (deterministic; no
    network / probe required)
  - Snapshot test against `KittyRenderer` (deterministic; uses
    `KittyOptions::transmit($id)`)
- `candy-mosaic/tests/Renderer/KittyRendererTest.php` — extend the
  existing test with a `renderFrame()` case that verifies the
  emitted sequence is identical to `renderWithOptions(..., KittyOptions::transmit($id))`.

No candy-flip test changes for step 07.15.

---

## 8. Decision criteria revisited (for future re-litigation)

If a future contributor wants to revisit this split:

- **The split flips to Option A (mosaic requires flip)** only if more
  than one upstream-relevant frame source materialises and the
  re-encode cost of going through `ImageSource[]` becomes the bottleneck.
  Today the only frame source is `candy-flip\Decoder`; the re-encode
  cost is one-shot at load time. Don't bother.
- **The split flips to Option B (flip absorbs mosaic)** only if
  `candy-mosaic` is decommissioned for some other reason (e.g. a
  decision to wrap libchafa exclusively). No such reason exists.
- **A separate "candy-anim" lib** is overkill until at least three
  consumers want the animation surface without wanting the protocol
  surface — none do today.

---

## 9. Sequencing

This memo lands **before** step 07.15. The supervisor's `updates.md`
Blockers section explicitly cites this plan as the precondition.

Step ordering after this memo lands:

1. ✅ **this memo** (step 11.04) ← unblocks 07.15
2. **step 07.15** — candy-mosaic Animation + AnimationDriver +
   KittyRenderer::renderFrame() + tests (no composer.json edits).
3. **step 09.01** — candy-flip `imagecreatefromstring()` + per-frame
   GCE timing (independent of 07.15).
4. **step 09.02** — candy-flip Floyd-Steinberg + transparency
   (independent of 07.15).
5. **step 09.03** — candy-flip adaptive cell size via candy-pty
   `SizeIoctl::query()` + frame cache (independent of 07.15).
6. **(later, optional, NOT yet stepped)** — candy-flip `MosaicBridge`
   that re-encodes `Frame[]` → `ImageSource[]` → `Animation`, behind
   a new `candy-flip` → `candy-mosaic` composer dep. Adds the
   "play this GIF through Kitty" CLI flag to `bin/candy-flip`.

Step 07.15 and steps 09.01–09.03 are independent and can ship in
parallel (different lib roots; no MATCHUPS.md conflict).

---

## 10. Acceptance for this memo

- [x] `ls plans/candy-flip-mosaic-split.md` returns the file.
- [x] Boundary clearly documented (§§1–4) so step 07.15 can proceed
      without re-litigating.
- [x] Explicit answer to step 07.15's "IF the split says so" composer
      bullet: it says **no**. Drop the `sugarcraft/candy-flip: "@dev"`
      line from step 07.15's Modify list. (See §3 table + §6.1.)
- [x] Direction of any future dependency named (candy-flip →
      candy-mosaic, if and when the bridge ships; never the reverse).
- [x] candy-flip's existing phase-09 work (09.01 / 09.02 / 09.03)
      explicitly noted as unaffected (§5.1).
- [x] No code shipped; this is a plan-only deliverable per the step
      file (§Deliverable).

---

## 11. Out of scope for this memo

- The candy-flip phase-09 quality work itself (covered by phase-09
  step files).
- The candy-mosaic phase-07 work outside 07.15 (covered by phase-07
  step files).
- Whether `candy-mosaic` should grow a `videoSource` adapter for
  MP4 / WebM — that is a separate strategic question (no consumer
  asks for it yet).
- Receive-side graphics-protocol decode — covered by
  `plans/candy-vt-graphics.md` (step 11.03), which is a different
  axis entirely.
- A `bin/candy-mosaic-play` binary — out of scope; if anyone wants
  a CLI demo, it lives in `candy-mosaic/examples/animation.php`.
