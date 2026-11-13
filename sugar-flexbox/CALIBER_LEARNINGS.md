# sugar-flexbox CALIBER_LEARNINGS

## [pattern:ssot-composition] — SSOT composition via wrapper classes

Viewport and Scrollbar are composed from the canonical sugar-forms classes
rather than reimplemented. The sugar-flexbox namespace (`SugarCraft\Flexbox`)
wraps `SugarCraft\Forms` types as immutable value objects. This keeps
sticker-level customisation options open without duplicating scroll/viewport
logic. (FBX-20: the lib formerly routed through the deprecated
`sugar-widgets` class_alias façade; the require and imports now name Forms
directly.)

- Viewport: `SugarCraft\Flexbox\Viewport` wraps `SugarCraft\Forms\Viewport\Viewport`
- Scrollbar: `SugarCraft\Flexbox\Scrollbar` wraps `SugarCraft\Forms\Scrollbar\Scrollbar`

Viewport sticky positioning (sticky headers/footers that appear/disappear
as the user scrolls) is implemented in the Viewport wrapper at
`sugar-flexbox/src/Viewport.php` via `withStickyHeader()` and
`withStickyFooter()`. The sticky middle-offset is clamped to prevent
over-scroll from pulling footer lines into the middle window.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

## [decision:wrap-border-removed] — FlexBox `wrap` and `border` removed from public API

FlexBox advertised `wrap` (items wrap to next line/column) and `border`
(box-draw frame) in its public API and README but never implemented them
in rendering. Per the remediation plan's "prefer removing unimplemented
advertised capabilities over shipping silent no-ops" guidance, both
`withWrap()` / `withBorder()` methods and the corresponding `$wrap` / `$border`
constructor parameters were removed from FlexBox. The README was updated to
remove the "Wrap" bullet. This keeps the API surface honest — no capability
is advertised without a working implementation.

## Buffer diffing

- Table renderer holds a `?Buffer $previousFrame`; on each render it diffs against the prior frame and emits only delta ops via `DiffEncoder`.
- Reset or re-emit in full on first paint, explicit `reset()`, and any change
  to the emitted width or height; on a shrink the diff runs against the
  previous frame on a union canvas so out-of-range cells erase rather than
  ghost. Diffing across these boundaries bare produces visual corruption.
- **Source:** step-27 ai/buffer-diff-consumers

## Performance characteristics

### FlexBox $measured array allocation (GC pressure)

`FlexBox::renderRow()` (lines 114-120) and `FlexBox::renderColumn()` (lines 195-201) create a `$measured` array on every render using `array_map()` with closures:

```php
$measured = \array_map(fn(FlexItem $item): array => [
    'item'   => $item,
    'width'  => $this->measureWidth($item),
    'height' => $this->measureHeight($item),
    'ratio'  => $item->ratio,
    'basis'  => $item->basis,
], $items);
```

This allocates a new array with closures on every render call, creating GC pressure for frequently-updated layouts. Could be cached if items haven't changed, but adds complexity for marginal gain. Document as known pattern — no immediate fix required.

### TableRenderer per-frame Cell minting (memory usage)

`TableRenderer::bufferFromOutput()` rebuilds the full grid every frame. For each row it calls `splitStyledLine()`, which returns the line as parallel arrays of grapheme clusters and their `Style`s (styles are attributed per cluster, never per UTF-8 byte), then mints one `Cell` per cluster — a wide cluster also consumes a continuation cell — and pads the row out to width with blank cells before `Buffer::fromGrid()` assembles the frame.

A 100×50 frame therefore mints roughly 5,000 short-lived `Cell` objects per frame. That is inherent to the cell-buffer diff model: the frame is rebuilt once, the `DiffEncoder` walk then emits only the cells that changed, and the steady-state cost stays proportional to what actually moved on screen.

> Historical note: this section documented a `$strippedPosToStyle` associative array built per line by `renderLine()`. Both were removed in the `splitStyledLine`/`bufferFromOutput` shape described above; the stale entry pointed future edits at code that no longer exists.

### Viewport scroll defaults

`Viewport::scrollLeft(int $n = 1)` and `Viewport::scrollRight(int $n = 1)` default to 1 (not 0) for consistency with other navigation methods like `lineUp()` and `lineDown()`. A default of 0 would make no-argument calls a no-op.

## 2026-10-24 — Accessor-deprecation era (bare twins, ruling 3b)
- **[pattern:accessor-deprecation-era]** `Viewport::getWidth()`/`getHeight()` were renamed to the bare twins `width()`/`height()` (unclaimed — the mutators are `setWidth()`/`setHeight()`, no fluent collision), the legacy names kept as one-line `@deprecated` BC forwarders marked `REMOVED-IN: 2.0.0`; zero behavior change, zero removals this era. The bare twins still delegate through the wrapped widgets viewport's own `getWidth()`/`getHeight()` — the sugar-forms class behind that alias is a separate era (owner judgment pending), do not "fix" it from this lib. `tests/AccessorDeprecationTest.php` pins the era from four directions: live-object BC identity, forwarder shape, a discovery-first `src/` census, and the README table — a third deprecation without all rows goes red.
