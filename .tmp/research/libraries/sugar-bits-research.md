# sugar-bits Component Library Research

**Date:** 2026-05-13
**Upstream:** charmbracelet/bubbles (Go)
**Focus:** Prebuilt interactive TUI components for the SugarCraft monorepo

---

## Executive Summary

sugar-bits provides 14+ prebuilt TUI components (TextInput, TextArea, Table, ItemList, Paginator, etc.) following the Bubble Tea MVU model. This research compares sugar-bits against alternatives in Go, Rust, Python, and Node.js to identify API improvements, cleaner patterns, and additional features worth porting.

**Key findings:**
1. sugar-bits TextInput is more feature-rich than Bubbles (vim mode, history limits, sentinel-based setters)
2. Table lacks sorting/filtering/pagination that bubble-table provides
3. ratatui's separated state pattern (TableState/ListState) is worth adopting for cleaner render loops
4. Textual's validator timing (`validate_on`) is a better API than sugar-bits' single validator approach
5. lipgloss-style fluent styling API should be adopted consistently

---

## 1. TextInput Comparison

### 1.1 Current sugar-bits API (912 lines)

```php
// Source: sugar-bits/src/TextInput/TextInput.php:L36-L71
final class TextInput implements Model
{
    private function __construct(
        public readonly string $value,
        public readonly int $cursorPos,
        public readonly string $placeholder,
        public readonly Style $placeholderStyle,
        public readonly string $prompt,
        public readonly int $charLimit,
        public readonly int $width,
        public readonly bool $focused,
        public readonly Cursor $cursor,
        public readonly EchoMode $echoMode,
        public readonly string $echoChar,
        public readonly int $offset,
        public readonly array $suggestions = [],
        public readonly bool $showSuggestions = false,
        public readonly int $currentSuggestionIndex = 0,
        public readonly ?\Closure $validate = null,
        public readonly ?string $err = null,
        public readonly ?Styles $styles = null,
        public readonly bool $vimMode = false,
        public readonly bool $vimNormalMode = true,
        public readonly string $prefix = '',
        public readonly string $suffix = '',
        public readonly array $history = [],
        public readonly int $historyIndex = -1,
        public readonly int $historyLimit = 0,
    ) {}
}
```

**Strengths over upstream:**
- ✅ Vim mode (h/l/w/b/0/$/i/a/A/x)
- ✅ History with limits (`withHistoryLimit()`)
- ✅ Sentinel-based nullable setters (`$validateSet`, `$errSet`) per CALIBER_LEARNINGS
- ✅ Prefix/suffix support
- ✅ Short-form method aliases (`.placeholder()`, `.prompt()`, etc.)

**Weaknesses vs alternatives:**
- ❌ Validation fires on every edit only; no timing control
- ❌ No `validate_on` option (blur/submit/changed)
- ❌ No restrict pattern (regex-based input filtering)
- ❌ No password field-specific styling options

### 1.2 Go Bubbles TextInput

```go
// Source: github.com/charmbracelet/bubbles/blob/main/textinput/textinput.go
type Model struct {
    Err error
    Prompt        string
    Placeholder   string
    EchoMode      EchoMode
    EchoCharacter rune
    CharLimit     int
    Validate      ValidateFunc
    // ... suggestions, styles, etc.
}

// Key methods:
func New() Model
func (m *Model) Focus()
func (m *Model) Blur()
func (m *Model) SetValue(s string)
func (m *Model) Value() string
func (m *Model) SetSuggestions(s []string)
func (m *Model) SetStyles(s Styles)
```

**Notable:** Bubbles v2 consolidated styles into a `Styles` struct with focused/blurred states.

### 1.3 Textual Input Widget (Python)

```python
# Source: textual.textualize.io/widgets/input
input = Input(
    value="Hello World",
    placeholder="Enter text here",
    password=False,
    max_length=100,
    restrict=r"[a-zA-Z]*",  # Regex restriction
    suggester=Suggester,     # Auto-completion
    validators=[Required(), Length(min=1, max=50)],
    validate_on=["blur", "changed", "submitted"],  # Validation timing!
    valid_empty=False,
)
```

**Better patterns to port:**
1. **`validate_on` timing** — Sugar-bits should support `onChange`, `onBlur`, `onSubmit` validation triggers
2. **`restrict` regex** — Filter allowed characters before insertion
3. **Multiple validators** — Currently sugar-bits only supports one validator closure

### 1.4 tview InputField (Go)

```go
// Source: context7.com/rivo/tview/llms.txt
nameInput := tview.NewInputField().
    SetLabel("Name: ").
    SetFieldWidth(30).
    SetAcceptanceFunc(tview.InputFieldMaxLength(50)).
    SetAutocompleteFunc(func(currentText string) []string {
        // autocomplete logic
    })
```

**Notable patterns:**
- `InputFieldInteger` / `InputFieldFloat` / `InputFieldMaxLength` as built-in acceptance functions
- Form integration with label+field as a unit

### 1.5 Recommended TextInput Improvements

| Priority | Improvement | Effort | Notes |
|----------|-------------|--------|-------|
| **HIGH** | Add `validate_on` timing (blur/changed/submit) | Medium | Follow Textual's `validate_on` pattern |
| **HIGH** | Add `restrict` regex for input filtering | Low | Pre-filter input before insertion |
| **MEDIUM** | Add multiple validators support | Medium | Instead of single `?\Closure`, accept `list<Closure>` |
| **LOW** | Built-in acceptance funcs (integer, email, URL) | Low | Mirror tview's `InputField*` helpers |

---

## 2. TextArea Comparison

### 2.1 Current sugar-bits API (845 lines)

```php
// Source: sugar-bits/src/TextArea/TextArea.php:L30-L70
final class TextArea implements Model
{
    private function __construct(
        public readonly array $lines,
        public readonly int $row,
        public readonly int $col,
        public readonly string $placeholder,
        public readonly int $charLimit,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $focused,
        public readonly Cursor $cursor,
        public readonly int $rowOffset,
        public readonly bool $showLineNumbers = false,
        public readonly int $maxWidth = 0,
        public readonly int $maxHeight = 0,
        public readonly string $endOfBufferCharacter = '~',
        public readonly string $prompt = '',
        public readonly ?\Closure $validate = null,
        public readonly ?string $err = null,
        public readonly ?\Closure $promptFunc = null,
        public readonly bool $dynamic = false,
        public readonly string $editorExtension = '.txt',
    ) {}
}
```

**Strengths:**
- ✅ Line-by-line storage with `row`/`col` cursor tracking
- ✅ Dynamic height mode (renders only content lines, capped by maxHeight)
- ✅ `promptFunc` for per-line dynamic prompts
- ✅ External editor integration (Ctrl+O opens `$EDITOR`)
- ✅ `word()` method to get word under cursor
- ✅ Multibyte-safe (grapheme-aware)

### 2.2 Go Bubbles TextArea

```go
// Source: github.com/charmbracelet/bubbles/blob/master/textarea/textarea.go
type Model struct {
    Err error
    Prompt                 string
    Placeholder            string
    ShowLineNumbers        bool
    EndOfBufferCharacter   rune
    CharLimit              int
    MaxHeight              int
    MaxWidth               int
    styles                 Styles
    promptFunc             func(PromptInfo) string
    value                  [][]rune  // Line-based storage
    // ... cursor, viewport
}
```

**Key difference:** Bubbles uses `viewport` internally for scroll management; sugar-bits uses `rowOffset`.

### 2.3 ratatui Paragraph (Rust)

```rust
// Source: docs.rs/ratatui/latest/ratatui/widgets/struct.Paragraph.html
let paragraph = Paragraph::new(text)
    .wrap(Wrap { trim: true })
    .scroll((1, 1));  // (y, x) scroll offset
```

### 2.4 Textual TextArea

```python
# Simple multi-line text area in Textual
await pilot.ppress("b")  # Send 'b' key
await pilot.click("#textarea-id")
```

Textual uses auto-height with scrolling within a container rather than explicit dimension settings.

### 2.5 Recommended TextArea Improvements

| Priority | Improvement | Effort | Notes |
|----------|-------------|--------|-------|
| **LOW** | Consider viewport-based scrolling (align with Bubbles v2) | High | Current rowOffset approach works fine |
| **LOW** | Add `maxHeight` behavior clarification for dynamic mode | Low | Document that maxHeight caps in dynamic mode |

---

## 3. Table Comparison

### 3.1 Current sugar-bits API (398 lines)

```php
// Source: sugar-bits/src/Table/Table.php:L26-L47
final class Table implements Model
{
    private function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly int $cursor,
        public readonly int $offset,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $focused,
        public readonly array $colWidths = [],
        public readonly ?Styles $styles = null,
        public readonly ?\Closure $styleFunc = null,
    ) {}

    public const HEADER_ROW = -1;
}
```

**Features:**
- ✅ Cursor-based row selection (vim keys j/k)
- ✅ Column width auto-sizing with explicit overrides
- ✅ `styleFunc` for per-cell styling
- ✅ Fixed header rendering with underline

**Missing vs bubble-table:**
- ❌ No built-in sorting
- ❌ No filtering/searching
- ❌ No pagination
- ❌ No multi-row selection
- ❌ No column hiding/reordering

### 3.2 Go Bubbles Table

```go
// Source: github.com/charmbracelet/bubbles/blob/main/README.md
columns := []table.Column{
    {Title: "COUNTRY", Width: 20},
    {Title: "POPULATION", Width: 20},
}
rows := []table.Row{
    {"Canada", "38,246,100"},
    // ...
}
m.table = table.New(table.WithColumns(columns), table.WithRows(rows))
```

Simple table without built-in sort/filter/pagination.

### 3.3 bubble-table (Go - community)

```go
// Source: context7.com/evertras/bubble-table/llms.txt
table.New(columns).
    WithRows(rows).
    WithPageSize(10).                    // Pagination
    WithPaginationWrapping(true).
    Focused(true).
    SelectableRows(true).                // Checkbox selection
    WithFiltered(true).                  // Filtering via '/'
    SortByDesc("wins").                  // Sorting
    ThenSortByAsc("type")                // Multi-column sort
```

**Key features to port:**
1. **Pagination** — `WithPageSize()`, `PageUp()`, `PageDown()`, `PageFirst()`, `PageLast()`
2. **Filtering** — `WithFiltered(true)`, per-column `WithFiltered(true)`
3. **Sorting** — `SortByDesc()`, `SortByAsc()`, `ThenSortByDesc()`
4. **Selection** — `SelectableRows(true)`, `SelectedRows()`, `HighlightedRow()`

### 3.4 ratatui Table (Rust)

```rust
// Source: docs.rs/ratatui/latest/ratatui/widgets/struct.TableState.html
let mut table_state = TableState::default();
table_state.select(Some(3));  // Select row 3
table_state.select_column(Some(2));  // Select column 2

// Table widget:
table = Table::new(rows, widths)
    .highlight_symbol(">>")
    .row_highlight_style(Style::new().reversed());
```

**ratatui pattern: Separated state**

The key architectural difference — ratatui separates `TableState` from the `Table` widget:

```php
// PHP equivalent pattern sugar-bits could adopt:
// TableState holds: cursor position, scroll offset, selected rows
// Table holds: headers, rows, column config, styling
// Parent model owns TableState; passes to Table::view($state)
```

This enables:
- State persistence across renders without mutation
- Multiple tables with shared state management
- Cleaner render loop (state in parent, widget is pure)

### 3.5 tview Table (Go)

```go
// Source: context7.com/rivo/tview/llms.txt
table.SetCell(r, c, tview.NewTableCell(text).
    SetTextColor(color).
    SetAlign(tview.AlignCenter))
table.Select(0, 0).SetFixed(1, 1)  // Fixed header row
table.SetSelectedFunc(func(row, column int) {
    // Handle selection
})
```

**Notable:** tview supports fixed rows/columns and cell-level selection.

### 3.6 Recommended Table Improvements

| Priority | Improvement | Effort | Notes |
|----------|-------------|--------|-------|
| **HIGH** | Add sorting (`SortByAsc`, `SortByDesc`) | Medium | Per-column sortable; multi-column support |
| **HIGH** | Add pagination (`WithPageSize`, `PageUp`, `PageDown`) | Medium | Integrate Paginator or create table-specific pagination |
| **MEDIUM** | Add filtering (`WithFiltered`, per-column) | Medium | '/' to activate filter mode |
| **MEDIUM** | Consider TableState separation pattern | High | Separate scroll/selection state from table widget |
| **LOW** | Multi-row selection (checkboxes) | Medium | nice-to-have for data-heavy tables |

---

## 4. ItemList Comparison

### 4.1 Current sugar-bits API (560 lines)

```php
// Source: sugar-bits/src/ItemList/ItemList.php:L32-L56
final class ItemList implements Model
{
    private function __construct(
        public readonly array $items,
        public readonly int $cursor,
        public readonly int $offset,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $focused,
        public readonly string $title,
        public readonly bool $filtering,
        public readonly string $filterText,
        public readonly bool $showDescription,
        public readonly bool $showStatusBar = true,
        public readonly bool $showHelp = true,
        public readonly bool $showFilter = true,
        public readonly bool $infiniteScrolling = false,
        public readonly string $statusMessage = '',
        public readonly float $statusMessageExpiresAt = 0.0,
        public readonly float $statusMessageLifetime = 1.0,
        public readonly string $cursorPrefix = '> ',
        public readonly string $unselectedPrefix = '  ',
        public readonly bool $keepFilter = false,
    ) {}
}
```

**Features:**
- ✅ Filtering mode (press `/` to enter)
- ✅ Status messages with expiration
- ✅ Infinite scrolling (wrap cursor)
- ✅ Cursor/unselected prefixes for custom markers
- ✅ `setItem`, `insertItem`, `removeItem` for list manipulation

### 4.2 ratatui List (Rust)

```rust
// Source: docs.rs/ratatui/latest/ratatui/widgets/struct.ListState.html
let mut state = ListState::default();
state.select(Some(3));  // Select item 3

// StatefulWidget pattern:
frame.render_stateful_widget(list, area, &mut state);
```

**Same pattern:** ratatui separates `ListState` from `List` widget.

### 4.3 tview List (Go)

```go
// Source: context7.com/rivo/tview/llms.txt
list := tview.NewList().
    AddItem("View Profile", "Display user information", 'p', func() {
        // Callback on selection
    }).
    AddItem("Edit Settings", "Modify application settings", 's', nil)

list.SetChangedFunc(func(index int, mainText, secondaryText string, shortcut rune) {
    // Handle selection change
})
```

**Notable:** tview List supports:
- Shortcut keys per item (keyboard accelerators)
- Secondary description text
- Selection change callbacks

### 4.4 Textual ListView (Python)

```python
# Source: textual.textualize.io/widgets/list_view
class ListApp(App):
    def compose(self) -> ComposeResult:
        yield ListView(
            Header(),
            *[
                ListItem(Label(item.name), id=item.id)
                for item in items
            ]
        )
```

### 4.5 Recommended ItemList Improvements

| Priority | Improvement | Effort | Notes |
|----------|-------------|--------|-------|
| **LOW** | Consider ListState separation (ratatui pattern) | High | May be overkill for PHP use case |
| **LOW** | Add per-item keyboard shortcuts | Medium | tview-style 'p' for "profile" |
| **LOW** | Add secondary description per item | Low | Already has `Item::description()` |
| **LOW** | Selection change callback | Medium | Could complement polling `selectedItem()` |

---

## 5. Paginator Comparison

### 5.1 Current sugar-bits API (220 lines)

```php
// Source: sugar-bits/src/Paginator/Paginator.php:L25-L46
final class Paginator implements Model
{
    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalItems,
        public readonly Type $type,
        public readonly string $activeDot,
        public readonly string $inactiveDot,
        public readonly string $arabicFormat = '%d/%d',
    ) {}

    public const TYPE_DOTS = 'dots';
    public const TYPE_ARABIC = 'arabic';
}
```

**Features:**
- ✅ Dots mode (● ○ ○ ○)
- ✅ Arabic mode ("3/8")
- ✅ Keyboard navigation (arrows, h/l, pgup/pgdn)
- ✅ `sliceBounds()` for item pagination

**Missing:**
- ❌ No page size configuration after construction
- ❌ No first/last buttons
- ❌ No page jump / direct page selection

### 5.2 bubble-table Pagination (Go)

```go
// Source: context7.com/evertras/bubble-table/llms.txt
m = m.PageDown()           // Next page
m = m.PageUp()             // Previous page
m = m.PageFirst()          // First page
m = m.PageLast()           // Last page
m = m.WithCurrentPage(5)   // Jump to page 5
```

More comprehensive navigation methods.

### 5.3 Recommended Paginator Improvements

| Priority | Improvement | Effort | Notes |
|----------|-------------|--------|-------|
| **MEDIUM** | Add `PageFirst()`, `PageLast()` methods | Low | mirror bubble-table |
| **MEDIUM** | Add `WithPage(int)` for direct navigation | Low | Jump to specific page |
| **LOW** | Add page size mutator (currently constructor-only) | Low | allow `withPerPage()` post-construction |

---

## 6. Form Patterns

### 6.1 tview Form (Go)

```go
// Source: context7.com/rivo/tview/llms.txt
form := tview.NewForm().
    AddDropDown("Title", []string{"Mr.", "Ms."}, 0, callback).
    AddInputField("First name", "", 20, nil, callback).
    AddCheckbox("Age 18+", false, callback).
    AddTextArea("Address", "", 40, 3, 0, callback).
    AddPasswordField("Password", "", 10, '*', nil).
    AddButton("Save", func() { ... })
```

**Key pattern:** Form as a compound widget that manages focus navigation between fields.

### 6.2 Textual Compound Widgets (Python)

```python
# Source: textual.textualize.io/guide/widgets
class InputWithLabel(Widget):
    def compose(self) -> ComposeResult:
        yield Label(self.input_label)
        yield Input()

# Usage:
yield InputWithLabel("First Name")
yield InputWithLabel("Last Name")
yield InputWithLabel("Email")
```

**Key pattern:** Compose multiple widgets into reusable compound components.

### 6.3 Recommended Form Improvements

| Priority | Improvement | Effort | Notes |
|----------|-------------|--------|-------|
| **MEDIUM** | Document form-building patterns | Low | Write CALIBER_LEARNINGS section on composing sugar-bits components into forms |
| **LOW** | Consider Form wrapper class | High | May be over-engineering for PHP |

---

## 7. State Management Patterns

### 7.1 ratatui's Separated State Pattern (Rust)

**Current sugar-bits pattern (MVU):**
```php
// Component owns its state, update() returns [Model, Cmd]
public function update(Msg $msg): array {
    return [$this->mutate(cursor: $newCursor), $cmd];
}
```

**ratatui pattern (separated state):**
```rust
// State object is owned by parent, passed to widget's render
let mut table_state = TableState::default();
table_state.select(Some(3));

frame.render_stateful_widget(table, area, &mut table_state);
```

**Pros for sugar-bits adoption:**
- State persisted in parent model, survives across renders
- Multiple views can share same state without copying
- Clearer separation: state management vs rendering

**Cons for sugar-bits adoption:**
- PHP doesn't have mutable references like Rust
- Would require breaking API changes
- Current pattern is simpler for PHP use cases

**Verdict:** Don't adopt separated state pattern. PHP's reference semantics make the current MVU approach more ergonomic.

### 7.2 Textual's Reactive State

```python
# Source: textual.textualize.io
value: var[str] = var("")  # Reactive variable

@on(Input.Changed)
def input_changed(self, event: Input.Changed) -> None:
    self.value = event.value  # Auto-updates UI
```

**Verdict:** Event-driven (which sugar-bits already uses via Msg/Update) vs property binding. Sugar-bits is fine.

---

## 8. Styling API Comparison

### 8.1 lipgloss (Go)

```go
// Source: context7.com/charmbracelet/lipgloss/llms.txt
style := lipgloss.NewStyle().
    Bold(true).
    Foreground(lipgloss.Color("#FAFAFA")).
    Background(lipgloss.Color("#7D56F4")).
    PaddingTop(2).PaddingLeft(4).PaddingRight(4).PaddingBottom(2).
    Width(40).
    Align(lipgloss.Center).
    Border(lipgloss.RoundedBorder()).
    BorderForeground(lipgloss.Color("#874BFD"))
```

**What sugar-bits should adopt from lipgloss:**
1. Fluent chaining (already used in `Style`)
2. Border helpers (`RoundedBorder()`, `NormalBorder()`, etc.)
3. Color constants (`lipgloss.Color("63")`)
4. `Width()`, `Height()`, `Align()` layout helpers

### 8.2 ratatui Style (Rust)

```rust
// Source: docs.rs/ratatui/latest/ratatui/widgets/struct.List.html
let list = List::new(items)
    .highlight_style(Style::new().reversed())
    .highlight_symbol(">>")
```

### 8.3 sugar-bits Style (current)

```php
// Style is already fluent but less comprehensive than lipgloss
public function render(string $s): string {
    // ... ANSI SGR rendering
}
```

---

## 9. Cross-Language Patterns Summary

| Feature | sugar-bits | Bubbles | ratatui | Textual | tview | prompt_toolkit |
|---------|------------|---------|---------|---------|-------|----------------|
| TextInput vim mode | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| History with limits | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| validate_on timing | ❌ | ❌ | N/A | ✅ | ❌ | ❌ |
| Textarea dynamic height | ✅ | ✅ | N/A | ✅ | ✅ | ✅ |
| Table sorting | ❌ | ❌ | ✅ | ✅ | ❌ | N/A |
| Table filtering | ❌ | ❌ | ❌ | ✅ | ❌ | N/A |
| Table pagination | ❌ | ❌ | ❌ | ✅ | ❌ | N/A |
| Table multi-select | ❌ | ❌ | ✅ | ✅ | ❌ | N/A |
| Separated state (State struct) | ❌ | ❌ | ✅ | ❌ | ❌ | N/A |
| Form compound widgets | ❌ | ❌ | N/A | ✅ | ✅ | N/A |
| Input restrict/regex | ❌ | ❌ | N/A | ✅ | ✅ | ❌ |
| Multiple validators | ❌ | ❌ | N/A | ✅ | ❌ | ❌ |

---

## 10. Priority Implementation Plan

### Phase 1: TextInput Enhancements

**Add `validate_on` timing support:**
```php
// New Types
enum InputValidationOn {
    case Changed;
    case Blur;
    case Submit;
}

// In TextInput:
public readonly set<InputValidationOn> $validateOn = [];

public function withValidateOn(set<InputValidationOn> $timing): self {
    return $this->mutate(validateOn: $timing);
}

// Validation runs only on specified events
```

**Add `restrict` pattern:**
```php
public readonly ?string $restrict = null;  // Regex pattern

public function withRestrict(?string $pattern): self {
    return $this->mutate(restrict: $pattern);
}

// In insert(): only insert if preg_match($restrict, $rune)
```

**Effort:** Medium | **Priority:** HIGH

### Phase 2: Table Sorting & Filtering

**Add sorting:**
```php
// In Table:
public readonly array $sortColumns = [];  // ['col' => 'desc', 'col2' => 'asc']

public function sortBy(string $column, string $direction = 'asc'): self;
public function thenSortBy(string $column, string $direction = 'asc'): self;
public function clearSort(): self;
```

**Add filtering:**
```php
public readonly bool $filterable = false;
public readonly string $filterText = '';
public readonly ?Closure $filterFunc = null;  // fn(row): bool

public function withFilterable(bool $on = true): self;
```

**Effort:** Medium | **Priority:** HIGH

### Phase 3: Table Pagination

**Option A: Integrate Paginator**
```php
// Consumer code:
[$table, $paginator] = $tableModel->withPagination(10);
// Render: $table->view() . "\n" . $paginator->view();
```

**Option B: Table-specific pagination methods**
```php
public function withPageSize(int $size): self;
public function pageUp(): self;
public function pageDown(): self;
public function pageFirst(): self;
public function pageLast(): self;
public function currentPage(): int;
public function totalPages(): int;
```

**Effort:** Medium | **Priority:** MEDIUM

### Phase 4: Paginator Improvements

**Add navigation methods:**
```php
public function pageFirst(): self;
public function pageLast(): self;
public function withPage(int $page): self;
```

**Effort:** Low | **Priority:** MEDIUM

### Phase 5: Documentation & Patterns

**Add to CALIBER_LEARNINGS.md:**
- Form composition patterns (TextInput + TextArea in a form)
- Validation timing strategies
- Table filtering/sorting usage

**Effort:** Low | **Priority:** LOW

---

## 11. Summary of Recommended Improvements

### Immediate (next PR)
1. **TextInput: Add `validate_on`** — validation timing control
2. **TextInput: Add `restrict` regex** — input filtering
3. **Paginator: Add `pageFirst()`, `pageLast()`, `withPage()`**

### Short-term (next 2-3 PRs)
4. **Table: Add sorting** — `sortBy()`, `thenSortBy()`
5. **Table: Add filtering** — `withFilterable()`
6. **Table: Add pagination** — `withPageSize()` + navigation

### Long-term (backlog)
7. **TextInput: Multiple validators** — accept `list<Closure>`
8. **Document form composition patterns**
9. Consider built-in acceptance functions (integer, email, URL)

---

## 12. References

### Documentation Sources
- [Bubble Tea](https://github.com/charmbracelet/bubbletea) - Go TUI framework
- [Bubbles](https://github.com/charmbracelet/bubbles) - Go TUI components
- [bubble-table](https://github.com/evertras/bubble-table) - Go table with pagination/sort/filter
- [lipgloss](https://github.com/charmbracelet/lipgloss) - Go styling library
- [ratatui](https://ratatui.rs) - Rust TUI library
- [Textual](https://textual.textualize.io) - Python TUI framework
- [tview](https://github.com/rivo/tview) - Go terminal UI library
- [prompt_toolkit](https://python-prompt-toolkit.readthedocs.io) - Python CLI library
- [blessed](https://github.com/chjj/blessed) - Node.js terminal library

### Key File Locations
- sugar-bits TextInput: `sugar-bits/src/TextInput/TextInput.php`
- sugar-bits TextArea: `sugar-bits/src/TextArea/TextArea.php`
- sugar-bits Table: `sugar-bits/src/Table/Table.php`
- sugar-bits ItemList: `sugar-bits/src/ItemList/ItemList.php`
- sugar-bits Paginator: `sugar-bits/src/Paginator/Paginator.php`
- CALIBER_LEARNINGS: `sugar-bits/CALIBER_LEARNINGS.md`
