<img src=".assets/icon.png" alt="sugar-bits" width="160" align="right">

# SugarBits

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-bits)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-bits)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-bits?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-bits)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/spinners.gif)

PHP port of [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) —
15 pre-built TUI components for SugarCraft, including the interactive
`Tree` (mirrors upstream Bubbles #233), dynamic-height `TextArea`
(mirrors #910), and per-cell `Table::styleFunc(...)` (mirrors #246).

```sh
composer require sugarcraft/sugar-bits
```

> `TextInput`, `TextArea`, and `Help` expose short-form aliases for the
> most-used setters: `placeholder` / `charLimit` / `width` / `height` /
> `prompt` / `validator` / `styles` / `separator` / `ellipsis`. The
> upstream-mirroring `with*` long forms still work side-by-side.

## Components

Upstream Bubbles ships 13 components; SugarBits ships those 13 plus
`AnimatedProgress` (the spring-physics variant lives in its own class
to keep the static `Progress` lean).

| Component | What it does | Notable msgs |
|---|---|---|
| `Cursor\Cursor` | Animated text cursor | `BlinkMsg` |
| `Help\Help` | Render short / full key-help footer from a `KeyMap`; `Help::updateWithBinding($msg, $toggle)` flips show-all in response to a key | — |
| `Key\Binding` | One key + label + help row; `Binding::new(...)`, `Binding::withDisabled(...)` factories | — |
| `Spinner\Spinner` | Animated loading glyph — 12 built-in styles _(deprecated alias — re-exported from `SugarCraft\Forms\Spinner` in `sugarcraft/candy-forms`)_ | `Spinner\TickMsg` |
| `Progress\Progress` | Static progress bar (gradient fill optional, `withColors(...)` / `withColorFunc(...)` / `withShowValue(...)`) | — |
| `Progress\AnimatedProgress` | Spring-physics-animated progress bar (HoneyBounce-driven) | `SpringTickMsg` |
| `Timer\Timer` | Countdown timer; `interval()`, `timeout()`, `withInterval(float)` | `Timer\TickMsg`, `TimeoutMsg` |
| `Stopwatch\Stopwatch` | Elapsed-time counter; `interval()`, `withInterval(float)` | `Stopwatch\TickMsg` |
| `TextInput\TextInput` | Single-line input with autocomplete + validators + `ValidateOn` timing + restrict pattern + vim mode + placeholder styling + prefix/suffix + `Styles` | — |
| `TextArea\TextArea` | Multi-line editor with line numbers / set-prompt-func / `focused()` / `cursor()` / `line()` / `column()`; `Ctrl+O` opens the buffer in `$EDITOR` (`withEditorExtension('.md')` to control the syntax-highlight suffix) | `TextArea\TextAreaEditedMsg` |
| `Viewport\Viewport` | Scrollable text region with mouse-wheel, scrollbar, horizontal scroll, `setWidth(int)` / `setHeight(int)` | — |
| `Paginator\Paginator` | Dot / arabic page indicator | — |
| `ItemList\ItemList` | Selectable / scrollable / filterable list with status messages | — |
| `Tree\Tree` | Interactive tree — cursor, expand/collapse, viewport scroll. Mirrors upstream Bubbles #233. | — |
| `Table\Table` | Selectable data table with `Column` struct + nav + multi-column sort | — |
| `Tabs\Tabs` | Tabbed panel — keyboard (`Tab`/`Shift+Tab`/`1-9`) + mouse navigation, wrap/clamp modes, scrollable overflow | — |
| `FilePicker\FilePicker` | Directory browser with icons / size / sort modes | — |

### Vim mode

`TextInput` vim mode (Insert/Normal/Visual/VisualLine keybindings) is
powered by `candy-forms`'s shared `VimKeyHandler` — the same handler
backing `sugar-prompt` and `sugar-readline`. Adding a new binding to the
`VimAction` enum benefits all three libs at once. The per-lib opt-in flag
`withVimMode(true)` is preserved; consumers control whether vim mode is
enabled.

### Msg routing cheat-sheet

Forward these into your model's `update()` so the embedded component
can react: `BlinkMsg` (Cursor / TextInput), `Spinner\TickMsg`
(Spinner), `Timer\TickMsg` + `Timer\TimeoutMsg`, `Stopwatch\TickMsg`,
`SpringTickMsg` (AnimatedProgress), `StartStopMsg` (Timer / Stopwatch),
`TextArea\TextAreaEditedMsg` (TextArea's Ctrl+O round-trip).
Each component's `update()` filters by its own `id()` so multiple
instances of the same component coexist on one event loop.

## Quickstart — TextInput with autocomplete

```php
use SugarCraft\Bits\TextInput\TextInput;
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
use SugarCraft\Bits\Progress\AnimatedProgress;

$bar = AnimatedProgress::new()
    ->withWidth(40)
    ->withDefaultGradient();

[$bar, $cmd] = $bar->setPercent(0.75);
// dispatch $cmd via the Program — ticks re-fire from inside update()
// until the bar settles within 5e-4 of the target.
```

## Quickstart — TextInput with placeholder styling and prefix/suffix

```php
use SugarCraft\Bits\TextInput\TextInput;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Core\Util\Color;

$ti = TextInput::new()
    ->withPlaceholder('Enter command…')
    ->withPlaceholderStyle(Style::new()->faint())           // default: dim
    ->withPrefix('$ ')                                      // fixed prefix
    ->withSuffix(' <');                                     // fixed suffix

echo $ti->view();
// $ Enter command… <
```

## TextInput — ValidateOn and restrict

`TextInput` supports deferred and filtered validation via two new builders:

### ValidateOn timing control

```php
use SugarCraft\Bits\TextInput\{TextInput, ValidateOn};

$ti = TextInput::new()
    ->withValidateOn(ValidateOn::Blur);   // validate when focus leaves
```

| Case | When validation fires |
|------|-----------------------|
| `ValidateOn::None` | Never (default — use when you drive validation manually) |
| `ValidateOn::Blur` | When the input loses focus |
| `ValidateOn::Change` | On every keystroke |
| `ValidateOn::Submit` | Only on `Enter` keypress |

### Keystroke filter (restrict)

```php
use SugarCraft\Bits\TextInput\TextInput;

// Accept only digits
$numeric = TextInput::new()->withRestrict('[0-9]');

// Accept alphanumeric only
$alphanum = TextInput::new()->withRestrict('[a-zA-Z0-9]');
```

### TextInput notable builders

| Method | What it does |
|--------|--------------|
| `withValidateOn(ValidateOn $timing)` | Set validation timing (`None` / `Blur` / `Change` / `Submit`) |
| `withRestrict(string $pattern)` | Set a PCRE regex — only matching characters are accepted (no delimiters) |

## Table — multi-column sort

```php
use SugarCraft\Bits\Table\{Table, SortDirection, SortState};

// Primary sort by Name ascending
$t = $table->withSort('Name');

// Tiebreaker: Age descending
$t = $table->thenSortBy('Age', SortDirection::Desc);

// Reset to insertion order
$t = $table->clearSort();

// Inspect current sort criteria
$state = $t->getSortState(); // SortState
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
| `getSortState(): SortState` | Return the current sort criteria (readonly accessor) |

Sorting throws `\InvalidArgumentException` with message `table.sort_unknown_column` when the column name is not found. The exception message is localizable.

## Table — filtering

```php
use SugarCraft\Bits\Table\Table;

// Enable the filter feature (opt-in)
$t = $table->withFilterable(true);

// Set a query string — default: case-insensitive substring match across all visible columns
$t = $table->withFilter('foo');

// Custom filter: receives a row (list<string>), returns true to keep
$t = $table->withFilterPredicate(fn(array $row): bool =>
    str_contains(strtolower(implode("\t", $row)), 'foo')
);

// Inspect current filter state
$isFilterable = $t->getFilterable();   // bool
$query        = $t->getFilter();        // string
$predicate    = $t->getFilterPredicate(); // ?Closure(list<string>): bool
```

When `withFilterPredicate()` is set, it overrides the default substring-match behaviour. Pass `null` to restore the default.

### Table filter builders

| Method | Description |
|--------|-------------|
| `withFilterable(bool $filterable)` | Enable or disable the filter feature |
| `withFilter(string $query)` | Set the filter query string (non-empty enables filtering) |
| `withFilterPredicate(?Closure(list<string>): bool $predicate)` | Custom filter callable — `null` restores the default |
| `getFilterable(): bool` | Return whether filtering is enabled |
| `getFilter(): string` | Return the current filter query string |
| `getFilterPredicate(): ?Closure` | Return the current custom predicate |

The default filter applies case-insensitive substring matching across all visible columns.

## Table — pagination

```php
use SugarCraft\Bits\Table\Table;

// Enable pagination: 10 rows per page
$t = $table->withPageSize(10);

// Navigate pages
$t = $t->withPage(1);   // zero-based — go to page 1 (second page)
$t = $t->nextPage();
$t = $t->prevPage();
$t = $t->pageFirst();
$t = $t->pageLast();

// Inspect pagination state
$pageSize   = $t->getPageSize();      // int — rows per page (0 = pagination disabled)
$current   = $t->getCurrentPage();   // int — zero-based current page
$totalPages = $t->getTotalPages();  // int — 1 when pagination is disabled

// Wire a Paginator to the table for UI rendering
$paginator = $t->getPaginator();    // Paginator instance
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
| `getPageSize(): int` | Return rows per page (`0` = pagination off) |
| `getCurrentPage(): int` | Return the current zero-based page |
| `getTotalPages(): int` | Return the total page count (`1` when pagination is disabled) |
| `getPaginator(): Paginator` | Return a `Paginator` instance wired to the table's current page state |

Pagination works with sort and filter: changing the sort order, filter query, or page size automatically re-clamps the cursor to the first row of the current page so the cursor never points to a row outside the current page boundary.

## Snapshot tests

Render output is covered by golden-file snapshot tests. Fixture files live
in `tests/fixtures/` with a `.golden` extension and are compared against
actual ANSI byte output via `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi()`.
To re-record fixtures after intentional output changes:

```sh
UPDATE_GOLDENS=1 vendor/bin/phpunit
```

## Test

```sh
cd sugar-bits && composer install && vendor/bin/phpunit
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

### Tabs

![tabs](.vhs/tabs.gif)

### Table

![table](.vhs/table.gif)

### Text area

![text-area](.vhs/text-area.gif)

### Text input

![text-input](.vhs/text-input.gif)

### Text input (enhanced)

![text-input](.vhs/text-input-enhanced.gif)

### Timer

![timer](.vhs/timer.gif)

### Tree

![tree](.vhs/tree.gif)

### Viewport

![viewport](.vhs/viewport.gif)

## Related

- [SugarCraft monorepo](https://github.com/detain/sugarcraft)
- Upstream: [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles)

