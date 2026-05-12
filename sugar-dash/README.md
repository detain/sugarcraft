# SugarDash

Dashboard TUI library for SugarCraft — column grid layout, framed panels, and more.

## Installation

```bash
composer require sugarcraft/sugar-dash
```

## Quick Start

```php
use SugarCraft\Dash\Grid\StackedGrid;
use SugarCraft\Dash\Grid\Frame;
use SugarCraft\Dash\Grid\ItemOptions;
use SugarCraft\Dash\Grid\Options;

$grid = new StackedGrid(new Options(fitScreen: true));
$grid->addItem(new SomePanel(), new ItemOptions(column: 0));
$grid->addItem(new AnotherPanel(), new ItemOptions(column: 1));

$grid->setSize(80, 24);
echo $grid->render();
```

## Packages

- `SugarCraft\Dash\Grid` — Column grid layout with framed panels
