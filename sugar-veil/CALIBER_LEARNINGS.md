# SugarVeil Caliber Learnings

## Backdrop Dimming

- Backdrop opacity 0–100 is clamped internally via `max(0, min(100, $opacity))`
- Converts to ANSI SGR dim passes: 0–100 maps to 0–3 `"\x1b[2m"` wrap passes
- Applied per-line before compositing; each wrap adds `\x1b[2m`…`\x1b[0m` around the line
- Dim codes nest cleanly — triple-wrap at 100% gives maximum dimming

## Animation System

- `AnimationKind` is a backed enum with three cases: `SLIDE`, `FADE`, `SCALE`
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

### Fade Animation

- Terminal emulators do not support true per-character alpha blending
- `Fade::apply()` returns the foreground unchanged; `opacity(float $progress)` returns 0–100 for external use
- The easing calculation is still performed so callers can implement their own opacity handling

### Scale Animation

- Reveals lines from the center of the foreground outward
- `round($eased * $totalLines)` clamped to `[1, totalLines]` ensures at least one line shows above 0%

## Immutable Pattern

- `withBackdrop()` and `withAnimation()` return new instances via private `mutate()`
- `animate()` delegates to `composite()` after applying animation transforms
- All state held in `readonly` private properties
