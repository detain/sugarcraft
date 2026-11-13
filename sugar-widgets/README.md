<img src=".assets/icon.png" alt="sugar-widgets" width="160" align="right">

# SugarWidgets

> Formerly published as `sugarcraft/sugar-bits` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Bits\` → `SugarCraft\Widgets\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-widgets)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-widgets)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-widgets?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-widgets)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/spinners.gif)

sugar-widgets — TUI components for PHP 8.3+: 9 first-party components (Table, Tabs, Tree, Progress, Paginator, Help, Key, Timer, Stopwatch), 8 deprecated alias re-exports from `sugarcraft/sugar-forms` (TextInput, TextArea, Viewport, Cursor, ItemList, FilePicker, Scrollbar, Spinner), and two btop-derived render helpers (`Input\TextEdit`, `Menu\OptionRow`) — including the interactive `Tree` and per-cell `Table::styleFunc(...)`.

```sh
composer require sugarcraft/sugar-widgets
```

Requires PHP ≥ 8.3. No non-bundled extensions are needed (mbstring is
bundled with PHP). When you depend on this package standalone, its `sugarcraft/*`
siblings resolve from Packagist.

> The most-used setters have short-form aliases, per class:
> `TextInput` — `placeholder` / `prompt` / `charLimit` / `width` /
> `echoMode` / `echoChar` / `suggest` / `validator` / `styles` / `prefix` /
> `suffix` / `placeholderStyle`; `TextArea` — `placeholder` / `charLimit` /
> `width` / `height` / `prompt` / `validator`; `Help` — `width` /
> `styles` / `separator` / `ellipsis` / `fullSeparator`. The `with*` long
> forms still work side-by-side.

> `Help::getStyles()` and `Help::getWidth()` are deprecated BC accessors —
> the house convention is bare accessors. Read the promoted readonly
> `styles` / `maxWidth` properties instead.

## Components

The table below lists the first-party components — Help, Key, Progress
(includes the AnimatedProgress spring-variant), Timer, Stopwatch,
Paginator, Tree, Table, Tabs — plus the two btop-derived render helpers,
`Input\TextEdit` and `Menu\OptionRow`, followed by the deprecated alias
re-exports from `sugarcraft/sugar-forms` (each row flags which it is).
The counted inventory lives once, in the intro at the top of this file.

| Component | What it does | Notable msgs |
|---|---|---|
| `Cursor\Cursor` | Animated text cursor _(deprecated alias — re-exported from `SugarCraft\Forms\Cursor`)_ | `BlinkMsg` |
| `Help\Help` | Render short / full key-help footer from a `KeyMap`; `Help::updateWithBinding($msg, $toggle)` flips show-all in response to a key. `Help::new(...)` is the house factory (all eight styling params optional; the public constructor stays for BC); gap knobs via `withKeyDescGap()` / `withColumnGap()` / `withWidth()` | — |
| `Key\Binding` | One key + label + help row; `Binding::new(...)`, `Binding::withDisabled(...)` factories | — |
| `Key\KeyMap` | Interface — implement it so `Help` can render your bindings: `shortHelp(): list<Binding>` for the inline row, `fullHelp(): list<list<Binding>>` for the expanded pane (one inner list per column); `Tabs\TabsKeyMap` is the shipped example. Note `Key\Help` — the small `{key, desc}` value object bindings render through — is a *different* type from the `Help\Help` component | — |
| `Spinner\Spinner` | Animated loading glyph — 12 built-in styles _(deprecated alias — re-exported from `SugarCraft\Forms\Spinner` in `sugarcraft/sugar-forms`)_ | `Spinner\TickMsg` |
| `Progress\Progress` | Static progress bar (gradient fill optional, `withColors(...)` / `withColorFunc(...)` / `withShowValue(...)`; render modes via `withRenderMode(ProgressRenderMode)` — `Block` / `Line` / `Slim` — and custom percent copy via `withPercentFormat(string)`) | — |
| `Progress\AnimatedProgress` | Spring-physics-animated progress bar (powered by `sugarcraft/sugar-spring`); honours `REDUCE_MOTION` *and* `PREFERS_REDUCED_MOTION` (read through the sugar-colorprofile probe — any value outside `''`/`0`/`false`/`no`/`off`) by snapping to the target percent within one tick | `SpringTickMsg` |
| `Timer\Timer` | Countdown timer — `Timer::new($duration, $interval = 1.0)` (duration is required); `remaining` / `interval` are promoted readonly properties, the methods are `interval(): float`, `timeout(): float` (remaining seconds — `0` once `TimeoutMsg` has fired) and `withInterval(float)`; there is no `remaining()` method. Quirk: `view()` renders only the formatted time — running / timed-out state is invisible in the bytes, query `isRunning()` / `timedOut()`. In-flight ticks from stopped generations are refused via the tick `epoch` | `Timer\TickMsg`, `TimeoutMsg` |
| `Stopwatch\Stopwatch` | Count-up timer; `elapsed`, `interval()`, `withInterval(float)`. `elapsed` is a **tick-count accumulator** (ticks × interval), not wall-clock — it advances only while `update()` consumes ticks. Same render-bytes quirk as `Timer` | `Stopwatch\TickMsg` |
| `TextInput\TextInput` | Single-line input with autocomplete + validators + `ValidateOn` timing + restrict pattern + vim mode + placeholder styling + prefix/suffix + `Styles` _(deprecated alias — re-exported from `SugarCraft\Forms\TextInput`)_ | — |
| `TextArea\TextArea` | Multi-line editor with line numbers / set-prompt-func / `focused()` / `cursor()` / `line()` / `column()`; `Ctrl+O` opens the buffer in `$EDITOR` (`withEditorExtension('.md')` to control the syntax-highlight suffix) _(deprecated alias — re-exported from `SugarCraft\Forms\TextArea`)_ | `TextArea\TextAreaEditedMsg` |
| `Viewport\Viewport` | Scrollable text region with mouse-wheel, scrollbar, horizontal scroll, `setWidth(int)` / `setHeight(int)` _(deprecated alias — re-exported from `SugarCraft\Forms\Viewport`)_ | `ViewportTickMsg` |
| `Paginator\Paginator` | Dot / arabic page indicator | — |
| `ItemList\ItemList` | Selectable / scrollable / filterable list with status messages; items are `ItemList\Item` / `ItemList\StringItem` values — `list<Item>` is a phpdoc contract, there is no runtime element guard _(deprecated alias — re-exported from `SugarCraft\Forms\ItemList` — depend on `sugarcraft/sugar-forms` directly)_ | — |
| `Tree\Tree` | Interactive tree — cursor, expand/collapse, viewport scroll. Roots are `Tree\Node` values (`Node::branch($label, ...$children)` / `Node::leaf($label)` — the constructor's second parameter is `$value`, not children, so a 2-arg `new Node(...)` silently parks the child list in the value slot and renders flat); chrome glyphs and prefixes are immutable via `withStyle(...)` — `withStyle` knobs are checked at ingress, while node labels are sanitised at RENDER (control characters stripped and `Sanitize::untrustedForMarkedFrames()` applied), not stored raw. | — |
| `Table\Table` | Selectable data table with `Column` struct + nav + multi-column sort | — |
| `Tabs\Tabs` | Tabbed panel — keyboard (`Tab`/`Shift+Tab`/`1-9`) + mouse navigation, wrap/clamp modes, scrollable overflow. Mouse *zones* are opt-in: labels are only advertised to `sugar-mouse` when you attach a supplied manager with `withZoneManager()` (passing `null` detaches); until then clicks find nothing. `focus()` renders the active tab underlined — before the M9 fix (Tabs.php source comment) the focused and blurred frames were byte-identical, so keyboard focus was invisible in the render bytes | — |
| `FilePicker\FilePicker` | Directory browser with icons / size / sort modes _(deprecated alias — re-exported from `SugarCraft\Forms\FilePicker`)_ | — |
| `Scrollbar\Scrollbar` | Scrollbar indicator for scrollable viewports _(deprecated alias — re-exported from `SugarCraft\Forms\Scrollbar`)_ | — |
| `Input\TextEdit` | Inline single-line editor value object with an underline caret (no hardware cursor), grapheme-cluster caret, cell-budget windowing, digits-only mode — btop `Draw::TextEdit` | — |
| `Menu\OptionRow` | Static renderer for btop's two-line options-menu row (bold centred name + `← value →` / `↵` line, live `TextEdit` while editing) | — |

### Keyboard control

The widgets above decode these keys inside their own `update()` — the
host forwards `KeyMsg` rows and the component moves:

| Component | Keys |
|---|---|
| `Viewport` | `↑`/`k` line up, `↓`/`j` line down, `←`/`h` scroll left, `→`/`l` scroll right, `PageUp`/`b`, `PageDown`/`f`/`Space`, `Ctrl+u`/`Ctrl+d` half pages, `Home`/`g`, `End`/`G` |
| `Table` | `↑`/`k`, `↓`/`j`, `Home`/`g`, `End`/`G`, `PageUp`/`PageDown` by `max(1, height)` rows — rows only, no vim letter bindings |
| `Tree` | `↑`/`k`, `↓`/`j`, `Enter` toggles, `→`/`l` expands, `←`/`h` collapses, `g`/`G` jump |

### Vim mode

`TextInput` vim mode (Insert/Normal/Visual/VisualLine keybindings) is
powered by `sugar-forms`'s shared `VimKeyHandler` — the same handler
backing `sugar-forms`' own fields and `sugar-readline`; this package's
`TextInput` is a façade re-export riding that handler, not an
independent third home. Adding a new binding to the `VimAction` enum
benefits the forms and readline consumers at once. The per-lib opt-in
flag `withVimMode(true)` is preserved; consumers control whether vim
mode is enabled.

### Msg routing cheat-sheet

Forward these into your model's `update()` so the embedded component
can react: `SugarCraft\Widgets\Cursor\BlinkMsg` (Cursor / TextInput,
deprecated alias of `Forms\Cursor\BlinkMsg`),
`SugarCraft\Widgets\Spinner\TickMsg` (Spinner),
`SugarCraft\Widgets\Timer\TickMsg` + `SugarCraft\Widgets\Timer\TimeoutMsg`,
`SugarCraft\Widgets\Stopwatch\TickMsg`,
`SugarCraft\Widgets\Progress\SpringTickMsg` (AnimatedProgress),
`SugarCraft\Widgets\TextArea\TextAreaEditedMsg` (TextArea's Ctrl+O
round-trip), `SugarCraft\Widgets\Viewport\ViewportTickMsg` (Viewport's
auto-scroll tick — the alias of `Forms\Viewport\ViewportTickMsg` the
component arms through `subscriptions()`). `ItemList\LoadMoreMsg` has
**no** widgets alias — import `SugarCraft\Forms\ItemList\LoadMoreMsg`.
The id-carrying components (`Timer`, `Stopwatch`, `Cursor`,
`Spinner`) filter in `update()` by their own `id()`, so multiple
instances of the same component coexist on one event loop. Not every
tick is id-routed: `SpringTickMsg` is an id-less sentinel and
`AnimatedProgress::update()` applies no id filtering, so two animated
bars on one loop react to each other's ticks (seam noted for an owner
ruling; no behaviour change here). Timer and
Stopwatch ticks also carry an `epoch` (generation counter bumped by
`start()`): a tick armed before a stop/start flip is refused rather than
double-counted.
Reduced motion is gated per component: `AnimatedProgress` honours
`REDUCE_MOTION` / `PREFERS_REDUCED_MOTION` itself, but
`Cursor\BlinkMsg` and `Spinner\TickMsg` are not gated in the widget —
hosts that follow WCAG 2.2.2 must stop forwarding those ticks when the
user asks for reduced motion.

## Quickstart — TextInput with autocomplete

> `TextInput` (and `Spinner`) are deprecated `sugar-forms` re-exports.
> New code should `composer require sugarcraft/sugar-forms` and import
> `SugarCraft\Forms\…` — as the example below does; the
> `SugarCraft\Widgets\TextInput\…` spellings resolve to the very same
> classes through the alias and keep working until the aliases are
> removed in the next major.
```php
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Core\{Cmd, Model, Msg, Program};
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;

final class Search implements Model
{
    public function __construct(public readonly TextInput $ti) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Enter) {
            return [$this, Cmd::quit()];
        }
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Tab) {
            return [new self($this->ti->acceptSuggestion()), null];
        }
        [$ti, $cmd] = $this->ti->update($msg);
        return [new self($ti), $cmd];
    }

    public function view(): string
    {
        $body = $this->ti->view();
        if (($s = $this->ti->currentSuggestion()) !== null) {
            $body .= "\n  → $s";
        }
        return $body;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

[$ti, $cmd] = TextInput::new()
    ->withSuggestions(['apple', 'apricot', 'banana', 'cherry'])
    ->showSuggestions()
    ->withValidator(fn(string $v) => strlen($v) >= 2 ? null : 'too short')
    ->focus();

(new Program(new Search($ti)))->run();
```

## Quickstart — animated progress bar

```php
use SugarCraft\Widgets\Progress\AnimatedProgress;

$bar = AnimatedProgress::new()
    ->withWidth(40)
    ->withDefaultGradient();

[$bar, $cmd] = $bar->setPercent(0.75);
// dispatch $cmd via the Program — ticks re-fire from inside update()
// until the bar settles within 5e-4 of the target.
```

## Quickstart — TextInput with placeholder styling and prefix/suffix

```php
use SugarCraft\Widgets\TextInput\TextInput;
use SugarCraft\Style\Style;
use SugarCraft\Core\Util\Color;

$ti = TextInput::new()
    ->withPlaceholder('Enter command…')
    ->withPlaceholderStyle(Style::new()->faint())           // default: dim
    ->withPrefix('$ ')                                      // fixed prefix
    ->withSuffix(' <');                                     // fixed suffix

echo $ti->view();
// $ > Enter command… <   (the built-in '> ' prompt precedes the dim placeholder;
//   once focused the placeholder is replaced by the reverse-video block cursor)
```

## TextInput — ValidateOn and restrict

`TextInput` supports deferred and filtered validation via two new builders:

### ValidateOn timing control

```php
use SugarCraft\Widgets\TextInput\{TextInput, ValidateOn};

$ti = TextInput::new()
    ->withValidateOn(ValidateOn::Blur);   // validate when focus leaves
```

| Case | When validation fires |
|------|-----------------------|
| `ValidateOn::None` | On every edit (default — immediate, like `Change`; nothing is deferred) |
| `ValidateOn::Blur` | When the input loses focus |
| `ValidateOn::Change` | On every keystroke |
| `ValidateOn::Submit` | Only on `Enter` keypress |

### Keystroke filter (restrict)

```php
use SugarCraft\Widgets\TextInput\TextInput;

// Accept only digits
$numeric = TextInput::new()->withRestrict('[0-9]');

// Accept alphanumeric only
$alphanum = TextInput::new()->withRestrict('[a-zA-Z0-9]');
```

### TextInput notable builders

| Method | What it does |
|--------|--------------|
| `withValidateOn(ValidateOn $timing)` | Set validation timing — `None` (default) and `Change` validate on every edit; `Blur` / `Submit` defer |
| `withRestrict(string $pattern)` | Set a PCRE regex — only matching characters are accepted (no delimiters) |

## Table — multi-column sort

```php
use SugarCraft\Widgets\Table\{Table, SortDirection, SortState};

// Primary sort by Name ascending
$t = $table->withSort('Name');

// Tiebreaker: Age descending
$t = $table->thenSortBy('Age', SortDirection::Desc);

// Reset to insertion order
$t = $table->clearSort();

// Inspect current sort criteria
$state = $t->sortState(); // SortState
foreach ($state->criteria as [$colIndex, $dir]) {
    // $colIndex is an int, $dir is SortDirection::Asc or SortDirection::Desc
}
```

### SortDirection enum

| Case | Value | Description |
|------|-------|-------------|
| `SortDirection::Asc` | `'asc'` | Sort in ascending order |
| `SortDirection::Desc` | `'desc'` | Sort in descending order |

`SortDirection::toggle()` returns the opposite direction.
`SortDirection::marker()` returns the header glyph (`↑` / `↓`).

When a sort is active the header cell gains that marker (`Name ↑`), so
the visible state matches `sortState()` — and when the selection cursor
is on a row, `focused` state rides `focus()`/`blur()`.

### SortState DTO

Immutable list of sort criteria — each entry is a `(column index, direction)` pair. Applied in order: first entry is primary sort, second is tiebreaker, etc.

| Method | Returns | Description |
|--------|---------|-------------|
| `SortState::empty()` | `SortState` | Factory for no criteria |
| `SortState->withCriterion(int $col, SortDirection $dir)` | `SortState` | Append a criterion |
| `SortState->isEmpty()` | `bool` | True when no criteria are set |
| `SortState->criteria` | `list<array{0:int,1:SortDirection}>` | Raw criteria list |

### Table sort builders

| Method | Description |
|--------|-------------|
| `withSort(string $column, SortDirection $dir = Asc)` | Set primary sort — clears any prior sort chain |
| `thenSortBy(string $column, SortDirection $dir = Asc)` | Add a secondary (or further) tiebreaker criterion |
| `clearSort()` | Remove all sort criteria, restoring insertion order |
| `sortState(): SortState` | Return the current sort criteria (readonly accessor) |

Sorting throws `\InvalidArgumentException` when a column name is not
found; the message comes from the `table.sort_unknown_column` locale key
(English: `sort column not found: {column}`). See Localization below.

## Table — filtering

```php
use SugarCraft\Widgets\Table\Table;

// Enable the filter feature (opt-in)
$t = $table->withFilterable(true);

// Set a query string — default: case-insensitive substring match across every cell of every row
$t = $table->withFilter('foo');

// Custom filter: receives a row (list<string>), returns true to keep
$t = $table->withFilterPredicate(fn(array $row): bool =>
    str_contains(strtolower(implode("\t", $row)), 'foo')
);

// Inspect current filter state
$isFilterable = $t->filterable();   // bool
$query        = $t->filter();        // string
$predicate    = $t->filterPredicate(); // ?Closure(list<string>): bool
```

When `withFilterPredicate()` is set, it overrides the default substring-match behaviour. Pass `null` to restore the default.

### Table filter builders

| Method | Description |
|--------|-------------|
| `withFilterable(bool $filterable)` | Enable or disable the filter feature |
| `withFilter(string $query)` | Set the filter query string (non-empty enables filtering) |
| `withFilterPredicate(?Closure(list<string>): bool $predicate)` | Custom filter callable — `null` restores the default |
| `filterable(): bool` | Return whether filtering is enabled |
| `filter(): string` | Return the current filter query string |
| `filterPredicate(): ?Closure` | Return the current custom predicate |

The default filter applies case-insensitive substring matching across every cell of every row (hidden columns are a future feature — there is no visibility filtering today).

## Table — pagination

```php
use SugarCraft\Widgets\Table\Table;

// Enable pagination: 10 rows per page
$t = $table->withPageSize(10);

// Navigate pages
$t = $t->withPage(1);   // zero-based — go to page 1 (second page)
$t = $t->nextPage();
$t = $t->prevPage();
$t = $t->pageFirst();
$t = $t->pageLast();

// Inspect pagination state
$pageSize   = $t->pageSize();      // int — rows per page (0 = pagination disabled)
$current   = $t->currentPage();   // int — zero-based current page
$totalPages = $t->paginator()->totalPages();  // int — 1 when pagination is disabled and at least one row exists (empty table → 0)

// Wire a Paginator to the table for UI rendering
$paginator = $t->paginator();    // Paginator instance
```

### Table pagination builders

| Method | Description |
|--------|-------------|
| `withPageSize(int $size)` | Set rows per page — `0` disables pagination; `≥1` enables it |
| `withPage(int $page)` | Navigate to a zero-based page (clamps to valid range) |
| `nextPage()` | Advance one page |
| `prevPage()` | Retreat one page |
| `pageFirst()` | Jump to the first page |
| `pageLast()` | Jump to the last page |
| `pageSize(): int` | Return rows per page (`0` = pagination off) |
| `currentPage(): int` | Return the current zero-based page |
| `paginator()->totalPages(): int` | Return the total page count (`1` when pagination is disabled and the table has at least one row; an empty table reports `0`) |
| `paginator(): Paginator` | Return a `Paginator` instance wired to the table's current page state |

Pagination works with sort and filter, but nothing "re-clamps to the page head". `withFilter()`, `withFilterPredicate()` and `withPageSize()` reclamp through `moveCursor()`, which preserves the cursor's row index (only bounding it to the surviving rows) and re-derives the page as `intdiv(cursor, pageSize)` — the visible page follows the cursor. Sorting (`withSort()`, `thenSortBy()`, `clearSort()`) never moves the cursor and never reclamps: the cursor keeps pointing at the same row index under the new ordering, and its page is recomputed from there.

## Table — per-cell styles (`styleFunc`)

`Table::styleFunc(?Closure $fn)` installs a per-cell composer,
`fn(int $row, int $col): Style` (row `-1` is the header). Each returned
style is merged *under* the row's own style via `Style::inherit()` — the
selection inversion (and the header underline) therefore always survives
the cell paint. Each cell still ends with its own `ESC[0m` terminator,
and that reset only closes the cell's own attributes: it cannot clobber
the row wrapper, so even an aggressive `styleFunc` output leaves a
focused table rendering differently from an unfocused one.

```php
use SugarCraft\Widgets\Table\Table;
use SugarCraft\Style\Style;
use SugarCraft\Core\Util\Color;

$table = Table::new(['Name', 'Status'], $rows)
    ->styleFunc(static function (int $row, int $col) use ($rows): Style {
        if ($col === 1 && $row >= 0 && $rows[$row][1] === 'FAIL') {
            return Style::new()->foreground(Color::hex('#ff5555'));
        }
        return Style::new();
    });

[$focused, ] = $table->focus();   // red FAIL cells now ride UNDER the reverse cursor
```

The composer returns *style* only — colours and attributes, never cell
text. A fixed TrueColor hex renders as authored on TrueColor hosts; to
degrade gracefully on palettes that cannot carry it, follow the
`Progress` precedent: accept a `SugarCraft\Core\Util\ColorProfile`
(Progress takes one as its constructor default `ColorProfile::TrueColor`
and offers `withColorProfile()`) and choose the shades from the detected
profile inside your closure.

`getStyleFunc(): ?Closure` reads the seam back for host-side
re-composition. Partial `Table\Styles` objects no longer delete the
cursor either — unset slots fall back to the same defaults the
no-styles path renders (header underline, selected reverse).

## btop helpers — TextEdit, OptionRow, position-coloured Progress

Three pieces added for [candy-top](https://github.com/detain/sugarcraft/tree/master/candy-top) (the btop-inspired system monitor) that are
useful to any TUI drawing overlays, option screens or gradient meters.

### `Input\TextEdit` — underline-caret inline editor

A pure immutable value object (not a Model–Update–View component) mirroring btop
`Draw::TextEdit` — the proc filter bar and options-menu value editor. The
caret is drawn as an SGR underline (`TextEdit::UL` / `TextEdit::UUL`) on
the cluster under it, so several editors can be on screen while the
hardware cursor stays hidden. For a focusable, blinking, validated input
use `TextInput` instead.

```php
use SugarCraft\Widgets\Input\TextEdit;

$edit = TextEdit::new('firefox')   // caret defaults to the end
    ->home()->right()->right()     // caret on "r"
    ->insert('X')                  // "fiXrefox"
    ->backspace();                 // "firefox", caret 2
echo $edit->view();                // "fi\e[4mr\e[24mefox"
echo TextEdit::new('a-very-long-filter-string')->view(10); // "er-string\e[4m \e[24m"

$pid = TextEdit::new('', numeric: true)->insert('12')->insert('a')->insert('3');
echo $pid->text();                 // "123" — non-digit input is rejected whole
```

- `new(string $text = '', ?int $caret = null, bool $numeric = false)`;
  accessors `text()` / `caret()` / `numeric()` / `length()`.
- Editing: `left()` / `right()` / `home()` / `end()` / `withCaret(int)`
  (clamped) / `insert(string)` / `backspace()` / `delete()` / `clear()`.
- The caret counts **grapheme clusters** (a ZWJ emoji or combining
  sequence moves and deletes as one); `view(int $limit = 0)` budgets in
  **display cells**, windowing around the caret with btop's half-budget
  law. Unlike btop, the end-caret cell is charged to the budget, so
  output never exceeds `$limit` — with one carve-out: when `$limit < 2`
  a lone wide cluster sitting under the caret stays visible and the
  output may overrun the budget by one cell (clipping the caret away
  would hide the position). `$limit <= 0` is unlimited.
- `withNumeric(bool $on = true)` strips existing non-digits (keeping the
  caret on the same surviving digit) so `text()` is always parseable.

### `Menu\OptionRow` — btop options-menu geometry

`OptionRow::renderRow(...)` is the primary API: the single-line btop
option row `← label … value ↵ →` sized to exactly the field width the
caller provides — label and value share the budget, and values are
clipped with `Width::truncateAnsi()` so caller ANSI survives. The field
width is floored at 16 cells (`max(16, $field)`), so a row always
renders into at least 16 columns even when the caller budgets less.

> `OptionRow::render(...)` — the older two-line form (bold name centred
> in `$nameCol`, value line centred in `$valueCol`) — is **deprecated**:
> its second line was built `valueCol + 4` cells wide regardless of
> `$nameCol`, overrunning the next column whenever
> `nameCol < valueCol + 4` (the default 29/25 pair makes that read
> `nameCol < 29`); it now clips to `$nameCol` (byte-identical at the
> default pair) and stays supported for candy-top, but prefer
> `renderRow()`.

With `←` / `→` on the selected row (while not editing) when
`$hasArrows` and `↵` (or `E` with `tty: true`) only when the row is
*both* selected and `$editable`. Passing a
`TextEdit` with `editing: true` renders its underline-caret view in
`valueCol - 1` cells.

```php
use SugarCraft\Widgets\Input\TextEdit;
use SugarCraft\Widgets\Menu\OptionRow;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;

echo OptionRow::render(
    'Update ms',
    TextEdit::new('2000', numeric: true),
    selected: true,
    editing: true,
    hasArrows: true,
    editable: true,
    selBg: Style::new()->background(Color::hex('#3d59a1')),
    selFg: Style::new()->foreground(Color::hex('#ffffff')),
);
```

Centering follows btop `cjust` (the odd spare column goes on the left,
the opposite of `Width::padCenter()`); over-wide text truncates to the
column in display cells. The selected style deliberately bleeds into the
value line, so the selected option reads as one two-row block. Optional
`title:` / `main:` styles colour the unselected name / value.

### `Progress::withColorFunc` — bar width as a 4th argument

The colour closure is now called
`fn(int $i, int $filledCells, float $percent, int $barWidth): Color` —
`$barWidth` is the true cell width (filled + empty, after any percent
suffix). That lets each filled cell take its colour from its *position*
on a 101-stop ramp, btop-style. Three-parameter closures keep working.

```php
use SugarCraft\Widgets\Progress\Progress;
use SugarCraft\Core\Util\Color;

$ramp = Color::hex('#50fa7b')->blend1d(Color::hex('#ff5555'), 101);
echo Progress::new()
    ->withWidth(20)
    ->withShowPercent(false)
    ->withPercent(0.6)
    ->withColorFunc(static fn (int $i, int $filled, float $pct, int $barWidth): Color
        => $ramp[(int) round(($i + 1) * 100 / $barWidth)])
    ->view();
```

This gives btop's *colouring*, not its fill count (Progress fills
`round(percent * barWidth)` cells, where `barWidth` is the total width less the
measured percent-suffix when the `%` label fits — see the `computeBarLayout()`
reserved-suffix maths). For an exact btop meter use sugar-dashboard
`Meter::withGradient($stops, positionWise: true)`.

## Localization

Exception messages and label copy resolve through
`SugarCraft\Widgets\Lang` (the `sugar-core` i18n lookup: exact locale →
base language → `en` → raw key). `lang/en.php` is the source of truth,
mirrored into 15 further locales (ar, cs, de, es, fr, it, ja, ko, nl,
pl, pt-br, pt, ru, tr, zh-cn); placeholder tokens such as `{column}`
survive in every locale, and `tests/LangTest.php` fails closed when the
per-locale key sets diverge, when a locale drops a placeholder, or when
`src/` requests a key the catalogue does not ship.

## Snapshot tests

Render output is covered by golden-file snapshot tests. Fixture files live
in `tests/fixtures/` with a `.golden` extension and are compared against
actual ANSI byte output via `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi()`.
Re-recording is create-only — an existing fixture is never silently
overwritten — so after an intentional output change delete the affected
fixtures first:

```sh
rm tests/fixtures/<name>.golden
UPDATE_GOLDENS=1 vendor/bin/phpunit --filter <owningTest>
git diff tests/fixtures/   # the rewrite must be the disclosed delta
```

## Test

```sh
cd sugar-widgets && composer install && vendor/bin/phpunit
```

## Demos

### Cursor

![cursor](.vhs/cursor.gif)

### File picker

![file-picker](.vhs/file-picker.gif)

### Help

![help](.vhs/help.gif)

### Item list

![item-list](.vhs/item-list.gif)

### Paginator

![paginator](.vhs/paginator.gif)

### Progress

![progress](.vhs/progress.gif)

### Spinners

![spinners](.vhs/spinners.gif)

### Stopwatch

![stopwatch](.vhs/stopwatch.gif)

### Table

![table](.vhs/table.gif)

### Tabs

![tabs](.vhs/tabs.gif)

### Text area

![text-area](.vhs/text-area.gif)

### Text input

![text-input](.vhs/text-input.gif)

### Text input (enhanced)

![text-input-enhanced](.vhs/text-input-enhanced.gif)

### Timer

![timer](.vhs/timer.gif)

### Tree

![tree](.vhs/tree.gif)

### Viewport

![viewport](.vhs/viewport.gif)

## Related

- [SugarCraft monorepo](https://github.com/detain/sugarcraft)

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
