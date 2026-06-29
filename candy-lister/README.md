<img src=".assets/icon.png" alt="candy-lister" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-lister)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-lister)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-lister?label=packagist)](https://packagist.org/packages/sugarcraft/candy-lister)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyLister

PHP port of [treilik/bubblelister](https://github.com/treilik/bubblelister) — a tree-list view component for terminal UIs. Renders items with custom prefix/suffix hooks, line wrapping, and cursor-aware styling.

## Features

- **Customisable Prefixer** — generates per-line prefix strings (line numbers, box-drawing borders, tree branches)
- **Customisable Suffixer** — generates per-line suffix strings (status markers, padding)
- **Line wrapping** — items wrap to multiple lines within a fixed viewport width
- **Cursor navigation** — current item highlighted with configurable style
- **Viewport awareness** — respects `Width` × `Height` viewport; `CursorOffset` gap from edges
- **`Stringable` items** — any PHP object with `__toString()` or `Stringable` works as a list item
- **`StringItem` adapter** — wrap plain strings as list items without a class
- **`LessFunc` / `EqualsFunc`** — plug-in sorting and equality comparison
- **Fuzzy matching** — `FuzzyMatch` scores candidates via Smith-Waterman local alignment
- **Filter state machine** — `withFilterFn()` / `withoutFilter()` with `FilterState` enum tracking (unfiltered / filtering / filtered)
- **Pure rendering** — outputs ANSI-styled strings; integrate with any TUI framework

## Install

```bash
composer require sugarcraft/candy-lister
```

## Quick Start

```php
use SugarCraft\Lister\{Model, StringItem, DefaultPrefixer, DefaultSuffixer};

$model = Model::new();
$model->setWidth(80)->setHeight(24);
$model->addItem(new StringItem('First item'));
$model->addItem(new StringItem('Second item'));
$model->addItem(new StringItem('Third item'));
$model->setPrefixer(new DefaultPrefixer());
$model->setSuffixer(new DefaultSuffixer());

echo $model->View();
// Renders the list with ╭ ├ │ prefixes, line numbers, and > cursor marker
```

## Item Types

**Note:** Item values are emitted verbatim via `(string) $value`. If item text
originates from a database or other untrusted source, sanitize it before adding
to the model — the list does not perform any escaping.

```php
// Plain string adapter
$model->addItem(new StringItem('Plain string item'));

// Any Stringable object
class MyItem implements \Stringable {
    public function __toString(): string { return 'Formatted item'; }
}
$model->addItem(new MyItem());
```

## Custom Prefixer

```php
use SugarCraft\Lister\{Prefixer, Model};

$model->setPrefixer(new class implements Prefixer {
    public function initPrefixer(
        \Stringable $value, int $currentIndex, int $cursorIndex,
        int $lineOffset, int $width, int $height
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
use SugarCraft\Lister\{Suffixer, Model};

$model->setSuffixer(new class implements Suffixer {
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

Set the rendering viewport dimensions before calling `View()`:

```php
$model->setWidth(80)->setHeight(25);
$model->setCursorOffset(3); // keep 3 lines between cursor and screen edge
```

## Filtering

Attach a filter function to narrow the visible items. The model tracks filter state via the `FilterState` enum:

```php
use SugarCraft\Lister\{Model, StringItem, FilterState};

// Start with a list
$model = Model::new();
$model->setWidth(80)->setHeight(24);
foreach (['apple', 'banana', 'cherry', 'apricot', 'blueberry'] as $f) {
    $model->addItem(new StringItem($f));
}

// Filter to items starting with "a"
$filtered = $model->withFilterFn(
    fn(\Stringable $item) => stripos((string) $item, 'a') === 0
);
// filterState is now FilterState::filtering → FilterState::filtered

echo $filtered->length(); // 2 (apple, apricot)
echo $filtered->View();

// Remove filter and restore original items
$restored = $filtered->withoutFilter();
// filterState is now FilterState::unfiltered
echo $restored->length(); // 5
```

Filter state transitions:

| From | To | Trigger |
|------|----|---------|
| `unfiltered` | `filtering` | `withFilterFn()` called |
| `filtering` | `filtered` | filter applied, items reduced |
| `filtered` | `unfiltered` | `withoutFilter()` called |
| `filtering` | `unfiltered` | filter cleared before result |

## Fuzzy Matching

`FuzzyMatch` implements Smith-Waterman local alignment to rank candidates by relevance to a query string. It is memory-efficient (two-row DP matrix) and penalizes gaps and mismatches while rewarding consecutive character matches:

```php
use SugarCraft\Lister\FuzzyMatch;

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

## Buffer diffing

The `Model::View()` maintains a `?Buffer $previousFrame` across renders. On each render it
builds the current Buffer, computes `current->diff(previous)` (from
[candy-buffer](https://github.com/detain/sugarcraft-candy-buffer)), and emits only
the delta ANSI ops via `DiffEncoder::encode($ops)`. The current frame then replaces
`previousFrame` for the next render.

**SSH bandwidth + flicker win:** a one-character change in an 80×24 viewport
produces ~8 bytes of delta ops instead of ~1 940 bytes for a full repaint.
Over an SSH session this means far less per-frame data on the wire and
eliminates the full-screen flicker of rewrite-based terminals. The first render
after startup or a resize still emits a full Buffer (no diff possible), so
behaviour is always correct.

## License

[MIT](LICENSE)
