# SugarVeil Caliber Learnings

## Backdrop Dimming

- Backdrop opacity 0–100 is clamped internally via `max(0, min(100, $opacity))`
- Uses truecolor foreground blend toward black: `\e[38;2;R;G;Bm` where each channel = 255 * (1 - opacity/100)
- Applied per-line before compositing; each dimmed line is wrapped as `\e[38;2;R;G;Bm`…`\e[39m`
- This replaces the old nested `\e[2m` FAINT approach which only produced ~2 visual states; the truecolor blend gives a smooth gradient across 0–100%
- At opacity 0, no dimming is applied (line returned unchanged)
- Applies to all lines uniformly; for backdrop lines (typically terminal-default foreground) the gray blend achieves visible dimming; for styled overlay content the truecolor blends with existing colors

## Animation System

- `AnimationKind` is a pure (unit) enum with three cases: `SLIDE`, `FADE`, `SCALE`
- All three animations consume `SugarCraft\Bounce\Easing\CubicBezier` (honey-bounce)
- Default easing per animation:
  - `Slide` → `CubicBezier::easeOut()`
  - `Fade`  → `CubicBezier::easeInOut()`
  - `Scale` → `CubicBezier::easeOut()`
- Custom easing can be injected via constructor; null falls back to defaults

### Slide Animation

- Returns offset deltas for `xOffset`/`yOffset` rather than modifying the foreground string
- Anchor detection: vertical anchor from `Position` (TOP/BOTTOM), horizontal from `Position` (LEFT/RIGHT)
- Factor = `1.0 - easedProgress` so the overlay slides IN toward its final position as progress increases
- Polarity: at p=0 the foreground is displaced OFF-SCREEN toward the anchored edge (LEFT/TOP → negative offsets, RIGHT/BOTTOM → positive); `CENTER` on an axis contributes no displacement — a CENTER/CENTER slide is a deliberate no-op

### Fade Animation

- Terminal emulators do not support true per-character alpha blending, so `Fade::apply()` renders opacity as a truecolor gray pen (`\e[38;2;L;L;Lm`, L = 255 × opacity/100), the same blend the backdrop dim uses
- The pen is re-applied after every SGR sequence inside a line so content colours / resets cannot punch through; background colours and attributes are untouched
- Opacity 0 → `''` (nothing painted, like Scale at 0); opacity 100 → foreground byte-for-byte

### Scale Animation

- Reveals lines from the center of the foreground outward
- Slide and Scale count lines with `Lines::split()` — the same rule as `Veil::splitLines()` — so a trailing `\n` never adds a phantom row to travel distance or reveal budget
- `round($eased * $totalLines)` clamped to `[1, totalLines]` ensures at least one line shows above 0%

## Z-Index and Stacking

- `zIndex` defaults to `0` — higher values render on top of lower values
- VeilStack sorts ascending by z-index before compositing so each subsequent veil layers on top
- `VeilStack::composite()` feeds each veil's output as the next veil's background — order matters

## Auto-Size

- When `autoSize` is `true`, `composite()` calls `applyBorderChrome()` on the foreground BEFORE measuring dimensions
- This means border chrome is already baked in when computing line width and count
- Without autoSize, foreground dimensions are measured raw then the border is not accounted for

## Border Chrome

- Uses `SugarCraft\Sprinkles\Style::new()->border($border)->render($content)` to wrap content
- `applyBorderChrome()` returns content unchanged when `$this->border === null`
- `withBorder()` accepts a `SugarCraft\Sprinkles\Border` instance (not a raw value)

## Click-Outside Dismiss

- `isClickOutside(MouseMsg $mouse): bool` returns `false` when `clickOutsideDismiss` is `false`
- Requires `scan($rendered)` to have been called first; otherwise throws `\RuntimeException`
- When enabled and scanned, delegates to the self-contained candy-mouse `Scanner` via `hit($x, $y)` — a `null` hit means the click landed outside every zone (i.e., outside the veil)

## Immutable Pattern

- `withBackdrop()` and `withAnimation()` return new instances via private `mutate()`
- `withZIndex()`, `withClickOutsideDismiss()`, `withAutoSize()`, `withBorder()`, and `withPosition()` also return new instances via `mutate()`
- `withoutSession()` returns a copy with a fresh `RenderSession`, used by `VeilStack` to ensure inner compositing always emits full frames
- `animate()` delegates to `composite()` after applying animation transforms
- All state held in `readonly` private properties
- `mutate()` accepts nulls for optional parameters and falls back to `$this->property`
- The scanner is carried forward by `mutate()` (`$scanner ?? $this->scanner`) together with `lastRendered`; dropping it made any `with*()` after `scan()` answer hit-tests from an empty zone set while the unscanned guard stayed muted

## Mouse hit-testing

- Mouse hit-testing is self-contained via candy-mouse `Scanner` — no external Manager. `scan($rendered)` registers zones; `hit($col, $row)` queries them.
- The inert candy-zone `Manager` back-compat shim (`withManager()`/`manager()`) was removed — sugar-veil no longer depends on candy-zone.

## Buffer diffing

- `composite()` holds a `?Buffer $previousFrame`; on each call it diffs against the prior frame and emits only delta ops via `DiffEncoder`.
- Reset `previousFrame` on window resize, cursor-position-lost, or first paint — diffing across these boundaries produces visual corruption.
- **Source:** step-27 ai/buffer-diff-consumers
