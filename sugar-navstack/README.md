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
> The eight old `SugarCraft\Crumbs\` classes still resolve through deprecated
> `class_alias` shims (autoloaded from `aliases/`); they ship as rename grace
> only — new code should use `SugarCraft\NavStack\`.

sugar-navstack (package and directory; `SugarNavstack` is the title spelling;
`NavStack` is the stack class) — NavStack (navigation stack) and Breadcrumb
components for terminal UIs, for PHP 8.3+.

A TUI that drills into nested screens (file trees, menus, settings pages) needs to
remember *how you got here* so the breadcrumb bar can show it and a click can jump
back. That is the whole job of this library: a stack of visited levels plus a pure
string renderer for it.

## Features

- **NavStack** — hierarchical navigation state with push/pop/peek operations
- **Breadcrumb renderer** — width-aware rendering of the current path, with left-elision and per-crumb mouse zones
- **Shell** — combines NavStack + Breadcrumb into one navigation facade
- **Pure renderer** — breadcrumb output is just strings; works with any TUI framework
- **Small dependency surface** — built on the SugarCraft foundations only: [sugar-core](../sugar-core/) (untrusted-input sanitization, grapheme width, i18n base — facade ready, glyphs host-fed) and [sugar-mouse](../sugar-mouse/) (zone markers for click routing). No third-party packages.

## Install

```bash
composer require sugarcraft/sugar-navstack
```

> Packagist currently serves this package from `dev-master` only (no tagged
> release yet): require it with a root `minimum-stability: "dev"` plus
> `prefer-stable: true` — the SugarCraft-wide convention — or pin
> `sugarcraft/sugar-navstack: "dev-master"`.

The Quick Start and renderer follow runnable demos in this repository:
`php examples/basic.php` drives the stack and the breadcrumb renderer, and
`php examples/navigation.php` walks the push/pop/back-navigation flow. Both
register the Composer autoloader themselves.

## NavStack Quick Start

```php
require __DIR__ . '/../vendor/autoload.php'; // or the host app's autoloader

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

> **Untrusted-display caution** — `current()`, `parent()`, `pop()`, and
> `items()` hand back the **raw** stored title, unsanitized. When titles come
> from outside your code (paths, URLs, user data), print them through the
> public surfaces that sanitize at the boundary — `view()`, `viewHtml()`,
> `Breadcrumb::render()`, or `Breadcrumb::renderTitles()` — or escape the raw
> title yourself before echoing. (`TitleSanitizer` is `@internal` machinery
> behind those surfaces, not a published API.)

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
| `popTo(int $index): self` | Truncate so item `$index` becomes the top; out-of-range clamps silently — a negative index clamps to EMPTY (guard with `max(0, $index)` for root-or-deeper), an oversized one clamps to the current top |
| `current(): ?NavigationItem` / `parent(): ?NavigationItem` | Peek at the top / one below it |
| `depth(): int` / `isEmpty(): bool` / `items(): list<NavigationItem>` | Inspect the stack |
| `updateTop(mixed $data): self` | Replace the top item's payload (no-op when empty) |
| `setItems(array $items): self` | Replace the whole list (used by `Shell` for its immutable ops) |
| `clear(): self` | Remove everything |
| `view(string $separator = ' › '): string` | Plain-text crumb trail; titles sanitized for terminal safety |
| `viewHtml(?callable $href = null, string $label = 'Breadcrumb'): string` | Semantic `<nav>` HTML; last crumb gets `aria-current="page"`, titles HTML-escaped |
| `filter(string $term): self` | New stack of items whose title **or** payload matches a case-insensitive substring (array payloads match when any nested scalar/Stringable value does); payloads nested deeper than 64 levels throw |

### Guards — limits & refusals

Size and shape ceilings, all of them throwing `InvalidArgumentException`
rather than silently truncating:

- `push()` refuses empty/whitespace-only titles and refuses to grow past
  `NavStack::MAX_STACK_DEPTH` (10,000 levels).
- Every item is born through `NavigationItem`'s constructor, which refuses
  titles above `NavigationItem::MAX_TITLE_BYTES` (65,536 raw bytes).
- `filter()` walks array payloads recursively and refuses payloads nested
  deeper than 64 levels (a self-referencing payload would otherwise recurse
  until the process dies).
- `Shell::pushDirectory()` and `Url::parse()` grow stacks from external input
  through the same `push()` guards — hostile path/URL input costs at most a
  loud refusal, never an unbounded render.
- On the renderer side, `Breadcrumb::setSeparator()` refuses empty and
  CR/LF-bearing separators and `setMaxWidth()` refuses negative widths.

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

When `setMaxWidth()` holds a positive width, trails that exceed it drop the
OLDEST crumbs and prefix the `setTruncator()` marker (default `"… "`); the
default `0` DISABLES the cap entirely (negatives throw). If even the single
newest crumb overflows, it is ellipsis-truncated — and when the truncated
crumb alone fills the width, the marker itself is dropped. Above zero,
`setMaxWidth()` is a hard cap.
`renderTitles(list<string>)` renders a bare title list without a stack — titles
handed to it skip the stack's size and emptiness guards, so bound hostile lists
yourself (the trust notes under `Url` spell out why).
`setItemRenderer(fn(NavigationItem, int): ?string)` customizes each crumb
(`null` falls back to the SANITIZED title, not the raw one) — its output is
measured for width INCLUDING SGR escape bytes, so a styled trail elides earlier
than it looks; strip styling before doing visual-width math. All `set*` methods
mutate and return `$this`; `withScanner()` is the one clone-returning method.
`scan()`'s `row:` is 1-based — a `row: 0` is silently coerced to the first row.

### Click-Region Tracking

Attach a `Scanner` from [sugar-mouse](../sugar-mouse/) for mouse-click zone routing:

```php
use SugarCraft\NavStack\Breadcrumb;
use SugarCraft\Mouse\Scanner;

$bc = Breadcrumb::new()->withScanner(Scanner::new());

$output = $bc->render($stack);
// Each crumb is wrapped in a named zone marker keyed by stack position:
// "crumb-0", "crumb-1", … The markers are raw U+E000/U+E001 sentinel bytes
// carried INSIDE the string: hand scan() the untouched output, but STRIP
// the row (SugarCraft\Mouse\Scan::strip($output)) before painting it to a
// terminal — or the sentinels leak onto screen as garbage cells.

// Feed the rendered row to the owned scanner. Pass the terminal's viewport
// width so zones clamp to it: cells past the edge stay UNCLICKABLE even
// when the emulator visually wraps the row. Pass the 1-based row the bar
// is painted on when that is not row 1 — from here on hit()/hitItem()
// take ABSOLUTE terminal coordinates. Both values are host-owned state:
$cols = 80; // current terminal width
$row = 1;   // 1-based row the trail occupies
$bc->scan($output, viewportWidth: $cols, row: $row);

// One-call click dispatcher: resolves a clicked cell straight to the stack
// item whose crumb zone contains it (null when the click misses every
// crumb — off the bar's row, on the "…" elision marker, or outside all
// crumb zones; the gutter separators after non-final crumbs DO belong to
// the crumb on their left, so instinctive mid-gap clicks still route).
$item = $bc->hitItem($stack, $col, $row);
if ($item !== null) {
    // Zone ids carry positions in the stack THAT WAS RENDERED, so the jump
    // cannot misroute even after setMaxWidth() elided older crumbs — pass
    // that same stack here (after filter() the ids are FILTERED positions;
    // resolving them against the original stack would misroute):
    $index = \array_search($item, $stack->items(), true);
    $stack->popTo($index);
}
```

`hit($col, $row)` stays available when you want the `Zone` itself rather
than the item. Failure shapes are asymmetric on purpose: without a scanner,
`hit()`/`hitItem()` return `null` QUIETLY (every lookup dead-ends), while
`scan()` throws `LogicException` — a missing `withScanner()` surfaces loudly
at the scan call, not at the first click. With a scanner attached but the
current render unscanned, both lookups throw too: every `set*` mutation only
takes effect on the NEXT `render()`, and each render clears the scanned flag
— render, then scan, then hit, and never trust zones across a re-render.

Two guarantees make that recipe safe under truncation:

- Zone ids carry the crumb's position in the **full stack**, never a
  renumbered visible-list index — after `"… Settings › Display"`, the left
  crumb is still `crumb-1`, so `popTo($index)` cannot misroute. The
  qualification is which stack: `filter()` returns a FRESH stack and
  renumbers, so a filtered bar's ids are positions in the FILTERED stack,
  and lookups must resolve against it.
- `withScanner()` **copies** the scanner you pass (it is never aliased), so two
  breadcrumbs seeded from one `Scanner` can't overwrite each other's zones.
  Read the owned copy back with `Breadcrumb::scanner()`.

Two more handles serve multi-bar hosts: `setZonePrefix()` renames the id
family (`crumb-N` → `<prefix>-N`, restricted to sugar-mouse's zone-id
alphabet) so zone ids stay distinct when a host merges the zone enumerations
of several breadcrumb widgets, and `visibleIndices()` reports the stack
indices the most recent render actually showed — exactly the ids the current
zones carry — so a jump target can be checked against elision before use.

### Keyboard & accessible navigation

`viewHtml()`'s anchors exist for a reason: given an `$href` callback, every
non-current crumb becomes a real `<a href>` — tab-navigable and discoverable
in a screen reader's link list — while the current crumb stays text bearing
`aria-current="page"`, and the `$label` argument localizes the landmark's
visually-hidden heading. Pair it with `Url::derive()` (the natural `$href`)
after sanitizing the titles yourself, and the same trail serves pointer,
keyboard, and assistive-technology users without extra state.

The terminal path has a modality-neutral jump story too: `Breadcrumb::
visibleIndices()` plus `NavStack::popTo()` is the keyboard route to exactly
the jumps the mouse zones serve — resolve a selected visible index against
the rendered stack and `popTo()` it, and the guard is automatic (a selection
past the end is a no-op). Mouse zones and key handling are two transports
for one navigation operation; neither is privileged.

## Shell

`Shell` is a convenience facade pairing a stack with a renderer through
immutable operations — every `with*` (and `pushDirectory`) returns a NEW
`Shell` and never mutates the receiver. The boundary is thinner than
"immutable" suggests: the new Shell SHARES THE SAME (mutable-by-design)
`Breadcrumb` instance, and its item list carries the same `NavigationItem`
objects (titles and payloads are readonly, so sharing is safe to read) — the
class docblock spells out the full boundary. Usage:

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
runs each **title** through `TitleSanitizer` (terminal controls, zone
sentinels, invisible formatting) at the boundary. But titles are not the only
text that reaches the output, and FOUR legs carry un-sanitized input by
design — they are yours to feed safely:

- `setItemRenderer()`: a custom closure's return string is emitted RAW so
  theme ANSI styling survives (the trust split in the `Breadcrumb` class
  docblock). Sanitize any user data you embed in it yourself — the library's
  `TitleSanitizer` is `@internal` machinery, not a published API.
- `viewHtml($href)`: your callback's URL lands in `href="..."` with only
  quote-escaping applied, so a returned `javascript:` or `data:` URL passes
  straight through — `$href` MUST return a validated absolute path or an
  allow-listed scheme.
- `view($separator)`: the separator string is interpolated RAW (control
  bytes and newlines survive into the output) — treat it as a host-chosen
  constant, never a carrier for user data.
- `Breadcrumb::renderTitles()`: bare title strings skip the
  `NavigationItem` constructor entirely — no size or emptiness guards — so
  sanitize and bound the values before handing them in.

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
no-ops), and `push()` constructs the item itself — a `Closable` of your own
type can therefore NEVER enter the stack. To react to navigation, carry your
screen objects as item `data` and dispatch their hooks yourself, or track
stack changes around your own `push`/`pop` calls. Naming tax: "close" here is
the upstream port's historical term for LEAVING a level — nothing is
destroyed or finalized by it.

## Shared foundations

Mouse hit-testing is self-contained via [sugar-mouse](https://github.com/sugarcraft/sugar-mouse). The `Scanner` class handles zone registration and hit testing locally — no external zone-manager wiring is needed.

## License

MIT

## Credits & inspiration

Design antecedent: [KevM/bubbleo](https://github.com/KevM/bubbleo); SugarCraft is developed as a native PHP project.
