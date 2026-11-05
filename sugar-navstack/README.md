<img src=".assets/icon.png" alt="sugar-navstack" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-navstack)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-navstack)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-navstack?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-navstack)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarNavstack

> Formerly published as `sugarcraft/sugar-crumbs` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Crumbs\` → `SugarCraft\NavStack\`. <!-- @rename-keep -->

sugar-navstack — NavStack (navigation stack) and Breadcrumb components for terminal UIs, for PHP 8.3+.

A TUI that drills into nested screens (file trees, menus, settings pages) needs to
remember *how you got here* so the breadcrumb bar can show it and a click can jump
back. That is the whole job of this library: a stack of visited levels plus a pure
string renderer for it.

## Features

- **NavStack** — hierarchical navigation state with push/pop/peek operations
- **Breadcrumb renderer** — width-aware rendering of the current path, with left-elision and per-crumb mouse zones
- **Shell** — combines NavStack + Breadcrumb into one navigation facade
- **Pure renderer** — breadcrumb output is just strings; works with any TUI framework
- **Small dependency surface** — built on the SugarCraft foundations only: [sugar-core](../sugar-core/) (untrusted-input sanitization, grapheme width, i18n base) and [sugar-mouse](../sugar-mouse/) (zone markers for click routing). No third-party packages.

## Install

```bash
composer require sugarcraft/sugar-navstack
```

## NavStack Quick Start

```php
use SugarCraft\NavStack\NavStack;

$stack = NavStack::new();

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

> **Untrusted-display caution** — `current()`, `parent()`, and `pop()` hand
> back the **raw** stored title, unsanitized. When titles come from outside
> your code (paths, URLs, user data), print them through `view()`,
> `viewHtml()`, or `Breadcrumb::render()` — all sanitize at the boundary —
> or call `TitleSanitizer::sanitize()` yourself before echoing.

`NavStack` is **mutable by design** — `push`, `popTo`, `updateTop`,
`setItems`, and `clear` mutate in place and return `$this` for chaining (`pop`
also mutates in place but returns the removed item, `null` when the stack is
empty — never `$this`). This is a deliberate
exception to the repo-wide `with*()` copy-on-write convention; only `filter()`
returns a new instance.

### NavStack API

| Method | Behavior |
| --- | --- |
| `push(string $title, mixed $data = null): self` | Append an item (mutates) |
| `pop(): ?NavigationItem` | Remove and return the top; `null` when empty |
| `canPop(): bool` | True only when depth > 1 — guards the root crumb |
| `popTo(int $index): self` | Truncate so item `$index` becomes the top (out-of-range clamps silently) |
| `current(): ?NavigationItem` / `parent(): ?NavigationItem` | Peek at the top / one below it |
| `depth(): int` / `isEmpty(): bool` / `items(): list<NavigationItem>` | Inspect the stack |
| `updateTop(mixed $data): self` | Replace the top item's payload (no-op when empty) |
| `setItems(array $items): self` | Replace the whole list (used by `Shell` for its immutable ops) |
| `clear(): self` | Remove everything |
| `view(string $separator = ' › '): string` | Plain-text crumb trail; titles sanitized for terminal safety |
| `viewHtml(): string` | Semantic `<nav>` HTML; last crumb gets `aria-current="page"`, titles HTML-escaped |
| `filter(string $term): self` | New stack of items whose title **or** payload matches a case-insensitive substring (array payloads match when any nested scalar/Stringable value does) |

## Breadcrumb Rendering

```php
use SugarCraft\NavStack\Breadcrumb;

$bc = Breadcrumb::new();
$bc->setSeparator(' › ');        // default " › "
$bc->setMaxWidth(60);            // truncate if needed

// Render from NavStack
$stack = NavStack::new();
$stack->push('Home');
$stack->push('Settings');
$stack->push('Display');

echo $bc->render($stack);  // "Home › Settings › Display"
```

When the trail exceeds `setMaxWidth()`, the OLDEST crumbs are dropped and the
`setTruncator()` marker (default `"… "`) is prefixed. If even the single newest
crumb overflows, it is ellipsis-truncated — `setMaxWidth()` is a hard cap.
`renderTitles(list<string>)` renders a bare title list without a stack.
`setItemRenderer(fn(NavigationItem, int): ?string)` customizes each crumb
(`null` falls back to the plain title). All `set*` methods mutate and return
`$this`; `withScanner()` is the one clone-returning method.

### Click-Region Tracking

Attach a `Scanner` from [sugar-mouse](../sugar-mouse/) for mouse-click zone routing:

```php
use SugarCraft\NavStack\Breadcrumb;
use SugarCraft\Mouse\Scanner;

$bc = Breadcrumb::new()->withScanner(Scanner::new());

$output = $bc->render($stack);
// Each crumb is wrapped in a named zone marker keyed by stack position: "crumb-0", "crumb-1", …

// Feed the rendered row to the owned scanner. Pass the terminal's viewport
// width (zones then clamp the way the emulator wraps the row) and the
// 1-based row the bar is painted on when that is not row 1 — from here on
// hit()/hitItem() take ABSOLUTE terminal coordinates.
$bc->scan($output, viewportWidth: $cols, row: $row);

// One-call click dispatcher: resolves a clicked cell straight to the stack
// item whose crumb zone contains it (null when the click misses every
// crumb — off the bar's row, on the "…" elision marker, or outside all
// crumb zones; the gutter separators after non-final crumbs DO belong to
// the crumb on their left, so instinctive mid-gap clicks still route).
$item = $bc->hitItem($stack, $col, $row);
if ($item !== null) {
    // Zone ids carry the FULL-stack position, so the jump cannot misroute
    // even after setMaxWidth() elided older crumbs:
    $index = \array_search($item, $stack->items(), true);
    $stack->popTo($index);
}
```

`hit($col, $row)` stays available when you want the `Zone` itself rather
than the item. Both throw `LogicException` if a scanner is attached but the
current render has not been scanned (re-rendering invalidates the geometry —
hits are never served stale).

Two guarantees make that recipe safe under truncation:

- Zone ids carry the crumb's position in the **full stack**, never a
  renumbered visible-list index — after `"… Settings › Display"`, the left
  crumb is still `crumb-1`, so `popTo($index)` cannot misroute.
- `withScanner()` **copies** the scanner you pass (it is never aliased), so two
  breadcrumbs seeded from one `Scanner` can't overwrite each other's zones.
  Read the owned copy back with `Breadcrumb::scanner()`.

## Shell

`Shell` is a convenience facade pairing a stack with a renderer through
immutable operations — every `with*` (and `pushDirectory`) returns a NEW
`Shell` and never mutates the receiver. The boundary is thinner than
"immutable" suggests: the new Shell SHARES THE SAME (mutable-by-design)
`Breadcrumb` instance, and its item list carries the same `NavigationItem`
objects (titles and payloads are readonly, so sharing is safe to read).
See the `Shell` class docblock:

```php
use SugarCraft\NavStack\Shell;

$shell = Shell::new()
    ->withPush('Home')
    ->withPush('Settings');

// Every with* returns a new Shell — discarding the return value is a
// silent no-op. Assign or chain:
$oneDeep = $shell->withPop();       // new Shell, depth 1
$atHome  = $shell->withPopTo(0);    // new Shell truncated to Home
$shell->renderBreadcrumb();         // "Home › Settings" — $shell unchanged

// Directory-driven stacks are EXPLICIT: nothing detects directory changes,
// your host loop (cwd watcher, cd command, file browser) calls this —
// also immutable-returning, so capture the result:
$shell = $shell->pushDirectory('/home/user/projects');  // appends home, user, projects
```

`pushDirectory()` stores the accumulated path (`/home`, `/home/user`, …) as each
crumb's data and does not URL-decode segments. Note `withPop()` empties a
depth-1 stack (guard with `NavStack::canPop()` to keep the root).

## Url

```php
use SugarCraft\NavStack\{Url, NavStack};

$stack = NavStack::new();
$stack->push('Home')->push('My Settings');

Url::derive($stack);            // "/Home/My%20Settings"
Url::parse('/home/settings');   // fresh NavStack: home → settings
```

`derive()` rawurlencodes each title; `parse()` url-decodes segments. The URL
carries TITLES ONLY — item payloads do not travel, so a parsed stack is
title-equivalent to its source, never deep-equal.

Trust: untrusted decoded segments are safe to push because every render path
the LIBRARY owns — `view()`, `viewHtml()`, and the default breadcrumb path —
runs each title through `TitleSanitizer` (terminal controls, zone sentinels,
invisible formatting) at the boundary. The one deliberate exception is
`setItemRenderer()`: a custom closure's return string is emitted RAW so theme
ANSI styling survives (the trust split in the `Breadcrumb` class docblock).
A closure that includes user data in its output must sanitize it first, e.g.
via `TitleSanitizer::sanitize()`.

## Escape

Breadcrumb titles are never split on the separator (they render as single
crumbs even when they contain it), so escaping is only needed if your pipeline
round-trips a rendered bar back into titles. `Escape` is opt-in:

```php
use SugarCraft\NavStack\Escape;

$title  = Escape::title('Settings › Display');      // "Settings \ › Display"
$back   = Escape::unescape($title);                  // "Settings › Display"
Escape::title('a > b', ' > ');                       // custom separator must match the breadcrumb's
```

Both methods default to Breadcrumb's own `' › '` separator; pass the same
separator you gave `setSeparator()` if it differs.

## Lifecycle (Closable)

`NavigationItem` implements `Closable` (`onEnter()` / `onLeave()` / `title()`)
as a **vocabulary, not an event bus**: this library never dispatches those
hooks — your app decides when a level is entered or left and calls them on
whatever items it manages. `NavigationItem` is `final` (and its hooks are
no-ops); to react to navigation, implement `Closable` on your own item type or
track stack changes around your own `push`/`pop` calls.

## Shared foundations

Mouse hit-testing is self-contained via [sugar-mouse](https://github.com/sugarcraft/sugar-mouse). The `Scanner` class handles zone registration and hit testing locally — no external zone-manager wiring is needed.

## License

MIT

## Credits & inspiration

Design antecedent: [KevM/bubbleo](https://github.com/KevM/bubbleo); SugarCraft is developed as a native PHP project.
