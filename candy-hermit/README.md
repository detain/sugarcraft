<img src=".assets/icon.png" alt="candy-hermit" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-hermit)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-hermit)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-hermit?label=packagist)](https://packagist.org/packages/sugarcraft/candy-hermit)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyHermit

PHP port of [Genekkion/theHermit](https://github.com/Genekkion/theHermit) — fuzzy finder / quick-fix overlay for terminal UIs. Renders a filterable list overlay on top of a background view while the background continues to update.

## Features

- **Fuzzy filtering** — filter list items as you type
- **Overlay compositing** — background view renders underneath; overlay chars replace background at specified positions
- **Background continues updating** — The Hermit doesn't block the underlying view
- **Fully styleable** — custom filter prompt, item format, matching highlight
- **Pure renderer** — no terminal I/O; output is strings you manage

## Install

```bash
composer require sugarcraft/candy-hermit
```

## Quick Start

```php
use SugarCraft\Hermit\Hermit;

// Items to filter
$items = ['apple', 'banana', 'cherry', 'date', 'elderberry'];

// Create hermit with items
$h = Hermit::new($items)
    ->setPrompt('> ')
    ->setItemFormatter(fn($item, $selected) => ($selected ? '*' : ' ') . " $item");

// Show and type to filter
$h = $h->show();
$h = $h->type('ba');  // filter by 'ba'

echo $h->View("background content\nmore background");

// Navigate
$h = $h->cursorDown();
$h = $h->cursorUp();

// Select
$selected = $h->selected();  // currently selected item

// Hide
$h = $h->hide();
```

## Model Interface

Implement the `Model` interface to use Hermit inside a larger Bubble-Tea-style application:

```php
use SugarCraft\Hermit\Model;

class MyModel implements Model {
    public function update(Hermit $hermit, string $msg): Model { ... }
    public function view(Hermit $hermit): string { ... }
}
```

## License

[MIT](LICENSE)
