<img src=".assets/icon.png" alt="sugar-crumbs" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-crumbs)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-crumbs)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcore/sugar-crumbs?label=packagist)](https://packagist.org/packages/sugarcore/sugar-crumbs)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarCrumbs

PHP port of [KevM/bubbleo](https://github.com/KevM/bubbleo) — NavStack (navigation stack) and Breadcrumb components for terminal UIs.

## Features

- **NavStack** — hierarchical navigation state with push/pop/peek operations
- **Breadcrumb renderer** — renders the current navigation path as a clickable-looking breadcrumb string
- **Shell** — combines NavStack + Breadcrumb into a single component
- **Pure renderer** — breadcrumb output is just strings; works with any TUI framework
- **No external dependencies** — pure PHP 8.1+

## Install

```bash
composer require sugarcraft/sugar-crumbs
```

## NavStack Quick Start

```php
use SugarCraft\Crumbs\NavStack;

$stack = new NavStack();

// Push navigation items (each has a title + optional data)
$stack->push('Home');
$stack->push('Settings');
$stack->push('Display');

// Current item
echo $stack->current()->title;   // "Display"
echo $stack->depth();            // 3

// Pop back
$popped = $stack->pop();
echo $popped->title;             // "Display"
echo $stack->current()->title;   // "Settings"
```

## Breadcrumb Rendering

```php
use SugarCraft\Crumbs\Breadcrumb;

$bc = new Breadcrumb();
$bc->setSeparator(' › ');        // default " › "
$bc->setMaxWidth(60);            // truncate if needed

// Render from NavStack
$stack = new NavStack();
$stack->push('Home');
$stack->push('Settings');
$stack->push('Display');

echo $bc->render($stack);  // "Home › Settings › Display"
```

### Click-Region Tracking

Attach a `Manager` from `sugarcraft/candy-zone` to enable mouse-click routing:

```php
use SugarCraft\Crumbs\Breadcrumb;
use SugarCraft\Zone\Manager;

$manager = new Manager();
$bc = (new Breadcrumb())->withZoneManager($manager);

echo $bc->render($stack);
// Each crumb is wrapped in a named APC zone: "crumb-0", "crumb-1", …
// The parent calls Manager::scan() on the output to record bounds,
// then routes MouseMsg through Manager::anyInBoundsAndUpdate().
```

Detach by passing `null`: `(new Breadcrumb())->withZoneManager(null)`.

## Shared foundations

Mouse hit-testing is self-contained via [candy-mouse](https://github.com/detain/sugarcraft-candy-mouse). The `Scanner` class handles zone registration and hit testing locally — external Manager wiring is no longer needed for mouse-only use cases. `withZoneManager()` is retained as a deprecated back-compat wrapper.

## License

[MIT](LICENSE)
