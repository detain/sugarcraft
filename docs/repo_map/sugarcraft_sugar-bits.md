# SugarCraft/sugar-bits

## Metadata
- **URL:** https://github.com/sugarcraft/sugar-bits
- **Language:** PHP 8.3+
- **License:** MIT
- **Status:** 🟡 In Progress (15 components, many as aliases to candy-forms)
- **Description:** PHP port of charmbracelet/bubbles — 15+ pre-built TUI components for SugarCraft, including interactive Tree, dynamic-height TextArea, per-cell Table::styleFunc(), and spring-physics AnimatedProgress.

## Architecture Overview

### Deprecated Alias Pattern
sugar-bits employs a **stratified alias pattern** where many components are re-exported from `SugarCraft\Forms\*` with deprecation notices:

| sugar-bits Source | Points To | Status |
|---|---|---|
| `TextInput` | `SugarCraft\Forms\TextInput\TextInput` | 🟡 alias (full impl in candy-forms) |
| `ItemList` | `SugarCraft\Forms\ItemList\ItemList` | 🟡 alias (full impl in candy-forms) |
| `Viewport` | `SugarCraft\Forms\Viewport\Viewport` | 🟡 alias (full impl in candy-forms) |
| `FilePicker` | `SugarCraft\Forms\FilePicker\FilePicker` | 🟡 alias (full impl in candy-forms) |
| `Spinner` | `SugarCraft\Forms\Spinner\Spinner` | 🟡 alias (full impl in candy-forms) |
| `Cursor` | `SugarCraft\Forms\Cursor\Cursor` | 🟡 alias (full impl in candy-forms) |
| `TextArea` | `SugarCraft\Forms\TextArea\TextArea` | 🟡 alias (full impl in candy-forms) |
| `Scrollbar` | `SugarCraft\Forms\Scrollbar\Scrollbar` | 🟡 alias (full impl in candy-forms) |

**Original implementations** in sugar-bits itself:
- `Table` (720 lines) — sortable, filterable, paginated table with per-cell styleFunc
- `Tree` (364 lines) — interactive tree with expand/collapse
- `Tabs` (602 lines) — tabbed panel with keyboard/mouse navigation
- `Help` (311 lines) — keybinding help renderer
- `Paginator` (225 lines) — pagination state + dot/arabic rendering
- `Timer` (177 lines) — countdown timer with tick messages
- `Stopwatch` (141 lines) — elapsed time counter
- `Progress` (434 lines) — static progress bar with gradients
- `AnimatedProgress` — spring-physics progress (own class)
- `Key\Binding` (149 lines) — keybinding with help labels
- `Key\Help` — help label struct

### Dependencies
```json
{
  "sugarcraft/candy-core": "dev-master",      // TUI framework foundation
  "sugarcraft/candy-forms": "dev-master",        // Form primitives (most aliases)
  "sugarcraft/candy-sprinkles": "dev-master",   // Styling system
  "sugarcraft/candy-zone": "dev-master",          // Zone/mouse handling
  "sugarcraft/honey-bounce": "dev-master"     // Spring physics (AnimatedProgress)
}
```

---

## Component-by-Component Analysis

### 1. Table (`src/Table/Table.php` — 720 lines)

**Original implementation** in sugar-bits, not an alias.

**Features:**
- Column definitions via `Column` struct with optional fixed width
- Sortable: `withSort()`, `thenSortBy()`, `clearSort()` — multi-column sort chain
- Filterable: `withFilterable()`, `withFilter()`, `withFilterPredicate()` — custom predicate closure
- Paginated: `withPageSize()`, `withPage()`, `nextPage()`, `prevPage()`, `pageFirst()`, `pageLast()`
- Per-cell styling: `styleFunc(\Closure $fn)` — callback `(int $row, int $col): Style`
- Cursor navigation: up/down/Home/End/PageUp/PageDown + vim `j/k/g/G`
- Row selection with `selectedRow()`, `setCursor()`
- Viewport scrolling when rows exceed `height`

**Key implementation details:**
```php
// Multi-column sort state (src/Table/SortState.php)
public readonly array $criteria; // list<array{0:int,1:SortDirection}>

// Per-cell styleFunc (mirrors upstream #246)
public function styleFunc(?\Closure $fn): self
{
    return $this->mutate(styleFunc: $fn, styleFuncSet: true);
}

// Filtering with predicate
public function withFilterPredicate(?\Closure $predicate): self
{
    return $this->mutate(filterPredicate: $predicate, filterPredicateSet: true);
}
```

**Comparison to upstream bubbles:**
- Mirrors upstream `WithColumns`, `SetFilter`, `SetSort`, `SetPageSize`
- Missing: `HalfPageUp`, `HalfPageDown` navigation
- Per-cell styleFunc is a **SugarCraft enhancement** — upstream Bubbles #246 was long-requested

**Comparison to stickers/table:**
- stickers Table builds on ratio-based FlexBox; sugar-bits Table uses fixed-width columns
- stickers has x/y bidirectional scrolling; sugar-bits has vertical viewport scrolling only
- stickers uses generics for type-safe columns; sugar-bits uses string arrays
- stickers has footer status bar; sugar-bits does not

### 2. TextInput (`candy-forms/src/TextInput/TextInput.php` — 987 lines, aliased)

**Full implementation** in candy-forms, re-exported from sugar-bits.

**Features:**
- Single-line input with cursor positioning
- Echo modes: Normal, Password (mask char), None (hidden)
- Placeholder styling with custom `Style`
- Prefix/suffix strings (fixed, non-editable)
- Autocomplete suggestions with `matchedSuggestions()`, `acceptSuggestion()`
- Input history with `withHistory()`, `addToHistory()`, up/down navigation
- Vim mode: `h/l` movement, `w/b` word nav, `i/a/A/I` insert mode, `x` delete
- Validation: `withValidator()`, `withValidateOn(ValidateOn::Blur|Change|Submit|None)`
- Restrict pattern: `withRestrict('[0-9]')` — PCRE filter on keystrokes
- Char limit, width limiting with horizontal scroll
- Ctrl+A/E/U/K for line editing

**Key vim mode implementation (lines 153-260):**
```php
private function vimUpdate(KeyMsg $msg): array
{
    if ($this->vimNormalMode) {
        // h/l, w/b, 0/$, x, i/a/A/I, u/Ctrl+r
        if ($msg->type === KeyType::Char && $msg->rune === 'w') {
            return [$this->vimWordForward(), null];
        }
        // ... full vim key handling
    }
    // Insert mode: Escape returns to normal
}
```

**Comparison to upstream bubbles:**
- Mirrors all upstream features
- Vim mode is **SugarCraft enhancement** — not in upstream Go Bubbles
- ValidateOn timing control is **SugarCraft enhancement**
- `withRestrict()` pattern filter is **SugarCraft enhancement**

**Comparison to promptkit/textinput:**
- promptkit has auto-complete with common-prefix detection
- sugar-bits has suggestions but no auto-insert of common prefix
- Both support password hidden mode, validation, char limit

### 3. ItemList (`candy-forms/src/ItemList/ItemList.php`, aliased)

**Full implementation** in candy-forms, re-exported from sugar-bits.

**Features:**
- Selectable, scrollable, filterable list
- Filter-as-you-type with `/` key (case-insensitive substring)
- Keep filter on Enter: `withKeepFilter(true)`
- Pagination with `nextPage()`, `prevPage()`
- Vim keybindings (j/k for up/down, g/G for top/bottom)
- Item management: `setItems()`, `insertItem()`, `removeItem()`, `setItem()`
- Custom cursor prefix/selected prefix
- `StringItem` convenience class for simple string items

**Key filtering implementation (from tests):**
```php
public function testFilteringMatchesSubstring(): void
{
    $l = $this->focused();
    [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
    $this->assertTrue($l->isFiltering());
    [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
    [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'n'));
    $titles = array_map(static fn($i) => $i->title(), $l->visibleItems());
    $this->assertSame(['banana'], $titles);
}
```

**Comparison to upstream bubbles List:**
- Upstream uses `sahilm/fuzzy` for ranked fuzzy filtering
- sugar-bits uses simple case-insensitive substring matching
- sugar-bits lacks fuzzy ranking with character indices

**Comparison to bubblelister:**
- bubblelister has pluggable prefixer/suffixer interfaces
- bubblelister has concurrent search via goroutines
- sugar-bits ItemList has simpler filtering but better pagination

**Comparison to pterm InteractiveSelect:**
- pterm uses `lithammer/fuzzysearch` for ranked fuzzy filtering
- sugar-bits lacks fuzzy ranking

### 4. Viewport (`candy-forms/src/Viewport/Viewport.php`, aliased)

**Full implementation** in candy-forms, re-exported from sugar-bits.

**Features:**
- Scrollable text region with `setWidth()`, `setHeight()`
- Mouse wheel support for scrolling
- Horizontal scroll support
- Scrollbar rendering

**Comparison to upstream bubbles Viewport:**
- Mirrors upstream API
- Soft-wrap option available
- Gutter functions available

### 5. FilePicker (`candy-forms/src/FilePicker/FilePicker.php`, aliased)

**Full implementation** in candy-forms, re-exported from sugar-bits.

**Features:**
- Directory traversal with `../` navigation
- Hidden file toggle
- Extension filtering
- Sort by name/size/date
- File/directory icons

### 6. Progress (`src/Progress/Progress.php` — 434 lines)

**Original implementation** in sugar-bits.

**Features:**
- Static progress bar with gradient fills
- `withGradient(Color $start, Color $end)` — 2-stop gradient
- `withColors(Color ...$colors)` — multi-stop gradient
- `withColorFunc(Closure $fn)` — per-cell color callback
- `withShowValue()` — show current/total values
- `withRenderMode(ProgressRenderMode::Block|Line|Slim)` — 3 render modes
- `withPercentFormat()` — custom percent format string

**Render modes (lines 210-319):**
```php
public function view(): string
{
    // Block (default): ████░░░░░ 42%
    // Line: ━━━━━━━━  (no percent text)
    // Slim: ▌▌▌▌▒▒▒▒ 42%  (vertical blocks)
}
```

**Comparison to upstream bubbles Progress:**
- Mirrors upstream API
- Multi-stop gradient is **SugarCraft enhancement**
- Render mode enum (Block/Line/Slim) is **SugarCraft enhancement**

**Comparison to pterm ProgressbarPrinter:**
- pterm has RGB gradient fade (red→green)
- sugar-bits has explicit Color stops with blend
- pterm has elapsed time display; sugar-bits does not

### 7. AnimatedProgress (`src/Progress/AnimatedProgress.php`)

**Original implementation** in sugar-bits (separate class).

**Features:**
- Spring-physics interpolation using honey-bounce
- Settles to target percent with spring animation
- Returns cmd for re-tick until settled

### 8. Spinner (`candy-forms/src/Spinner/Spinner.php`, aliased)

**Full implementation** in candy-forms, re-exported from sugar-bits.

**Features:**
- 12 built-in styles: Line, Dot, MiniDot, Jump, Pulse, Points, Globe, Moon, Monkey, Meter, Hamburger, Ellipsis
- Animated via `TickMsg`
- Styled via `Style`

### 9. Cursor (`candy-forms/src/Cursor/Cursor.php`, aliased)

**Full implementation** in candy-forms, re-exported from sugar-bits.

**Features:**
- Virtual cursor with blink animation via `BlinkMsg`
- Modes: Blinking, Static, Hidden
- Embedded in TextInput for inline cursor rendering

### 10. Help (`src/Help/Help.php` — 311 lines)

**Original implementation** in sugar-bits.

**Features:**
- Renders `KeyMap` as short single-line or multi-column full block
- `showAll(true)` toggles short↔full rendering
- `width(int)` caps rendered width with ellipsis truncation
- `shortView()` / `fullView()` / `view(KeyMap)` entry points
- Styles customization via `Styles` struct

**Short-form aliases (lines 101-105):**
```php
public function styles(?Styles $styles): self     { return $this->withStyles($styles); }
public function separator(string $s): self        { return $this->withSeparator($s); }
public function ellipsis(string $glyph): self     { return $this->withEllipsis($glyph); }
public function fullSeparator(string $s): self     { return $this->withFullSeparator($s); }
```

**Comparison to upstream bubbles Help:**
- Mirrors upstream API
- Styles customization is **SugarCraft enhancement** (upstream uses lipgloss styles)

### 11. Paginator (`src/Paginator/Paginator.php` — 225 lines)

**Original implementation** in sugar-bits.

**Features:**
- Dot-style (`● ○ ○ ○ ○`) or Arabic (`3/8`) pagination
- Keyboard navigation: left/right arrows, h/l vim keys, PageUp/PageDown
- `sliceBounds()` returns `[start, end)` for current page
- `setTotalPages()` for pre-allocating dots
- `itemsOnPage()` returns count of visible items

### 12. Timer (`src/Timer/Timer.php` — 177 lines)

**Original implementation** in sugar-bits.

**Features:**
- Countdown from duration to 0
- `start()` schedules first `TickMsg` tick
- `stop()` / `toggle()` / `reset()`
- `TimeoutMsg` dispatched when timer reaches 0
- ID-based message routing for multiple timers

**Comparison to upstream bubbles Timer:**
- Mirrors upstream API exactly
- Static `format()` helper for "H:MM:SS" formatting

### 13. Stopwatch (`src/Stopwatch/Stopwatch.php` — 141 lines)

**Original implementation** in sugar-bits.

**Features:**
- Count-up elapsed time (no upper bound)
- `start()` / `stop()` / `toggle()` / `reset()`
- `TickMsg` for incremental updates
- Shares formatting with Timer

### 14. Tree (`src/Tree/Tree.php` — 364 lines)

**Original implementation** in sugar-bits.

**Features:**
- Interactive tree with `Node::branch()` / `Node::leaf()` factories
- Expand/collapse with `Enter`, `→`/`←` or `l`/`h`
- Cursor navigation: up/down, g/G for top/bottom
- `selectedNode()` / `selectedValue()` accessors
- `visibleRows()` computes flattened visible list
- Glyphs: `▼` expanded, `▶` collapsed, configurable

**Node structure (src/Tree/Node.php):**
```php
final class Node
{
    public readonly string $label;
    public readonly mixed $value;       // arbitrary payload
    public readonly bool $expanded;
    public readonly array $children;   // list<Node>

    public static function branch(string $label, Node ...$children): self { ... }
    public static function leaf(string $label, mixed $value = null): self { ... }
}
```

**Comparison to upstream bubbles Tree:**
- Mirrors upstream Bubbles #233 (long-requested feature)
- No built-in fuzzy filtering (noted as TODO)

### 15. Tabs (`src/Tabs/Tabs.php` — 602 lines)

**Original implementation** in sugar-bits.

**Features:**
- Keyboard: Tab/Shift+Tab (wrap or clamp), 1-9 for direct jump
- Mouse: zone-based click via `Manager`
- Scrollable overflow with ellipsis
- `withZoneManager()` attaches mouse zone tracking
- Styles: active (bold) vs inactive
- Divider customization between tabs

**Zone manager integration (lines 116-123):**
```php
if ($msg instanceof MsgZoneInBounds && $this->focused) {
    $tabIndex = $this->tabIndexFromZoneId($msg->zone->id);
    if ($tabIndex !== null && ...) {
        return [$this->withActive($tabIndex), null];
    }
}
```

**Comparison to upstream bubbles Tabs:**
- Mirrors upstream API
- Mouse zone integration is **SugarCraft enhancement**

### 16. Key/Binding (`src/Key/Binding.php` — 149 lines)

**Original implementation** in sugar-bits.

**Features:**
- Key binding with `keys` array and `Help` label
- `matches(KeyMsg)` checks if key matches
- `Binding::any()` variadic helper
- `disable()` / `setEnabled()` for toggle
- `unbind()` drops keys while preserving help

**Comparison to upstream bubbles Key:**
- Mirrors upstream API

### 17. TextArea (`candy-forms/src/TextArea/TextArea.php`, aliased)

**Full implementation** in candy-forms, re-exported from sugar-bits.

**Features:**
- Multi-line editor with line numbers
- Soft-wrapping with `focused()`, `cursor()`, `line()`, `column()`
- Ctrl+O opens buffer in `$EDITOR` with `withEditorExtension('.md')`

---

## Widget Patterns

### Immutable + Fluent (`with*` pattern)
All components follow immutable builder pattern:
```php
$table = Table::new($headers, $rows, 38, 7)
    ->withSort('Stars', SortDirection::Desc)
    ->withFilterable(true)
    ->withFilter('Go')
    ->withPageSize(10);
```

### Private `mutate()` helper
Components use private `mutate()` with sentinel `bool $XSet = false` for nullable fields:
```php
private function mutate(
    ?Styles $styles = null, bool $stylesSet = false,
    ?\Closure $styleFunc = null, bool $styleFuncSet = false,
    // ...
): self {
    return new self(
        styles: $stylesSet ? $styles : $this->styles,
        styleFunc: $styleFuncSet ? $styleFunc : $this->styleFunc,
        // ...
    );
}
```

### Model Contract
Components implement `Model` interface:
```php
interface Model {
    public function init(): ?\Closure;
    public function update(Msg $msg): array;  // [Model, ?Cmd]
    public function view(): string;
    public function subscriptions(): ?\Subscriptions;
}
```

### ID-based Message Routing
Timer/Stopwatch/Cursor use static `$nextId` counter:
```php
private static int $nextId = 0;
private function __construct(
    public readonly float $remaining,
    public readonly int $id = null,
) {
    $this->id = $id ?? ++self::$nextId;
}
```
TickMsg carries id; update filters by `if ($msg instanceof TickMsg && $msg->id === $this->id)`.

---

## Event Handling Conventions

### KeyMsg Routing
Components check `$msg instanceof KeyMsg && $this->focused` before processing keys.

### Vim Keybindings
TextInput and ItemList support `j/k` for up/down, `g/G` for top/bottom:
```php
$msg->type === KeyType::Char && $msg->rune === 'j'  // down
$msg->type === KeyType::Char && $msg->rune === 'k'  // up
$msg->type === KeyType::Char && $msg->rune === 'g'  // top (first press)
$msg->type === KeyType::Char && $msg->rune === 'G'  // bottom
```

### Focus/Blur
Components expose `focus(): array` (returns `[self, ?Cmd]`) and `blur(): self`:
```php
public function focus(): array { return [$this->mutate(focused: true), null]; }
public function blur(): self   { return $this->mutate(focused: false); }
```

### Zone-based Mouse Handling
Tabs uses `Manager` for zone detection:
```php
$tabs->withZoneManager($manager);  // Attach manager
// Parent calls Manager::anyInBoundsAndUpdate() on MouseMsg
```

---

## Styling Integration Patterns

### Style Nullable with Defaults
Help, Table, TextInput use nullable styles with no-op defaults:
```php
public readonly ?Styles $styles;  // null = no styling

public function withStyles(?Styles $styles): self {
    return $this->mutate(styles: $styles, stylesSet: true);
}
```

### Per-cell styleFunc (Table enhancement)
Table's `styleFunc` runs **per-cell** before row-level styles:
```php
$t = $table->styleFunc(fn(int $row, int $col): Style => match true {
    $row === Table::HEADER_ROW => Style::new()->bold(),
    $row % 2 === 0 => Style::new()->dim(),
    default => Style::new(),
});
```

### candy-sprinkles Style
Components use `SugarCraft\Sprinkles\Style` for ANSI rendering:
```php
use SugarCraft\Sprinkles\Style;

$style = Style::new()->bold()->foreground(Color::cyan());
$output = $style->render('Hello');
```

---

## Component Composition Patterns

### Table + Paginator
Table exposes `getPaginator()` for UI rendering:
```php
$paginator = $table->getPaginator();
echo $paginator->view();  // "● ○ ○ ○ ○" or "3/8"
```

### Table + AnimatedProgress via honey-bounce
Progress uses honey-bounce for spring physics:
```php
use SugarCraft\Bits\Progress\AnimatedProgress;
use SugarCraft\Core\Cmd;

[$bar, $cmd] = $bar->setPercent(0.75);
// $cmd fires SpringTickMsg until bar settles
```

### Viewport + Scrollbar
Viewport uses Scrollbar for visual scrollbar:
```php
$vp = $viewport->withScrollbar(true);
```

### Tree + Viewport
Tree uses viewport scroll when `height` is set:
```php
$tree = Tree::new(...)->withSize(40, 10);  // 10-row viewport
```

---

## Incomplete Features (🟡 Status)

### Known Gaps

1. **ItemList fuzzy filtering** — Uses simple substring match. Upstream bubbles uses `sahilm/fuzzy` for ranked matches with character indices. sugar-bits lacks:
   - Fuzzy ranking with match score
   - Character index reporting for highlighted filtering UI
   - `DefaultFilter` / `UnsortedFilter` variants

2. **TextArea soft-wrap line tracking** — Upstream maintains `LineInfo` struct with Width, Height, CharWidth, ColumnOffset, RowOffset. sugar-bits TextArea needs equivalent accounting for double-width Unicode.

3. **TextArea case transforms** — Upstream has `alt+u/l/c` for uppercase/lowercase/capitalize word forward. Not yet in PHP port.

4. **TextArea character transpose** — `ctrl+t` for transposition not implemented.

5. **Progress bar half-block color blending** — Upstream uses Unicode `▌` with separate foreground/background for 2x color resolution. Not yet in sugar-bits.

6. **Help KeyMap interface introspection** — Upstream calls `ShortHelp() / FullHelp()` on any KeyMap implementor. sugar-bits Help renders directly from `KeyMap` struct.

7. **Tree fuzzy filtering** — Noted as TODO in upstream.

8. **Table half-page navigation** — `HalfPageUp` / `HalfPageDown` not implemented.

9. **Interactive selection highlights** — pterm's InteractiveSelectPrinter has fuzzy search ranking. sugar-bits ItemList lacks ranked filtering.

10. **smenu TST-based filtering** — No ternary search tree implementation for fast prefix/fuzzy search of large word lists.

---

## Comparison Matrix

| Feature | sugar-bits | bubbles (Go) | pterm (Go) | stickers (Go) | promptkit (Go) |
|---------|-----------|-------------|-----------|--------------|---------------|
| TextInput vim mode | ✅ | ❌ | ❌ | ❌ | ❌ |
| ValidateOn timing | ✅ | ❌ | ❌ | ❌ | ❌ |
| Per-cell styleFunc | ✅ | ❌ | ❌ | ❌ | ❌ |
| Multi-col sort | ✅ | ❌ | ❌ | ❌ | ❌ |
| Filter predicate | ✅ | ❌ | ❌ | ❌ | ❌ |
| Fuzzy filtering | ❌ | ✅ | ✅ | ❌ | ✅ |
| Tree component | ✅ | ❌ | ✅ | ❌ | ❌ |
| FlexBox layout | ❌ | ❌ | ❌ | ✅ | ❌ |
| Progress gradients | ✅ | ✅ | ✅ | ❌ | ❌ |
| Spring animation | ✅ | ✅ | ❌ | ❌ | ❌ |
| Tabs with zones | ✅ | ❌ | ❌ | ❌ | ❌ |
| Generics | N/A | ✅ | ❌ | ✅ | ✅ |

---

## Innovation Points

### SugarCraft Enhancements Over Upstream

1. **Per-cell Table styling** — Mirrors upstream Bubbles #246 (long-requested feature)
2. **Vim mode for TextInput** — Not in upstream Go implementation
3. **ValidateOn timing control** — Deferred validation on Blur/Submit
4. **withRestrict() pattern filter** — PCRE keystroke filtering
5. **Tabs zone-based mouse** — Mouse click handling via Manager
6. **Multi-stop gradients** — `withColors(Color ...$colors)` for gradient with N stops
7. **Render modes for Progress** — Block/Line/Slim variants
8. **Short-form aliases** — `placeholder()`, `charLimit()`, `width()` for ergonomic chaining
9. **SortState DTO** — Immutable sort criteria chain
10. **Tree Node factories** — `Node::branch()` / `Node::leaf()` for ergonomic tree construction

---

## File References

### Core Source Files
- `/home/sites/sugarcraft/sugar-bits/src/Table/Table.php` — 720 lines
- `/home/sites/sugarcraft/sugar-bits/src/Tree/Tree.php` — 364 lines
- `/home/sites/sugarcraft/sugar-bits/src/Tabs/Tabs.php` — 602 lines
- `/home/sites/sugarcraft/sugar-bits/src/Help/Help.php` — 311 lines
- `/home/sites/sugarcraft/sugar-bits/src/Progress/Progress.php` — 434 lines
- `/home/sites/sugarcraft/sugar-bits/src/Paginator/Paginator.php` — 225 lines
- `/home/sites/sugarcraft/sugar-bits/src/Timer/Timer.php` — 177 lines
- `/home/sites/sugarcraft/sugar-bits/src/Stopwatch/Stopwatch.php` — 141 lines
- `/home/sites/sugarcraft/sugar-bits/src/Key/Binding.php` — 149 lines

### Alias Sources (candy-forms)
- `/home/sites/sugarcraft/candy-forms/src/TextInput/TextInput.php` — 987 lines
- `/home/sites/sugarcraft/candy-forms/src/ItemList/ItemList.php` — 680+ lines
- `/home/sites/sugarcraft/candy-forms/src/Viewport/Viewport.php`
- `/home/sites/sugarcraft/candy-forms/src/FilePicker/FilePicker.php`
- `/home/sites/sugarcraft/candy-forms/src/TextArea/TextArea.php`
- `/home/sites/sugarcraft/candy-forms/src/Spinner/Spinner.php`
- `/home/sites/sugarcraft/candy-forms/src/Cursor/Cursor.php`
- `/home/sites/sugarcraft/candy-forms/src/Scrollbar/Scrollbar.php`

### Tests
- `/home/sites/sugarcraft/sugar-bits/tests/Table/` — 5 test files (pagination, sort, filter, styles, padding regression)
- `/home/sites/sugarcraft/sugar-bits/tests/ItemList/ItemListTest.php` — 378 lines
- `/home/sites/sugarcraft/sugar-bits/tests/TextInput/TextInputTest.php`
- `/home/sites/sugarcraft/sugar-bits/tests/Tree/TreeTest.php`
- `/home/sites/sugarcraft/sugar-bits/tests/Timer/TimerTest.php`
- `/home/sites/sugarcraft/sugar-bits/tests/Progress/ProgressTest.php`

### Examples
- `/home/sites/sugarcraft/sugar-bits/examples/table.php`
- `/home/sites/sugarcraft/sugar-bits/examples/item-list.php`
- `/home/sites/sugarcraft/sugar-bits/examples/text-input.php`
- `/home/sites/sugarcraft/sugar-bits/examples/tree.php`
- `/home/sites/sugarcraft/sugar-bits/examples/timer.php`
- `/home/sites/sugarcraft/sugar-bits/examples/viewport.php`
- `/home/sites/sugarcraft/sugar-bits/examples/file-picker.php`

### Documentation
- `/home/sites/sugarcraft/sugar-bits/README.md` — 387 lines
- `/home/sites/sugarcraft/sugar-bits/CALIBER_LEARNINGS.md` — 12 lines
- `/home/sites/sugarcraft/sugar-bits/lang/en.php` + 15 locales

---

## Analysis

**sugar-bits** is a mature, well-structured PHP port of charmbracelet/bubbles with significant enhancements. The stratified architecture (aliases pointing to candy-forms while keeping original implementations for Table, Tree, Tabs, Help, etc.) allows parallel development and clear ownership.

**Strengths:**
- Comprehensive 15+ component coverage
- Immutable + fluent builder pattern throughout
- Per-cell styling enhancement not in upstream
- Vim mode and ValidateOn timing are valuable ergonomic improvements
- Good test coverage with behavioral and snapshot tests
- i18n support with 16 locales

**Gaps:**
- Fuzzy filtering lacks ranking (bubbles uses sahilm/fuzzy)
- TextArea soft-wrap lacks full LineInfo accounting
- Tree has no fuzzy filtering (TODO in upstream)
- No FlexBox layout component (stickers provides this)
- No TST-based fast prefix search (smenu pattern)

**Strategic position:** sugar-bits is the primary component library for building TUI applications in SugarCraft. Its layered relationship with candy-forms (where the heavier implementations live) allows sugar-bits to focus on leaf components while forms provides the foundational widgets. The 🟡 status correctly reflects that fuzzy filtering and some advanced TextArea features remain incomplete.

---

## Related Reports

- `/home/sites/sugarcraft/repo_map/charmbracelet_bubbles.md` — Primary upstream (Go)
- `/home/sites/sugarcraft/repo_map/charmbracelet_huh.md` — Form framework built on bubbles
- `/home/sites/sugarcraft/repo_map/pterm_pterm.md` — 25+ printer components (Go)
- `/home/sites/sugarcraft/repo_map/76creates_stickers.md` — FlexBox/Table for bubbletea
- `/home/sites/sugarcraft/repo_map/erikgeiser_promptkit.md` — Prompt library for bubbletea
- `/home/sites/sugarcraft/repo_map/Genekkion_theHermit.md` — Quick-fix overlay for bubbletea
- `/home/sites/sugarcraft/repo_map/rmhubbert_bubbletea-overlay.md` — Modal overlay for bubbletea
- `/home/sites/sugarcraft/repo_map/treilik_bubblelister.md` — List widget for bubbletea
- `/home/sites/sugarcraft/repo_map/Bdeering1_console-menu.md` — Simple menu in Rust
- `/home/sites/sugarcraft/repo_map/p-gen_smenu.md` — C selection filter with TST indexing
