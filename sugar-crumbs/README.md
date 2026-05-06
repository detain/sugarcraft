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
composer require candycore/sugar-crumbs
```

## NavStack Quick Start

```php
use CandyCore\Crumbs\NavStack;

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
use CandyCore\Crumbs\Breadcrumb;

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

## License

[MIT](LICENSE)
