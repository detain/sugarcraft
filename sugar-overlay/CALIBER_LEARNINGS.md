# SugarOverlay Caliber Learnings

## Backdrop Dimming

- Backdrop opacity 0–100 is clamped internally via `max(0, min(100, $opacity))`
- Uses truecolor foreground blend toward black: `\e[38;2;R;G;Bm` where each channel = 255 * (1 - opacity/100)
- Applied per-line before compositing; each dimmed line is wrapped as `\e[38;2;R;G;Bm`…`\e[39m`
- This replaces the old nested `\e[2m` FAINT approach which only produced ~2 visual states; the truecolor blend gives a smooth gradient across 0–100%
- At opacity 0, no dimming is applied (line returned unchanged)
- Applies to every NON-EMPTY background line uniformly (plain or escape-led) once opacity > 0; the dim pen is the FIRST pen on the line, so a color pen set inside the line wins for its own cells rather than blending — there is no per-cell color mixing. Styled OVERLAY (foreground) content never passes through the backdrop dim

## Animation System

- `AnimationKind` is a pure (unit) enum with three cases: `SLIDE`, `FADE`, `SCALE`
- All three animations consume `SugarCraft\Spring\Easing\CubicBezier` (sugar-spring)
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
- The pen is re-applied after every SGR sequence inside a line so content colors / resets cannot punch through; background colors and attributes are untouched
- Opacity 0 → `''` (nothing painted, like Scale at 0); opacity 100 → foreground byte-for-byte
- Two units, one ramp: animation PROGRESS is the float 0.0–1.0, pen OPACITY is the integer percent 0–100 it produces (`opacity() = round(easedProgress × 100)`); name each one, never "opacity 0…1" or "progress 0–100" (sugar-overlay-056)
- `withAnimation()` carries an instance (`Fade::new($curve)`) and `animate()` feeds content AND the scrim ramp the same `easedProgress()` — a linear scrim against eased content oscillates mid-frame contrast (sugar-overlay-066)

### Scale Animation

- Reveals lines from the center of the foreground outward
- Slide and Scale count lines with `Lines::split()` — the same rule as `Veil::splitLines()` — so a trailing `\n` never adds a phantom row to travel distance or reveal budget
- `round($eased * $totalLines)` clamped to `[1, totalLines]` ensures at least one line shows above 0%

## Z-Index and Stacking

- `zIndex` defaults to `0` — higher values render on top of lower values
- VeilStack sorts ascending by z-index before compositing so each subsequent veil layers on top
- `VeilStack::composite()` feeds each veil's output as the next veil's background — order matters

## Auto-Size

- `withAutoSize()` is a DEPRECATED NO-OP: `composite()` never reads the flag. Border chrome, when configured, applies unconditionally to the foreground before measuring (docs-fix-1023 C1 made the chrome unconditional; the `autoSize` field and `withAutoSize()` ride only for source compatibility — `BorderChromeIsUnconditionalTest` pins the behavior, the deprecated pair documents itself)

## Border Chrome

- Uses `SugarCraft\Style\Style::new()->border($border)->render($content)` to wrap content
- `applyBorderChrome()` returns content unchanged when `$this->border === null`
- `withBorder()` accepts a `SugarCraft\Style\Border` instance (not a raw value)

## Click-Outside Dismiss

- `isClickOutside(MouseMsg $mouse): bool` returns `false` when `clickOutsideDismiss` is `false`
- Requires `scan($rendered)` to have been called first; otherwise throws `\RuntimeException`
- When enabled and scanned, delegates to the self-contained sugar-mouse `Scanner` via `hit($x, $y)` — a `null` hit means the click landed outside every zone (i.e., outside the veil)

## Immutable Pattern

- `withBackdrop()` and `withAnimation()` return new instances via private `mutate()`
- `withZIndex()`, `withClickOutsideDismiss()`, `withAutoSize()`, `withBorder()`, and `withPosition()` also return new instances via `mutate()`
- `withoutSession()` returns a copy with a fresh `RenderSession`, used by `VeilStack` to ensure inner compositing always emits full frames
- `animate()` delegates to `composite()` after applying animation transforms
- All state held in `readonly` private properties
- `mutate()` accepts nulls for optional parameters and falls back to `$this->property`
- The scanner is carried forward by `mutate()` (`$scanner ?? $this->scanner`) together with `lastRendered`; dropping it made any `with*()` after `scan()` answer hit-tests from an empty zone set while the unscanned guard stayed muted

## Mouse hit-testing

- Mouse hit-testing uses the sugar-mouse `Scanner` directly — self-contained, no external Manager. `scan($rendered)` registers zones; `hit($col, $row)` queries them.
- The inert sugar-mouse `Manager` back-compat shim (`withManager()`/`manager()`) was removed. sugar-overlay STILL depends on sugar-mouse (composer requires it; `Veil` imports `Mark`, `Scanner`, `Zone`) — removing the shim removed one class, not the package.

## Buffer diffing

- The diff state (`?Buffer $previousFrame`, the full output, dims) lives on `RenderSession`, which `Veil` holds — NOT on `composite()` itself. Each call diffs against the prior frame and emits only delta ops via `DiffEncoder`; a same-size second `composite()` on the same session returns a DELTA, so zone hit-testing must read `RenderSession::lastFullFrame()` (exposed as `Veil::lastFullFrame()`) — deltas carry no marker envelopes.
- Reset the session (`resetPreviousFrame()` / dimension change) on window resize, cursor-position-lost, or first paint — diffing across these boundaries produces visual corruption.
- **Source:** step-27 ai/buffer-diff-consumers
