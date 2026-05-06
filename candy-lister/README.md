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
- **Pure rendering** — outputs ANSI-styled strings; integrate with any TUI framework

## Install

```bash
composer require candycore/candy-lister
```

## Quick Start

```php
use CandyCore\Lister\{Model, StringItem, DefaultPrefixer, DefaultSuffixer};

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
use CandyCore\Lister\{Prefixer, Model};

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
use CandyCore\Lister\{Suffixer, Model};

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

## License

[MIT](LICENSE)
