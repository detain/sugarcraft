# Overview

**candy-hermit** is a fuzzy finder / quick-fix overlay library for PHP TUI applications. It renders a filterable list overlay on top of a background view while the background continues to update — a "type-to-filter" pattern used in command palettes (VS Code Ctrl+P style), quick-fix navigation, and context-sensitive item selection.

**Ecosystem positioning:** It occupies a distinct niche in the SugarCraft ecosystem as the only overlay-specific fuzzy filter (versus `sugar-bits` inline components, `candy-lister` inline lists, or `sugar-veil` general-purpose overlay compositor). It is a v1-ready library with solid fundamentals but significant enhancement opportunities.

**Biggest opportunity areas:**
1. UTF-8 grapheme cluster support for match highlighting and text rendering
2. Scored fuzzy ranking (vs. simple anchor-biased substring)
3. ANSI-aware width calculation using existing SugarCraft utilities
4. Optional pagination for large lists (hundreds of items)
5. Keyboard focus management for embedded use

**Biggest missing capabilities:**
1. No UTF-8 awareness — `highlightMatches()` and `replaceSegment()` use byte-level indexing
2. No pagination — shows `windowHeight - 2` items and scrolls via cursor only
3. No fuzzy ranking — items return in original order, not ranked by relevance
4. No direct TTY ioctl for SIGWINCH — relies on stale `COLUMNS`/`LINES` env vars
5. No mouse support — keyboard-only navigation

---

# Internal Capability Summary

## Current Architecture

**Package Structure:**
```
candy-hermit/src/
├── Hermit.php           — Fuzzy finder overlay (main class, 594 lines)
├── Model.php             — tea.Model interface for embedding (31 lines)
├── Item.php              — Item contract: number() + value() (24 lines)
├── FilteredItem.php      — Numbered item implementation (30 lines)
├── HelpBar.php           — Keyboard shortcut summary line (90 lines)
├── StatusBar.php         — Status message line with segments (120 lines)
└── History/
    └── FileHistory.php   — JSONL-backed persistent history (84 lines)
```

**Core State (all readonly):**
- `$allItems` / `$filteredItems` — item arrays
- `$isShown` / `$cursor` / `$filterText` / `$prompt` — overlay state
- `$itemFormatter` / `$filterFn` / `$matchStyle` — customization closures
- `$windowHeight` / `$windowWidth` / `$xOffset` / `$yOffset` — geometry
- `$border` / `$style` / `$helpBar` / `$statusBar` / `$onResize` — decoration/config

**Immutable + Fluent Pattern:** All state mutations (`show()`, `hide()`, `type()`, `backspace()`, `clear()`, `cursorUp()`, `cursorDown()`, `cursorTop()`, `cursorBottom()`) and configuration (`withItems()`, `setPrompt()`, `setMatchStyle()`, `setWindowHeight()`, etc.) return new instances via internal clone pattern.

## Current Features

| Feature | Status |
|---------|--------|
| Overlay compositing (character-level replacement) | ✅ Complete |
| Background view preservation | ✅ Complete |
| Anchor-aware substring filtering | ✅ Complete |
| Match highlighting (ANSI SGR) | ✅ Complete |
| Custom filter predicate (setFilterFn post-filter) | ✅ Complete |
| Item interface (number() + value()) | ✅ Complete |
| Cursor navigation (up/down/top/bottom) | ✅ Complete |
| Configurable window dimensions/offset | ✅ Complete |
| HelpBar / StatusBar | ✅ Complete |
| FileHistory (JSONL persistent) | ✅ Complete |
| SIGWINCH via SignalForwarder | ✅ Complete |
| tea.Model interface for embedding | ✅ Complete |
| Border/Style composition (candy-sprinkles) | ✅ Complete |

## Strengths

1. **Fuzzy filtering delivered** — theHermit's most requested feature (noted as TODO upstream) is implemented
2. **Proper immutable + fluent pattern** — PHP `readonly` properties with clone-based mutation
3. **Composite design** — HelpBar, StatusBar, FileHistory all compose cleanly via interfaces
4. **Background continues updating** — the core Hermit value proposition preserved from upstream
5. **Character-level compositing** — simple and effective for the overlay use case
6. **Well-tested** — 47 test methods covering Hermit state machine, filtering, cursor, immutability, border/style composition
7. **SIGWINCH integration** — proper signal handling via SignalForwarder

## Weaknesses

1. **No UTF-8 grapheme awareness** — `highlightMatches()` uses `strncasecmp` with byte-level indexing; multi-byte characters break match highlighting
2. **No pagination** — shows `windowHeight - 2` items; no offset-based pagination for long lists
3. **No fuzzy ranking** — anchor-biased substring matching doesn't produce quality scores; items returned in original order
4. **No ANSI-aware width** — uses naive `strlen()` for character positioning; upstream uses `lipgloss.Width()` with ANSI/Unicode awareness
5. **SIGWINCH size source** — uses `COLUMNS`/`LINES` env vars rather than direct `/dev/tty` ioctl; may be stale or unset
6. **Single-column layout** — no multi-column or staggered layout options

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|------------------------|----------|
| `charmbracelet/bubbles` (List) | HIGH | `sahilm/fuzzy` scored filtering with character indices, pagination, delegates, auto-generated help | Critical |
| `charmbracelet/lipgloss` | HIGH | `Width()` with ANSI/Unicode grapheme awareness, `StyleRunes()` for styled rune indices | Critical |
| `charmbracelet/x` (ansi, cellbuf) | HIGH | `ansi.Parser` state machine, `cellbuf` cell-based buffer with grapheme support | High |
| `charmbracelet/bubbletea` | MEDIUM | Elm architecture pattern, synchronized output (ANSI 2026), delta rendering | Medium |
| `ratatui/ratatui` (List) | MEDIUM | Cassowary constraint-based layout, stateful List with `ListState`, buffer diffing | Medium |
| `p-gen/smenu` | MEDIUM | Ternary Search Tree (TST) for O(k) prefix search, bitmap tracking for match highlighting | Medium |
| `Genekkion/theHermit` (upstream) | MEDIUM | Overlay pattern, background compositing, lipgloss styling integration | Baseline |
| `rmhubbert/bubbletea-overlay` | LOW | General overlay compositing with z-index, animation, backdrop dimming | Reference |
| `php-tui/php-tui` | LOW | PHP-native TUI patterns, word wrap, index clamping in scrollable widgets | Low |
| `erikgeiser/promptkit` | LOW | Selection patterns, multi-selection, validation UX | Low |
| `daltonsw/bubbleup` | LOW | Overlay positioning patterns (edge-anchored), animation considerations | Low |

---

# Feature Gap Analysis

## Critical

### 1. UTF-8 Grapheme Cluster Support
**Title:** Grapheme-aware match highlighting and text width

**Description:** The current implementation uses `strlen()`, `strpos()`, and character-by-character loops that operate on bytes, not Unicode grapheme clusters. This breaks when users type filter text containing multi-byte characters (emoji, CJK, combining characters).

**Why it matters:** Any user filtering items with non-ASCII text will get incorrect match highlighting or truncated output.

**Source:** `charmbracelet/x` cellbuf package uses `uniseg.FirstGraphemeClusterInString()` for grapheme detection. `charmbracelet/lipgloss` uses `clipperhouse/displaywidth` for width calculation.

**Source file:** `docs/repo_map/charmbracelet_lipgloss.md` (Unicode Grapheme-Aware Width section); `docs/repo_map/charmbracelet_x.md` (Grapheme-Aware Width Calculation section)

**Implementation ideas:**
- Replace `strlen()`/`strpos()` with `grapheme_strlen()`/`grapheme_substr()` in `highlightMatches()` and `replaceSegment()`
- Add `Width()` utility using `grapheme_strlen()` for visual character count
- Use `IntlBreakIterator` for grapheme boundaries where `ext-intl` is available

**Estimated complexity:** Medium — requires reviewing all string operations in `Hermit.php`

**Expected impact:** HIGH — enables filtering CJK, emoji, and other multi-byte content correctly

---

### 2. ANSI-Aware Width Calculation
**Title:** Proper ANSI escape sequence and double-width character handling

**Description:** The `View()` method and overlay compositing use byte-level `strlen()` to determine positions. When ANSI SGR codes or double-width characters (CJK) are present in the background view, character positioning becomes incorrect.

**Why it matters:** Overlaying on styled terminal content (colors, bold, etc.) will misalign the overlay because ANSI escape sequences add bytes but consume zero visible width, while CJK characters consume 2 visible columns.

**Source:** `charmbracelet/lipgloss` `Width()` function handles this correctly via `clipperhouse/displaywidth`. `charmbracelet/x/ansi` provides the underlying ANSI parser.

**Source file:** `docs/repo_map/charmbracelet_lipgloss.md` (Width/Height measurement section)

**Implementation ideas:**
- Add `Width()` static method to `Hermit` or extract to `SugarCraft\Core\Util\Width`
- Use `mb_strwidth()` or `grapheme_strlen()` as fallback
- Consider leveraging existing `SugarCraft\Core\Ansi` utilities if they handle ANSI stripping
- Track visible width separately from byte length during compositing

**Estimated complexity:** Medium — requires replacing position calculations with width-aware versions

**Expected impact:** HIGH — enables correct overlay positioning on styled content

---

## High Value

### 3. Scored Fuzzy Filtering
**Title:** Quality-ranked fuzzy matching with match indices

**Description:** The current anchor-biased substring match returns items in their original array order. Items that match better (e.g., match at position 0 vs position 10) are not ranked higher. The `sahilm/fuzzy` library used by `charmbracelet/bubbles` provides scored matches with character indices.

**Why it matters:** For lists with many items, users expect best matches first. The current behavior makes "fuzzy" feel less useful than it could.

**Source:** `charmbracelet/bubbles` List component uses `sahilm/fuzzy` for filtering.

**Source file:** `docs/repo_map/charmbracelet_bubbles.md` (List component section)

**Implementation ideas:**
- Add optional fuzzy scoring to `applyFilter()` that sorts by match quality
- Implement Smith-Waterman-like local alignment for scoring substring matches
- Add `setFuzzyScoring(bool)` configuration option
- Expose match quality via `itemQuality()` query or sort the filtered array

**Estimated complexity:** High — requires implementing a scoring algorithm or porting `sahilm/fuzzy` to PHP

**Expected impact:** MEDIUM — improves UX significantly for large lists, but current approach works for small lists

---

### 4. Pagination for Large Lists
**Title:** Offset-based pagination for lists with hundreds of items

**Description:** The Hermit shows `windowHeight - 2` items (prompt + separator + items) and scrolls via cursor. For lists with hundreds of items (e.g., file lists, command histories), this means many cursor-down presses to reach the desired item. `charmbracelet/bubbles` List provides pagination.

**Why it matters:** Without pagination, navigating large lists is tedious. Users must press cursor-down many times.

**Source:** `charmbracelet/bubbles` List provides pagination via `Paginator` component.

**Source file:** `docs/repo_map/charmbracelet_bubbles.md` (List component section)

**Implementation ideas:**
- Add `setPageSize(int)` configuration
- Track `$pageOffset` state in addition to `$cursor`
- Add PageUp/PageDown key handling
- Render page indicator in StatusBar
- Allow jumping to page via number keys (1-9)

**Estimated complexity:** Medium — adds new state and key handling

**Expected impact:** MEDIUM — significant UX improvement for large item lists

---

### 5. Keyboard Focus Management
**Title:** Focus tracking for embedded Hermit in larger TUI apps

**Description:** When Hermit is embedded in a larger Bubble-Tea-style application (via the `Model` interface), there's no concept of focus. If the parent app has multiple interactive components, the Hermit doesn't know if it has keyboard focus.

**Why it matters:** In embedded scenarios, the Hermit should only respond to keys when it has focus. Without focus tracking, keys are processed regardless of focus state.

**Source:** `charmbracelet/bubbletea` has focus event handling via `tea.FocusMsg`.

**Source file:** `docs/repo_map/charmbracelet_bubbletea.md` (Focus Events section)

**Implementation ideas:**
- Add `$hasFocus` state to `Hermit`
- Add `focus()` / `blur()` methods
- On `blur()`, stop responding to key events but continue rendering
- Add `withFocusTracking()` factory variant

**Estimated complexity:** Low — adds simple boolean state

**Expected impact:** MEDIUM — enables proper multi-component TUI embedding

---

## Medium Priority

### 6. Custom Filter Modes
**Title:** Support for prefix/fuzzy/substring filter modes

**Description:** The current filter is anchor-biased substring. Some users may want strict prefix matching (`filter must start at position 0`) or true fuzzy matching (non-contiguous character matches).

**Why it matters:** Different use cases benefit from different filter modes. A file picker might want prefix-first, while a general search might want substring.

**Source:** `p-gen/smenu` provides three search modes: prefix, fuzzy, substring.

**Source file:** `docs/repo_map/sugarcraft_candy-hermit.md` (vs. p-gen/smenu section)

**Implementation ideas:**
- Add `enum FilterMode { Substring, Prefix, Fuzzy }` 
- Add `setFilterMode(FilterMode $mode)` configuration
- Implement respective algorithms for each mode

**Estimated complexity:** Medium — three distinct filter algorithms

**Expected impact:** LOW — most users are satisfied with substring

---

### 7. Direct TTY ioctl for SIGWINCH
**Title:** Use `/dev/tty` ioctl for accurate terminal size on SIGWINCH

**Description:** The current SIGWINCH handler reads `COLUMNS`/`LINES` environment variables to determine terminal size. These may be stale or unset in some environments. Using `ioctl(STDIN, TIOCGWINSZ, ...)` provides accurate real-time size.

**Why it matters:** In certain TUI environments (detached screens, certain SSH sessions, Docker without `-t`), the environment variables may not reflect the actual terminal size, causing incorrect overlay positioning.

**Source:** `charmbracelet/x/term` package provides `GetSize(fd)` using `ioctl`.

**Source file:** `docs/repo_map/charmbracelet_x.md` (term Package section)

**Implementation ideas:**
- Enhance `SignalForwarder::attachSigwinchToFd()` to accept a size-fetching callback
- Use `proc_open` with `TIOCGWINSZ` ioctl as fallback when env vars unavailable
- Document the limitation and fallback behavior

**Estimated complexity:** Medium — requires platform-specific code (already exists in `candy-pty`)

**Expected impact:** LOW — works correctly in most environments

---

### 8. Match Highlighting via StyleRunes Pattern
**Title:** Use styled-rune approach for match highlighting

**Description:** The current `highlightMatches()` uses `strncasecmp` for matching and wraps matched characters with `$matchStyle`. A more flexible approach uses index arrays like `lipgloss.StyleRunes(str, matchedIndices, matchedStyle, unmatchedStyle)`.

**Why it matters:** The current approach can't style unmatched portions differently. A two-style approach (matched vs unmatched) provides richer visual feedback.

**Source:** `charmbracelet/lipgloss` `StyleRunes` function.

**Source file:** `docs/repo_map/charmbracelet_lipgloss.md` (StyleRunes section)

**Implementation ideas:**
- Add `setUnmatchedStyle(string $ansiStyle)` configuration
- Modify `highlightMatches()` to return both matched and unmatched styled spans
- Update `itemFormatter` to receive matched/unmatched segments

**Estimated complexity:** Low — modifies existing highlighting logic

**Expected impact:** MEDIUM — improves visual feedback with two-tone highlighting

---

## Low Priority

### 9. Multi-Column Layout
**Title:** Support for multi-column item display

**Description:** Currently all items display in a single column. For wide terminals with many short items, a multi-column layout would show more items simultaneously.

**Why it matters:** Space efficiency — a list of 50 short items (icons, status indicators) wastes horizontal space.

**Source:** `ratatui/ratatui` List does not directly support this, but `charmbracelet/huh` Select uses horizontal layout option.

**Implementation ideas:**
- Add `setColumns(int $n)` configuration
- Calculate column width as `windowWidth / columns`
- Distribute items across columns in `View()`

**Estimated complexity:** High — requires reworking the View rendering logic

**Expected impact:** LOW — uncommon use case

---

### 10. Escape Key Handling
**Title:** First-class Escape key to close/cancel

**Description:** Currently users must check if `filterText === ''` to detect "escape pressed while empty = close" pattern. This logic is in the consuming application, not in Hermit.

**Why it matters:** Standard UX: Escape should close the Hermit overlay. Currently this is boilerplate each consumer must implement.

**Source:** `charmbracelet/bubbles` List handles Escape via keybindings.

**Implementation ideas:**
- Add `withEscapeClose(bool $closeOnEscape = true)` configuration
- Track a `$escapePressed` state
- Add `wasEscapePressed(): bool` query method
- On `hide()`, clear the escape state

**Estimated complexity:** Low — simple boolean and query method

**Expected impact:** MEDIUM — reduces boilerplate for consumers

---

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### Filtering Algorithm

**candy-hermit current:**
```
1. Case-insensitive substring search (strpos)
2. Anchor bias: match position * 2 < strlen(value)
3. Custom filter predicate (ANDed)
4. No ranking/scoring
```

**charmbracelet/bubbles List (sahilm/fuzzy):**
```
1. Finds all possible matches in the string
2. Assigns a score based on:
   - Consecutive character matches (higher score)
   - Match at word boundary (higher score)
   - Match at start of string (higher score)
   - Matched character ratio
3. Returns matches sorted by score descending
4. Provides character indices for highlighting
```

**p-gen/smenu (TST approach):**
```
1. Builds Ternary Search Tree from all items
2. O(k) search where k = key length (not string length)
3. Bitmap tracking for match highlighting
4. Three modes: prefix, fuzzy, substring
```

**Why external is better:** Scored matching produces ranked results that surface the most relevant items first. TST achieves O(k) search vs O(n*m) for brute force. Character indices enable precise highlighting without iterating the entire string.

**Tradeoffs for candy-hermit:** The current approach is simpler and works well for small to medium lists (hundreds of items). Implementing full fuzzy scoring adds complexity. The anchor-bias approach does bias toward earlier matches, but doesn't rank within "early matches" by quality.

**Applicability:** HIGH — implementing even a simple scoring (match position + consecutive bonus) would significantly improve UX with moderate implementation effort.

### Width Calculation

**candy-hermit current:**
```php
// Character-by-character loop with strlen()
for ($i = 0; $i < $len; $i++) {
    if ($x <= $i && $i < $x + $width) { ... }
}
```

**charmbracelet/lipgloss:**
```go
// Uses clipperhouse/displaywidth for Unicode-aware width
func Width(str string) int {
    return displaywidth.String(str)
}
```

**Why external is better:** The Go library correctly handles:
- ANSI escape sequences (zero width)
- Double-width characters (CJK, emoji)
- Combining characters (zero width when combined)
- Grapheme clusters (single visual unit)

**Tradeoffs for candy-hermit:** Full grapheme cluster support requires either `ext-intl` or careful implementation. The current byte-level approach works for ASCII but breaks for Unicode.

**Applicability:** HIGH — critical for correct rendering on styled/non-ASCII content.

---

# Architecture Improvements

## 1. Extract Width Utility

Create `SugarCraft\Core\Util\Width` class with static methods:
- `strwidth(string $s): int` — visual width accounting for ANSI and Unicode
- `substring(string $s, int $start, int $length): string` — grapheme-aware substr
- `ansiStrip(string $s): string` — remove ANSI codes for width calculation

This could be used by `candy-hermit`, `sugar-bits`, `sugar-prompt`, and other rendering components.

## 2. Extract Grapheme Aware String Utils

Create a string utility that wraps `grapheme_strlen`/`grapheme_substr` with fallback:
```php
class GraphemeUtil {
    public static function length(string $s): int;
    public static function substr(string $s, int $start, ?int $length = null): string;
    public static function strpos(string $haystack, string $needle, int $offset = 0): int|false;
}
```

## 3. Plugin for Scoring Algorithms

Define a `FilterScorer` interface:
```php
interface FilterScorer {
    public function score(string $filter, string $candidate): float;
    public function getMatchIndices(string $filter, string $candidate): array;
}
```

Then `Hermit` can accept different scorers via `setScorer(FilterScorer $scorer)`.

---

# API / Developer Experience Improvements

## 1. Static Factory with All Options

Currently the main factory is `Hermit::new(array $items, ?Closure $itemFormatter)` and all configuration is done via fluent setters. Add a static factory with options:

```php
public static function overlay(array $items, array $options = []): self
{
    $h = self::new($items, $options['itemFormatter'] ?? null);
    
    $configMethods = [
        'prompt' => 'setPrompt',
        'matchStyle' => 'setMatchStyle',
        'windowHeight' => 'setWindowHeight',
        'windowWidth' => 'setWindowWidth',
        'filterFn' => 'setFilterFn',
        // ...
    ];
    
    foreach ($configMethods as $key => $method) {
        if (isset($options[$key])) {
            $h = $h->$method($options[$key]);
        }
    }
    
    return $h;
}
```

## 2. Result Object for Selection

Currently `selected(): ?Item` returns the selected item. Add `SelectionResult` object:

```php
final readonly class SelectionResult {
    public function __construct(
        public ?Item $item,
        public bool $escaped,      // user pressed Escape
        public bool $cancelled,     // user pressed Ctrl+C
        public string $filterText, // final filter text
    ) {}
}
```

This makes it clear what happened to cause the selection, enabling better UX handling.

## 3. Event Hooks

Add lifecycle hooks for extensibility:

```php
public function onSelect(callable $callback): self;   // called when item selected
public function onClose(callable $callback): self;    // called when Hermit hides
public function onFilterChange(callable $callback): self;  // called when filter changes
```

---

# Documentation / Cookbook Opportunities

## 1. VS Code-Style Command Palette Tutorial

Create a cookbook example showing how to build a VS Code-style command palette with:
- Fuzzy file search
- Recent files from FileHistory
- Keyboard shortcut display in HelpBar
- Entry selection triggers action

## 2. Multi-Step Selection Flow

Document the pattern for multi-step selection:
1. Show Hermit with filtered items
2. User selects item
3. Hermit hides
4. Background view updates based on selection
5. Later, user can reopen Hermit with recent items shown

## 3. Embedding in Bubble-Tea-Style App

Document the `Model` interface pattern for embedding Hermit in larger apps, including:
- Message routing
- Focus management
- View composition

---

# UX / TUI Improvements

## 1. Visual Selection Indicator

Add visual options for the selected item:
- Reverse video (swap foreground/background colors)
- Underline
- Bold text
- Custom style via `setSelectedStyle()`

## 2. Empty State Rendering

When filter produces zero results, show a styled "No matches" message instead of blank:
```php
public function withEmptyMessage(string $message, ?string $ansiStyle = null): self;
```

## 3. Loading State

Add loading state for async filtering (future async support):
```php
public function withLoadingIndicator(string $spinner = '⠋'): self;
```

## 4. Preview Pane

Add optional second pane showing details of the currently-selected item:
```php
public function withPreviewPane(callable $renderer): self;
```

---

# Testing / Reliability Improvements

## 1. Add Unicode/Grapheme Test Cases

Expand test coverage with:
- Emoji in item values (`['🍎 Apple', '🍌 Banana']`)
- CJK characters in items and filter
- Combining characters (`café` vs `cafe\u0301`)
- Zero-width characters
- Mixed ASCII + multi-byte in same item

## 2. Add Property-Based Tests

Use `phpunit/phpunit` quickcheck-style testing to verify:
- `cursorUp() . cursorDown()` returns to same position
- `type() . backspace()` returns to previous filter state
- Filter always returns subset of all items

## 3. Snapshot Tests for View Output

Add golden file tests for `View()` output at various states:
- Default overlay with 5 items
- Overlay with match highlighting active
- Overlay with empty filter results
- Overlay with custom border/style

---

# Ecosystem / Integration Opportunities

## 1. Integration with `sugar-lister`

`sugar-lister` is an inline list with fuzzy filtering. Hermit could wrap a `lister` instance for rendering rather than implementing its own item rendering:
```php
public function withListRenderer(ListRenderer $renderer): self;
```

## 2. Integration with `candy-zone`

Add mouse support via `candy-zone` for clickable item selection:
```php
public function withMouseSelection(): self;
```

When enabled, clicking on an item selects it and clicking outside closes the Hermit.

## 3. VS Code Extension Integration

Create `sugarcraft/vscode-hermit` extension that:
- Registers `package.json` contributes for commands
- Uses Hermit for fuzzy command search
- Persists command history

## 4. Alfred/Launchy Integration

Document using Hermit as the search mechanism for desktop launcher integration.

---

# Notable PRs / Issues / Discussions

## charmbracelet/bubbles List — Fuzzy Filtering Quality

**Summary:** The `sahilm/fuzzy` library provides ranked fuzzy matching with character indices. The current candy-hermit approach (anchor-biased substring) is a significant downgrade in filtering quality.

**Relevance:** Implementing scored fuzzy matching in candy-hermit would close this gap. The algorithm is well-documented in `sahilm/fuzzy` source and produces both a score and match indices.

**Lessons learned:** The bubbles List demonstrates that even "good enough" substring matching is perceived as inferior to scored matching. Users expect best matches first.

**Potential adaptations:** Port `sahilm/fuzzy` to PHP or implement equivalent Smith-Waterman-style scoring.

---

## charmbracelet/x — ANSI Parser for Width Calculation

**Summary:** The `ansi` package provides ECMA-48 compliant ANSI parsing and width calculation. The `Width()` function in `charmbracelet/lipgloss` uses `clipperhouse/displaywidth` to correctly measure visual width.

**Relevance:** candy-hermit's `replaceSegment()` and `highlightMatches()` use byte-level operations that break with ANSI codes and multi-byte characters. The existing SugarCraft `Ansi` utilities may already handle this.

**Lessons learned:** ANSI-aware width calculation is foundational — it affects every text rendering operation. Getting it right enables correct compositing, truncation, and alignment.

**Potential adaptations:** Review existing `SugarCraft\Core\Ansi` for width/Unicode handling; add to `candy-core` if not present.

---

## php-tui/php-tui — Index Clamping and Multi-byte Bugs

**Summary:** php-tui issues #213 (table crash when selecting < 1st row) and #230 (`IntlChar` class not found) demonstrate common TUI bugs: index clamping failures and Unicode handling gaps.

**Relevance:** candy-hermit's `cursorUp()` and `cursorDown()` clamp to `[0, count - 1]` which is correct, but similar index boundary bugs in other components (e.g., pagination) could occur.

**Lessons learned:** All scrollable/list widgets must clamp indices explicitly. Multi-byte character handling requires `ext-intl` or careful fallback implementation.

**Potential adaptations:** Add explicit clamping tests to HermitTest.php. Document `ext-intl` requirement or provide fallback.

---

## erikgeiser/promptkit — Empty State Handling

**Summary:** Issue #27: `tea.Quit` returned from `Init()` for empty choices caused hard-to-debug hangs. The pattern of using early termination for empty states is an anti-pattern in composed models.

**Relevance:** candy-hermit's `show()` resets cursor and filter when shown. If `allItems` is empty, this is handled correctly (empty filtered list). But similar empty-state edge cases in the `Model` interface pattern could cause issues.

**Lessons learned:** Never use program termination for empty states. Use explicit state flags and let parent models decide how to handle empty/error states.

**Potential adaptations:** Ensure Hermit's `Model` interface implementations never terminate the program — always return state.

---

# Recommended Roadmap

## Immediate Wins (1-2 days each)

1. **UTF-8 grapheme support in highlightMatches()**
   - Replace `strncasecmp`/`strpos` with `grapheme_stripos()` 
   - Add test cases for emoji/CJK in items and filter

2. **Add Escape close state**
   - Add `wasEscapePressed()` query method
   - Document "Escape while empty = close" pattern

3. **Empty message configuration**
   - Add `withEmptyMessage(string $msg, ?string $style)`
   - Render message when filter produces zero items

## Medium-Term Improvements (1-2 weeks each)

4. **ANSI-aware width utility**
   - Extract `Width` utility to `candy-core` or `candy-sprinkles`
   - Use in `replaceSegment()` for correct positioning

5. **Pagination for large lists**
   - Add page state and PageUp/PageDown handling
   - Add page indicator to StatusBar

6. **Scored fuzzy filtering**
   - Implement basic scoring (consecutive match bonus + position bonus)
   - Sort filtered results by score
   - Add `setFuzzyScoring(bool)` configuration

7. **Keyboard focus tracking**
   - Add `focus()`/`blur()` methods
   - Add `hasFocus()` query
   - Only respond to keys when focused

## Major Architectural Upgrades (ongoing)

8. **FilterScorer plugin interface**
   - Extract scoring into `FilterScorer` interface
   - Default to current anchor-bias approach
   - Allow custom scorers (TST, fuzzy library)

9. **Async/streaming filter**
   - For large lists, allow filter to run in background
   - Show "filtering..." indicator while processing
   - Support cancellation

10. **Mouse selection support**
    - Integration with `candy-zone` for click handling
    - Track item regions for hit testing

## Experimental Ideas

- Multi-column layout for wide terminals
- Preview pane for selected item details
- vim-mode navigation (j/k for up/down, / for filter)
- Custom filter modes (prefix, fuzzy, substring)

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|-----------|------|---------------------|
| UTF-8 grapheme support | HIGH | Medium | LOW | **P0 — Critical** |
| ANSI-aware width | HIGH | Medium | LOW | **P0 — Critical** |
| Scored fuzzy filtering | MEDIUM | High | MEDIUM | **P1 — High** |
| Escape close state | MEDIUM | Low | LOW | **P1 — High** |
| Pagination | MEDIUM | Medium | LOW | **P1 — High** |
| Empty message | MEDIUM | Low | LOW | **P2 — Medium** |
| FilterScorer interface | MEDIUM | Medium | LOW | **P2 — Medium** |
| Keyboard focus | MEDIUM | Low | LOW | **P2 — Medium** |
| Visual selection style | MEDIUM | Low | LOW | **P2 — Medium** |
| Mouse selection | LOW | High | MEDIUM | **P3 — Low** |
| Multi-column layout | LOW | High | LOW | **P3 — Low** |
| Preview pane | LOW | Medium | LOW | **P3 — Low** |
| Direct TTY ioctl | LOW | Medium | MEDIUM | **P3 — Low** |
| Custom filter modes | LOW | Medium | LOW | **P4 — Nice to have** |

---

# Final Strategic Assessment

candy-hermit is a well-architected PHP port that enhances its upstream (`Genekkion/theHermit`) significantly — adding fuzzy filtering, match highlighting, HelpBar, StatusBar, FileHistory, and proper immutable+fluent patterns. It fills a distinct niche as the SugarCraft ecosystem's overlay-style fuzzy finder.

**Core strengths:** The overlay compositing pattern is sound. The character-level replacement approach is simple and effective for the use case. The immutable+fluent pattern follows SugarCraft conventions. The test coverage is comprehensive.

**Critical gaps:** UTF-8 grapheme awareness and ANSI-aware width are the two most urgent gaps. Without these, candy-hermit will break or misbehave when filtering non-ASCII content or compositing over styled terminal output. Both are well-understood problems with established solutions in the Go ecosystem.

**Competitive landscape:** Compared to `charmbracelet/bubbles` List, candy-hermit offers a unique overlay pattern (vs. in-place rendering) but lags in filtering quality and lacks pagination. Compared to `p-gen/smenu`, candy-hermit uses a simpler algorithm but lacks TST performance for very large lists.

**Recommended focus:** Address the two critical gaps (UTF-8 and ANSI width) first, then improve filtering quality with scored ranking. These changes maintain backward compatibility while significantly improving robustness and UX.

**Long-term positioning:** candy-hermit could become the PHP standard for "type-to-filter overlay" if it closes the Unicode/ANSI gaps and adds pagination. The overlay pattern is distinct from inline list components, making it complementary rather than competitive with `sugar-bits` or `candy-lister`.
