<img src=".assets/icon.png" alt="sugar-treelist" width="160" align="right">

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

sugar-treelist — a tree-list view component for terminal UIs, for PHP 8.3+. Renders items with custom prefix/suffix hooks, line wrapping, and cursor-aware styling.

## Features

- **Customisable Prefixer** — generates per-line prefix strings (line numbers, box-drawing borders, tree branches)
- **Customisable Suffixer** — generates per-line suffix strings (status markers, padding)
- **Line wrapping** — items wrap to multiple lines within a fixed viewport width
- **Cursor navigation** — current item highlighted with configurable style
- **Viewport awareness** — respects `Width` × `Height` viewport; `CursorOffset` gap from edges
- **`Stringable` items** — any PHP object with `__toString()` or `Stringable` works as a list item
- **`StringItem` adapter** — wrap plain strings as list items without a class
- **`LessFunc` / `EqualsFunc`** — plug-in sorting and equality comparison
- **Fuzzy matching** — `FuzzyMatch` scores candidates by delegating to sugar-fuzzy's `SmithWatermanMatcher` (SSOT — see [Deprecation & delegation](#deprecation--delegation))
- **Filter state machine** — `withFilterFn()` / `withoutFilter()` with `FilterState` enum tracking (unfiltered / filtering)
- **Delta rendering** — `view()` emits a full frame first, then buffer diffs against the previous frame; `resetPreviousFrame()` forces a repaint; integrates with any TUI framework (see [Buffer diffing](#buffer-diffing))

## Install

```bash
composer require sugarcraft/sugar-treelist
```

## Quick Start

```php
use SugarCraft\TreeList\{Model, StringItem, DefaultPrefixer, DefaultSuffixer};

// Fluent setters RETURN NEW Models — rebind (or chain); instances are immutable.
$model = Model::new()->setWidth(80)->setHeight(24);
$model = $model->addItem(new StringItem('First item'));
$model = $model->addItem(new StringItem('Second item'));
$model = $model->addItem(new StringItem('Third item'));
$model = $model->setPrefixer(new DefaultPrefixer());
$model = $model->setSuffixer(new DefaultSuffixer());

echo $model->view();
// Renders the list with ╭ ├ │ prefixes, line numbers, and > cursor marker
```

## Item Types

**Note:** Item values are rendered through the sugar-core sanitize choke point
(`Sanitize::untrusted`): C0/C1 control bytes, DEL, and any ANSI embedded in the
item text are stripped at render time. The only styles that reach the output
are the SGR parameters you pass to `setLineStyle()`/`setCurrentStyle()` (both
reject non-SGR escapes).

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

## Viewport

Set the rendering viewport dimensions before calling `view()`:

```php
$model = $model->setWidth(80)->setHeight(25);
$model = $model->setCursorOffset(3); // keep 3 lines between cursor and screen edge
```

## Filtering

Attach a filter function to narrow the visible items. The model tracks filter state via the `FilterState` enum:

```php
use SugarCraft\TreeList\{Model, StringItem, FilterState};

// Start with a list (rebind each step — the model is immutable)
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

## Fuzzy Matching

`FuzzyMatch` ranks candidates by Smith-Waterman local-alignment score. It is a
thin delegating shim: the DP core lives in sugar-fuzzy's `SmithWatermanMatcher`
(SSOT), and this class keeps sugar-treelist's own public contract —
`\Stringable` items and input-order tiebreak on equal scores (intentionally
*not* `SmithWatermanMatcher::matchAll()`, whose haystack-ascending tiebreak
would reorder equal-score items):

```php
use SugarCraft\TreeList\FuzzyMatch;

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

This lib is a façade over [sugar-fuzzy](https://github.com/detain/sugarcraft-sugar-fuzzy)
for fuzzy scoring (reciprocally documented in sugar-fuzzy's README):

- `SugarCraft\TreeList\ScoringProfile` is a **`@deprecated` `class_alias`** to
  sugar-fuzzy's `SugarCraft\Fuzzy\ScoringProfile` — the single source of truth.
  It is aliased, not subclassed, so `new FuzzyMatch(ScoringProfile::strict())`
  hands the SSOT matcher an instance it accepts natively.
- `SugarCraft\TreeList\FuzzyMatch` is a **thin delegating shim** over
  `SmithWatermanMatcher` (keeping sugar-treelist's own `\Stringable`-item
  contract and input-order tiebreak, plus the prefix-alignment cap behaviour
  documented above).

**New code should depend on `SugarCraft\Fuzzy\*` directly** and use
`sugar-fuzzy`'s `SmithWatermanMatcher` / `ScoringProfile`; the re-exports here
exist only for back-compat.

## Buffer diffing

The `Model::view()` maintains a `?Buffer $previousFrame` across renders. On each render it
builds the current Buffer, computes `current->diff(previous)` (from
[sugar-cellbuffer](https://github.com/detain/sugarcraft-sugar-cellbuffer)), and emits only
the delta ANSI ops via `DiffEncoder::encode($ops)`. The current frame then replaces
`previousFrame` for the next render. `resetPreviousFrame()` clears the stored frame,
forcing the next `view()` to emit a full repaint (window resize, cursor-position-lost).

**SSH bandwidth + flicker win:** a one-character change in an 80×24 viewport
produces ~8 bytes of delta ops instead of ~1 940 bytes for a full repaint.
Over an SSH session this means far less per-frame data on the wire and
eliminates the full-screen flicker of rewrite-based terminals. The first render
after startup or a resize still emits a full Buffer (no diff possible), so
behaviour is always correct.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

Design antecedent: [treilik/bubblelister](https://github.com/treilik/bubblelister).
