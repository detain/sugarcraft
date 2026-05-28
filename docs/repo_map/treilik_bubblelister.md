# treilik/bubblelister

## Metadata
- URL: https://github.com/treilik/bubblelister
- Language: Go
- Stars: 52
- License: MIT License
- Description: A bubble (TUI component) for the Charmbracelet Bubbletea framework that renders lists of structs with customizable prefixes, suffixes, word-wrapping, and cursor navigation.

## Feature List
- **List Model (tea.Model interface)**: Full Bubbletea-compatible list component with Init/Update/View lifecycle
- **Cursor Navigation**: Move cursor by item or by relative offset, jump to top/bottom, set cursor position
- **Item Management**: Add items, remove by index, update items via updater function, reset all items
- **Sorting**: Custom sort via LessFunc, stable sort preservation, cursor stays on same item after sort
- **Word Wrapping**: Hard-wrap content to available content width, configurable lines-per-item limit (Wrap property)
- **Prefix Generation**: DefaultPrefixer with line numbers, relative numbering, tree-style indentation, custom separators (╭├│), current-item marker (>)
- **Suffix Generation**: DefaultSuffixer with cursor-positioned markers, horizontal padding for alignment
- **Custom Prefixer/Suffixer Interfaces**: Pluggable rendering hooks for prefix/suffix per line
- **Viewport Management**: CursorOffset margin from viewport edges, responsive to WindowSizeMsg
- **Concurrent Search**: GetIndex uses goroutines for parallel EqualsFunc evaluation
- **Item Identity**: Unique ID generation with mutex protection, cursor preservation across sort operations

## Key Classes and Methods

### Model (list.go)
- `NewModel()` — Create Model with sane defaults (CursorOffset: 5, lineOffset: 5, Wrap: 0, DefaultPrefixer enabled)
- `Init()` — Returns nil (no-op per tea.Model)
- `Update(tea.Msg)` — Handles tea.WindowSizeMsg only
- `View() string` — Renders list as joined lines
- `Lines() ([]string, error)` — Returns visible lines without joining
- `MoveCursor(amount int)` — Move cursor relative, returns new absolute index
- `SetCursor(target int)` — Move cursor to absolute index
- `Top() error` — Jump to first item
- `Bottom() error` — Jump to last item
- `AddItems(...fmt.Stringer) error` — Append items, skip nil values
- `RemoveIndex(index int)` — Remove and return item at index
- `ResetItems(...fmt.Stringer) error` — Replace all items, optionally preserve cursor by EqualsFunc
- `UpdateItem(index int, updater func(fmt.Stringer)(fmt.Stringer, error)) error` — Update item via function, nil return removes item
- `Sort()` — Sort using LessFunc or string comparison, preserve cursor item
- `GetIndex(toSearch fmt.Stringer)` — Parallel search using EqualsFunc
- `GetCursorIndex()` / `GetCursorItem()` — Access current selection
- `GetAllItems()` — Return all items as []fmt.Stringer
- `MoveCursorItemTo(to)` / `MoveCursorItemBy(amount)` / `MoveItemBy(index, amount)` — Reorder items

### item (item.go)
- Internal struct wrapping `fmt.Stringer` value with unique `id int`
- `itemLines()` — Word-wrap item content to content width, apply Wrap limit
- `getItemLines()` — Surround wrapped lines with prefix/suffix, apply LineStyle/CurrentStyle

### Prefixer (prefixer.go)
- `Prefixer` interface: `InitPrefixer(value, currentItemIndex, cursorIndex, lineOffset, width, height int) int` + `Prefix(lineIndex, allLines int) string`
- `DefaultPrefixer`: Line numbers (absolute or relative), separators (╭├│), current marker (>)
- `NewPrefixer()` — Returns DefaultPrefixer with Unicode box-drawing defaults

### Suffixer (suffixer.go)
- `Suffixer` interface: `InitSuffixer(...) int` + `Suffix(lineIndex, allLines int) string`
- `DefaultSuffixer`: Pads line to list width, marks current item with `<`
- `NewSuffixer()` — Returns simple suffixer

### Error Types
- `NoItems`, `NotFound`, `OutOfBounds`, `MultipleMatches`, `ConfigError`, `NilValue`, `UnhandledKey`

## Notable Algorithms / Named Patterns

### Elm Architecture Integration
Implements the tea.Model interface (Init/Update/View pattern) from Charmbracelet Bubbletea. The Model is copied on each Update/View call, so the author notes large lists impact performance.

### Sort.Swap Interface Pattern
Model implements `sort.Interface` (Len/Less/Swap) for native Go sorting, with cursor tracking by old item ID after reorder.

### Concurrent Equality Search
GetIndex launches one goroutine per item, collects results via channels, returns NotFound/MultipleMatches errors.

### Viewport-relative Cursor Offset
Cursor stays centered relative to viewport edges using CursorOffset margin. validOffset() computes line-offset accounting for multiline items.

### Hard Word Wrap
Uses `github.com/treilik/reflow/wordwrap.HardWrap` to split on width without hyphenation, with 4-space indent on continuation.

## Strengths
- Clean tea.Model interface compliance for seamless Bubbletea integration
- Pluggable prefix/suffix rendering via interface contracts
- Support for multiline items with proper viewport scrolling
- Cursor management accounts for multiline item heights
- Sorting preserves cursor on same logical item (not index)
- Thread-safe unique ID generation for items
- Comprehensive test coverage including wrapped lines, multiline items, cursor movement
- MIT licensed with clear documentation
- Examples demonstrate tree view, checkbox TODO, and editable list use cases

## Weaknesses
- Update() only handles WindowSizeMsg — all other messages (keyboard, mouse) must be handled by user/wrapper
- No built-in keyboard bindings — user must implement all navigation in their Update
- Goroutine-based GetIndex has unbounded parallelism (potential thundering herd on large lists)
- Performance note in AddItems: Elm architecture copies model on each call, large lists slow updates
- No built-in filtering/search within the list
- No pagination or virtual scrolling for very large lists
- Comments note several TODOs (hard string limit, wrap handling in numWidth calculation)
- LessFunc/Sort is not stable by default
- Module uses a fork of muesli/reflow (TODO to switch back after PR merge)

## SugarCraft Mapping

SugarCraft is a PHP monorepo of Charmbracelet TUI library ports. This mapping identifies which SugarCraft libs would consume or be consumed by a bubblelister equivalent.

| treilik/bubblelister Feature | SugarCraft Lib(s) | Notes |
|---|---|---|
| List Model rendering (lines, viewport, cursor) | `candy-core` (foundation) | Core TUI model infrastructure |
| Word wrapping / text formatting | `candy-core` (StringUtil / WordWrap) | Reflow-based text wrapping |
| tea.Model interface compliance | `candy-core` (Model trait) | init/update/view lifecycle |
| Line number prefixes, tree prefixes | `sugar-bits` (Border / Frameless) | Box-drawing chars and border rendering |
| Multi-line item support | `sugar-prompt` (TextArea / input) | Multi-line input handling |
| Item sorting, LessFunc/EqualsFunc | `sugar-bits` (ListModel) | Sortable list data structure |
| Current item styling (reverse video) | `sugar-bits` (CurrentItem) | Highlighted selection state |
| Prefix/Suffix interfaces | `candy-core` (Decorator pattern) | Surrounding content hooks |
| Viewport cursor offset (margin from edge) | `sugar-bits` (ScrollMargins) | Viewport margin management |
| Concurrent item search | `sugar-bits` (parallel find) | Parallel search in lists |
| Custom sort functions | `sugar-bits` (Comparator) | Sort function injection |

**Primary mapping**: `sugar-bits` as the target for a bubblelister port, as it already contains list/listbox primitives. The Model infrastructure, viewport management, and word-wrapping belong in `candy-core`.

## Analysis

**treilik/bubblelister** is a Go library that ports the list/bubble concept from Charmbracelet's Bubbles collection to provide a full-featured scrollable list component for the Bubbletea TUI framework. It fills a gap in the Charmbracelet ecosystem: while bubbles providesspinner, textinput, viewport, and table, a cursor-navigable list with item management was missing. This library fills that gap with a well-structured Model that implements sort.Interface, manages viewport-relative cursor offsets, and supports multiline items via word wrapping.

The design philosophy emphasizes "inherent correctness" per the README — the author prioritizes correct behavior over API convenience. This shows in the careful handling of cursor position during sort operations (tracking by item ID, not index), the error-signaling approach (distinct error types for NoItems, NotFound, OutOfBounds, MultipleMatches), and the concurrent GetIndex that properly detects multiple matches. The tradeoff is verbosity: users must implement all keyboard handling themselves since Update() doesn't bind any keys.

The architecture follows the Elm pattern strictly: Model is copied on Update/View calls, so the author explicitly warns that very large lists will be slow. This is a fundamental constraint of Bubbletea's architecture, not a bug. The library handles this by providing efficient operations (MoveCursorItemBy instead of Remove+Add) and suggesting users handle item updates outside the tea.Msg loop when possible.

For SugarCraft, this maps cleanly to `sugar-bits` (list widget with cursor navigation) and `candy-core` (core model infrastructure, text wrapping). A port would need to reimplement the word-wrap (using bubbletea's reflow fork), viewport cursor-offset management, and the sort.Interface pattern. The concurrent GetIndex approach would need adaptation since ReactPHP (SugarCraft's async runtime) doesn't use goroutines.
