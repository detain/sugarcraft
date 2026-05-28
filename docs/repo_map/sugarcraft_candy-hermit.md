# sugarcraft/candy-hermit — Innovation & Comparison Report

## Metadata
- **SugarCraft Library:** `candy-hermit`
- **Porting Source:** `Genekkion/theHermit` (primary, 15 stars), with patterns from `rmhubbert/bubbletea-overlay` and `p-gen/smenu`
- **Status:** v1 Ready
- **PHP:** ^8.3
- **Namespace:** `SugarCraft\Hermit`
- **Composer:** `sugarcraft/candy-hermit`
- **Depends on:** `sugarcraft/candy-core` (Ansi, SignalForwarder), `sugarcraft/candy-sprinkles` (Border, Style, Align, VAlign), `sugarcraft/candy-pty` (SignalForwarder)
- **Test suite:** 4 test files (HermitTest, FilteredItemTest, ItemInterfaceTest, History/FileHistoryTest), ~47 test methods

---

## Feature Matrix

| Feature | theHermit (upstream) | candy-hermit (PHP port) | Enhancement? |
|---------|---------------------|------------------------|---------------|
| Overlay compositing | ✅ (centered fixed dims) | ✅ (configurable x/y offset) | Yes |
| Background view preservation | ✅ | ✅ | Parity |
| Fuzzy filtering | ❌ (noted as TODO) | ✅ (anchor-aware substring) | **New — major** |
| Item interface (number/value) | ❌ (Title() only) | ✅ (number() + value()) | **New** |
| Cursor navigation | ✅ (up/down only) | ✅ (up/down/top/bottom) | Yes |
| Window dimensions | Fixed 81x14 | Configurable (H/W/offset) | Yes |
| Border composition | lipgloss styling | candy-sprinkles Border | Adapted |
| HelpBar / StatusBar | ❌ | ✅ | **New** |
| FileHistory (persistent) | ❌ | ✅ (JSONL) | **New** |
| SIGWINCH resize handling | ✅ (auto-centering) | ✅ (callback + SignalForwarder) | Adapted |
| Match highlighting | ❌ | ✅ (ANSI SGR via setMatchStyle) | **New** |
| Custom filter predicate | ❌ | ✅ (setFilterFn post-filter) | **New** |
| tea.Model interface | ✅ | ✅ (Model interface for embedding) | Adapted |
| Immutable + fluent pattern | ❌ (Go struct mutation) | ✅ (readonly props + clone) | Adapted |

---

## Architecture

### Package Structure

```
candy-hermit/src/
├── Hermit.php           — Fuzzy finder overlay (main class, 594 lines)
├── Model.php             — tea.Model interface for embedding (31 lines)
├── Item.php              — Item contract: number() + value() (24 lines)
├── FilteredItem.php      — Numbered item implementation (30 lines)
├── HelpBar.php           — Keyboard shortcut summary line (90 lines)
├── StatusBar.php         — Status message line with segments (120 lines)
└── History/
    └── FileHistory.php  — JSONL-backed persistent history (84 lines)
```

### Core Class: `Hermit` (`src/Hermit.php`)

The `Hermit` class is the fuzzy finder overlay component. It is a `final` class with all state held in `readonly` private properties, following the immutable + fluent SugarCraft pattern.

```
SugarCraft\Hermit\Hermit
├── readonly array  $allItems          — Full item list
├── readonly array  $filteredItems      — Currently filtered subset
├── readonly bool  $isShown             — Visibility toggle
├── readonly int   $cursor              — 0-based cursor index
├── readonly string $filterText         — Current filter string
├── readonly string $prompt            — Filter prompt prefix
├── readonly \Closure $itemFormatter    — (item, isSelected): string
├── readonly \Closure $filterFn         — (Item): bool post-filter predicate
├── readonly string $matchStyle         — ANSI SGR codes for highlighting
├── readonly int    $windowHeight        — Overlay height (# visible items)
├── readonly int    $windowWidth         — Overlay width (0 = auto)
├── readonly int    $xOffset             — Horizontal position
├── readonly int    $yOffset            — Vertical position
├── readonly ?Border $border            — candy-sprinkles Border
├── readonly ?Style $style              — candy-sprinkles Style
├── readonly ?HelpBar $helpBar          — Attached keyboard help
├── readonly ?StatusBar $statusBar      — Attached status line
└── readonly ?\Closure $onResize        — SIGWINCH callback (cols, rows)
```

**Factory:** `Hermit::new(array $items, ?Closure $itemFormatter)` — zero-arg/default root instance, bare-named factory per SugarCraft convention.

**Configuration chain (all return new instance via internal `clone` pattern):**

State mutations — all return **new instance** (immutable + fluent):
- `show()` / `hide()` — visibility toggle, resets cursor/filter on show
- `type(string $char)` — append character, re-filter, reset cursor to 0
- `backspace()` — remove last char, re-filter, clamp cursor
- `clear()` — reset filter text, restore all items
- `cursorUp(int $n)` / `cursorDown(int $n)` — relative cursor movement with clamping
- `cursorTop()` / `cursorBottom()` — absolute cursor positioning

Configuration — fluent setters returning new instance:
- `withItems(array $items)` — replace items, reset filter/cursor
- `setPrompt(string $prompt)` — set filter prompt
- `setMatchStyle(string $ansiStyle)` — set ANSI SGR for match highlighting
- `setWindowHeight(int $h)` / `setWindowWidth(int $w)` — set dimensions
- `setOffset(int $x, int $y)` — set position (also calls `show()`)
- `setItemFormatter(Closure $fn)` — custom item formatting
- `setFilterFn(Closure $fn)` — custom post-filter predicate
- `withBorder(?Border $border)` / `withStyle(?Style $style)` — compose candy-sprinkles
- `withHelpBar(?HelpBar $helpBar)` / `withStatusBar(?StatusBar $statusBar)` — attach bars
- `withOnResize(?Closure $callback)` — register SIGWINCH resize callback

**Rendering:** `View(string $backgroundView): string` — composites overlay onto background.

---

## Overlay Architecture

### Overlay Rendering (`Hermit::View()` at lines 419–465)

The `View()` method performs character-level overlay compositing:

1. **Guard clause** — if `!isShown`, return background unchanged
2. **Build header line** — `prompt + filterText`, padded to window width
3. **Separator line** — `str_repeat('─', $winWidth)` horizontal rule
4. **Item lines** — apply `itemFormatter` with cursor state, optional match highlighting
5. **Pad remaining lines** — fill to `windowHeight` with spaces
6. **Composite over background** — `compositeOver()` replaces chars at (xOffset, yOffset)

### Composite Algorithm (`Hermit::compositeOver()` at lines 546–566)

```
Input: array of overlay lines, background string
Output: composited string

1. Split background on "\n" into bgLines[]
2. For each overlay line at lineIdx:
   a. destY = yOffset + lineIdx
   b. Skip if destY out of bounds
   c. Replace segment of bgLines[destY] at xOffset..xOffset+width with overlay line
3. Rejoin with "\n"
```

### Segment Replacement (`Hermit::replaceSegment()` at lines 568–593)

Character-by-character replacement at specified x position:

```
Input: line (string), x (int), width (int), replacement (string)
Output: modified line

For i in [0, len(line)):
  if x <= i < x+width:
    repIdx = i - x
    if repIdx < len(replacement): result += replacement[repIdx]
    else: result += ' ' (pad with space)
  else:
    result += line[i] (preserve background)
Pad if overlay extends beyond background line
```

**Key insight:** This is a **character-level** replacement, not a line-level overlay like bubbletea-overlay or sugar-veil. It literally replaces individual characters in background lines at specific positions, which is exactly how theHermit upstream works.

### Match Highlighting (`Hermit::highlightMatches()` at lines 516–544)

```
Input: text (string), filter (string)
Output: text with ANSI SGR highlighting on matched substrings

Uses strncasecmp for case-insensitive prefix matching at each position.
When match found at text[i], wraps matched chars with $matchStyle + Ansi::reset().
Uses plain strlen/strlen indexing (not UTF-8 grapheme-aware — only ASCII filters supported currently).
```

---

## Fuzzy Matching Implementation

### Filter Algorithm (`Hermit::applyFilter()` at lines 479–502)

The `applyFilter()` method implements **anchor-aware substring matching**:

```php
private function applyFilter(string $text): array
{
    $fn = $this->filterFn;
    if ($text === '') {
        // Empty filter: return all items passing custom predicate
        return array_values(array_filter($this->allItems, fn(Item $item): bool => $fn($item)));
    }
    $lower = strtolower($text);
    return array_values(array_filter(
        $this->allItems,
        function (Item $item) use ($lower, $fn): bool {
            $value = $item->value();
            $pos = strpos(strtolower($value), $lower);
            $anchorOk = $pos !== false && $pos * 2 < strlen($value);  // anchor bias
            return $anchorOk && $fn($item);
        }
    ));
}
```

**Anchor bias formula:** `$pos * 2 < strlen($value)` — the match position must be in the first half of the string. This biases toward matching near the start of the string, similar to "fuzzy" behavior without full scoring.

**Two-stage filtering:**
1. **Stage 1:** Case-insensitive substring match with anchor bias
2. **Stage 2:** Custom `$filterFn` predicate (e.g., category filter, visibility flag)

This allows combining user typing with programmatic filtering — a key design decision noted in CALIBER_LEARNINGS.

### Comparison: theHermit vs candy-hermit Filtering

| Aspect | theHermit | candy-hermit |
|--------|-----------|--------------|
| Filtering | ❌ None (noted as TODO) | ✅ Anchor-aware substring |
| Match highlight | ❌ | ✅ Via `setMatchStyle` + `highlightMatches()` |
| Post-filter predicate | ❌ | ✅ `setFilterFn()` |
| Filter-as-you-type | ❌ | ✅ `type()` / `backspace()` / `clear()` |

---

## Item Interface Design

### Item Contract (`src/Item.php`)

```php
interface Item {
    public function number(): int;   // 1-based ordinal for display
    public function value(): string; // Display text
}
```

### FilteredItem (`src/FilteredItem.php`)

```php
final readonly class FilteredItem implements Item {
    public function __construct(
        private int $number,
        private string $value,
    ) {}

    public function number(): int { return $this->number; }
    public function value(): string { return $this->value; }
}
```

**Design rationale:** The upstream `Item` interface only requires `Title()`. candy-hermit extends this to `number() + value()`, which enables numbered lists with arbitrary ordinals (not just 1-based sequential). Custom implementations can encode database IDs, file paths, or any structured key alongside display text.

---

## HelpBar and StatusBar

### HelpBar (`src/HelpBar.php`)

Renders keyboard shortcut summary as `"key: description │ key: description │ ..."`.

```php
final class HelpBar {
    private array $shortcuts = [];  // key -> description
    private bool $visible = true;

    // Fluent: withShortcut(), withoutShortcut(), show(), hide()
    public function render(): string  // "↑↓: navigate │ Enter: select"
}
```

### StatusBar (`src/StatusBar.php`)

Renders status message with optional named segments as `"[segment: value] message"`.

```php
final class StatusBar {
    private string $message = '';
    private bool $visible = true;
    private array $segments = [];  // name -> value

    // Fluent: withMessage(), withNoMessage(), withSegment(), withoutSegment()
    public function render(): string  // "[count: 7] Searching..."
}
```

Neither of these exists in the upstream theHermit — they are novel additions enabling richer UX.

---

## FileHistory (`src/History/FileHistory.php`)

```php
final class FileHistory {
    public function __construct(private string $path) {}

    public function append(Item $item): void
        // JSON encode {'n': number, 'v': value}, append with LOCK_EX

    public function all(): array  // list<Item> — reads all lines, returns FilteredItem[]
    public function clear(): void // unlink the file
    public function path(): string
}
```

**Format:** One JSON-encoded line per item: `{"n":1,"v":"apple"}\n`

**Design notes:**
- Uses `FILE_APPEND | LOCK_EX` for safe concurrent writes
- Line-based `fgets()` loop avoids loading entire file into memory
- Returns `FilteredItem` instances (not generic `Item` — concrete implementation)

---

## BubbleTea Model Integration

### Model Interface (`src/Model.php`)

```php
interface Model {
    public function update(Hermit $hermit, string $msg): Model;
    public function view(Hermit $hermit): string;
}
```

This allows Hermit to be embedded in a larger Bubble-Tea-style application:

```php
class MyModel implements Model {
    public function update(Hermit $hermit, string $msg): Model {
        return match($msg) {
            'up'    => $this->hermit->cursorUp(),
            'down'  => $this->hermit->cursorDown(),
            'enter' => $this->hermit->selected(),
            default => $this->hermit,
        };
    }
    public function view(Hermit $hermit): string { /* ... */ }
}
```

---

## SIGWINCH Resize Handling

### SignalForwarder Integration (`Hermit::attachSigwinch()` at lines 236–256)

```php
public function attachSigwinch(): bool
{
    if ($this->onResize === null) {
        return false;
    }

    $hermit = $this;
    return SignalForwarder::attachSigwinchToFd(
        \STDIN,
        static fn(): array => [
            'cols' => (int) (\getenv('COLUMNS') ?: 80),
            'rows' => (int) (\getenv('LINES') ?: 24),
        ],
        static function (int $cols, int $rows) use ($hermit): void {
            $cb = $hermit->onResize;
            if ($cb !== null) {
                $cb($cols, $rows);
            }
        },
    );
}
```

**Pattern:** The closure captures `$hermit` by value (`use ($hermit)`) to prevent the signal handler from holding a reference that outlives the request. The size tuple uses environment variables as a fallback when direct TTY ioctl is unavailable.

---

## Comparison Against Upstream Repos

### vs. Genekkion/theHermit

| Aspect | theHermit | candy-hermit |
|--------|-----------|-------------|
| **Architecture** | Go struct with mutation | PHP `final readonly` immutable |
| **Fuzzy filtering** | ❌ Not implemented | ✅ Anchor-aware substring match |
| **Item interface** | `Title()` only | `number()` + `value()` |
| **Cursor movement** | up/down only, with offset/pagination | up/down/top/bottom with clamping |
| **Window sizing** | Fixed 81x14 | Fully configurable H/W/offset |
| **SIGWINCH** | Auto-centering on resize | Callback + SignalForwarder |
| **Border/Style** | lipgloss Style | candy-sprinkles Border/Style |
| **HelpBar/StatusBar** | ❌ | ✅ |
| **Persistent history** | ❌ | ✅ FileHistory (JSONL) |
| **Match highlighting** | ❌ | ✅ ANSI SGR via setMatchStyle |
| **Custom filter predicate** | ❌ | ✅ setFilterFn post-filter |

**Major enhancement:** The fuzzy filtering is the most significant addition. theHermit explicitly notes fuzzy filtering as "Coming Soon" — candy-hermit delivers this from the start.

### vs. rmhubbert/bubbletea-overlay

bubble-overlay provides **general-purpose overlay compositing** with 5 position modes (Top/Right/Bottom/Left/Center) and ANSI-aware string operations. It is a pure compositor that merges foreground/background views.

candy-hermit's overlay is **list-specific** (filterable items) and handles **character-level replacement** rather than line-level merging. The approaches are complementary:
- `sugar-veil` (based on bubbletea-overlay): General-purpose overlay compositor with animation, z-index stacking, backdrop dimming
- `candy-hermit`: List-specific fuzzy filter overlay with character-level compositing

**Key difference:** bubbletea-overlay uses `lipgloss.Width()` for dimension measurement and `charmbracelet/x/ansi` for ANSI-aware truncation. candy-hermit uses `strlen()` for character-level positioning (simpler but less Unicode-correct).

### vs. p-gen/smenu

smenu uses a **Ternary Search Tree (TST)** for O(k) prefix search where k = key length. It provides three search modes (prefix, fuzzy, substring) with bitmap tracking for match highlighting. smenu is a C application (~17,500 lines monolithic), not a library.

candy-hermit's fuzzy filtering uses a **simpler anchor-bias substring algorithm** that is O(n*m) on the candidate list but works well for typical TUI item lists (hundreds to low thousands of items). For larger lists, the TST approach in smenu would be superior — this is a known gap noted in CALIBER_LEARNINGS pattern `[pattern:setfilterfn-post-fuzzy]`.

### vs. charmbracelet/bubbles List

The Go bubbles List component provides sophisticated fuzzy filtering via `sahilm/fuzzy` which returns scored matches with character indices. It also provides pagination, delegates, and full BubbleTea integration.

candy-hermit is a **lighter-weight overlay** (not a full List replacement). It focuses specifically on the quick-fix overlay pattern — compositing over a background view rather than rendering in-place. The filtering algorithm is simpler but functionally adequate for typical use cases.

---

## Strengths

1. **Fuzzy filtering delivered** — theHermit's most requested feature (noted as TODO) is implemented in candy-hermit
2. **Immutable + fluent** — proper PHP `readonly` properties with clone-based mutation, unlike the mutable Go struct
3. **Composite pattern** — HelpBar, StatusBar, FileHistory all compose cleanly via interfaces
4. **Background continues updating** — the core Hermit value proposition preserved from upstream
5. **Character-level compositing** — simple and effective for the overlay use case
6. **Well-tested** — 47 test methods covering Hermit state machine, filtering, cursor, immutability, border/style composition
7. **SIGWINCH integration** — proper signal handling via SignalForwarder

---

## Weaknesses

1. **No UTF-8 grapheme awareness** — `highlightMatches()` and `replaceSegment()` use byte-level indexing (`strlen`, `strpos`, character-by-character loops). Multi-byte character sequences will break match highlighting. Upstream theHermit uses `lipgloss.Width()` for ANSI/Unicode-aware width calculation.
2. **No pagination** — theHermit uses offset-based pagination for long lists; candy-hermit simply shows `windowHeight - 2` items and scrolls via cursor. For lists with hundreds of items, this could be limiting.
3. **No fuzzy ranking** — anchor-biased substring matching is simple but doesn't produce quality scores like Smith-Waterman or sahilm/fuzzy. Items are returned in original order rather than ranked by relevance.
4. **No ANSI-aware width** — uses naive `strlen()` for width calculation. The upstream uses `lipgloss.Width()` which correctly handles ANSI escape sequences and double-width characters.
5. **SIGWINCH size source** — uses `COLUMNS`/`LINES` environment variables rather than direct `/dev/tty` ioctl. May be stale or unset in some environments.

---

## Strategic Position

candy-hermit fills a **specific niche** in the SugarCraft ecosystem: the quick-fix / type-to-filter overlay that composes over a background view without blocking it. This is distinct from:

- `sugar-bits` / `candy-forms` — inline component rendering
- `sugar-veil` — general-purpose overlay compositor
- `candy-lister` — inline list with fuzzy filtering

The overlay pattern is useful for:
- Command palette / fuzzy file search (like VS Code Ctrl+P)
- Quick-fix navigation in editors
- Context-sensitive item selection over live content

**Recommended enhancements (post v1):**
1. UTF-8 grapheme cluster support in `highlightMatches()` and `replaceSegment()` (use `grapheme_strlen`/`grapheme_substr`)
2. ANSI-aware width calculation (leverage `SugarCraft\Core\Util\Width` or `Ansi` utilities)
3. Optional pagination for large lists
4. Quality-ranked fuzzy filtering (consider porting smith-waterman from `candy-lister`)
5. Direct TTY ioctl for SIGWINCH size (via candy-pty SignalForwarder enhancements)

---

## Files Reference

| File | Lines | Purpose |
|------|-------|---------|
| `src/Hermit.php` | 594 | Main overlay component |
| `src/Model.php` | 31 | tea.Model interface |
| `src/Item.php` | 24 | Item contract |
| `src/FilteredItem.php` | 30 | Numbered item implementation |
| `src/HelpBar.php` | 90 | Keyboard shortcut summary |
| `src/StatusBar.php` | 120 | Status message line |
| `src/History/FileHistory.php` | 84 | JSONL persistent history |
| `tests/HermitTest.php` | 280 | Core component tests |
| `tests/FilteredItemTest.php` | 56 | FilteredItem tests |
| `tests/ItemInterfaceTest.php` | 41 | Item interface contract tests |
| `tests/History/FileHistoryTest.php` | 93 | FileHistory persistence tests |
| `examples/basic.php` | 48 | Basic overlay demo |
| `examples/interaction.php` | 83 | Full interaction demo |
| `CALIBER_LEARNINGS.md` | 13 | Accumulated patterns |

---

## Upstream Sources

- **Primary:** [Genekkion/theHermit](https://github.com/Genekkion/theHermit) — Go quick-fix list overlay (MIT, ~15 stars)
- **Secondary:** [rmhubbert/bubbletea-overlay](https://github.com/rmhubbert/bubbletea-overlay) — modal overlay compositor patterns
- **Secondary:** [p-gen/smenu](https://github.com/p-gen/smenu) — TST-based filtering reference
- **Reference:** [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) — List component with fuzzy filtering
