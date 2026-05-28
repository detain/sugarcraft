# Overview

**candy-lister** is a focused PHP port of `treilik/bubblelister` (Go, MIT, 52 stars) providing a viewport-aware tree-list renderer for terminal UIs. It renders items with custom prefix/suffix hooks, line wrapping, and cursor-aware styling — designed as a pure rendering engine decoupled from any specific event loop, making it embeddable in any PHP TUI framework.

**Biggest opportunity areas:**
1. **Keyboard navigation bindings** — user must implement all navigation; no built-in vim-style keys, pgup/pgdn, home/end
2. **Mouse support** — no click-to-select or scroll-wheel handling
3. **Virtual scrolling / windowing** — all visible items rendered every frame; no windowing for large lists
4. **Concurrent search** — `find()` is O(n) sequential; Go upstream uses goroutines for parallel evaluation
5. **Stable sorting** — `usort` is not guaranteed stable; cursor could jump if equal elements exist

**Biggest missing capabilities:**
1. **tea.Model / Elm pattern** — not a BubbleTea component; cannot be dropped into a BubbleTea application
2. **No built-in filter UI** — filter function must be provided by user; no text input component driving filtering
3. **Rune-aware word splitting** — `splitOverWidth()` uses `strlen`/`substr` (byte offset) not rune-aware
4. **No wrap indent** — Go upstream indents wrapped lines with 4 spaces; PHP port does not
5. **Mutex-less ID counter** — `idCounter` has no mutex protection in concurrent contexts

---

# Internal Capability Summary

## Current Architecture

**Model-centric rendering engine** — `Model::new()` creates a list model; `setViewport()` sets dimensions; `addItem()` populates; `View()` returns ANSI-rendered string. All setters return new instances (copy-on-write via `with*()` pattern).

**Rendering pipeline:**
```
Model.lines()
  └─ renderItem(itemIndex)         [per visible item]
       ├─ prefixer.initPrefixer()  [once per item]
       ├─ suffixer.initSuffixer()   [once per item]
       ├─ hardWrap(content)        [word-wrap]
       └─ for each line:          [per line]
            ├─ prefixer.prefix()
            ├─ suffixer.suffix() (if non-empty)
            └─ applyStyle(lineStyle|currentStyle)
```

**Three-phase viewport rendering:**
1. Lines before cursor — walks backward from `cursorIndex - 1` up to `lineOffset`
2. Cursor item — included in forward walk from `cursorIndex`
3. Lines after cursor — continues forward until `height` lines accumulated

## Current Features

- Tree-list rendering with box-drawing prefixes (╭ ├ │)
- Cursor navigation (cursorUp/cursorDown/setCursor with bounds clamping)
- Hard word-wrap at content width without breaking mid-word; configurable per-item line limit
- Filter-as-you-type via `withFilterFn()` / `withoutFilter()` with three-state `FilterState` enum
- Smith-Waterman fuzzy matching with two-row DP matrix (O(c) memory)
- Pluggable `Prefixer`/`Suffixer` interfaces for per-line prefix/suffix
- `LessFunc`/`EqualsFunc` closure injection for sorting/equality
- Any PHP `Stringable` works as an item; `StringItem` adapter for plain strings
- Immutable fluent setters returning `$this`
- 16-language i18n via `lang/` dir

## APIs

| Method | Signature | Notes |
|---|---|---|
| `new()` | `static(): self` | Factory — no auto-prefixer/suffixer |
| `setViewport()` | `(int $w, int $h): self` | Fluent |
| `setCursorOffset()` | `(int $n): self` | Sets both cursorOffset and lineOffset |
| `setWrap()` | `(int $maxLines): self` | 0 = unlimited |
| `setPrefixer()` | `(Prefixer $p): self` | Fluent |
| `setSuffixer()` | `(Suffixer $s): self` | Fluent |
| `withFilterFn()` | `(\Closure $fn): self` | Returns **new** instance; saves originalItems |
| `withoutFilter()` | `(): self` | Returns **new** instance; restores originalItems |
| `addItem()` | `(\Stringable $v): self` | Fluent |
| `cursorUp()` / `cursorDown()` | `(int $n = 1): self` | Relative movement |
| `View()` | `(): string` | Newline-joined lines + trailing newline |

## Extension Systems

**Prefixer interface** — `initPrefixer()` computes prefix width reservation; `prefix()` produces per-line prefix string. DefaultPrefixer ships with line numbers (absolute/relative), box-drawing separators, `>` cursor marker.

**Suffixer interface** — `initSuffixer()` computes suffix width reservation; `suffix()` produces per-line suffix string. DefaultSuffixer shows `<` on first line of cursor item only.

## Strengths

1. Clean separation via Prefixer/Suffixer interfaces
2. Immutable filter state machine with explicit `FilterState` transitions
3. Memory-efficient Smith-Waterman (O(c) two-row DP)
4. Viewport-aware cursor offset margin from edges
5. Box-drawing defaults matching Go upstream
6. Full 16-language i18n

## Weaknesses

1. Not a BubbleTea component — pure view-only
2. No keyboard bindings — user implements all navigation
3. Sequential find — O(n) vs Go's concurrent goroutines
4. No wrap indent on continuation lines
5. Byte-based (not rune-based) word splitting for oversize words
6. No virtual scrolling / pagination
7. No mouse support
8. No built-in filter UI
9. No mutex on ID counter
10. `usort` is not stable — cursor could jump on equal elements

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|---|---|---|---|
| `treilik/bubblelister` (upstream) | 🔴 Direct port target | tea.Model, concurrent GetIndex, sort.Interface, wrap indent | Critical |
| `charmbracelet/bubbles` List | 🔴 List component reference | Fuzzy filtering (`sahilm/fuzzy`), delegates, vim keys, pagination | Critical |
| `charmbracelet/bubbletea` | 🟡 TEA framework | Elm architecture, Cmd/Batch, mouse events, windowing | High |
| `treilik/bubbleboxer` | 🟡 Layout composition | Layout tree, SizeFunc, safe EditLeaf, recursive rendering | Medium |
| `Genekkion/theHermit` | 🟡 Overlay list | Overlay rendering, center positioning, background preservation | Medium |
| `pterm/pterm` InteractiveSelect | 🟡 List UX patterns | Fuzzy search, virtual scrolling, keyboard navigation | High |
| `ratatui/ratatui` List | 🟡 Rust list widget | StatefulWidget + ListState, Cassowary layout, buffer diffing | Medium |
| `charmbracelet/huh` Select | 🟡 Form integration | Type-ahead filtering (`/`), vim keys, accessible mode | High |
| `Evertras/bubble-table` | 🟢 Table patterns | Stable multi-column sort, fuzzy filter, UserEvent system | Medium |
| `charmbracelet/lipgloss` | 🟢 Styling system | Style.Inherit, width measurement, text wrapping | Low |
| `daltonsw/bubbleup` | 🟢 Overlay compositing | Content overlay, position-based rendering | Low |
| `php-school/cli-menu` | 🟢 PHP menu reference | Builder pattern, non-canonical TTY mode, style propagation | Medium |

---

# Feature Gap Analysis

## Critical Priority

### 1. Keyboard Navigation Bindings
**Title:** Built-in keyboard navigation
**Description:** No built-in key bindings; user must implement all navigation (up/down/home/end/pgup/pgdn). charmbracelet/bubbles List uses vim keys (`j/k` for up/down), `pgup`/`pgdn` for page jumps, `g`/`G` for top/bottom. pterm's InteractiveSelect uses arrow keys + Enter.
**Why it matters:** Without built-in bindings, every candy-lister consumer must reimplement the same navigation logic. This is boilerplate that should be internal.
**Source:** `docs/repo_map/charmbracelet_bubbles.md` (List component with vim keys), `docs/repo_map/pterm_pterm.md` (InteractiveSelect with arrow keys)
**Implementation ideas:** Add a `KeyBindings` class with configurable bindings. `update(KeyMsg $msg): array{Model,?Cmd}` method that handles arrow keys, vim keys, pgup/pgdn, home/end. Return `?Closure` cmd for async operations.
**Estimated complexity:** Medium — requires wiring into an event loop
**Expected impact:** High — eliminates boilerplate for every consumer

### 2. tea.Model Interface Compliance
**Title:** BubbleTea-compatible Model interface
**Description:** Not a BubbleTea component; cannot be dropped into a BubbleTea application. The Go upstream implements `tea.Model` (Init/Update/View). sugar-craft has `candy-core` with `Model` trait.
**Why it matters:** Cannot integrate with existing BubbleTea apps or use BubbleTea subscriptions, commands, or the broader ecosystem.
**Source:** `docs/repo_map/charmbracelet_bubbletea.md`, `docs/repo_map/treilik_bubblelister.md`
**Implementation ideas:** Implement `SugarCraft\Core\Model` trait with `init()`, `update(Msg $msg): array{self,?Cmd}`, `view(): string`. Handle `WindowSizeMsg`, `KeyMsg`, `TickMsg`.
**Estimated complexity:** High — requires event loop integration
**Expected impact:** High — enables ecosystem integration

### 3. Stable Sorting
**Title:** Stable sort for cursor preservation
**Description:** Uses `usort` which is not guaranteed stable; cursor could jump if equal elements exist. Go upstream uses `sort.Stable` or `sort.Interface` which is stable.
**Why it matters:** When sorting items with equal keys, stable sort preserves original relative order — cursor stays on the same logical item.
**Source:** `docs/repo_map/treilik_bubblelister.md` (sort.Interface with stable sort), `docs/repo_map/Evertras_bubble-table.md` (stable multi-column sort via `sort.Stable`)
**Implementation ideas:** Implement stable sort via `ArrayObject` with `SORT_REGULAR` flag, or implement a manual merge sort. Also track cursor by item ID (already done) rather than by index.
**Estimated complexity:** Low — one-line fix with `ArrayObject::asort` pattern or custom merge sort
**Expected impact:** Medium — subtle bug that causes cursor to jump unexpectedly

---

## High Value

### 4. Virtual Scrolling / Windowing
**Title:** Viewport windowing for large lists
**Description:** All visible items in viewport are rendered every frame; no windowing for large lists. Go upstream and ratatui both support scrollable viewports. pterm's InteractiveSelect uses `MaxHeight` for virtual scrolling.
**Why it matters:** For lists with 10k+ items, rendering every item each frame is expensive. Virtual scrolling only renders visible items + small buffer.
**Source:** `docs/repo_map/ratatui_ratatui.md` (ListState with offset()), `docs/repo_map/pterm_pterm.md` (InteractiveSelect virtual scrolling)
**Implementation ideas:** Track `offset` in Model. Slice items to visible window in `lines()`. Add scroll commands (`scrollUp()`, `scrollDown()`, `scrollTo()`). Only render `items[offset:offset+height]`.
**Estimated complexity:** Medium — viewport math changes
**Expected impact:** High — enables large list handling

### 5. Mouse Support
**Title:** Click-to-select and scroll-wheel
**Description:** No mouse support. charmbracelet/bubbletea provides full mouse handling (click, wheel, motion via SGR extended mode). theHermit and bubbleboxer don't implement it either, but bubbletea does.
**Why it matters:** Users expect click-to-select in 2026. Scroll-wheel is essential for long lists.
**Source:** `docs/repo_map/charmbracelet_bubbletea.md` (MouseMsg types: MouseClickMsg, MouseWheelMsg, MouseMotionMsg)
**Implementation ideas:** Add `withMouseEnabled()` to Model. Handle `MouseMsg` in `update()`. Map click Y coordinate to item index. Map wheel events to `cursorUp/Down`.
**Estimated complexity:** Medium — requires mouse coordinate math
**Expected impact:** High — modern UX expectation

### 6. Built-in Filter UI / Type-ahead
**Title:** Filter text input that drives filtering
**Description:** Filter function must be provided by user; no text input component that drives filtering. charmbracelet/huh Select uses `/` to trigger filtering; bubbles List uses `sahilm/fuzzy`.
**Why it matters:** The most common use case is "type to filter" — should be built-in, not require user to wire up a TextInput to a filter function.
**Source:** `docs/repo_map/charmbracelet_huh.md` (Select with `Filtering(bool)`, `/` trigger), `docs/repo_map/charmbracelet_bubbles.md` (fuzzy filtering with sahilm/fuzzy)
**Implementation ideas:** Add `withFilterInput()` method accepting a `TextInput` model. When filter key `/` is pressed, focus the filter input. Apply filter on each keystroke. Show filter state in a status area.
**Estimated complexity:** Medium — requires composing with TextInput
**Expected impact:** High — most common use case

### 7. Concurrent Search
**Title:** Parallel item search
**Description:** `find()` is O(n) sequential. Go upstream's `GetIndex` launches one goroutine per item and collects via channels. In PHP this pattern would use ReactPHP promises or async workers.
**Why it matters:** For large lists (10k+ items), parallel search would be significantly faster.
**Source:** `docs/repo_map/treilik_bubblelister.md` (concurrent GetIndex with goroutines)
**Implementation ideas:** Use `React\Promise\all()` with deferred promises per chunk of items. Or use `sugarcraft/candy-pty` for process-based parallelism. Chunk items (e.g., 100 per chunk) and search chunks in parallel.
**Estimated complexity:** High — requires async/concurrent infrastructure
**Expected impact:** Medium — only matters for large lists

---

## Medium Priority

### 8. Rune-aware Word Splitting
**Title:** Unicode-aware splitOverWidth
**Description:** `splitOverWidth()` uses `strlen`/`substr` (byte offset) not rune-aware. May misbehave with multi-byte UTF-8 (emoji, CJK wide chars).
**Why it matters:** Terminal applications frequently deal with non-ASCII text. Breaking a 4-byte UTF-8 rune at byte 3 produces invalid output.
**Source:** `docs/repo_map/sugarcraft_candy-lister.md` (noted weakness), `docs/repo_map/treilik_bubblelister.md` (uses reflow/wordwrap with proper Unicode)
**Implementation ideas:** Replace `substr()` with `mb_substr()` or iterate runes using `IntlBreakIterator`. Use `preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY)` to get rune array.
**Estimated complexity:** Low — replace byte-based切割 with rune-based
**Expected impact:** Medium — bug with Unicode text

### 9. Wrap Indent (Continuation Line Indent)
**Title:** 4-space indent on wrapped lines
**Description:** Go upstream indents wrapped lines with 4 spaces. PHP port does not. This is a cosmetic divergence.
**Why it matters:** Without indent, wrapped content starts at column 0, making it unclear whether wrapped lines belong to the same item.
**Source:** `docs/repo_map/treilik_bubblelister.md` (reflow/wordwrap.HardWrap with 4-space indent)
**Implementation ideas:** Add `wrapIndent: string` property to Model (default `"    "`). Prepend indent to continuation lines in `hardWrap()`.
**Estimated complexity:** Low — string prepending
**Expected impact:** Low — cosmetic improvement

### 10. Fuzzy Filtering Quality
**Title:** Better fuzzy matching than Smith-Waterman local alignment
**Description:** Current Smith-Waterman is memory-efficient but `sahilm/fuzzy` (used by charmbracelet/bubbles) provides ranked matches with character index reporting for highlighted filtering UI. `lithammer/fuzzysearch` (used by pterm) provides similar.
**Why it matters:** For filter-as-you-type, knowing which characters matched enables visual highlighting of the match.
**Source:** `docs/repo_map/charmbracelet_bubbles.md` (sahilm/fuzzy with DefaultFilter/UnsortedFilter), `docs/repo_map/pterm_pterm.md` (lithammer/fuzzysearch)
**Implementation ideas:** Add `FuzzyFilter` class that wraps `FuzzyMatch` and also returns matched character indices. Use indices to produce highlighted output in DefaultPrefixer.
**Estimated complexity:** Medium — scoring is already there, need index reporting
**Expected impact:** Medium — improves UX of filtering

---

## Low Priority

### 11. Mutex-protected ID Counter
**Title:** Thread-safe item ID generation
**Description:** `idCounter` is plain `int` with no mutex. Thread-safety concern in async/concurrent contexts.
**Source:** `docs/repo_map/treilik_bubblelister.md` (Go uses mutex-protected counter)
**Implementation ideas:** Use `AtomicInt` from ReactPHP or `synchronized` block. For most PHP use cases (single-threaded), this is not critical.
**Estimated complexity:** Low — add mutex/lock
**Expected impact:** Low for single-threaded, higher for concurrent

### 12. Mutex-less ID Counter
**Title:** Stable sorting with stable sort
**Description:** Uses `usort` which is not guaranteed stable.
**Source:** Same as critical item #3
**Implementation ideas:** Implement stable sort
**Estimated complexity:** Low
**Expected impact:** Medium

### 13. DefaultPrefixer Improvements
**Title:** Relative line numbers, custom separators
**Description:** DefaultPrefixer already has absolute/relative numbering. Could add configurable separator strings (pterm uses `├── ` and `│   ` for tree indentation).
**Source:** `docs/repo_map/pterm_pterm.md` (TreePrinter with walkOverTree)
**Implementation ideas:** Add `setSeparator()`, `setCurrentMarker()` to DefaultPrefixer. Support custom tree characters.
**Estimated complexity:** Low — configuration addition
**Expected impact:** Low

### 14. Help Integration
**Title:** Auto-generated help from keybindings
**Description:** No help system. charmbracelet/bubbles Help component generates help from KeyMap interface (`ShortHelp() / FullHelp() []key.Binding`).
**Source:** `docs/repo_map/charmbracelet_bubbles.md` (Help component), `docs/repo_map/treilik_bubblelister.md`
**Implementation ideas:** Add `Help` class that reads `KeyMap` and renders help text. Display on `?` keypress.
**Estimated complexity:** Medium — help text rendering
**Expected impact:** Medium — improves discoverability

### 15. Pagination
**Title:** Page-based navigation for long lists
**Description:** No pagination. ratatui's ListState has `offset()` and `scroll_to()`. charmbracelet/bubbles Paginator provides pagination logic.
**Source:** `docs/repo_map/ratatui_ratatui.md` (ListState.offset()), `docs/repo_map/charmbracelet_bubbles.md` (Paginator)
**Implementation ideas:** Add `pageSize` property and `paginate()` method. Add `nextPage()`, `prevPage()`, `firstPage()`, `lastPage()` navigation.
**Estimated complexity:** Low — similar to virtual scrolling
**Expected impact:** Medium — alternative navigation mode

---

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### Fuzzy Matching: Smith-Waterman vs sahilm/fuzzy / lithammer/fuzzysearch

**candy-lister:** Smith-Waterman local alignment with two-row DP matrix. Returns score but not matched indices.

**External (sahilm/fuzzy, lithammer/fuzzysearch):** Subsequence-based matching. Returns `[]int` of matched character positions — enables visual highlighting.

**Why external is better for filtering UX:** When filtering "sep" against "September", knowing that positions 0,1,2 match enables rendering `"[Sep}tember"` with the matched portion highlighted. Smith's score alone doesn't tell WHERE the match occurred.

**Tradeoffs:** Smith-Waterman is more algorithmically sophisticated (rewards consecutive matches, penalizes gaps) but subsequence is sufficient for most UI filtering and enables highlighting.

**Applicability:** High — candy-lister already has FuzzyMatch; augment to also return matched indices.

### Sequential Find vs Concurrent Goroutine Search

**candy-lister:** O(n) sequential `find()` using `equalsFunc` or string comparison.

**External (Go treilik/bubblelister):** One goroutine per item, collects results via channels. Parallel O(n/p) where p = goroutine count.

**Why external is better:** For 10k-item lists, parallel search is ~p× faster. Go's goroutines are cheap; PHP's equivalent would be processes or promises.

**Tradeoffs:** PHP-FPM is not designed for long-running concurrent computation. ReactPHP async is the right model, but requires architecture changes. For most lists (< 1000 items), this is premature optimization.

**Applicability:** Medium — depends on target list sizes

### Immediate-mode Rendering vs Buffer Diffing

**candy-lister:** Renders full list on each `View()` call. No diffing — full string rebuild.

**External (ratatui):** Immediate-mode; app redraws everything, renderer diffs against previous buffer, only changed cells written.

**Why external is better:** For large viewports (100×40 = 4000 cells), full redraws are expensive. Buffer diffing writes only ~10-50 changed cells per frame.

**Tradeoffs:** Buffer diffing requires holding previous buffer in memory and computing diff on each frame. For most terminal apps (60fps animations excluded), full redraws are acceptable.

**Applicability:** Low for simple lists, High for large/dynamic views

---

# Architecture Improvements

## 1. Implement `SugarCraft\Core\Model` Trait
Add `init()`, `update(Msg $msg): array{self,?Cmd}`, `view(): string` to enable integration with `candy-core` runtime. Handle `KeyMsg`, `WindowSizeMsg`, `TickMsg` internally.

**Source:** `docs/repo_map/charmbracelet_bubbletea.md` (tea.Model interface), `docs/repo_map/sugarcraft_candy-core.md`

## 2. Extract Rendering from Model
Separate `Renderer` from `Model`. `Model` holds state; `Renderer` produces output. Allows testing rendering in isolation.

**Source:** `docs/repo_map/treilik_bubbleboxer.md` (ModelMap separation), `docs/repo_map/ratatui_ratatui.md` (Widget trait)

## 3. Add `ListState` for StatefulWidget Pattern
Track `offset`, `selected`, `selecting` as separate state object. ratatui uses `StatefulWidget` + `ListState` to separate persistent state from widget definition.

**Source:** `docs/repo_map/ratatui_ratatui.md` (ListState with select(), selected(), offset())

## 4. Event System (UserEvent)
Add `UserEvent` types for highlight change, selection toggle, filter focus/unfocus — enabling application to react without polling.

**Source:** `docs/repo_map/Evertras_bubble-table.md` (UserEvent system)

---

# API / Developer Experience Improvements

## 1. Builder Pattern for Model Construction
Add `Model::builder(): ModelBuilder` for step-by-step fluent construction.

**Source:** `docs/repo_map/php-school_cli-menu.md` (CliMenuBuilder), `docs/repo_map/Evertras_bubble-table.md` (fluent With*)

## 2. Option Functions Pattern
Add `withWidth()`, `withHeight()`, `withViewport()` option functions alongside fluent setters, for ergonomic partial application.

**Source:** `docs/repo_map/charmbracelet_bubbles.md` (Option func(*Model) pattern)

## 3. Error Types
Replace `RuntimeException` messages with typed errors: `NoItemsError`, `NotFoundError`, `OutOfBoundsError`. Go upstream uses these; PHP should too.

**Source:** `docs/repo_map/treilik_bubblelister.md` (Error types: NoItems, NotFound, OutOfBounds, MultipleMatches)

## 4. Named Constructors
Add `Model::fromArray(array $items): self`, `Model::fromTraversable(\Traversable $items): self` for common initialization patterns.

**Source:** `docs/repo_map/pterm_pterm.md` (TableDataFromCSV, TableDataFromStructSlice)

---

# Documentation / Cookbook Opportunities

## 1. Recipe: File Browser with Tree Prefixer
Example showing `DefaultPrefixer` with tree indentation for a file tree. Also document `Prefixer`/`Suffixer` interface usage.

**Source:** `docs/repo_map/treilik_bubblelister.md` (tree view example), `docs/repo_map/pterm_pterm.md` (TreePrinter)

## 2. Recipe: Selectable List with vim Keys
Example showing keyboard-driven selection with vim-style navigation (`j/k`).

**Source:** `docs/repo_map/charmbracelet_bubbles.md` (List vim keys), `docs/repo_map/charmbracelet_huh.md` (vim-style navigation)

## 3. Recipe: Filterable List with FuzzyMatch
Example showing `withFilterFn()` with `FuzzyMatch::match()` wired to a text input.

**Source:** `docs/repo_map/charmbracelet_bubbles.md` (fuzzy filtering)

## 4. Recipe: Large List with Virtual Scrolling
Example showing `setViewport()` with chunked item loading for 10k+ item lists.

**Source:** `docs/repo_map/ratatui_ratatui.md` (viewport virtualization), `docs/repo_map/pterm_pterm.md` (MaxHeight virtual scrolling)

## 5. API Reference
Improve README with complete method signatures, all constructor options, and a visual screenshot of rendered output.

**Source:** `docs/repo_map/charmbracelet_bubbles.md` (screenshot in README), `docs/repo_map/pterm_pterm.md`

---

# UX / TUI Improvements

## 1. DefaultPrefixer Enhancements
- Add `setCurrentMarker(string)` — currently hardcoded to `>`
- Add `setSeparatorWrap(string)` — currently hardcoded to `│`
- Add `setEmptyMarker(string)` — for empty list indicator
- Add `setNumberRelative(bool)` — toggle absolute/relative numbering

**Source:** `docs/repo_map/treilik_bubblelister.md` (DefaultPrefixer properties), `docs/repo_map/pterm_pterm.md` (TreePrinter)

## 2. Line Style per Item Type
Support per-item style based on content (e.g., directories in bold, files normal). Currently styles are global `lineStyle`/`currentStyle`.

**Source:** `docs/repo_map/Evertras_bubble-table.md` (StyleFunc per-row), `docs/repo_map/charmbracelet_lipgloss.md` (Style.Inherit)

## 3. Zebra Striping
Add `setZebra(bool)` and `setZebraStyle(Style)` for alternating row backgrounds. bubble-table uses `RowStyleFunc`.

**Source:** `docs/repo_map/Evertras_bubble-table.md` (zebra striping via RowStyleFunc)

## 4. Per-side Border Colors
Support `BorderForeground` / `BorderBackground` with individual side accessors — lipgloss supports this extensively.

**Source:** `docs/repo_map/charmbracelet_lipgloss.md` (per-side border colors with BorderForeground/Background)

---

# Testing / Reliability Improvements

## 1. Golden / Snapshot Tests
Add snapshot tests asserting exact `\x1b[...m` SGR byte sequences for known inputs. pterm has 28,952 tests; bubble-table has fuzz tests.

**Source:** `docs/repo_map/pterm_pterm.md` (28,952 tests), `docs/repo_map/Evertras_bubble-table.md` (fuzz tests for scrolling)

## 2. Fuzz Tests for Word Wrapping
Test `hardWrap()` and `splitOverWidth()` with random Unicode strings, emoji, CJK characters, and edge cases (empty strings, single long words, whitespace-only).

**Source:** `docs/repo_map/Evertras_bubble-table.md` (fuzz tests for scrolling)

## 3. Property-based Tests
Test cursor clamping, filter state transitions, sort stability with QuickCheck-style random inputs.

**Source:** `docs/repo_map/Evertras_bubble-table.md` (fuzz tests)

## 4. Concurrent Search Tests
If implementing concurrent search, add tests for correctness (same results as sequential) and performance (measurable speedup).

**Source:** `docs/repo_map/treilik_bubblelister.md` (concurrent GetIndex)

---

# Ecosystem / Integration Opportunities

## 1. Integration with sugar-prompt
Wire candy-lister as the completion list for sugar-prompt's fuzzy autocomplete. FuzzyMatch is already used in Prompt.

**Source:** `docs/repo_map/sugarcraft_sugar-prompt.md` (FuzzyMatcher in Prompt), `docs/repo_map/charmbracelet_bubbles.md` (List with fuzzy filter)

## 2. Integration with sugar-table
Use candy-lister for `Table::Filter()` row filtering. bubble-table uses `filterFuncContains` and `filterFuncFuzzy`.

**Source:** `docs/repo_map/Evertras_bubble-table.md` (FilterFunc with fuzzy filter)

## 3. Integration with candy-forms
Expose candy-lister as `ItemList` component in candy-forms (the form primitives base). Currently `sugar-bits` ItemList is 🟡 with fuzzy filtering gaps.

**Source:** `docs/repo_map/sugarcraft_candy-forms.md` (ItemList), `docs/repo_map/charmbracelet_bubbles.md` (List component)

## 4. Integration with bubbleboxer Layout
A candy-lister instance could be a leaf in a bubbleboxer-style layout tree — multiple lists stacked or side-by-side with borders.

**Source:** `docs/repo_map/treilik_bubbleboxer.md` (layout tree pattern), `docs/repo_map/Genekkion_theHermit.md` (overlay vs stacked)

## 5. BubbleTea Program Integration
If Model implements `tea.Model`, it can run inside `candy-core`'s `Program::run()`. This enables drop-in for Go BubbleTea apps being ported to PHP.

**Source:** `docs/repo_map/charmbracelet_bubbletea.md` (tea.Model integration)

---

# Notable PRs / Issues / Discussions

## treilik/bubblelister: Concurrent GetIndex (upstream)
**Summary:** Go upstream's `GetIndex` uses goroutines to search items in parallel, returning when first match found or collecting multiple matches.
**Relevance:** Reference for concurrent search pattern in candy-lister.
**Lessons:** Goroutines are cheap in Go; PHP needs ReactPHP promises or process workers for equivalent parallelism.

## charmbracelet/bubbles#595: List fuzzy filtering
**Summary:** The List component in bubbles uses `sahilm/fuzzy` for real-time filtering with matched index reporting.
**Relevance:** Reference for integrating fuzzy filter into List.
**Lessons:** `sahilm/fuzzy` provides both score and matched indices — enables highlighting matched characters in the UI.

## Evertras/bubble-table#127: Multi-column stable sort
**Summary:** bubble-table uses Go's `sort.Stable` for iterative multi-column sorting, preserving relative order of equal primary-key values.
**Relevance:** Reference for stable sort implementation.
**Lessons:** `sort.Stable` with `sort.Interface` (Len/Less/Swap) cleanly separates sort logic from data.

## pterm/pterm#521: Interactive select performance
**Summary:** pterm's InteractiveSelect uses `MaxHeight` for virtual scrolling — only renders `MaxHeight` items at a time.
**Relevance:** Reference for virtual scrolling without full viewport math.
**Lessons:** Simple offset-based slicing handles most cases; complex scroll mathematics (ratatui's `scroll_to()`) may be premature optimization.

## Genekkion/theHermit: Overlay rendering
**Summary:** theHermit overlays list content onto specific regions of the existing view, allowing background to continue updating.
**Relevance:** Reference for overlay positioning and center calculation.
**Lessons:** Center position: `midPoint1 = windowHeight/2 - height/2 + 1`. Background preservation enables non-destructive list display.

## ratatui#684: Cassowary constraint solver for layout
**Summary:** ratatui uses Cassowary algorithm via `kasuari` crate for constraint-based layout that handles resize automatically.
**Relevance:** Reference for future layout system in candy-lister (if it becomes a layout-aware component).
**Lessons:** Cassowary solves layout constraints declaratively — `Width(10) + Gap(5) + Flex(1)` style constraints are more flexible than manual calculation.

---

# Recommended Roadmap

## Immediate Wins (0–2 weeks)

1. **Fix byte-based word splitting** — Replace `substr()`/`strlen` with rune-aware `mb_substr()`/`preg_split('//u')` in `splitOverWidth()`. Low risk, clear bug fix.
2. **Implement stable sort** — Replace `usort` with a manual merge sort or `ArrayObject::asort` with `SORT_REGULAR`. Clear correctness fix.
3. **Add wrap indent** — Add `$this->wrapIndent` property defaulting to `"    "`; prepend to continuation lines in `hardWrap()`. Cosmetic improvement.
4. **Add typed errors** — Replace `RuntimeException` messages with `NoItemsError`, `NotFoundError`, `OutOfBoundsError` classes.

## Medium-term Improvements (1–3 months)

5. **Built-in keyboard navigation** — Add `KeyBindings` class and `update(KeyMsg $msg)` method handling vim keys, pgup/pgdn, home/end. Integrate with `candy-core` event loop.
6. **Mouse support** — Add `withMouseEnabled()` and handle `MouseMsg` for click-to-select and scroll-wheel.
7. **FuzzyMatch index reporting** — Extend `FuzzyMatch::score()` to also return matched character indices alongside score.
8. **Virtual scrolling** — Track `offset` in Model; slice items to visible window; add scroll navigation methods.
9. **Builder pattern** — Add `ModelBuilder` class for step-by-step fluent construction.

## Major Architectural Upgrades (3–6 months)

10. **`tea.Model` compliance** — Implement `SugarCraft\Core\Model` trait with `init()`/`update()`/`view()`. Enable integration with `candy-core` runtime.
11. **Concurrent search** — Use ReactPHP promises for parallel item search. Chunk items and search chunks in parallel.
12. **Event system (UserEvent)** — Add `UserEvent*` types for application reaction to user interactions.
13. **Built-in filter UI** — Add `withFilterInput()` method composing with `TextInput`. Handle `/` to trigger filtering.
14. **Help integration** — Add `Help` class that renders keybindings from `KeyMap`. Display on `?` keypress.

## Experimental Ideas (6+ months)

15. **Cassowary layout** — If Model becomes layout-aware, use Cassowary constraint solver for responsive sizing.
16. **Layer compositing** — If overlay rendering needed, adopt lipgloss-style `Layer`/`Compositor` pattern.
17. **Animation** — Add spring-based cursor movement (via `honey-bounce`) for smooth navigation transitions.

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|---|---|---|---|---|
| Fix byte-based word splitting | Medium | Low | Low | Immediate |
| Implement stable sort | Medium | Low | Low | Immediate |
| Add wrap indent | Low | Low | Low | Immediate |
| Add typed errors | Medium | Low | Low | Immediate |
| Built-in keyboard navigation | High | Medium | Medium | Medium-term |
| Mouse support | High | Medium | Medium | Medium-term |
| FuzzyMatch index reporting | Medium | Medium | Low | Medium-term |
| Virtual scrolling | High | Medium | Medium | Medium-term |
| Builder pattern | Medium | Low | Low | Medium-term |
| tea.Model compliance | High | High | High | Major |
| Concurrent search | Medium | High | High | Major |
| UserEvent system | Medium | Medium | Medium | Major |
| Built-in filter UI | High | Medium | Medium | Major |
| Help integration | Medium | Medium | Low | Major |
| Cassowary layout | Low | High | High | Experimental |
| Layer compositing | Low | High | High | Experimental |
| Animation | Low | Medium | Medium | Experimental |

---

# Final Strategic Assessment

**candy-lister** provides a solid, focused rendering engine for viewport-aware lists in PHP TUIs. Its pluggable Prefixer/Suffixer architecture is well-designed and matches the spirit of the Go upstream while being idiomatic to PHP. The filter state machine and Smith-Waterman fuzzy matching are genuine extensions beyond the upstream that add value.

The most pressing gap is **keyboard navigation** — every consumer must reimplement the same vim keys, pgup/pgdn, home/end logic. This is textbook premature generalization: the library correctly separated rendering from event handling, but overshot by leaving ALL event handling to consumers. Adding a `KeyBindings` class with a simple `update(KeyMsg $msg): array{Model,?Cmd}` method would dramatically improve usability.

The second major gap is **tea.Model compliance**. Since `candy-core` implements the BubbleTea runtime in PHP, candy-lister should implement `SugarCraft\Core\Model` to enable drop-in use. This is the port's reason-for-being — being the list component for a PHP BubbleTea.

The third gap is **mouse support and virtual scrolling** — modern TUI expectations in 2026. These are independent improvements that could be added in any order.

The Smith-Waterman implementation is already excellent (O(c) memory, consecutive match bonus), but lacks matched index reporting. Augmenting it to return matched indices would enable the visual highlighting that makes fuzzy filtering feel polished.

The architecture is clean enough that these improvements can be layered without redesign. The immediate priorities should be: fix the Unicode bug (byte-based splitting), implement stable sort, add keyboard navigation, and add tea.Model compliance.

For a v1 release, the library is feature-complete and stable. The above roadmap prioritizes by impact × complexity, focusing on immediate wins before major architectural investments.
