<img src=".assets/icon.png" alt="sugar-quickpick" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-quickpick)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-quickpick)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-quickpick?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-quickpick)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarQuickpick

> Formerly published as `sugarcraft/candy-hermit` (renamed 2026-10). <!-- @rename-keep -->

sugar-quickpick — a fuzzy finder / quick-pick overlay for terminal UIs (PHP 8.3+). Renders a filterable list overlay on top of a background view while the background continues to update.

## Install

```bash
composer require sugarcraft/sugar-quickpick
```

## Quick Start — string items

The simplest use case with plain string items:

```php
use SugarCraft\QuickPick\QuickPick;

// Items to filter
$items = ['apple', 'banana', 'cherry', 'date', 'elderberry'];

// Create the overlay
$h = QuickPick::new($items)
    ->setPrompt('> ')
    ->setItemFormatter(fn($item, $selected) => ($selected ? '*' : ' ') . " $item");

// Show and type to filter
$h = $h->show();
$h = $h->type('ba');  // filter by 'ba'

echo $h->view("background content\nmore background");

// Navigate
$h = $h->cursorDown();
$h = $h->cursorUp();

// Select
$selected = $h->selected();  // ?Item — null when nothing is selected; string inputs are coerced to FilteredItem

// Hide
$h = $h->hide();
```

## Quick Start — numbered items with history

Use the `Item` interface + `FilteredItem` for numbered list items and persistent history:

```php
use SugarCraft\QuickPick\QuickPick;
use SugarCraft\QuickPick\FilteredItem;
use SugarCraft\QuickPick\History\FileHistory;

// Create numbered items
$items = [
    new FilteredItem(1, 'apple'),
    new FilteredItem(2, 'banana'),
    new FilteredItem(3, 'cherry'),
];

// Create the overlay with Item[] and a custom filter function
$h = QuickPick::new($items)
    ->setFilterFn(fn($item): bool => $item->number() > 0)  // filter by custom predicate
    ->setPrompt('> ');

// Persist selections to a JSONL file inside your app's own state directory.
// Pick a path you own — never a predictable world-shared location like /tmp.
$history = new FileHistory(getenv('HOME') . '/.myapp/history.jsonl');
if ($choice = $h->selected()) {   // selected() is ?Item — null when nothing is selected
    $history->append($choice);    // save selected item
}
$allItems = $history->all();      // load previous items
```

## Fuzzy ranking (sugar-fuzzy)

By default the filter is a **contiguous, case-folded substring match with an
anchor bias**: a candidate is kept only when the first match starts in the
**first half** of the item text. The rule is measured in Unicode codepoints,
not bytes, so multibyte values (é, 日, emoji) halve where a user would expect.
Worked misses: `ry` does not match `cherry` (first hit sits exactly at the
midpoint), and `e` does not match `apple` (its first hit is past halfway).
These are deliberate — deep matches are usually noise — and `setRanker()` is
the escape hatch: plug in a
[sugar-fuzzy](https://github.com/sugarcraft/sugar-fuzzy) ranker to switch to **true subsequence matching**,
ordering the list by descending fuzzy score and highlighting the scored runes:

```php
use SugarCraft\QuickPick\QuickPick;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

$h = QuickPick::new($items)
    ->setRanker(new SmithWatermanMatcher())   // or any FuzzyMatcher
    ->setMatchStyle("\e[1m")
    ->show()
    ->type('tml');   // matches "terminal" (t·m·l subsequence), substring would miss it

// setRanker(null) restores the default substring filter.
```

A custom `setFilterFn()` predicate still applies on top of the ranker.

## Features

| Feature | Description |
|---------|-------------|
| **Fuzzy filtering** | Filter list items as you type with anchor-aware substring matching, or plug in a sugar-fuzzy ranker via `setRanker()` for true subsequence scoring |
| **Overlay compositing** | Background view renders underneath; overlay chars replace background at specified positions |
| **Background continues updating** | The QuickPick doesn't block the underlying view |
| **Fully styleable** | Custom filter prompt, item format, matching highlight, window dimensions |
| **String renderer** | `view()` returns strings you manage; the only terminal I/O is the opt-in `attachSigwinch()` resize attach (an `ioctl` + `pcntl` handler), which you never have to call |
| **Item interface** | Work with structured `Item` objects instead of raw strings |
| **Persistent history** | `FileHistory` stores items as JSONL for session persistence |
| **Custom filter predicates** | `setFilterFn()` lets you filter items by arbitrary criteria |
| **Border & Style composition** | `withBorder()` and `withStyle()` compose `sugar-style` `Border` and `Style` for window decoration |
| **HelpBar / StatusBar** | `withHelpBar()` and `withStatusBar()` attach keyboard-shortcut and status-line renders below the overlay |
| **SIGWINCH resize** | `withOnResize()` + `attachSigwinch()` forwards terminal resize events via `SignalForwarder::attachSigwinchToFd` |
| **Colorless by design** | The frame itself emits no color — pure Unicode runes. SGR appears only where the host opts in (`setMatchStyle()`, the item formatter, `withStyle()`), so there is nothing for `NO_COLOR` to suppress |

## Architecture

```
SugarCraft\QuickPick
├── QuickPick           — Fuzzy finder overlay (main class)
├── Item                — Interface for filterable items
├── FilteredItem        — Numbered item implementation of Item
├── HelpBar             — Keyboard shortcut summary line
├── StatusBar           — Status message line with optional segments
├── Model               — update()/view() composition contract (package-local)
├── ItemFactory         — string[]/Item[] coercion (used by new()/withItems())
├── Concerns
│   └── Visible         — show()/hide()/isVisible() shared by HelpBar/StatusBar
├── Support
│   ├── AnsiWalk        — escape-preserving codepoint walker (highlighting)
│   └── UntrustedText   — trust-boundary guard for rendered strings
└── History
    └── FileHistory     — JSONL-backed persistent history
```

The `examples/` directory in the repository is package-internal: it exists to
drive the VHS demo tapes and is not shipped as a runnable template.

## API — QuickPick factory

```php
// Create with items (string[] or Item[])
public static function new(array $items = [], ?\Closure $itemFormatter = null): self
```

## API — QuickPick configuration (fluent setters)

Every `set*()`/`with*()` below returns a **clone**; the receiver is never
mutated. Bind the result (`$h = $h->setPrompt('> ');`) or the change is lost.

```php
// Replace items (string[] or Item[]). The filter text is KEPT and the new
// list is re-filtered with it; the cursor resets to 0.
public function withItems(array $items): self

// Configure the filter prompt
public function setPrompt(string $prompt): self

// Set ANSI SGR codes for matched character highlighting (e.g. "\e[33m")
public function setMatchStyle(string $ansiStyle): self

// Set overlay window height in rows INCLUDING header+separator, so the
// visible item count is height − 2 (clamped to a minimum of 2). HelpBar and
// StatusBar rows append BELOW this height.
public function setWindowHeight(int $h): self

// Set overlay width (0 = auto from prompt + items, clamped to the terminal
// column count; a positive value is the OUTER width — a border consumes two
// of those cells)
public function setWindowWidth(int $w): self

// Position the overlay at (x, y). Pure placement: visibility is unchanged
// (use show()/hide()) and negative coordinates clamp to 0.
public function setOffset(int $x, int $y): self

// Custom item formatter:
//   Closure(string $value, bool $isSelected, int $number): string
// It always receives the VALUE STRING (never an Item), even for Item objects,
// plus the selection flag and the item's ordinal. Values arrive already
// guarded (control bytes, escape sequences and forged zone sentinels are
// stripped before you see them); SGR you add survives highlighting.
public function setItemFormatter(\Closure $fn): self

// Custom filter predicate: Closure(Item $item): bool
// Applied after text-based fuzzy filtering; return false to exclude item
public function setFilterFn(\Closure $fn): self

// Plug in a sugar-fuzzy ranker (null restores the default substring filter).
// When set, a non-empty filter ranks items by descending fuzzy score (true
// subsequence matching) and highlighting follows the scored indices.
// Cost note: every keystroke re-scores the WHOLE corpus against the filter —
// tens of ms to hundreds of ms on 20k-item lists (the substring path is
// single-digit ms). Debounce at the host or keep corpora modest.
public function setRanker(?\SugarCraft\Fuzzy\FuzzyMatcher $matcher): self

// Apply a sugar-style Border to the overlay window
public function withBorder(?\SugarCraft\Style\Border $border): self

// Apply a sugar-style Style to the overlay window
public function withStyle(?\SugarCraft\Style\Style $style): self

// Attach a HelpBar (keyboard shortcut summary) below the filter list
public function withHelpBar(?HelpBar $helpBar): self

// Attach a StatusBar (status message line) at the bottom of the overlay
public function withStatusBar(?StatusBar $statusBar): self

// Register a callback invoked after SIGWINCH resize events (cols, rows)
public function withOnResize(?\Closure $callback): self

// Attach SIGWINCH handler via SignalForwarder::attachSigwinchToFd (requires ext-pcntl)
public function attachSigwinch(): bool
```

## API — QuickPick state mutations

```php
// Show the overlay and reset cursor/filter
public function show(): self

// Hide the overlay
public function hide(): self

// Append one or more characters to the filter text and re-filter. Resets
// the cursor to 0. Input is silently refused once the filter holds
// MAX_FILTER_LENGTH (256) codepoints — compare filterText() to detect.
public function type(string $char): self

// Remove the last codepoint from the filter text. Unlike type(), the
// cursor CLAMPS to the new list end instead of resetting — deliberate
// asymmetry: narrowing preserves your place, widening does not.
public function backspace(): self

// Clear filter text and show all items
public function clear(): self

// Move cursor up (default 1)
public function cursorUp(int $n = 1): self

// Move cursor down (default 1)
public function cursorDown(int $n = 1): self

// Jump to first item
public function cursorTop(): self

// Jump to last item
public function cursorBottom(): self
```

## API — QuickPick queries

```php
public function isShown(): bool       // Whether overlay is visible
public function cursor(): int        // Current 0-based cursor index
public function filterText(): string // Current filter string
public function selected(): ?Item   // Currently selected Item (or null)
public function items(): array       // Filtered items (list<Item>)
public function itemCount(): int      // Number of visible (filtered) items
public function allCount(): int      // Total number of items
public function border(): ?\SugarCraft\Style\Border
public function style(): ?\SugarCraft\Style\Style
public function helpBar(): ?HelpBar
public function statusBar(): ?StatusBar
public function onResize(): ?\Closure  // Registered resize callback (cols, rows)
```

## API — QuickPick rendering

```php
// Composite the overlay over a background view and return the result.
public function view(string $backgroundView): string
```

The method was originally declared `View()`. It is now lowercase `view()`,
matching PSR-1 and the Model–Update–View render contract of {@see Model::view()}. Nothing to
migrate: PHP resolves method names case-insensitively, so an existing
`$hermit->View($bg)` call still binds here.

## API — Item interface

```php
use SugarCraft\QuickPick\Item;
use SugarCraft\QuickPick\FilteredItem;

// Item contract
interface Item {
    public function number(): int;  // 1-based ordinal for display
    public function value(): string; // Display text
}

// Numbered item implementation
final readonly class FilteredItem implements Item {
    public function __construct(private int $number, private string $value) {}
    public function number(): int { return $this->number; }
    public function value(): string { return $this->value; }
}
```

## API — FileHistory

```php
use SugarCraft\QuickPick\History\FileHistory;
use SugarCraft\QuickPick\FilteredItem;

// Point at a history file. Nothing touches the filesystem yet — the file is
// created lazily on the first append(), with 0600 permissions regardless of
// the process umask. Use a path you own; on shared hosts, never a predictable
// world-writable location such as /tmp (a planted symlink there is refused
// outright — see below).
$history = new FileHistory(getenv('HOME') . '/.myapp/history.jsonl');

// Optionally jail the file inside a private base directory: paths that
// resolve outside it (via '..', an absolute path elsewhere, a symlinked
// parent — or a symlink at the file itself) are rejected at construction.
$history = new FileHistory('history.jsonl', baseDir: getenv('HOME') . '/.myapp');

// Append an Item (stored as a JSON line: {"n":1,"v":"apple"}\n).
// Fails LOUD — a RuntimeException when the file cannot be opened, locked,
// or fully written, or when the path is a symlink. Nothing is ever
// silently dropped.
$history->append(new FilteredItem(1, 'apple'));

// Read all items back; lines that are not encoded entries (bad JSON,
// scalars, arrays without a value field) are skipped.
$items = $history->all();  // returns list<Item>

// Growth policy: the file grows unbounded by design — there is no built-in
// rotation or cap. Hosts that need one trim with clear() (or rewrite the
// file themselves between sessions).

// Clear the history: the file is truncated in place under an exclusive
// lock (permissions and inode are preserved; no unlink-and-recreate).
$history->clear();

// Get the history file path (absolute and confined when baseDir is set).
$path = $history->path();
```

## API — HelpBar

```php
use SugarCraft\QuickPick\HelpBar;

// Create with optional shortcuts
$bar = new HelpBar([
    '↑↓' => 'navigate',
    'Enter' => 'select',
    'Esc' => 'close',
]);

// Add or remove shortcuts (fluent)
$bar = $bar->withShortcut('Ctrl+R', 'reload')
           ->withoutShortcut('↑↓');

// Show or hide
$bar = $bar->show();
$bar = $bar->hide();

// Render: "↑↓: navigate │ Enter: select │ Esc: close"
echo $bar->render();
```

Shortcut labels are free-form strings rendered verbatim, so prefer word-form entries like 'Up/Down' (the 'Enter' row above already is one) over glyphs like '↑↓' for screen-reader listeners — the example removes the glyph entry with `withoutShortcut()` for exactly this reason.

## API — StatusBar

```php
use SugarCraft\QuickPick\StatusBar;

// Create with optional message
$bar = new StatusBar('3 of 12 items');

// Fluent setters
$bar = $bar->withMessage('Searching...')
           ->withNoMessage()          // clear the message
           ->withSegment('count', '7') // named segment
           ->withoutSegment('count');

// Show or hide
$bar = $bar->show();
$bar = $bar->hide();

// Render: "[count: 7] Searching..."
echo $bar->render();
```

## Wiring keyboard input

QuickPick **reads no input itself**. It holds state and renders; your event
loop decodes terminal keys and forwards them. A conventional mapping:

| Key | Call |
|-----|------|
| printable text | `$h = $h->type($s)` |
| Backspace | `$h = $h->backspace()` |
| Ctrl+U | `$h = $h->clear()` |
| Up / Down | `$h = $h->cursorUp()` / `$h->cursorDown()` (step argument optional; there are no page keys — call with a step) |
| Home / End | `$h = $h->cursorTop()` / `$h->cursorBottom()` |
| Enter (accept) | `$choice = $h->selected()` — `null` means nothing to accept — then `$h = $h->hide()` |
| Esc (cancel) | `$h = $h->hide()` |

`hide()` is not `accept()`: the overlay simply stops rendering; reading
`selected()` **before** hiding is your accept path. The `Model` interface's
`update(QuickPick $hermit, string $msg)` expects exactly this forwarding —
`$msg` strings are host-defined, nothing in this library decodes them.

## SIGWINCH Resize

```php
use SugarCraft\QuickPick\QuickPick;

$h = QuickPick::new($items)
    ->withOnResize(function (int $cols, int $rows): void {
        echo "Terminal resized to {$cols}x{$rows}\n";
    });

// attachSigwinch() returns bool — whether the handler installed — not the
// QuickPick, so call it on its own line rather than continuing the chain.
if ($h->attachSigwinch() === false) {
    // No ext-pcntl, no SIGWINCH, no callback registered, or the size query
    // cannot fire (non-tty STDIN — the handler installs but silently stops
    // reporting on such descriptors): fall back to
    // polling the size yourself, or simply carry on without resize events.
}
```

Signals are process-global. In a long-lived host (PHP-FPM worker, ReactPHP
daemon, test suite) detach them at session teardown with
`SugarCraft\Pty\SignalForwarder::reset()`.

## Model Interface

Implement the `Model` interface to use QuickPick inside a larger Model–Update–View application:

```php
use SugarCraft\QuickPick\QuickPick;
use SugarCraft\QuickPick\Model;

class MyModel implements Model {
    public function update(QuickPick $hermit, string $msg): Model { ... }
    public function view(QuickPick $hermit): string { ... }
}
```

## License

MIT

## Credits & inspiration

Originally inspired by [Genekkion/theHermit](https://github.com/Genekkion/theHermit) — fuzzy finder / quick-fix overlay for terminal UIs; SugarCraft is developed as a native PHP project.
