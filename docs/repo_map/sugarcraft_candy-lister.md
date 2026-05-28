# SugarCraft/candy-lister

## Metadata
- **SugarCraft Library**: candy-lister
- **Namespace**: `SugarCraft\Lister`
- **Composer pkg**: `sugarcraft/candy-lister`
- **Upstream**: treilik/bubblelister (Go, 52 stars, MIT)
- **Status**: 🟢 v1 ready
- **PHP**: ^8.3
- **Dependencies**: `sugarcraft/candy-core` (via `SugarCraft\Core\Util\Ansi`, `SugarCraft\Core\Util\Width`)
- **Test suite**: 3 test files (ModelTest, FuzzyMatchTest, FilterStateTest), 289 ModelTest lines

---

## Feature List

- **Tree-list rendering** — viewport-aware list with box-drawing prefixes (╭ ├ │)
- **Cursor navigation** — cursorUp/cursorDown/setCursor with bounds clamping
- **Word wrapping** — hard-wrap at content width without breaking mid-word; configurable per-item line limit (Wrap)
- **Filter-as-you-type** — `withFilterFn()` / `withoutFilter()` with three-state `FilterState` enum (unfiltered/filtering/filtered)
- **Fuzzy matching** — Smith-Waterman local alignment with consecutive-match bonus; two-row DP matrix for O(c) memory
- **Pluggable Prefixer/Suffixer** — interface hooks for per-line prefix and suffix strings
- **LessFunc/EqualsFunc** — injectable sorting and equality comparison via closures
- **Stringable items** — any PHP `Stringable` works as an item; `StringItem` adapter for plain strings
- **Immutable fluent setters** — all setters return `$this` for chaining
- **i18n** — 16-language lang/ dir following LOCALES.md recommended set

---

## Key Classes and Methods

### Model (`src/Model.php`)
Core list model. Stores items, renders visible lines within viewport.

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
| `addItem()` | `(\Stringable $v): self` | Fluent, returns self |
| `removeItem()` | `(int $index): self` | Cursor-clamped after removal |
| `sort()` | `(): self` | Uses `lessFunc` or string comparison |
| `cursorIndex()` | `(): int` | Current cursor index |
| `cursorItem()` | `(): \Stringable` | Throws if empty |
| `setCursor()` | `(int $index): self` | Clamped to [0, length-1] |
| `cursorUp()` / `cursorDown()` | `(int $n = 1): self` | Relative movement |
| `find()` | `(\Stringable $value): int` | Uses `equalsFunc` or string comparison |
| `lines()` | `(): array` | Visible lines within viewport |
| `View()` | `(): string` | Newline-joined lines + trailing newline |

**Properties**: `width`, `height`, `cursorOffset`, `lineOffset`, `wrap`, `lessFunc`, `equalsFunc`, `prefixer`, `suffixer`, `lineStyle`, `currentStyle`, `filterFn`, `filterState`

### Item (`src/Item.php`)
Internal wrapper pairing a `Stringable` value with a unique integer ID for stable identity across sort/reorder operations.

```php
final class Item {
    public readonly \Stringable $value;
    public readonly int $id;
}
```

### StringItem (`src/StringItem.php`)
Plain-string adapter implementing `\Stringable` for use as list items.

```php
final class StringItem implements \Stringable {
    public readonly string $value;
}
```

### FilterState (`src/FilterState.php`)
Three-state enum tracking filter lifecycle:

```php
enum FilterState {
    case unfiltered;  // no filter active
    case filtering;     // filter fn set, filtering applied
    case filtered;      // filter applied, results shown
}
```

### FuzzyMatch (`src/FuzzyMatch.php`)
Smith-Waterman local alignment for fuzzy string scoring.

| Const | Value | Purpose |
|---|---|---|
| `MATCH_SCORE` | 3 | Matching character reward |
| `MISMATCH_PENALTY` | -3 | Mismatch cost |
| `GAP_OPEN` | -5 | Gap opening penalty |
| `GAP_EXTEND` | -1 | Gap extension penalty |
| `ADJACENT_BONUS` | 5 | Consecutive match reward |

**Key methods**:
- `score(string $query, string $candidate): int` — two-row DP, O(c) memory
- `match(string $query, array<\Stringable> $items): array` — returns `[[item, score], ...]` sorted desc

### Prefixer Interface (`src/Prefixer.php`)
```php
interface Prefixer {
    public function initPrefixer(\Stringable $value, int $currentIndex,
        int $cursorIndex, int $lineOffset, int $width, int $height): int;
    public function prefix(int $currentLine, int $totalLines): string;
}
```

### DefaultPrefixer (`src/DefaultPrefixer.php`)
Box-drawing prefixer with:
- Separators: `╭` (first), `├` (middle), `│` (wrap continuation)
- Line numbers (absolute or relative to cursor)
- `>` current-item marker (first line only)
- `prefixWrap` continuation prefix with `│`

Properties: `number`, `numberRelative`, `prefixWrap`, `firstSep`, `separator`, `separatorWrap`, `currentMarker`, `emptyMarker`

### Suffixer Interface (`src/Suffixer.php`)
```php
interface Suffixer {
    public function initSuffixer(\Stringable $value, int $currentIndex,
        int $cursorIndex, int $lineOffset, int $width, int $height): int;
    public function suffix(int $currentLine, int $totalLines): string;
}
```

### DefaultSuffixer (`src/DefaultSuffixer.php`)
Shows `<` marker on first line of cursor item; empty string on all other lines (no padding emitted).

---

## List Rendering Architecture

### Viewport-relative cursor offset
The cursor stays `cursorOffset` lines from the visible viewport edge. `lines()` builds visible output in three phases:

1. **Lines before cursor** — walks backward from `cursorIndex - 1` up to `lineOffset` items, collecting item lines bottom-up (reversed for correct display order)
2. **Cursor item** — included in the forward walk from `cursorIndex`
3. **Lines after cursor** — continues forward until `height` lines accumulated or items exhausted

### Per-item rendering (`renderItem()`)
For each item at a given index:

1. `initPrefixer()` called once to compute prefix width reservation
2. `initSuffixer()` called once to compute suffix width reservation
3. Content width = `width - prefixWidth - suffixWidth`
4. `hardWrap()` splits item string into lines at content width
5. Wrap limit applied via `array_slice` if `wrap > 0`
6. For each resulting line:
   - `prefixer->prefix(lineIdx, total)` produces line prefix
   - `suffixer->suffix(lineIdx, total)` produces line suffix (only emitted if non-empty)
   - Free space = `contentWidth - ansiWidth(lineContent)`; suffix right-padded with spaces to fill
   - LineStyle or CurrentStyle ANSI codes applied based on whether `itemIndex === cursorIndex`

### ANSI width computation
Uses `SugarCraft\Core\Util\Width::string()` for printable (non-ANSI) cell width, then `Ansi::CSI . $codes . 'm'` for SGR wrapping.

### Rendering pipeline
```
Model.lines()
  └─ renderItem(itemIndex)         [per visible item]
       ├─ prefixer.initPrefixer()  [once per item]
       ├─ suffixer.initSuffixer()  [once per item]
       ├─ hardWrap(content)         [word-wrap]
       └─ for each line:           [per line]
            ├─ prefixer.prefix()
            ├─ suffixer.suffix() (if non-empty)
            └─ applyStyle(lineStyle|currentStyle)
```

---

## Filter Implementation

### State machine
Three states with explicit transitions documented in `FilterState`:

| From | To | Trigger |
|---|---|---|
| `unfiltered` | `filtering` | `withFilterFn()` called |
| `filtering` | `filtered` | filter applied, items reduced |
| `filtered` | `unfiltered` | `withoutFilter()` called |
| `filtering` | `unfiltered` | filter cleared before result |

### `withFilterFn()` behavior
1. Clones model (copy-on-write)
2. Saves `originalItems = items`
3. Applies `array_filter` using the closure
4. Transitions `filterState` to `filtering`
5. Clamps cursor to new length: `min(cursorIndex, max(0, count(items) - 1))`
6. Returns the new cloned model

### `withoutFilter()` behavior
1. If `filterFn === null`, returns `$this` (no-op)
2. Clones model
3. Restores `items = originalItems`
4. Clears `originalItems`
5. Transitions `filterState` to `unfiltered`
6. Clamps cursor
7. Returns the new cloned model

### Filter function signature
```php
\Closure(\Stringable): bool
```
True = keep item, false = exclude.

---

## Navigation Patterns

### Cursor movement
- `cursorUp(int $n = 1)` — decrements cursor by n, clamped to 0
- `cursorDown(int $n = 1)` — increments cursor by n, clamped to length-1
- `setCursor(int $index)` — absolute position, clamped to [0, length-1]
- `cursorItem()` — returns value at current cursor position (throws if empty)

### Bounds handling
All cursor operations use `max(0, min(index, count - 1))` for clamping. No out-of-bounds cursor possible.

### Sorting
- `sort()` — applies `usort` with `lessFunc` or string comparison
- Cursor stays on same **item** (tracked by unique ID) not same index after sort
- Go upstream uses `sort.Interface` (Len/Less/Swap) — PHP uses closure injection

### Finding
- `find(\Stringable $value): int` — linear search using `equalsFunc` or string comparison
- Returns -1 if not found
- Go upstream's `GetIndex()` uses concurrent goroutines; PHP port uses sequential search

---

## Word-wrap Handling

### Algorithm: `hardWrap()` (Model.php lines 461-494)
1. Split input on `\n` for paragraph handling
2. For each paragraph, split on whitespace (`preg_split('/\s+/')`)
3. Greedy accumulation: if `withWord` fits in `contentWidth`, keep accumulating
4. When next word would exceed width:
   - Emit current line
   - If single word exceeds width, split it with `splitOverWidth()`
   - Start new line with the word
5. Handle final line after loop

### Oversized word splitting: `splitOverWidth()` (lines 502-509)
```php
private function splitOverWidth(string $word, int $maxWidth): array {
    $chunks = [];
    $len = strlen($word);
    for ($i = 0; $i < $len; $i += $maxWidth) {
        $chunks[] = substr($word, $i, $maxWidth);
    }
}
```
Note: Uses `strlen` (byte offset) not rune-aware splitting. This differs from Go upstream which uses `reflow/wordwrap.HardWrap` with proper Unicode handling.

### Wrap limit
```php
if ($this->wrap > 0 && count($rawLines) > $this->wrap) {
    $rawLines = array_slice($rawLines, 0, $this->wrap);
}
```
Truncates lines after wrapping, matching Go upstream's `if m.Wrap != 0 && len(lines) > m.Wrap`.

### Go upstream word-wrap
Go upstream uses `github.com/treilik/reflow/wordwrap.HardWrap(i.value.String(), contentWith, "    ")` with a 4-space indent on wrapped lines. The PHP port does NOT indent wrapped lines — this is a minor divergence.

---

## Comparison: candy-lister vs treilik/bubblelister (Upstream)

### Faithfulness
| Aspect | treilik/bubblelister (Go) | candy-lister (PHP) | Divergence? |
|---|---|---|---|
| tea.Model interface | Yes (Init/Update/View) | No — pure rendering model | Yes — PHP port is view-only |
| sort.Interface | Yes (Len/Less/Swap) | No — closure-based sort | Yes |
| Concurrent GetIndex | Yes (goroutines) | No — sequential find | Yes |
| Word wrap | reflow/wordwrap.HardWrap | Custom hardWrap | Yes — different algo |
| Wrap indent | 4-space continuation | None | Yes |
| Unique item IDs | Mutex-protected counter | Simple int counter (no mutex) | Yes |
| Error types | NoItems, NotFound, etc. | RuntimeException with messages | Yes |
| WindowSizeMsg | Yes — Update handles it | No — user sets dimensions | Yes |
| Keyboard handling | None (user implements) | None | Parity |
| MoveCursor/Top/Bottom | Yes | No — cursorUp/Down/setCursor only | Partial |

### Key architectural differences

1. **No tea.Model** — candy-lister is not a BubbleTea component; it is a standalone rendering engine. The upstream implements the full Elm-architecture pattern (Init/Update/View with message passing). This is a fundamental design choice reflecting PHP's synchronous nature vs Go's concurrent model.

2. **sort.Interface vs closures** — Go uses the `sort.Interface` pattern (Len/Less/Swap methods). PHP uses `lessFunc`/`equalsFunc` closures injected as public properties. This is more idiomatic for PHP.

3. **FuzzyMatch class** — Not present in Go upstream; added as a standalone Smith-Waterman scorer that can be used independently of the list model.

4. **Filter state machine** — Not present in Go upstream; added as a first-class feature with the three-state enum pattern.

5. **No concurrent search** — The Go `GetIndex` launches one goroutine per item and collects results via channels. PHP port's `find()` is sequential.

6. **ANSI styling** — Go uses `termenv.Style` for styling; PHP uses raw ANSI CSI codes via `Ansi::CSI`.

---

## Comparison: candy-lister vs Other Mapped Repos

### vs charmbracelet/bubbles (List component)
- **charmbracelet/bubbles List**: Full tea.Model with built-in filtering (`filterer` interface), vim keys, pagination, row selection. Much heavier (14 components total).
- **candy-lister**: Lighter, focused on viewport rendering, pluggable prefix/suffix, word-wrap. No built-in key bindings.
- **Verdict**: Different design philosophies — bubbles is a full component with opinions; candy-lister is a rendering engine with extension points.

### vs Genekkion/theHermit
- **theHermit**: Quick-fix overlay list. Wraps existing views, background continues updating. Overlay positioning with centering.
- **candy-lister**: Inline list renderer; no overlay/wrapper pattern.
- **Verdict**: Complementary — theHermit provides the overlay mechanism, candy-lister provides the list rendering.

### vs treilik/bubbleboxer
- **bubbleboxer**: Layout composer for composing multiple tea.Models into a tree structure with recursive rendering.
- **candy-lister**: Single list rendering engine.
- **Verdict**: bubbleboxer is a composition layer above individual components; candy-lister is a leaf component. A candy-lister instance could be a leaf in a bubbleboxer layout.

---

## Strengths

1. **Clean separation of concerns** — Prefixer/Suffixer interfaces allow full customization of per-line rendering without modifying the core model
2. **Immutable filter state** — `withFilterFn()`/`withoutFilter()` return new instances; original model unchanged
3. **Memory-efficient fuzzy matching** — Two-row Smith-Waterman DP keeps memory at O(c) where c = candidate length
4. **Viewport-aware rendering** — cursorOffset margin from edges, proper line slicing for visible window
5. **Smith-Waterman scoring** — Rewards consecutive matches (+5 bonus), penalizes gaps (-5 open, -1 extend), producing more intuitive rankings than Levenshtein
6. **Box-drawing defaults** — DefaultPrefixer ships with Unicode box characters (╭├│) matching Go upstream defaults
7. **Full i18n** — 16-language lang/ dir following LOCALES.md recommended set
8. **Fluent builder API** — All setters return self for method chaining

---

## Weaknesses

1. **No tea.Model / Elm pattern** — Not a BubbleTea component; cannot be dropped into a BubbleTea application as a component. Pure rendering model only.
2. **No keyboard bindings** — User must implement all navigation (up/down/home/end/pgup/pgdown) in their own event loop.
3. **No concurrent search** — `find()` is O(n) sequential; Go upstream uses goroutines for parallel evaluation.
4. **No wrap indent** — Go upstream indents wrapped lines with 4 spaces; PHP port does not.
5. **Byte-based word splitting** — `splitOverWidth()` uses `strlen`/`substr` (byte offset) not rune-aware splitting. May misbehave with multi-byte UTF-8 characters.
6. **No virtual scrolling / pagination** — All visible items in viewport are rendered every frame; no windowing for large lists.
7. **No mouse support** — No mouse click-to-select or scroll-wheel handling.
8. **No built-in filter UI** — Filter function must be provided by user; no text input component that drives filtering.
9. **Mutex-less ID counter** — `idCounter` is a plain int with no mutex protection (unlike Go upstream). Thread-safety concern in async/concurrent contexts.
10. **Sort is not stable** — Uses `usort` which is not guaranteed stable; cursor could jump if equal elements exist.

---

## SugarCraft Mapping

| candy-lister Feature | SugarCraft Library | Notes |
|---|---|---|
| List Model rendering | `candy-core` (Display, Buffer, Terminal) | Core TUI rendering infrastructure |
| Word wrapping | `candy-core` (Width utility) | Width::string() for ANSI-aware width |
| ANSI styling | `candy-core` (Ansi utility) | Ansi::CSI for SGR wrapping |
| Prefixer/Suffixer | `candy-lister` itself | Core extension hooks |
| Fuzzy matching | `sugar-bits` (existing FuzzyMatcher) | FuzzyMatch pattern already in Prompt |
| Filter state machine | `candy-lister` itself | FilterState enum + withFilterFn |
| Box-drawing chars | `candy-shine` (border primitives) | Unicode box chars |

---

## Analysis

**candy-lister** is a focused PHP port of treilik/bubblelister that prioritizes rendering correctness over API compatibility. The most significant design decision is the abandonment of the tea.Model interface — in PHP, there is no BubbleTea runtime to integrate with, so the port decouples the list rendering from any specific event loop. This makes candy-lister a pure view library that can be embedded in any PHP TUI framework (candy-core, ratatui-php, or raw termbox).

The filter state machine and FuzzyMatch class are notable extensions beyond the Go upstream. The three-state FilterState enum makes the filter lifecycle explicit and debuggable, while the Smith-Waterman scorer can be used independently of the list model for other filtering UI (e.g., sugar-prompt's fuzzy completion).

The main gap compared to Go upstream is the absence of concurrent search, stable sorting, and full tea.Model compliance. For large lists (10k+ items), the concurrent goroutine pattern in Go's `GetIndex` would significantly outperform PHP's sequential `find()`. The sort.Interface pattern is replaced with a more PHP-idiomatic closure injection, though this sacrifices the ability to use Go's standard library sort algorithms directly.

The word-wrap implementation differs from Go in two ways: no 4-space indent on continuation lines, and byte-based (not rune-based) splitting for oversize words. The former is a cosmetic divergence; the latter could cause issues with Unicode text containing emoji or wide characters.

Overall, candy-lister provides a solid foundation for list rendering in PHP TUIs. The pluggable Prefixer/Suffixer pattern is particularly well-suited for tree-style file browsers, selectable lists with status indicators, and other specialized list UIs. The main extension path would be adding keyboard navigation bindings (possibly in sugar-prompt), virtual scrolling for large lists, and rune-aware word splitting.
