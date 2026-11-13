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

> **Stability:** this package requires its `sugarcraft/` siblings at
> `dev-master`. A Composer project left on the default `stable` minimum-stability
> cannot resolve them — add `"minimum-stability": "dev", "prefer-stable": true`
> to the consuming root (or pin the released `sugarcraft/*` tags once cut). The
> monorepo publishes all libs together, so sibling versions always line up.

## Requirements

- **PHP 8.3+** and **ext-ffi — required at install time.** The library
  hard-requires `sugarcraft/sugar-pty`, whose own manifest hard-requires
  `ext-ffi`, so `composer require` fails on an FFI-less PHP build even
  though quickpick touches exactly one sugar-pty symbol,
  `SignalForwarder`, and only when you call `attachSigwinch()`. An ordinary
  `view()`-only integration never executes FFI — the floor is a packaging
  fact, not a runtime behavior of the renderer.
- **ext-pcntl** — only for `attachSigwinch()`, the opt-in SIGWINCH
  re-render attach. Without it `attachSigwinch()` returns `false` and
  everything else keeps working. On a resized terminal the auto width then
  re-probes only when some width-affecting mutation (`setPrompt`,
  `withItems`, `type()`, …) drops the memoized computed width — force a
  re-measure by applying one, or build a fresh instance.

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
// FileHistory creates the file, never its parent directories: make the
// directory first, or the first append() throws RuntimeException.
$historyPath = getenv('HOME') . '/.myapp/history.jsonl';
@mkdir(\dirname($historyPath), 0700, true);
$history = new FileHistory($historyPath);
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
Worked misses: `ry` does not match `cherry` (its first hit starts past the
midpoint — the accepted window is positions `[0, len/2)`, so a hit beginning
exactly at the halfway point is already rejected), and `e` does not match
`apple` (its first hit is past halfway).
When the bias hides matches that plain substring would have kept, the frame
says so: a `N hidden by anchor bias` row counts what the rule dropped.
These are deliberate — deep matches are usually noise — and `setRanker()` is
the escape hatch: plug in a
[sugar-fuzzy](https://github.com/sugarcraft/sugar-fuzzy) ranker to switch to **true subsequence matching**,
ordering the list by descending fuzzy score and highlighting the scored runes.
A ranker alone only REORDERS — emphasis is a separate switch: nothing is
painted until you also set `setMatchStyle()` with an SGR string — the example
below ships both switches:

```php
use SugarCraft\QuickPick\QuickPick;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

// Self-contained corpus: 'terminal' is a t·m·l subsequence, no other item has all three runes.
$items = ['terminal', 'editor', 'compiler'];

// requireFullQuery: true is what makes "no other item has all three runes"
// the rule that runs: by default the matcher also keeps PARTIAL subsequence
// scores, so 'editor' (t) and 'compiler' (m, l) would survive at 'tml' too.
$h = QuickPick::new($items)
    ->setRanker(new SmithWatermanMatcher(requireFullQuery: true))   // or any FuzzyMatcher
    ->setMatchStyle("\e[1m")
    ->show()
    ->type('tml');   // keeps only "terminal"; the default substring filter would miss it

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
| **String renderer** | `view()` returns strings you manage; the only terminal I/O is the STDIN geometry probe the auto-width path runs on a width-cache miss plus the screen-clamp probe every render applies (see `setWindowWidth` — a width that fits the screen renders identically everywhere) and the opt-in `attachSigwinch()` resize attach (an `ioctl` + `pcntl` handler), which you never have to call |
| **Item interface** | Work with structured `Item` objects instead of raw strings |
| **Persistent history** | `FileHistory` stores items as JSONL for session persistence |
| **Custom filter predicates** | `setFilterFn()` lets you filter items by arbitrary criteria |
| **Border & Style composition** | `withBorder()` and `withStyle()` compose `sugar-style` `Border` and `Style` for window decoration |
| **HelpBar / StatusBar** | `withHelpBar()` and `withStatusBar()` attach keyboard-shortcut and status-line renders below the overlay |
| **SIGWINCH resize** | `withOnResize()` + `attachSigwinch()` forwards terminal resize events via `SignalForwarder::attachSigwinchToFd` |
| **Colorless by design** | The frame itself emits no color — pure Unicode runes (the default row decoration is the `●` cursor dot plus plain ordinals, and `setItemFormatter()` replaces it wholesale). SGR appears only where the host opts in (`setMatchStyle()`, the item formatter, `withStyle()`) — and once opted in, those escapes go out unconditionally: honoring `NO_COLOR`/`CLICOLOR` is the host's job (skip the opt-ins on dumb terminals, or prefer the two-channel emphasis of runes that needs no color) |

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

The raw constructor additionally accepts a third optional `?\Closure $filterFn`
predicate — a way to seed a custom filter without a fluent call. `new()` does
not expose it; `setFilterFn()` (configuration below) is the fluent path.

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
// visible item count is height − 2 (clamped to a minimum of 2) — minus one
// more row when the overflow marker is needed. Two chrome states live in
// the item area and consume an item row: the marker `  ↕ showing %d-%d of
// %d` (1-based visible window, on overflow) and, when a non-empty filter
// matches nothing, `  no matches`. Both are pad-only — never intentionally
// truncated — so on a frame narrower than the text they keep their full
// length: over an empty background they spill past where the right border
// would sit, and over a background-backed row the spill pushes that row's
// surviving tail rightward instead of overwriting it. Truncating them to
// the frame width would be a behavior change and is deliberately not done.
// HelpBar and StatusBar rows append BELOW this height. The value is a
// MAXIMUM: each frame also budgets the live terminal's rows — the windowed
// region shrinks to `min(h, rows − reserved chrome)` (border and visible
// help/status rows come off the screen first; a two-row minimum frame is
// the floor), so a tall frozen window never spills past a short terminal
// (-029, shrink-only: growing back recovers up to h, never past it).
public function setWindowHeight(int $h): self

// Set overlay width. 0 (default) = auto: the computed prompt+items width is
// clamped to min(computed, cols) — an auto frame never exceeds the live
// terminal (-059: the old max(20, cols) ceiling wrapped real sub-20-column
// screens; a degenerate zero-width probe is absorbed by the 80-column
// leg of the throw-net instead). Every width-cache miss on the auto path
// PROBES the terminal through sugar-core's Tty ladder: ioctl(TIOCSWINSZ)
// on STDIN, then the COLUMNS/LINES environment, then /dev/tty, then a
// stty shell-out — and the ladder's LAST answer is Backend::LAST_RESORT_SIZE,
// 200×60. A piped run therefore renders at whatever that ladder reports
// (commonly the 200×60 last resort on a headless runner), NOT at a phantom
// 80×24: QuickPick's own 80×24 is the throw-only net, reached when the
// ladder raises or answers a zero dimension. The cache invalidates on every
// mutation that can change the width (filter edits, item/prompt swaps), so
// chained auto-mode builders re-probe per affected operation. A positive
// value is the OUTER width, negative inputs clamp to 0/auto, and it is
// clamped down to the live columns at render time (-027: an over-cols width
// used to compose wrapping rows); a border consumes two of those cells with
// a border-consumed minimum of 3 so the runes are never sliced. That screen
// clamp is the one geometry probe the explicit-width path still runs.
public function setWindowWidth(int $w): self

// Position the overlay at (x, y). Pure placement: visibility is unchanged
// (use show()/hide()), negative coordinates clamp to 0, and the column is
// clamped at compose time so the overlay never starts past the terminal's
// right edge (-063: setOffset(1000, 0) used to emit 1023-cell rows).
public function setOffset(int $x, int $y): self

// Custom item formatter:
//   Closure(string $value, bool $isSelected, int $number): string
// Replaces the built-in default rendering (a '● ' cursor prefix on the
// selected row, 'N. ' ordinals, indent alignment for the rest) ENTIRELY —
// re-render $isSelected/$number yourself if you still want an indicator.
// It always receives the VALUE STRING (never an Item), even for Item objects,
// plus the selection flag and the item's ordinal. Values arrive already
// guarded (control bytes, escape sequences and forged zone sentinels are
// stripped before you see them); SGR you add survives highlighting.
public function setItemFormatter(\Closure $fn): self

// Custom filter predicate: Closure(Item $item): bool
// Applied in addition to the built-in filter (contiguous substring match by
// default; fuzzy scoring only when setRanker() is active); return false to
// exclude the item
public function setFilterFn(\Closure $fn): self

// Plug in a sugar-fuzzy ranker (null restores the default substring filter).
// When set, a non-empty filter ranks items by descending fuzzy score (true
// subsequence matching) and highlighting follows the scored indices.
// Cost note: every keystroke re-scores the WHOLE corpus against the filter —
// on the order of hundreds of ms on 20k-item lists (the substring path is
// low double-digit ms; both figures are machine-dependent). Debounce at the
// host or keep corpora modest.
public function setRanker(?\SugarCraft\Fuzzy\FuzzyMatcher $matcher): self

// Apply a sugar-style Border to the overlay window
public function withBorder(?\SugarCraft\Style\Border $border): self

// Apply a sugar-style Style to the overlay window
public function withStyle(?\SugarCraft\Style\Style $style): self

// Attach a HelpBar (keyboard shortcut summary) below the filter list. Its
// rendered line is hard-clipped to the overlay width (no ellipsis), so a
// wide legend under-reports its own keys at narrow widths — size the
// overlay to fit the legend or keep the legend short.
public function withHelpBar(?HelpBar $helpBar): self

// Attach a StatusBar (status message line) at the bottom of the overlay;
// the same hard clip to the overlay width applies.
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
// the cursor to 0. Input is END-ONLY by design — the drawn caret `▏` sits
// at the end of the filter and there is no motion through it; a mid-filter
// typo means backspace/clear and retype. The MAX_FILTER_LENGTH (256
// codepoint) budget is enforced by CLAMPING the appended run: a paste at
// 255 lands exactly 256, never past it, and at the cap the header edge
// shows a `⋯` overflow indicator (compare filterAtCap()).
public function type(string $chars): self

// True once the filter holds MAX_FILTER_LENGTH codepoints: type() then
// refuses input and the header shows the overflow indicator.
public function filterAtCap(): bool

// Remove the last codepoint from the filter text — grapheme clusters such
// as '👍🏽' span several codepoints, so clearing one may take several
// backspaces. Unlike type(), the
// cursor CLAMPS to the new list end instead of resetting — deliberate
// asymmetry: narrowing preserves your place, widening does not.
public function backspace(): self

// Clear filter text and show all items
public function clear(): self

// Move cursor up (default 1). A step below 1 throws InvalidArgumentException
// (-025: negative steps silently reversed direction and parked the cursor
// out of range).
public function cursorUp(int $n = 1): self

// Move cursor down (default 1). A step below 1 throws (same rule as
// cursorUp).
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
`$h->View($bg)` call still binds here.

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

// Point at a history file. Without a baseDir, nothing touches the filesystem
// yet — the file is created lazily on the first append() via exclusive-open,
// then chmod'ed 0600 before the first payload line. That chmod is
// best-effort: if the filesystem
// refuses it, the mode follows the process umask and nothing is signalled.
// Use a path you own; on shared hosts, never a predictable
// world-writable location such as /tmp (a planted symlink there is refused
// outright — see below). The parent directory must already exist —
// FileHistory creates only the file, never intermediate directories, so a
// missing parent surfaces as a RuntimeException on the first append().
$history = new FileHistory(getenv('HOME') . '/.myapp/history.jsonl');

// Optionally jail the file inside a private base directory. That check is
// EAGER, not lazy: the constructor realpath-confines the path immediately,
// rejects anything resolving outside the base (via '..', an absolute path
// elsewhere, a symlinked parent — or a symlink at the file itself), and
// throws InvalidArgumentException when the base directory itself does not
// exist.
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

// Read back the entries (key → description)
$shortcuts = $bar->shortcuts();

// Render: "Enter: select │ Esc: close │ Ctrl+R: reload"
echo $bar->render();

// Show or hide (fluent). A hidden bar renders the empty string — that is
// how the overlay omits the line entirely:
$bar->hide()->render(); // ''
```

Shortcut labels are free-form strings rendered verbatim, so prefer word-form entries like 'Up/Down' (the 'Enter' row above already is one) over glyphs like '↑↓' for screen-reader listeners — the example removes the glyph entry with `withoutShortcut()` for exactly this reason.

## API — StatusBar

```php
use SugarCraft\QuickPick\StatusBar;

// Create with optional message
$bar = new StatusBar('3 of 12 items');

// Fluent setters
$bar = $bar->withMessage('Searching...')   // replaces the constructor message
           ->withSegment('count', '7');    // named segment

// Read back the message and the named segments (name → value)
$message  = $bar->message();
$segments = $bar->segments();

// Render: "[count: 7] Searching..."
echo $bar->render();

// The clearing and visibility ops undo one thing each; shown separately so
// the echoed state above stays truthful:
$bar->withoutSegment('count')->render(); // 'Searching...'
$bar->withNoMessage()->render();         // '[count: 7]'
$bar->hide()->render();                  // '' — a hidden bar renders empty
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
`update(QuickPick $model, string $msg)` expects exactly this forwarding —
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
    // A genuine install failure: no ext-pcntl, no SIGWINCH support, the OS
    // refused pcntl_signal(), or no withOnResize() callback was registered.
    // Poll the size yourself, or simply carry on without resize events.
}
```

Installing and reporting are separate facts. A `true` return only says the
handler is wired up: SIGWINCH is raised by the terminal driver, so on a
non-tty STDIN (pipe, redirect) the callback may simply never fire even
though the attach succeeded. If you want a size-polling fallback, gate it
on `stream_isatty(STDIN)` — not on the return value.

The width memo is a shared object, not a per-instance property, so a resize
invalidates the whole clone family at once: attach before or after your
fluent pipeline settles, the live clone still sees every geometry change.
One binding does remain instance-flavoured: the handler forwards to the
attach-time instance's registered callback, so call `withOnResize()` before
`attachSigwinch()` — a callback swapped in on a later clone is not
re-registered by the old handler.

Signals are process-global. In a long-lived host (PHP-FPM worker, ReactPHP
daemon, test suite) detach them at session teardown with
`SugarCraft\Pty\SignalForwarder::reset()`.

## Model Interface

Implement the `Model` interface to use QuickPick inside a larger Model–Update–View application:

```php
use SugarCraft\QuickPick\QuickPick;
use SugarCraft\QuickPick\Model;

class MyModel implements Model {
    public function update(QuickPick $model, string $msg): Model { ... }
    public function view(QuickPick $model): string { ... }
}
```

## License

MIT

## Credits & inspiration

Originally inspired by [Genekkion/theHermit](https://github.com/Genekkion/theHermit) — fuzzy finder / quick-fix overlay for terminal UIs; SugarCraft is developed as a native PHP project.
