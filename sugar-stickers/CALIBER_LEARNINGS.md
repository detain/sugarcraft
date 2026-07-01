# sugar-stickers CALIBER_LEARNINGS

## [pattern:ssot-composition] — SSOT composition via wrapper classes

Viewport and Scrollbar are composed from sugar-bits rather than reimplemented.
The sugar-stickers namespace (`SugarCraft\Stickers`) wraps canonical
`SugarCraft\Bits` types as immutable value objects. This keeps sticker-level
customisation options open without duplicating scroll/viewport logic.

- Viewport: `SugarCraft\Stickers\Viewport` wraps `SugarCraft\Bits\Viewport\Viewport`
- Scrollbar: `SugarCraft\Stickers\Scrollbar` wraps `SugarCraft\Bits\Scrollbar\Scrollbar`

Viewport sticky positioning (sticky headers/footers that appear/disappear
as the user scrolls) is implemented in the Viewport wrapper at
`sugar-stickers/src/Viewport.php` via `withStickyHeader()` and
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
- Reset `previousFrame` on window resize, cursor-position-lost, or first paint — diffing across these boundaries produces visual corruption.
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

### TableRenderer $strippedPosToStyle array (memory usage)

`TableRenderer::renderLine()` (lines 119-150) builds a `$strippedPosToStyle` associative array per line to track ANSI SGR styles through the diff algorithm:

```php
$strippedPosToStyle = [];
// ... populated per character in the line
```

Creates approximately 5,000 entries for a 100×50 character buffer. This is necessary for correct ANSI style tracking but represents a known memory usage pattern for large renders. Could be replaced with a more memory-efficient structure if profiling shows it to be a bottleneck.

### Viewport scroll defaults

`Viewport::scrollLeft(int $n = 1)` and `Viewport::scrollRight(int $n = 1)` default to 1 (not 0) for consistency with other navigation methods like `lineUp()` and `lineDown()`. A default of 0 would make no-argument calls a no-op.
