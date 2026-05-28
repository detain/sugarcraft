# sugar-bits Update Report

## Overview

**sugar-bits** is a mature PHP port of `charmbracelet/bubbles` providing 15+ pre-built TUI components for the SugarCraft monorepo. The library occupies a critical position as the primary component layer (`SugarCraft\Bits`) built on foundational libraries (`candy-core`, `candy-forms`, `candy-sprinkles`). The biggest opportunity is filling the fuzzy filtering gap (ranked matches with character indices), adding a FlexBox layout system (per `76creates/stickers`), and implementing the missing half-block color blending for high-resolution progress bars. The library correctly uses a stratified alias pattern where simple wrappers re-export from `candy-forms` while keeping original implementations for Table, Tree, Tabs, and other leaf components.

## Internal Capability Summary

### Current Architecture

The library uses a **stratified alias pattern**:
- **Aliases** (from `candy-forms`): `TextInput`, `ItemList`, `Viewport`, `FilePicker`, `Spinner`, `Cursor`, `TextArea`, `Scrollbar`
- **Original implementations**: `Table` (720 lines), `Tree` (364 lines), `Tabs` (602 lines), `Help` (311 lines), `Paginator` (225 lines), `Timer` (177 lines), `Stopwatch` (141 lines), `Progress` (434 lines), `AnimatedProgress`, `Key\Binding`

### Core Components

| Component | Lines | Key Features |
|---|---|---|
| `Table` | 720 | Multi-column sort, filter predicate, pagination, per-cell styleFunc |
| `Tree` | 364 | Expand/collapse, Node::branch()/leaf(), viewport scroll |
| `Tabs` | 602 | Keyboard + zone-based mouse, scrollable overflow |
| `Help` | 311 | Short/full mode, Styles customization |
| `Progress` | 434 | Multi-stop gradients, Block/Line/Slim render modes |
| `TextInput` | 987 | Vim mode, ValidateOn timing, restrict pattern |
| `ItemList` | 680+ | Filter-as-you-type, pagination, vim keys |
| `TextArea` | ~800 | Line numbers, soft-wrap, Ctrl+O external editor |

### Model Contract

All components implement:
```php
interface Model {
    public function init(): ?\Closure;
    public function update(Msg $msg): array;  // [Model, ?Cmd]
    public function view(): string;
    public function subscriptions(): ?\Subscriptions;
}
```

### Key Patterns

1. **Immutable + fluent**: `with*()` returns new instance via private `mutate()` with sentinel `bool $XSet`
2. **ID-based routing**: Timer/Stopwatch/Cursor use static `$nextId` counter
3. **Focus/Blur**: `focus(): array` returns `[self, ?Cmd]`, `blur(): self`
4. **Zone-based mouse**: Tabs uses `Manager` for zone detection

### Strengths

- Comprehensive 15+ component coverage
- Immutable + fluent builder pattern throughout
- **Per-cell `styleFunc` enhancement** — not in upstream Go Bubbles
- **Vim mode for TextInput** — not in upstream
- **ValidateOn timing control** — not in upstream
- **Tabs zone-based mouse** — not in upstream
- Good test coverage with behavioral and snapshot tests
- i18n support with 16 locales
- Spring physics animation via `honey-bounce`

### Weaknesses

- Fuzzy filtering uses simple substring match — no ranking or character index reporting
- TextArea soft-wrap lacks full `LineInfo` accounting for double-width Unicode
- Tree has no fuzzy filtering (noted as TODO in upstream)
- No FlexBox layout component (stickers provides this)
- No TST-based fast prefix search (smenu pattern)
- No half-page navigation (`HalfPageUp`/`HalfPageDown`)
- No half-block color blending for progress (2x color resolution)
- No TextArea case transforms (`alt+u/l/c`) or character transpose (`ctrl+t`)

## Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|---|---|---|---|
| `charmbracelet/bubbles` | Primary upstream | Elm architecture, fuzzy filtering (sahilm/fuzzy), half-block color blending, LineInfo for soft-wrap, KeyMap introspection | Critical |
| `76creates/stickers` | High | Ratio-based FlexBox layout, generics for type-safe columns, ContentGenerator pattern, x/y bidirectional scrolling | Critical |
| `pterm/pterm` | High | TextPrinter interface, LivePrinter for updates, fuzzy search (lithammer/fuzzysearch), AreaPrinter for dynamic regions, slog integration, RGB.Fade() | High |
| `erikgeiser/promptkit` | High | Generic type-safe prompts, common prefix auto-complete, auto-pagination, template-based rendering | High |
| `p-gen/smenu` | Medium | Ternary Search Tree for fast prefix/fuzzy search, bitmap tracking for match highlighting, multiple search modes | Medium |
| `treilik/bubblelister` | Medium | Pluggable prefixer/suffixer interfaces, concurrent search via goroutines, sort.Interface pattern | Medium |
| `charmbracelet/huh` | Medium | Dynamic forms with *Func pattern, 5 built-in themes, accessible mode, Bubble Tea integration | Medium |
| `rmhubbert/bubbletea-overlay` | Low | Compositing engine, Viewable interface, 5-position positioning system | Low |
| `Genekkion/theHermit` | Low | Quick-fix overlay pattern, ANSI-aware width calculation | Low |

## Feature Gap Analysis

### Critical

1. **Fuzzy Filtering with Ranking** (ItemList)
   - **Description**: ItemList uses simple case-insensitive substring matching. Upstream bubbles uses `sahilm/fuzzy` for ranked matches with character indices.
   - **Why it matters**: Modern filtering UIs show ranked results with highlighted match positions. Without this, sugar-bits ItemList feels primitive compared to interactive-select in pterm or Selection in promptkit.
   - **Source**: `docs/repo_map/charmbracelet_bubbles.md` — List uses `sahilm/fuzzy` with `DefaultFilter`/`UnsortedFilter` variants
   - **Source**: `docs/repo_map/pterm_pterm.md` — InteractiveSelect uses `lithammer/fuzzysearch` to rank-filter options
   - **Implementation ideas**: Port or wrap a PHP fuzzy library (e.g., `gabordemooij/cit` pattern), implement `FilterMatch` struct with score + character indices
   - **Complexity**: Medium — requires algorithmic implementation
   - **Expected impact**: High — transforms ItemList from basic to production-grade

2. **FlexBox Layout Component** (new component)
   - **Description**: No equivalent to stickers' ratio-based responsive grid layout system.
   - **Why it matters**: Complex dashboards need CSS flexbox-style responsive layouts. Without this, developers must manually calculate terminal dimensions.
   - **Source**: `docs/repo_map/76creates_stickers.md` — FlexBox/Row/Cell hierarchy with ratio-based sizing, minWidth/minHeight, ContentGenerator
   - **Implementation ideas**: Port `flexbox/utils.go` ratio algorithms, implement `FlexBox`→`Row`→`Cell` chain with `withContentGenerator(callable)` pattern
   - **Complexity**: High — ratio distribution algorithms are non-trivial
   - **Expected impact**: Critical — enables complex dashboard UIs

### High Value

3. **Half-Block Color Blending** (Progress)
   - **Description**: Progress bar uses solid block characters. Upstream uses Unicode `▌` with separate foreground/background for 2x color resolution.
   - **Why it matters**: Visual fidelity — gradient transitions appear smoother with half-block stepping.
   - **Source**: `docs/repo_map/charmbracelet_bubbles.md` — "half-block color blending using Unicode `▌` with separate foreground/background"
   - **Implementation ideas**: Add `withHalfBlock(true)` option that uses `\xE2\x96\x8C` (▌) with fg/bg color pairing
   - **Complexity**: Low — rendering technique change
   - **Expected impact**: Medium — visual polish

4. **TextArea LineInfo Accounting**
   - **Description**: Upstream maintains `LineInfo` struct with Width, Height, CharWidth, ColumnOffset, RowOffset. sugar-bits TextArea lacks this for double-width Unicode.
   - **Why it matters**: Cursor positioning across wrapped lines breaks with CJK characters.
   - **Source**: `docs/repo_map/charmbracelet_bubbles.md` — "TextArea soft-wrap line tracking maintains `LineInfo` struct... for precise cursor positioning across wrapped lines"
   - **Implementation ideas**: Create `LineInfo` struct, track per-line character widths during soft-wrap
   - **Complexity**: Medium — requires rune-width awareness
   - **Expected impact**: High — correctness for international text

5. **Help KeyMap Interface Introspection**
   - **Description**: Help renders directly from `KeyMap` struct. Upstream calls `ShortHelp()`/`FullHelp()` on any KeyMap implementor.
   - **Why it matters**: Polymorphic help generation enables user-defined keybinding collections.
   - **Source**: `docs/repo_map/charmbracelet_bubbles.md` — "Help component introspects `ShortHelp() / FullHelp() []key.Binding` on any implementation"
   - **Implementation ideas**: Create `KeyMap` interface with `shortHelp()`/`fullHelp()` methods
   - **Complexity**: Low — interface addition
   - **Expected impact**: Medium — extensibility

6. **Table Half-Page Navigation**
   - **Description**: Table has `HalfPageUp`/`HalfPageDown` not implemented.
   - **Why it matters**: Large table navigation is inefficient without half-page scrolling.
   - **Source**: `docs/repo_map/sugarcraft_sugar-bits.md` — "Table half-page navigation — `HalfPageUp` / `HalfPageDown` not implemented"
   - **Implementation ideas**: Implement `HalfPageUp`/`HalfPageDown` in update() switch
   - **Complexity**: Low — straightforward cursor offset calculation
   - **Expected impact**: Medium — usability for large tables

### Medium

7. **TextArea Case Transforms** (`alt+u/l/c`)
   - **Description**: Uppercase/lowercase/capitalize word forward not implemented.
   - **Source**: `docs/repo_map/charmbracelet_bubbles.md` — "TextArea case transforms (`alt+u/l/c` for uppercase/lowercase/capitalize)"
   - **Implementation ideas**: Add transform methods, bind to alt-key combos
   - **Complexity**: Low
   - **Expected impact**: Low — niche feature

8. **TextArea Character Transpose** (`ctrl+t`)
   - **Description**: Character transposition not implemented.
   - **Source**: `docs/repo_map/charmbracelet_bubbles.md` — "character transpose (`ctrl+t`) not yet in PHP"
   - **Implementation ideas**: Swap character at cursor with previous
   - **Complexity**: Low
   - **Expected impact**: Low

9. **Tree Fuzzy Filtering**
   - **Description**: Tree has no fuzzy filtering (noted as TODO in upstream bubbles).
   - **Source**: `docs/repo_map/sugarcraft_sugar-bits.md` — "Tree fuzzy filtering — Noted as TODO"
   - **Implementation ideas**: Leverage the new fuzzy filtering when implemented for ItemList
   - **Complexity**: Medium
   - **Expected impact**: Medium

10. **TextArea Word Wrap Limit**
    - **Description**: No per-item line limit for wrapped content.
    - **Source**: `docs/repo_map/treilik_bubblelister.md` — Wrap property for lines-per-item limit
    - **Implementation ideas**: Add `withWrapLimit(int)` to TextArea
    - **Complexity**: Low
    - **Expected impact**: Low

### Low Priority

11. **Interactive Confirm/Continue Prompts**
    - **Description**: No standalone yes/no or continue prompts separate from pterm-style usage.
    - **Source**: `docs/repo_map/pterm_pterm.md` — InteractiveConfirmPrinter, InteractiveContinuePrinter
    - **Implementation ideas**: Could be composed from existing primitives
    - **Complexity**: Low
    - **Expected impact**: Low

12. **slog-Style Structured Logging**
    - **Description**: pterm's Logger has slog integration. sugar-bits has no structured logging.
    - **Source**: `docs/repo_map/pterm_pterm.md` — SlogHandler bridge to Go 1.21+ log/slog
    - **Implementation ideas**: Could add PSR-3 compatible structured logger
    - **Complexity**: Medium
    - **Expected impact**: Low — PHP ecosystem has existing logging solutions

## Algorithm / Performance Opportunities

### Current Approach vs External

| Aspect | Current | External (Better) | Tradeoffs | Applicability |
|---|---|---|---|---|
| ItemList filtering | Case-insensitive substring `str_contains()` | `sahilm/fuzzy` ranked fuzzy with match indices | SugarCraft gains ranked results + highlighted matches; adds dependency | High |
| Table sorting | Bubble sort (per stickers note) | Go's `sort.Slice` with generics | Replace with `usort` + typed callback; O(n log n) vs O(n²) | Medium |
| FlexBox layout | None | `calculateRatioWithMinimum` recursive algorithm | Port ratio distribution math; complex but enables responsive layouts | Critical |
| Prefix search | Linear scan | Ternary Search Tree (smenu) | TST gives O(k) prefix search where k=key length; significant for large lists | Medium |
| Common prefix auto-complete | None | `commonPrefix` via sorted endpoints (promptkit) | Sort once, compare first/last only; O(n log n) sort + O(k) compare | Medium |

### Bubble Sort in Table

The `76creates/stickers` uses bubble sort for table ordering. SugarCraft Table's sorting is likely similar. Quicksort/mergesort via PHP's `usort` would improve large dataset performance from O(n²) to O(n log n).

### TST-Based Prefix Search (smenu)

The `p-gen/smenu` C library uses a Ternary Search Tree for indexing words, enabling O(k) prefix search where k is the key length. For ItemList with large word lists (e.g., autocomplete dictionaries), this would be significantly faster than linear scan.

**Implementation for PHP**: A `TernarySearchTree` class with `insert()`, `prefixSearch()`, `fuzzyTraverse()` methods. Each node stores a linked list of positions for repeated words.

## Architecture Improvements

1. **Extract FlexBox to separate lib** (`sugar-layout` or `candy-layout`)
   - Ratio-based responsive grid is complex enough to warrant its own lib
   - Would enable complex dashboard compositions

2. **Add TextPrinter-like interface** (per pterm pattern)
   - `TextPrinter` interface with `Sprint()`/`Sprintf()`/`Sprintln()`/`Print()`/`PrintOnError()`
   - Consistent API surface across all components

3. **Add LivePrinter for dynamic updates** (per pterm pattern)
   - `LivePrinter` interface with `GenericStart()`/`GenericStop()`
   - Enables AnimatedProgress, Spinner, AreaPrinter to share update infrastructure

4. **Consider bubble tea-overlay pattern** for modal system
   - `Viewable` interface accepting any `View() string` implementer
   - Compositing engine for overlays (5-position: Top/Right/Bottom/Left/Center)

5. **Add FilterResult DTO** for fuzzy matching
   - `FilterResult` struct: `score`, `indices[]` (matched character positions), `matched` bool
   - Enables highlighted filtering UI

## API / Developer Experience Improvements

1. **Consistent short-form aliases** across ALL components
   - Currently TextInput, TextArea, Help have short forms (`placeholder()`, `charLimit()`, `width()`, etc.)
   - Extend to Table (`headers()`, `rows()`, `pageSize()`), Tree (`nodes()`, `height()`), Tabs (`items()`, `active()`)

2. **Add `CommonPrefix` auto-complete helper** (per promptkit)
   ```php
   // Sorts suggestions, compares first and last only
   public static function commonPrefix(array $suggestions): string
   ```

3. **Add `WrapMode` enum** for TextArea (per promptkit)
   - `WordWrap` — break at word boundaries
   - `HardWrap` — break at column width
   - `Truncate` — ellipsis at width

4. **Improve error messages with localizable exceptions**
   - Table already does `table.sort_unknown_column` with localizable message
   - Extend to all components

5. **Add `FilterMatch` struct** for fuzzy results
   ```php
   final readonly class FilterMatch {
       public function __construct(
           public int $score,
           public list<int> $indices,
           public bool $matched,
       ) {}
   }
   ```

## Documentation / Cookbook Opportunities

1. **Add "Advanced Filtering" cookbook** showing:
   - Custom `withFilterPredicate()` for cross-column search
   - Fuzzy ranking implementation
   - Highlighted match display using `\x1b[7m` (inverse video)

2. **Add "FlexBox Layouts" cookbook** (when implemented) showing:
   - Ratio-based responsive grids
   - Nested FlexBox compositions
   - ContentGenerator for adaptive text

3. **Add "Theming" cookbook** showing:
   - Custom Styles per component
   - Theme presets
   - Dark/light mode detection

4. **Add "Animation" cookbook** showing:
   - AnimatedProgress spring physics
   - Composing animated components
   - TickMsg timing patterns

5. **Improve inline API documentation**
   - Add parameter doc comments with types
   - Add `@see` references to related methods
   - Add examples in docblocks

## UX / TUI Improvements

1. **Half-page navigation for Table**
   - Implement `HalfPageUp`/`HalfPageDown` for efficient large-table navigation
   - Current PageUp/PageDown jumps entire page; half-page is more ergonomic

2. **Auto-pagination based on terminal height** (per promptkit)
   - `forceUpdatePageSizeForHeight()` — automatically adjusts page size to fit terminal
   - Formula: try preferred page size, if doesn't fit, brute force find fitting size

3. **Help should support `KeyMap` interface introspection**
   - Call `shortHelp()`/`fullHelp()` on any KeyMap implementor
   - Enables user-defined keybinding collections with polymorphic help

4. **Add `Viewable` interface** for overlay system
   ```php
   interface Viewable {
       public function view(): string;
   }
   ```
   Enables bubbletea-overlay style compositing

5. **Improve Tree/Table cursor clamping**
   - Ensure cursor never goes below 0 or above last item index
   - Currently may not handle edge cases properly

## Testing / Reliability Improvements

1. **Add snapshot tests for ALL components**
   - pterm has 28,952 tests with exact ANSI byte assertions
   - sugar-bits should similarly assert exact `\x1b[...m` sequences

2. **Add fuzz testing for input validation**
   - TextInput withRestrict should be fuzzed with invalid PCRE patterns
   - Edge cases: empty restrict, unicode restrict, malformed regex

3. **Add performance regression tests**
   - Table sort on 10,000 rows should complete within threshold
   - ItemList filter on 10,000 items should complete within threshold

4. **Add concurrent update tests** for Timer/Stopwatch
   - Multiple timers updating simultaneously
   - Race condition detection

5. **Add fuzzy filtering test coverage**
   - Test ranking behavior
   - Test match index reporting
   - Test with various input patterns

## Ecosystem / Integration Opportunities

1. **Create `sugar-layout` FlexBox library** (from stickers port)
   - High-value addition for complex dashboard UIs
   - Ratio-based responsive grid with min constraints

2. **Integrate with ReactPHP for async**
   - pterm uses goroutines for concurrent search (treilik/bubblelister)
   - SugarCraft should use ReactPHP coroutines instead
   - `React\Async\async()` for concurrent operations

3. **Add PSR-3 logger adapter**
   - Bridge sugar-bits logging to PSR-3 compatible loggers
   - Enables integration with existing PHP logging infrastructure

4. **Add MCP (Model Context Protocol) integration**
   - Components could expose tools/resources for AI agents
   - TextInput could be an MCP text resource
   - Tree could expose hierarchical tool namespace

5. **Create `sugar-overlay` modal library** (from bubbletea-overlay)
   - Positioning system: Top/Right/Bottom/Left/Center
   - Compositing engine for layered UIs

## Notable PRs / Issues / Discussions

### charmbracelet/bubbles #246 (Per-cell styling for Table)
- **Summary**: Long-requested feature to style individual cells based on content
- **Relevance**: sugar-bits already implemented `styleFunc` as a SugarCraft enhancement
- **Lesson**: This was a legitimate upstream gap that SugarCraft identified and filled proactively

### charmbracelet/bubbles #233 (Tree component)
- **Summary**: Tree was a community-contributed feature (by Genekkion)
- **Relevance**: sugar-bits mirrors this implementation
- **Lesson**: Community contributions can fill ecosystem gaps

### pterm InteractiveSelect fuzzy search (lithammer/fuzzysearch)
- **Summary**: Ranked fuzzy filtering with real-time update as user types
- **Relevance**: Directly comparable to ItemList filtering gap
- **Lesson**: Fuzzy ranking is standard expectation for interactive filtering

### stickers ratio-based layout (calculateRatioWithMinimum)
- **Summary**: Recursive algorithm for distributing space with minimum constraints
- **Relevance**: Core algorithmic contribution; must be faithfully ported
- **Lesson**: Layout algorithms from Go ports need careful translation for PHP

### smenu bitmap tracking
- **Summary**: Each matched word stores bitmap of matched character positions
- **Relevance**: Enables efficient highlighted rendering without re-scanning
- **Lesson**: Match metadata structures enable sophisticated UI without re-computation

### promptkit commonPrefix algorithm
- **Summary**: Sort once, compare first and last elements only
- **Relevance**: O(n log n) sort + O(k) compare vs O(n*k) naive
- **Lesson**: Sorting enables elegant optimization for prefix detection

## Recommended Roadmap

### Immediate Wins (0-2 weeks)

1. **Implement Table half-page navigation**
   - Add `HalfPageUp`/`HalfPageDown` key handlers
   - Low complexity, medium usability impact

2. **Implement Help KeyMap interface introspection**
   - Create `KeyMap` interface with `shortHelp()`/`fullHelp()`
   - Low complexity, medium extensibility impact

3. **Add missing short-form aliases** to Table, Tree, Tabs, Paginator
   - `headers()`, `rows()`, `pageSize()`, `nodes()`, `height()`, `items()`, `active()`
   - Low complexity, high DX impact

4. **Improve TextArea LineInfo tracking** for double-width Unicode
   - Track per-line character widths during soft-wrap
   - Medium complexity, high correctness impact

### Medium-term Improvements (2-8 weeks)

5. **Implement fuzzy filtering with ranking** (ItemList)
   - Port or wrap PHP fuzzy library
   - Implement `FilterResult` with score + indices
   - Medium complexity, high UX impact

6. **Port stickers FlexBox layout** to `sugar-layout`
   - Port ratio distribution algorithms
   - Implement FlexBox→Row→Cell chain
   - High complexity, critical UX impact

7. **Implement half-block color blending** (Progress)
   - Add `withHalfBlock(true)` option
   - Use `\xE2\x96\x8C` with fg/bg color pairing
   - Low complexity, medium visual impact

8. **Add auto-pagination** to ItemList/Table
   - Calculate fitting page size from terminal height
   - Medium complexity, high UX impact

### Major Architectural Upgrades (8+ weeks)

9. **Create `sugar-overlay` modal library**
   - Viewable interface
   - Compositing engine with 5-position placement
   - High complexity, new capability

10. **Create `sugar-sift` for TST-based filtering**
    - Ternary Search Tree implementation
    - Fast prefix/fuzzy search for large word lists
    - Medium complexity, enables autocomplete improvements

11. **Add TextPrinter/LivePrinter interfaces**
    - Consistent API surface across components
    - Shared infrastructure for animated components
    - Medium complexity, long-term maintainability

## Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|---|---|---|---|---|
| Fuzzy filtering with ranking | High | Medium | Low | Critical |
| FlexBox layout component | Critical | High | Medium | Critical |
| Half-page navigation | Medium | Low | Low | Immediate |
| KeyMap interface introspection | Medium | Low | Low | Immediate |
| Short-form aliases | High | Low | Low | Immediate |
| TextArea LineInfo | High | Medium | Low | Medium |
| Half-block color blending | Medium | Low | Low | Medium |
| Auto-pagination | High | Medium | Low | Medium |
| Viewable/overlay system | Medium | High | Medium | Low |
| TST-based prefix search | Medium | Medium | Low | Low |

## Final Strategic Assessment

**sugar-bits** is a well-structured, mature PHP port that successfully provides 15+ TUI components for the SugarCraft ecosystem. The stratified alias pattern (re-exporting from candy-forms while keeping original implementations for complex leaf components) demonstrates sound architectural judgment. The library's SugarCraft-specific enhancements — per-cell `styleFunc`, vim mode for TextInput, ValidateOn timing control, and zone-based mouse for Tabs — show it is not merely a passive port but an active improvement over upstream.

The most significant gaps are **fuzzy filtering** and **FlexBox layout**. Fuzzy filtering using simple substring match puts sugar-bits behind upstream bubbles, pterm, and promptkit. The FlexBox absence means complex dashboard UIs must be built manually, a significant limitation. Both gaps are addressable: fuzzy filtering via a PHP fuzzy library implementation, FlexBox via careful port of the ratio distribution algorithms from stickers.

The library is production-ready for standard TUI component usage. The recommendation is to prioritize the critical items (fuzzy filtering + FlexBox) while immediately addressing low-complexity wins (half-page navigation, short-form aliases, KeyMap introspection). The long-term architectural goal should be establishing sugar-bits as a comprehensive component ecosystem comparable to pterm's breadth, with the TextPrinter/LivePrinter interfaces providing consistent API surface across all components.
