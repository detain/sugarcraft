<img src=".assets/icon.png" alt="" width="160">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-treelist)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-treelist)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-treelist?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-treelist)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarTreelist

> Formerly published as `sugarcraft/candy-lister` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Lister\` → `SugarCraft\TreeList\`. <!-- @rename-keep -->

sugar-treelist — a tree-list view component for terminal UIs, for PHP 8.3+. Renders items with custom prefix/suffix hooks (box-drawing gutters by default), whitespace-faithful word wrap, cursor navigation, and host-driven live filtering.

## Features

- **Customizable Prefixer** — generates per-line prefix strings (0-based line numbers, box-drawing borders, tree branches)
- **Customizable Suffixer** — generates per-line suffix strings (status markers, padding)
- **Line wrapping** — items wrap to multiple lines within a fixed viewport width, whitespace-faithfully (indentation and inter-word runs survive)
- **Cursor navigation** — current item highlighted with configurable style
- **Viewport awareness** — fixed-height rendering via `setViewport()`; `setCursorOffset()` controls the context gap kept above the cursor (render-clamped so it can never exceed the viewport)
- **`Stringable` items** — any PHP object with `__toString()` or `Stringable` works as a list item
- **`StringItem` adapter** — wrap plain strings as list items without a class
- **`LessFunc` / `EqualsFunc`** — plug-in sorting and equality comparison
- **Fuzzy matching** — `FuzzyMatch` scores candidates by delegating to sugar-fuzzy's `SmithWatermanMatcher` (SSOT — see [Deprecation & delegation](#deprecation--delegation))
- **Filter state machine** — `withFilterFn()` / `withoutFilter()` with `FilterState` enum tracking (unfiltered / filtering); a filter is a live view, mutations project through it
- **Delta rendering** — `view()` emits a full frame first, then repaints only the rows that changed; `resetPreviousFrame()` forces a repaint (see [Delta rendering](#delta-rendering))

## Install

```bash
composer require sugarcraft/sugar-treelist
```

## Quick Start

```php
use SugarCraft\TreeList\{Model, StringItem, DefaultPrefixer, DefaultSuffixer};

// Fluent setters RETURN NEW Models — rebind (or chain); instances are immutable
// as far as data goes — but view()/resetPreviousFrame() mutate the delta-render
// row memo in place (see Delta rendering).
$model = Model::new()->setWidth(80)->setHeight(24);
$model = $model->addItem(new StringItem('First item'));
$model = $model->addItem(new StringItem('Second item'));
$model = $model->addItem(new StringItem('Third item'));
$model = $model->setPrefixer(new DefaultPrefixer());
$model = $model->setSuffixer(new DefaultSuffixer());

echo $model->view();
// Renders the list with a ╭ ├ ╰ box-drawing gutter, 0-based line numbers, and > cursor marker
```

## Item Types

**Note:** Item values are rendered through the sugar-core sanitize choke point
(`Sanitize::untrustedForMarkedFrames`): C0/C1 control bytes, DEL, any ANSI
embedded in the item text, and mouse-zone sentinels (U+E000/U+E001) are
stripped at render time — item content can forge neither a frame nor a click
zone. The only styles that reach the output are the SGR parameters you pass to
`setLineStyle()`/`setCurrentStyle()` — each is sanitize-then-reject: leading
escape/`[`/`m` decoration bytes are trimmed before the `[0-9;]` gate, so a
style like `m1;3` is repaired to `1;3` and accepted, while anything else that
survives the trim is refused (no escape bytes can ever ride through), and the
`DefaultPrefixer`/`DefaultSuffixer` glyph knobs refuse control bytes at init.
The library emits no colour of its own: all styles are caller-supplied — pass
empty style strings to emit no colour (sugar-treelist neither reads nor acts on
the `NO_COLOR` variable).

```php
// Plain string adapter
$model = $model->addItem(new StringItem('Plain string item'));

// Any Stringable object
class MyItem implements \Stringable {
    public function __toString(): string { return 'Formatted item'; }
}
$model = $model->addItem(new MyItem());
```

## Custom Prefixer

`DefaultPrefixer` renders the box-drawing gutter and has public knobs (all
glyph strings are validated at init — control bytes and malformed UTF-8 are
refused): `$firstSep` (top corner, default `╭`), `$separator` (middle join,
default `├`), `$lastSep` (bottom corner, default `╰`), `$separatorWrap`
(continuation rows of a wrapped item, default `│`), `$currentMarker` (`>`),
`$emptyMarker` (a single space on non-cursor rows), `$number` (toggle the
number column — its field width is derived from the item count, so columns
stay aligned past nine items), `$numberRelative` (number as each item's
unsigned distance from the cursor: 2 1 0 1 2), and `$prefixWrap` (continuation
rows use `$separatorWrap` instead of the join glyph). The join glyph follows
the item's **position in the list**, so the gutter reads as a tree — the list
itself stays flat: tree structure is yours to encode as leading whitespace in
the item text, which survives the whitespace-faithful wrapper verbatim.
`DefaultSuffixer::$currentMarker` (default `<`) marks the cursor row.

Guide the cursor with text, not colour alone. Keep a marker glyph
(`$currentMarker`, or your suffixer's) so position survives monochrome
terminals and screen readers — relying on `setCurrentStyle()`'s colour by
itself is a WCAG 1.4.1 (use of colour) warning; if you do style-only, add a
redundant cue. Passing `''` to any glyph knob drops that decoration (control
bytes are refused loudly, empty is not). `setLineStyle('')` +
`setCurrentStyle('')` + `lines()` is the full plain-text mode: `lines()` never
styles and never deltas, so it is the right reader for assistive and
scripted-output hosts.

To implement the contract yourself:

```php
use SugarCraft\TreeList\{Prefixer, Model};

$model = $model->setPrefixer(new class implements Prefixer {
    public function initPrefixer(
        \Stringable $value, int $currentIndex, int $cursorIndex,
        int $lineOffset, int $width, int $height, int $totalItems
    ): int {
        return 0; // no prefix width
    }
    public function prefix(int $currentLine, int $totalLines): string {
        return $currentLine === 0 ? '• ' : '  ';
    }
});
```

## Custom Suffixer

```php
use SugarCraft\TreeList\{Suffixer, Model};

$model = $model->setSuffixer(new class implements Suffixer {
    public function initSuffixer(
        \Stringable $value, int $currentIndex, int $cursorIndex,
        int $lineOffset, int $width, int $height
    ): int {
        return 0;
    }
    public function suffix(int $currentLine, int $totalLines): string {
        return '';
    }
});
```

Returning `''` from `suffix()` means *no suffix and no padding* on that line —
the reserved width is still subtracted from the content budget on every line,
but only a non-empty return is right-aligned into the column. Reserve the
width your widest real suffix needs (an over-wide return is not clamped; it
follows the line past the reservation).

## Viewport

Set the rendering viewport dimensions before calling `view()`:

```php
$model = $model->setViewport(80, 25);     // or setWidth(80)->setHeight(25)
$model = $model->setCursorOffset(3);
$model = $model->setLineOffset(5);
$model = $model->setWrap(2);
```

| Setter | What it controls |
| --- | --- |
| `setCursorOffset(n)` | context gap kept **above** the cursor at the viewport top (render-clamped to `height - 1`) |
| `setLineOffset(n)` | context value reported to `initPrefixer()`/`initSuffixer()` for relative markers — not the viewport anchor, not a scroll budget |
| `setWrap(n)` | per-item displayed-row cap — `0` means **no per-item cap**; rows still stay bounded by the viewport height |

Geometry setters reject negative values and dimensions beyond the
`MAX_DIMENSION` cap; the `MAX_TOTAL_CELLS` area cap applies to the viewport
(width × height) only. Zero is accepted at set time for every knob; a zero
viewport dimension is refused loudly at render, while zero
`cursorOffset`/`lineOffset` are legal steady states and zero `wrap` means no
per-item cap. `setCursorOffset` is additionally **clamped at
render** to `height - 1`, so it can never ask for more context rows than the
viewport has. Scrolling uses a scroll-end clamp: near the bottom of the list
the window slides back so the frame stays full and the cursor row is always
inside it — short lists simply show everything.

## Filtering

Attach a filter function to narrow the visible items. The model tracks filter state via the `FilterState` enum:

```php
use SugarCraft\TreeList\{Model, StringItem, FilterState};

// Start with a list (rebind each step — setters return new Models;
// only the delta-render frame memo is mutated in place by view())
$model = Model::new()->setWidth(80)->setHeight(24);
foreach (['apple', 'banana', 'cherry', 'apricot', 'blueberry'] as $f) {
    $model = $model->addItem(new StringItem($f));
}

// Filter to items starting with "a"
$filtered = $model->withFilterFn(
    fn(\Stringable $item) => stripos((string) $item, 'a') === 0
);
// filterState is now FilterState::filtering

echo $filtered->length(); // 2 (apple, apricot)
echo $filtered->view();

// Remove filter and restore original items
$restored = $filtered->withoutFilter();
// filterState is now FilterState::unfiltered
echo $restored->length(); // 5
```

Filter state transitions:

| From | To | Trigger |
|------|----|---------|
| `unfiltered` | `filtering` | `withFilterFn()` called (filter applied, items reduced) |
| `filtering` | `unfiltered` | `withoutFilter()` called (original items restored) |

A filter is a **live view**, not a one-time snapshot: while filtering,
`addItem()`/`addItems()`/`addItemsFromArray()`/`removeItem()`/`clear()`/`sort()`
all act on the authoritative list and the projection is re-derived immediately
— added items appear when (and only when) the predicate accepts them, removals
stick, and lifting the filter with `withoutFilter()` reveals exactly the
mutations that happened underneath. `removeItem()` re-anchors the cursor by
item **identity**: it stays on the same logical item it was on (clamping only
when that item was itself removed).

## Fuzzy Matching

`FuzzyMatch` ranks candidates by Smith-Waterman local-alignment score. It is a
thin delegating shim: the DP core lives in sugar-fuzzy's `SmithWatermanMatcher`
(SSOT), and this class keeps sugar-treelist's own public contract —
`\Stringable` items and input-order tiebreak on equal scores (intentionally
*not* `SmithWatermanMatcher::matchAll()`, whose haystack-ascending tiebreak
would reorder equal-score items):

```php
use SugarCraft\TreeList\FuzzyMatch;
use SugarCraft\TreeList\StringItem;

$matcher = new FuzzyMatch();

// Score a single candidate
$score = $matcher->score('april', 'apricot'); // 27 (consecutive match bonus applied)

// Filter and rank a list of items
$items = [
    new StringItem('April'),
    new StringItem('September'),
    new StringItem('June'),
    new StringItem('July'),
    new StringItem('November'),
];

$results = $matcher->match('sep', $items);
// Returns [ [StringItem('September'), 19], ... ] sorted by score descending
```

**Candidate cap (DoS guard):** sugar-fuzzy caps input lengths
(`SmithWatermanMatcher::DEFAULT_MAX_QUERY_LENGTH` /
`DEFAULT_MAX_CANDIDATE_LENGTH`). Over-cap input is aligned on its **prefix**
on the same score scale as every other item, so a very long candidate ranks
consistently with short ones. The trade-off: **text that exists only past the
candidate cap is not searched.**

## Deprecation & delegation

This lib is a façade over [sugar-fuzzy](https://github.com/sugarcraft/sugar-fuzzy)
for fuzzy scoring (reciprocally documented in sugar-fuzzy's README):

- `SugarCraft\TreeList\ScoringProfile` is a **`@deprecated` `class_alias`** to
  sugar-fuzzy's `SugarCraft\Fuzzy\ScoringProfile` — the single source of truth.
  It is aliased, not subclassed, so `new FuzzyMatch(ScoringProfile::strict())`
  hands the SSOT matcher an instance it accepts natively.
- `SugarCraft\TreeList\FuzzyMatch` is a **thin delegating shim** over
  `SmithWatermanMatcher` (keeping sugar-treelist's own `\Stringable`-item
  contract and input-order tiebreak, plus the prefix-alignment cap behaviour
  documented above). Its `?ScoringProfile` constructor parameter and
  `withProfile()` exist for migration only — because the alias is the same
  class, sugar-fuzzy's own profile passes straight through; new code depends
  on `SugarCraft\Fuzzy\` directly.

**New code should depend on `SugarCraft\Fuzzy\*` directly** and use
`sugar-fuzzy`'s `SmithWatermanMatcher` / `ScoringProfile`; the re-exports here
exist only for back-compat.

## Delta rendering

`Model::view()` keeps a memo of the rows it emitted last time
(`?list<string> $previousRows` — styled row text, one entry per emitted frame
line). The first render emits a full frame; each later render compares rows one
by one and repaints **only the changed rows**, each addressed absolutely: one
cursor-position before the row's own bytes and one erase-line after them. Frame
lines are joined and terminated with bare `\n` (no carriage return), relying on
the host terminal's `ONLCR` mapping to wrap each line — the same convention the
full frame uses. Unchanged
rows cost nothing; a changed row costs its own content plus a fixed addressing
overhead. A resize, a filter transition, `resetPreviousFrame()`, or a failed
frame clears the memo and forces the next `view()` to emit a full repaint.

Because rows are written whole, styled rows round-trip exactly — wide glyphs
are never split and SGR bytes never land in the grid as literal glyph cells.
(The earlier cell-granular design projected rows into sugar-cellbuffer and
diffed them; its style transport could not carry palette SGR spellings, so it
corrupted styled frames after the first repaint. That dependency was retired
with the row-granular rewrite.)

**SSH bandwidth win:** over a slow link only changed rows ride the wire, which
greatly reduces per-frame data and the full-screen flicker of rewrite-based
rendering.

**Lineage caveat:** the memo is per-instance render state **carried through
clones**. If you fork a model and later switch which fork's frames you paint
onto the same terminal region, call `resetPreviousFrame()` on the fork you are
about to render — otherwise it diffs against its sibling's last frame. Same
rule after any out-of-band write to the list's screen area.

## Cursor & navigation API

Beyond the fluent setters shown above, the navigation surface is:

- `setCursor(int $index)` — clamped to `0..count-1` (an out-of-range value lands on the nearest end; the setter always returns a fresh clone).
- `cursorUp(int $n = 1)`/`cursorDown(int $n = 1)` and the page helpers `cursorPageUp(int $pages = 1)`/`cursorPageDown(int $pages = 1)` — movement **saturates** at the list ends (`cursorDown(PHP_INT_MAX)` lands on the last item, never wraps or overflows). A page spans `height` **items**, not displayed lines — a list of multi-line items pages visually faster. `cursorToStart()`/`cursorToEnd()` jump to the ends.
- `cursorIndex()` — the cursor's **item** index (not a row position: with multi-line items, rows and items differ). `cursorItem()` — the cursor item's `\Stringable`; throws `RuntimeException` on an empty list. `tryCursorItem()` returns `null` instead, matching the `tryItemAt()`/`itemAt()` pair.
- `length()`, `isEmpty()`, `itemAt(int)`, `tryItemAt(int)`, `find(\Stringable)`, `itemIds()` — read-only item access (indices address the **visible** projection, so they follow the active filter).
- `lines()` — the laid-out frame rows without any delta bookkeeping; throws `RuntimeException` with a localized message (see below) on an empty list or zero viewport. `linesStream()` is a generator-shaped wrapper over the same eager computation — it interleaves nothing.
- `view()` — full frame or row delta as described above; the only method that mutates in place (its render memo), which is why the immutability contract is scoped to item/configuration data. Unlike `lines()`, `view()` **never throws**: a `Throwable` raised mid-render drops the memo and the frame becomes the sanitized exception message on a single line (`Sanitize::untrustedForMarkedFrames` is the choke, so a failure surfaces as readable text, never as a live escape channel); pass a `LoggerInterface` as `view()`'s optional parameter to receive the same failure at `warning` level. `lines()` keeps the opposite polarity and throws.

## Localisation

The two refusal strings raised by `lines()` — `list.no_items` (which
`cursorItem()` reuses for its own empty-list refusal) and
`list.zero_viewport` — are live `Lang::t()` lookups across the 16 shipped
locales under `lang/`. Locale selection is the host application's concern; the
renderer only formats through `SugarCraft\TreeList\Lang`.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

Design antecedent: [treilik/bubblelister](https://github.com/treilik/bubblelister).
