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

## Where it fits

`sugar-navstack` has **zero in-repo consumers today**: it is the
navigation-state *model* — stack depth, crumb identity, generation
stamping — built and tested standalone. The fleet's trail UIs are its
hosts: `sugar-dashboard`'s Nav components, for instance, are one UI
consuming exactly this kind of state; this library renders nothing, the
dashboard decides *how* — neither side owns both halves.

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
> title yourself before echoing. (`TitleSanitizer` behind those surfaces is
> `@internal` machinery — the published door for your own echo sites is
> `NavStack::safeTitle()`, byte-identical to what the surfaces apply.)

`NavStack` is **mutable by design** — `push`, `popTo`, `updateTop`,
`setItems`, and `clear` mutate in place and return `$this` for chaining (`pop`
also mutates in place but returns the removed item, `null` when the stack is
empty — never `$this`). This is a deliberate
exception to the repo-wide `with*()` copy-on-write convention; only
`filtered()` returns a new instance (its old verb-style name `filter()` is a
deprecated forwarder to it).

### NavStack API

| Method | Behavior |
| --- | --- |
| `push(string $title, mixed $data = null): self` | Append an item (mutates) |
| `pop(): ?NavigationItem` | Remove and return the top; `null` when empty |
| `canPop(): bool` | True only when depth > 1 — guards the root crumb |
| `popTo(int $index): self` | Truncate so item `$index` becomes the top; a NEGATIVE index throws (`InvalidArgumentException` — use `popToRoot()` for keep-only-the-root), an oversized one clamps silently to the current top |
| `popToRoot(): self` | Keep only the root item (spelled intent; no-op on an empty stack) |
| `current(): ?NavigationItem` / `parent(): ?NavigationItem` | Peek at the top / one below it |
| `depth(): int` / `isEmpty(): bool` / `items(): list<NavigationItem>` | Inspect the stack |
| `updateTop(mixed $data): self` | Replace the top item's payload (no-op when empty) |
| `setItems(array $items): self` | Replace the whole list (used by `Shell` for its immutable ops) |
| `clear(): self` | Remove everything |
| `view(string $separator = ' › '): string` | Plain-text crumb trail; titles sanitized and the separator control-gated (empty legal) |
| `viewHtml(?callable $href = null, string $label = 'Breadcrumb', ?list<string> $allowSchemes = null): string` | Semantic `<nav>` HTML; last crumb gets `aria-current="page"`, titles HTML-escaped; `$href` receives (item, stack index); `$allowSchemes` gates callback-returned URLs (null = trusted legacy bytes) |
| `filtered(string $term): self` | New stack of items whose title **or** payload matches a case-insensitive (Unicode-folded, `mb_stripos`) substring (array payloads match when any nested scalar/Stringable value does); payloads nested deeper than 64 levels throw. `filter()` is a deprecated forwarder to it |
| `static safeTitle(string $title): string` | The published display-sweep facade — byte-identical to what every library render path applies to titles; use it when echoing an untrusted title outside those surfaces |

### Guards — limits & refusals

Size and shape ceilings, all of them throwing `InvalidArgumentException`
rather than silently truncating:

- `push()` refuses titles that are empty after trimming and sanitizing —
  blank, whitespace-only, or built purely of terminal/sentinel bytes — and
  refuses to grow past `NavStack::MAX_STACK_DEPTH` (10,000 levels).
- `popTo()` refuses negative indices (the historical silent clamp-to-EMPTY
  is closed; `popToRoot()` spells the keep-only-the-root intent).
- Every item is born through `NavigationItem`'s constructor, which refuses
  titles above `NavigationItem::MAX_TITLE_BYTES` (65,536 raw bytes).
- `filtered()` walks array payloads recursively and refuses payloads nested
  deeper than 64 levels (a self-referencing payload would otherwise recurse
  until the process dies).
- `Shell::withDirectory()` (formerly `pushDirectory()`) and `Url::parse()` grow stacks from external input
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

Two ellipsis vocabularies share the renderer on purpose: the configurable
`setTruncator()` marker ANNOUNCES left-elision (whole crumbs dropped, and it
rides outside the clickable zones), while the lone `…` that
`truncateSegment()` appends is a byte-budget CUT marker inside the single
surviving crumb — a mid-word trim, not an elision announcement. The default
stays `…` regardless of the truncator setting (byte stability; the values
merely agree by default).
A depth-0 trail renders `''` unless you opt in: `setEmptyLabel($text)`
paints the label through the same pipeline a crumb runs — sanitizer sweep,
`setMaxWidth()` cap, truncator vocabulary — so a fixed-row bar can tell a
cold app from a deliberately blank one. The label is never a clickable
zone: no item sits behind it, so a scanned empty render carries zero zones
and `hit()`/`hitItem()` answer `null` over its cells (NS-15). The HTML
surfaces take no label state; pass screen-reader copy to `viewHtml()`'s
`$label` instead.
`renderTitles(list<string>)` renders a bare title list without a stack — each
title is bound by `MAX_TITLE_BYTES` here as well (an oversized one throws just
as on the stack path), but the list LENGTH stays the host's duty: N capped
titles still cost N crumbs of width math (the trust notes under `Url` spell
out why).
`setItemRenderer(fn(NavigationItem, int, bool $focused): ?string)` customizes
each crumb (the third argument is the `setFocusedIndex()` flag; 2-arg
closures keep working). A renderer answering the assignment-time smoke call
with anything but string|null is REJECTED there, not at first paint — and
because `null` is always legal, no correct closure can fail the probe
(`null` falls back to the SANITIZED title, not the raw one) — width math runs
on VISIBLE CELLS, so SGR escape bytes are excluded and a styled trail elides
exactly where the plain one does. The honest exception is the cut: when the cap
forces a crumb to be truncated, the cut returns a plain byte-prefix (sugar-core
`Width::truncate`), so the styling of a TRUNCATED crumb does not survive the
cut — uncut crumbs pass through byte-for-byte untouched. All `set*` methods
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

// Feed the rendered row to the owned scanner. The terminal's viewport
// width is REQUIRED (NS-20): zones clamp to it, so cells past the edge
// stay UNCLICKABLE even when the emulator visually wraps the row — the
// nullable-width shim was rejected as a lie of optionality. Pass the
// 1-based row the bar is painted on when that is not row 1 — from here on
// hit()/hitItem() take ABSOLUTE terminal coordinates. Both values are
// host-owned state:
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
    // that same stack here (after filtered() the ids are FILTERED positions;
    // resolving them against the original stack would misroute):
    $index = \array_search($item, $stack->items(), true);
    $stack->popTo($index);
}
```

`hit($col, $row)` stays available when you want the `Zone` itself rather
than the item. The failure shapes form ONE rule table (mirrored in the
`scan()`/`hit()` docblocks): no scanner attached — `scan()` THROWS,
`hit()`/`hitItem()` answer `null` QUIETLY (optional-mouse hosts must not
crash on a stray click; the missing `withScanner()` surfaces loudly at the
scan call, not at the first click); scanner attached but the current render
unscanned — both lookups THROW (stale geometry would misroute); scanned —
geometry live until the next render. Omitting the viewport width at `scan()`
is now a `TypeError`, not a policy (NS-20 made it required).

The `…` elision marker is deliberately NOT a clickable zone — it rides
outside the crumb wrapping, so `hitItem()` can never forge an item for it.
Hosts that want a "click … to expand" affordance query `elisionSpan()`
after `scan()`: `[start, end, row]` in `hit()`'s coordinate space, or null
when no marker is painted on the current scanned render (no scanner, not
yet scanned, nothing elided, or the degenerate corner where the marker
itself was dropped). Treat hits inside the span as an expansion gesture,
not navigation (NS-21). Every `set*` mutation takes
effect only on the NEXT `render()`, and each render clears the scanned flag
— render, then scan, then hit, and never trust zones across a re-render.

Three guarantees make that recipe safe under truncation:

- `hitItem()` refuses CROSS-GENERATION dispatch (NS-08): the render stamps
  the stack's identity and mutation counter, so handing it a stack that has
  changed since render/scan — or a DIFFERENT object, including a
  `filtered()` copy — throws `LogicException` instead of silently
  misresolving the clicked crumb. Clicks outside every crumb stay quiet
  nulls.

- Zone ids carry the crumb's position in the **full stack**, never a
  renumbered visible-list index — after `"… Settings › Display"`, the left
  crumb is still `crumb-1`, so `popTo($index)` cannot misroute. The
  qualification is which stack: `filtered()` returns a FRESH stack and
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

`viewHtml()`'s anchors exist for a reason: whenever the `$href` callback
answers a **string** for a non-current crumb, that crumb becomes a real
`<a href>` — tab-navigable and discoverable in a screen reader's link list;
a `null` answer (or no callback at all) leaves the crumb as plain text — an
*unlinked ancestor*, not a broken link. The current crumb stays text bearing
`aria-current="page"`. The `$label` argument names the landmark via
`nav[aria-label=…]` — that attribute IS the mechanism (there is no
visually-hidden heading). The natural `$href` is `Url::deriveTo()`: the
callback's second argument is the crumb's stack index, so
`fn($item, $i) => Url::deriveTo($stack, $i)` links every ancestor to its
sanitized, percent-encoded prefix path (NS-22's rule, one shared encoder —
the titles need no pre-sanitizing by the host). The same trail then serves
pointer, keyboard, and assistive-technology users without extra state.

The terminal path has a modality-neutral jump story too: `Breadcrumb::
visibleIndices()` plus `NavStack::popTo()` is the keyboard route to exactly
the jumps the mouse zones serve — resolve a selected visible index against
the rendered stack and `popTo()` it, and the guard is automatic (a selection
past the end is a no-op). Mouse zones and key handling are two transports
for one navigation operation; neither is privileged.

The widget can also tell a renderer which crumb is selected:
`setFocusedIndex(?int)` hands the item renderer a third argument,
`bool $focused`, true for exactly the intended crumb (2-arg closures keep
working unchanged). Focus is intent, not truth — an index beyond the stack,
negative, or elided off the left edge is treated as no selection, and
`focusedIndex()` reports what the most recent render actually reflected.
Pair it with `visibleIndices()`: a key handler that sets focus is guaranteed
to see, from one accessor, whether the selection survived the width math.

## Accessibility & terminals

**Screen readers and elision.** The `…` marker is not decoration — on the
terminal path it is the only announcement that older crumbs were dropped.
Assistive tech speaks U+2026 as an ellipsis, and `setTruncator('[more] ')`
says more; `setTruncator('')` stays available as the deliberate silent-drop
escape, but a silent drop is exactly what a keyboard user cannot recover
from by luck.

**Basic terminals.** The shipped glyphs (`›` U+203A separator, `…` U+2026
truncator — NS-13's consts) assume a UTF-8-capable terminal. Under `LANG=C`
or `TERM=dumb`, swap both to ASCII fallbacks:

```php
$bc->setSeparator(' > ')->setTruncator('... ');
```

**Color & motion.** The library itself emits no ANSI and no motion: the
default output is monochrome static text. Any styling rides in through
*your* `setItemRenderer()` / separator payload — honor `NO_COLOR` and
reduced-motion preferences there, not here. Width accounting strips and
re-balances SGR around the geometry (NS-01), so a styled crumb costs
nothing extra to the cell math.

**Direction and language.** The one assistive-tech-spoken string in the
HTML path is `$label` — its default `'Breadcrumb'` is English, and the
localization route is passing your own: `viewHtml($href,
$yourTranslatedLabel)`. The dormant registry (see Localization) stays
dormant here on purpose: a caller-supplied parameter never needs a
built-in catalog. On direction: the default chevron `›` faces the reading
direction, so RTL hosts pass mirrored glyphs to `setSeparator()` /
`setTruncator()`. The library paints left-to-right exactly what the stack
holds — it does not reorder crumbs or mirror glyphs itself, and says so
honestly.

## Shell

`Shell` is a convenience facade pairing a stack with a renderer through
immutable operations — every `with*` (including `withDirectory`; the
verb-named `pushDirectory` is a deprecated forwarder to it, NS-26) returns a NEW
`Shell` and never mutates the receiver. The boundary is thinner than
"immutable" suggests in one way: the item list carries the same
`NavigationItem` objects (titles and payloads are readonly, so sharing is
safe to read). The renderer does NOT leak: every derived Shell gets its own
copy of the (mutable-by-design) `Breadcrumb`, so `setSeparator()`-style
configuration on one Shell is invisible to its ancestors — pass a bar
deliberately through `withBreadcrumb()` when you WANT one shared instance.
The class docblock spells out the full boundary. Usage:

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
$shell = $shell->withDirectory('/home/user/projects');  // appends home, user, projects
```

`withDirectory()` stores the accumulated path (`/home`, `/home/user`, …) as each
crumb's data and does not URL-decode segments. Within one call, `.` segments are
skipped and `..` cancels only same-call segments — a `..`-only path pushes
NOTHING, and climbing out of an existing stack is `withPop()`, not
`withDirectory('..')`. The split is POSIX `/` only — a Windows drive path
arrives as one `C:`-bearing crumb; explode it host-side first (NS-26).
Note `withPop()` empties a depth-1 stack (guard with
`NavStack::canPop()` to keep the root).

## Url

```php
use SugarCraft\NavStack\{Url, NavStack};

$stack = NavStack::new();
$stack->push('Home')->push('My Settings');

Url::derive($stack);            // "/Home/My%20Settings"
Url::parse('/home/settings');   // fresh NavStack: home → settings
```

`derive()` sanitizes each title through the same display sweep the render
surfaces use, THEN rawurlencodes it (RFC 3986 §2 percent-encoding over
UTF-8); `parse()` url-decodes segments. The URL
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
  docblock). Sanitize any user data you embed in it yourself — the published
  door for that is `NavStack::safeTitle()` (byte-identical to the sanitizer
  the library surfaces run; `TitleSanitizer` itself stays `@internal`).
- `viewHtml($href)`: your callback's URL lands in `href="..."` with only
  quote-escaping applied, so a returned `javascript:` or `data:` URL passes
  straight through — `$href` MUST return a validated absolute path or an
  allow-listed scheme, **or** pass the library's `$allowSchemes` gate: a
  list of scheme tokens (case-insensitive) plus `'/'` to admit scheme-less
  paths; a rejected URL renders its crumb as plain text (title kept, anchor
  dropped). `null` (default) keeps the trusted legacy bytes.
- `view($separator)`: the separator is now control-gated (NS-30) — zone
  sentinels are stripped and CR/LF or any other C0/DEL control byte
  throws, so no unbalanced escape rides the gutter into the output. An
  empty separator is legal (crumbs butt together); styled gutters belong
  to `Breadcrumb::setSeparator()`, which width-accounts them.
- `Breadcrumb::renderTitles()`: bare title strings skip the
  `NavigationItem` constructor, so blank titles are NOT refused (a
  whitespace-only title renders a whitespace crumb that still consumes
  cells); the size bound IS enforced per title (`MAX_TITLE_BYTES` —
  oversized throws). Sanitize and mind the list LENGTH before handing
  hostile data in.

## Escape

Breadcrumb titles are never split on the separator (they render as single
crumbs even when they contain it), so escaping is only needed if your pipeline
round-trips a rendered bar back into titles. `Escape` is opt-in:

```php
use SugarCraft\NavStack\Escape;

$title  = Escape::title('Settings › Display');      // "Settings\ › Display"
$back   = Escape::unescape($title);                  // "Settings › Display"
Escape::title('a > b', ' > ');                       // "a\ > b" — custom separator must match the breadcrumb's
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

## Localization

Two glyphs are user-visible — the separator (` › `) and the truncation marker
(`… `) — and both are published consts: `NavStack::SEPARATOR` and
`NavStack::TRUNCATOR` are the single source. `Breadcrumb`'s defaults,
`Escape`'s default separator and the `lang/en.php` registry copy all reference
that pair — the registry copy is headed as such, and `LangCoverageTest` pins
it byte-identical. Everything else visible is titles you supplied.

The `lang/` registry is therefore DORMANT BY POLICY: no render path consults
it. A host that wants localized glyphs reads `Lang::t('separator')` /
`Lang::t('truncator')` itself and feeds `Breadcrumb::setSeparator()` /
`setTruncator()`. The one string assistive technology ever utters from this
package is `viewHtml()`'s `<nav aria-label>` — that is the `$label`
parameter, localized by passing your own string, never from this registry.
Nothing negotiates a locale inside the library, and that is by design: the
punctuation is per-app taste, not per-locale text to translate.

## Shared foundations

Mouse hit-testing is self-contained via [sugar-mouse](https://github.com/sugarcraft/sugar-mouse). The `Scanner` class handles zone registration and hit testing locally — no external zone-manager wiring is needed.

## License

MIT

## Credits & inspiration

Design antecedent: [KevM/bubbleo](https://github.com/KevM/bubbleo); SugarCraft is developed as a native PHP project.
