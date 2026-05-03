<img src=".assets/icon.png" alt="candy-sprinkles" width="160" align="right">

# CandySprinkles

![demo](.vhs/dashboard.gif)

PHP port of [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss) —
declarative styling and layout for terminal UIs.

```sh
composer require candycore/candy-sprinkles
```

## Quickstart

```php
use CandyCore\Sprinkles\Style;
use CandyCore\Core\Util\Color;

$banner = Style::new()
    ->bold()
    ->foreground(Color::hex('#ff5f87'))
    ->padding(0, 2)
    ->render('hello, candy world');

echo $banner . "\n";
```

## Layout helpers

```php
use CandyCore\Sprinkles\Layout;
use CandyCore\Sprinkles\Position;

// Side-by-side
echo Layout::joinHorizontal(Position::TOP, $left, $right);

// Top-down
echo Layout::joinVertical(Position::LEFT, $header, $body, $footer);

// Place inside a fixed rectangle
echo Layout::place(40, 10, Position::CENTER, Position::CENTER, 'centered text');
```

## Tables

```php
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Table\Table;
use CandyCore\Sprinkles\Style;

$styled = Table::new()
    ->headers('Name', 'Age')
    ->row('Alice', '30')
    ->row('Bob',   '25')
    ->border(Border::rounded())
    ->styleFunc(static fn(int $row, int $col): Style
        => $row === Table::HEADER_ROW
            ? Style::new()->bold()
            : Style::new())
    ->render();
echo $styled;
```

## Trees & lists

```php
use CandyCore\Sprinkles\Listing\{Enumerator, ItemList};
use CandyCore\Sprinkles\Tree\Tree;

echo ItemList::new()
    ->items(['Apples', 'Bananas', 'Cherries'])
    ->enumerator(Enumerator::roman())
    ->render();

echo Tree::new()
    ->root('Documents')
    ->child(Tree::new()->root('Travel')->child('Italy.md')->child('Japan.md'))
    ->child('Resume.pdf')
    ->render();
```

## Public API

- **`Style`** — every lipgloss prop (~40 `with*()` methods): fg/bg/border
  colours (incl. per-side), bold/italic/underline/strikethrough/faint/blink/
  reverse, padding/margin (1/2/4-arg shorthand + per-side), width/height,
  maxWidth/maxHeight, align (Align/VAlign), inline, transform, tabWidth,
  marginBackground, colorWhitespace. Plus 21 getters and 15 `unset*()`.
- **`Border`** — `normal()`, `rounded()`, `thick()`, `double()`, `block()`,
  `hidden()`. Per-side toggles via `Style::border*`.
- **`AdaptiveColor` / `CompleteColor` / `CompleteAdaptiveColor`** — pick the
  right concrete colour at render time per `ColorProfile` (TrueColor / 256 /
  Ansi) or per dark-vs-light background.
- **`LightDark`** — pick helper for dark-bg vs light-bg colour schemes.
- **`Layout`** — `Place`, `PlaceHorizontal`, `PlaceVertical`,
  `JoinHorizontal`, `JoinVertical`, `Width`, `Height`, `Size` (all
  package-level layout primitives from lipgloss).
- **`Position`** — `TOP / LEFT / CENTER / RIGHT / BOTTOM` floats for layout
  anchors.
- **`Listing\ItemList`** + **`Listing\Enumerator`** — bullet, dash, asterisk,
  arabic, alphabet, roman, romanUpper, decimal, none. Nested sublists +
  per-item style hooks.
- **`Tree\Tree`** + **`Tree\Enumerator`** — default / rounded / ascii
  connector sets; per-section style overrides; custom indenter.
- **`Table\Table`** — `headers` / `row(s)` / `border` / `align` /
  `headerAlign` / `rowAlign` / `styleFunc` / per-side border toggles /
  `width` / `offset`.

## Test

```sh
cd candy-sprinkles && composer install && vendor/bin/phpunit
```

## Demos

### Border styles

![border](.vhs/border.gif)

### Canvas (multi-layer compositor)

![canvas](.vhs/canvas.gif)

### Layout dashboard

![dashboard](.vhs/dashboard.gif)

### List

![list](.vhs/list.gif)

### Style

![style](.vhs/style.gif)

### Table

![table](.vhs/table.gif)

### Tree

![tree](.vhs/tree.gif)

