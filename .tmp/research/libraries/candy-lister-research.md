# candy-lister: List/Filter TUI Components Research

**Context:** Research for SugarCraft/candy-lister list filtering functionality  
**Upstream:** treilik/bubblelister  
**Date:** 2026-05-13

---

## Executive Summary

candy-lister currently provides list rendering with cursor navigation, but **lacks any filtering capability**. This research examines filter implementations across Go (upstream bubblelister + charmbracelet/bubbles), Rust (skim/fuzzy-matcher), and Python (Textual) to identify the best approach for implementing filtering in PHP.

**Key Finding:** The Go bubbles/list component provides the most complete reference implementation with a pluggable FilterFunc interface. The skim V2 algorithm (Smith-Waterman with affine gaps) offers superior result ranking. For PHP, a Fuse.js-style fuzzy matcher or skim V2 port would provide the best user experience.

---

## 1. Current candy-lister Implementation

**Source:** `/home/sites/sugarcraft/candy-lister/src/Model.php`

### Capabilities
- Viewport-based rendering with configurable width/height
- Cursor navigation (up/down with clamping)
- Prefix/suffix hooks for custom styling (DefaultPrefixer, DefaultSuffixer)
- Word-wrap with configurable max lines per item
- External sort/equals functions via closures
- Item finding by equality function

### Gaps (Missing Filter Functionality)
- **No filtering** — items cannot be filtered by a search term
- **No fuzzy matching** — exact string comparison only
- **No result ranking** — items appear in insertion order
- **No highlighting** — matched characters not visualized
- **No pagination** — relies on viewport height only

---

## 2. Go: charmbracelet/bubbles/list

**Source:** [charmbracelet/bubbles/list/list.go](https://raw.githubusercontent.com/charmbracelet/bubbles/master/list/list.go)

### Filter Architecture

```go
// FilterFunc takes a term and a list of strings to search through.
// It should return a sorted list of ranks.
type FilterFunc func(string, []string) []Rank

type Rank struct {
    Index          int     // index in the original input
    MatchedIndexes []int   // rune indices of matched characters
}

// DefaultFilter uses sahilm/fuzzy to filter through the list
func DefaultFilter(term string, targets []string) []Rank {
    ranks := fuzzy.Find(term, targets)
    sort.Stable(ranks)
    result := make([]Rank, len(ranks))
    for i, r := range ranks {
        result[i] = Rank{
            Index:          r.Index,
            MatchedIndexes: r.MatchedIndexes,
        }
    }
    return result
}
```

### Key Design Decisions

| Aspect | Approach |
|--------|----------|
| **Filter function** | Pluggable via `FilterFunc` interface — can swap in custom algorithms |
| **Filter state** | Three states: `Unfiltered`, `Filtering`, `FilterApplied` |
| **UI integration** | Separate `FilterInput` textinput component for editing |
| **Key handling** | When `filterState == Filtering`, cursor keys disabled |
| **Result storage** | `filteredItems []filteredItem` — ephemeral, separate from master items |
| **Match highlighting** | `MatchesForItem(index)` returns matched rune indices |
| **Real-time** | `filterItems()` runs as `tea.Cmd` (asynchronous) |

### Filter States and Transitions

```
Unfiltered --[press filter key]--> Filtering --[press Enter/accept]--> FilterApplied
     ^                                  |                                    |
     |______[press Escape/clear]________|____________________________________|
```

### Strengths
- Clean separation between filter algorithm and UI
- Async filtering via tea.Cmd — doesn't block render loop
- Built-in status bar showing "X filtered"
- Full help system integration

### Weaknesses
- Depends on `sahilm/fuzzy` — not a native Go implementation
- Uses sorted ranks, not scored matches (loses score granularity)

---

## 3. Go: treilik/bubblelister (Upstream)

**Source:** [github.com/treilik/bubblelister](https://github.com/treilik/bubblelister)

### Overview
- Lightweight wrapper around bubbles/list
- Focuses on struct-to-list conversion for Bubble Tea
- Stars: 52 | MIT License
- Last updated: 2023-04-29

### Relationship to candy-lister
candy-lister ports the **model/view rendering** from bubblelister, but not the filtering subsystem. The filtering in Go bubbles/list is actually independent of bubblelister — it's in the base `list` component.

---

## 4. Rust: skim + fuzzy-matcher (SkimMatcherV2)

**Source:** [skim-rs/skim](https://github.com/skim-rs/skim), [skim-rs/fuzzy-matcher](https://crates.io/crates/fuzzy-matcher)

### Algorithm: Smith-Waterman with Affine Gap Penalty

The skim V2 algorithm is based on sequence alignment (DNA sequencing) and provides **typo-resistant** fuzzy matching.

```rust
// Core scoring configuration
pub struct SkimScoreConfig {
    pub score_match: i32,           // Score for each matched character
    pub gap_start: i32,             // Penalty for starting a gap (unmatched chars)
    pub gap_extension: i32,         // Penalty for extending a gap
    pub bonus_first_char_multiplier: i32,  // First char is more significant
    pub bonus_head: i32,            // Match at word beginning
    pub bonus_break: i32,           // Match after word break
    pub bonus_camel: i32,           // Match in camelCase
    pub bonus_consecutive: i32,     // Consecutive match bonus
    pub penalty_case_mismatch: i32, // Case mismatch penalty
}
```

### Key Scoring Features

| Bonus/Penalty | Purpose |
|---------------|---------|
| `bonus_head` | Prefers matches at word boundaries |
| `bonus_camel` | Scores camelCase transitions |
| `bonus_consecutive` | Rewards consecutive character matches |
| `gap_start + gap_extension` | Penalizes gaps (non-matching chars between matches) |
| `smart_case` | Case insensitive unless pattern has uppercase |

### API

```rust
trait FuzzyMatcher {
    fn fuzzy_indices(&self, choice: &str, pattern: &str) -> Option<(ScoreType, MatchIndices)>;
    fn fuzzy_match(&self, choice: &str, pattern: &str) -> Option<ScoreType>;
}

// Usage
let matcher = SkimMatcherV2::default();
assert!(matcher.fuzzy_match("axbycz", "abc").is_some());
let (score, indices) = matcher.fuzzy_indices("axbycz", "abc").unwrap();
assert_eq!(indices, [0, 2, 4]);  // positions of matched chars
```

### Strengths
- **Superior ranking** — Smith-Waterman produces better-ordered results
- **Typo tolerance** — gap penalties handle transpositions/misspellings
- **Configurable** — every scoring parameter is tunable
- **Thread-local caching** — performance via `ThreadLocal<RefCell<>>`

### Weaknesses
- Complex algorithm (800+ lines for SkimMatcherV2)
- No PHP equivalent exists

---

## 5. Python: Textual Fuzzy Matcher

**Source:** [textualize/textual - fuzzy.py](https://raw.githubusercontent.com/Textualize/textual/main/src/textual/fuzzy.py)

### Simpler Algorithm Approach

Textual uses a **heuristic-based approach** rather than dynamic programming:

```python
class FuzzySearch:
    def match(self, query: str, candidate: str) -> tuple[float, Sequence[int]]:
        # Quick exit: exact substring match
        if query in candidate:
            query_location = candidate.find(query)
            offsets = list(range(query_location, query_location + len(query)))
            return (score * 2.0 if candidate == query else 1.5, offsets)

        # For each letter, find all positions in candidate
        for offset, letter in enumerate(query):
            positions = [i for i, c in enumerate(candidate) if c == letter]
            if not positions:
                return (0.0, ())  # No match

        # Score based on:
        # - Number of matched positions
        # - First letter bonus
        # - Consecutive grouping bonus
        return (score(candidate, offsets), offsets)

    def score(self, candidate: str, positions: Sequence[int]) -> float:
        first_letters = frozenset(match.start() for match in finditer(r"\w+", candidate))
        offset_count = len(positions)
        score = offset_count + len(first_letters.intersection(positions))

        # Grouping bonus: fewer groups = higher score
        groups = sum(1 for i, o in enumerate(offsets) if i > 0 and o != offsets[i-1] + 1)
        normalized_groups = (offset_count - (groups - 1)) / offset_count
        score *= 1 + (normalized_groups * normalized_groups)
        return score
```

### Strengths
- **Simple** — ~150 lines, readable and maintainable
- **Fast substring exit** — O(n) for literal substring matches
- **LRU caching** — `LRUCache` for repeated queries
- **Good enough** — heuristic performs well for command palette use

### Weaknesses
- Doesn't handle typos/gaps as well as Smith-Waterman
- Grouping heuristic is simplistic

---

## 6. PHP Fuzzy Matching Libraries

### Available Options

| Library | Algorithm | Stars | Notes |
|---------|-----------|-------|-------|
| **loilo/Fuse** | Fuse.js port | ~300 | Weighted keys, threshold, distance options |
| **nullform/fuzzio** | Levenshtein + similar_text | ~50 | Simple API, UTF-8 safe |
| **ilya-dev/fuzzy** | Edit distance | ~30 | Minimal, threshold-based |
| **wataridori/simple-fuzzy-search** | LCS + Levenshtein | ~100 | Multi-key search |

### Fuse.js-style (Recommended for PHP)

```php
// Example: loilo/Fuse
$fuse = new Fuse($items, [
    'keys' => ['name', 'description'],
    'threshold' => 0.3,
    'distance' => 100,
    'ignoreLocation' => true,
]);

$results = $fuse->search('query string');
// Returns: [{item, score, matches}, ...]
```

### Direct Levenshtein

```php
// Using PHP's built-in functions
levenshtein($needle, $haystack);  // O(n*m) edit distance
similar_text($needle, $haystack, $percent);  // Similarity ratio
```

---

## 7. Comparison Matrix

| Feature | Go bubbles/list | skim V2 | Python Textual | PHP (Fuse) |
|---------|-----------------|---------|----------------|------------|
| **Algorithm** | Sublime-style | Smith-Waterman | Heuristic | Configurable |
| **Typo tolerance** | Yes | Excellent | Moderate | Yes |
| **Result ranking** | Sorted by score | By score | By score | By score |
| **Match indices** | Yes | Yes | Yes | Partial |
| **Weighted keys** | No | No | No | Yes |
| **Async filtering** | Yes (tea.Cmd) | N/A | Yes (async) | No |
| **PHP available** | No | No | No | Yes |
| **Lines of code** | ~100 for filter | ~800 | ~150 | ~500 (Fuse) |

---

## 8. Recommended Implementation for candy-lister

### Priority 1: Filter Interface (Low Effort, High Impact)

**Reference:** Go `FilterFunc` interface in bubbles/list

```php
/**
 * Filter result — index + matched character positions.
 */
final readonly class FilterMatch
{
    public function __construct(
        public int $index,
        public list<int> $matchedIndexes,
        public int $score = 0,
    ) {}
}

/**
 * Filter function signature — pluggable algorithm.
 *
 * @param string $term User's search term
 * @param list<string> $targets FilterValue() strings from items
 * @return list<FilterMatch> Matched items with indices and positions, sorted by relevance
 */
type FilterFunc = callable(string $term, array $targets): array;
```

### Priority 2: FuzzyMatch Class (Medium Effort)

**Reference:** skim V2 + Textual approach

```php
/**
 * Fuzzy matcher producing score + matched positions.
 *
 * Mirrors skim-rs/fuzzy-matcher SkimMatcherV2.
 */
final class FuzzyMatch
{
    public function __construct(
        private string $term,
        private int $scoreMatch = 16,
        private int $gapStart = -3,
        private int $gapExtension = -1,
        private bool $ignoreCase = true,
    ) {}

    /**
     * Match term against a single candidate.
     *
     * @return array{score: int, indices: list<int>}|null null if no match
     */
    public function match(string $candidate): ?array
    {
        // 1. Quick substring exit
        if ($this->ignoreCase) {
            $lowerTerm = \mb_strtolower($this->term);
            $lowerCand = \mb_strtolower($candidate);
        }

        $pos = \mb_strpos($lowerCand, $lowerTerm);
        if ($pos !== false) {
            $indices = \range($pos, $pos + \mb_strlen($this->term) - 1);
            return [
                'score' => $this->scoreMatch * 2,
                'indices' => $indices,
            ];
        }

        // 2. Character-by-character fuzzy matching with gap penalties
        // ... (Smith-Waterman inspired implementation)
    }
}
```

### Priority 3: Filter Model Integration (High Effort)

**Reference:** Go `filterItems()` + `FilterState` machine

```php
enum FilterState
{
    case Unfiltered;
    case Filtering;    // User is typing in filter input
    case FilterApplied; // Filter active, user browsing results
}

final class Model
{
    // ... existing properties ...

    private ?string $filterTerm = null;
    private FilterState $filterState = FilterState::Unfiltered;
    private list<FilterMatch> $filteredItems = [];

    /** @var FilterFunc|null */
    public ?callable $filterFunc = null;

    public function filter(string $term): self { ... }
    public function resetFilter(): self { ... }
    public function isFiltered(): bool { ... }
    public function filterState(): FilterState { ... }
    public function visibleItems(): array { ... }  // Returns filtered or all
    public function matchedIndexes(int $visibleIndex): array { ... }
}
```

### Priority 4: Filter Input View (Medium Effort)

**Reference:** Go `FilterInput textinput.Model`

For TTY raw mode, a simple filter input:

```php
public function filterPrompt(string $prompt = 'Filter: '): string
{
    echo $prompt;
    // Read single line with readline()/TTY raw mode
    // Return the user's input
}
```

---

## 9. Specific Improvements (Prioritized)

| # | Improvement | Reference | Effort | Impact |
|---|-------------|-----------|--------|--------|
| 1 | Add `FilterFunc` type and `FilterMatch` class | Go `FilterFunc` | 2h | High |
| 2 | Implement `FuzzyMatch` class with score + indices | skim V2 / Textual | 8h | High |
| 3 | Add `filter($term)`, `resetFilter()`, `FilterState` to Model | Go filter state | 4h | High |
| 4 | Add `visibleItems()` and `matchedIndexes()` to Model | Go `VisibleItems()` | 2h | Medium |
| 5 | Add FilterInput helper (optional, for future TUI integration) | Go `FilterInput` | 4h | Medium |
| 6 | Add highlight support via matchedIndexes() in renderer | Go `MatchesForItem()` | 3h | Medium |
| 7 | Weighted multi-key search (like Fuse.js) | Fuse.js | 6h | Low |
| 8 | Async filtering for large lists | Go async via tea.Cmd | 4h | Low |

**Total Estimated Effort:** ~33 hours

---

## 10. Conclusion

candy-lister has a solid rendering foundation. The missing piece is filtering. The Go bubbles/list provides the cleanest architectural reference — a pluggable `FilterFunc` that returns ranked matches. For the actual algorithm, a PHP port of skim V2's Smith-Waterman approach would provide the best user experience (typo tolerance, good ranking), though a Textual-style heuristic would be faster to implement and "good enough" for most use cases.

**Recommended Path:**
1. Define `FilterFunc` interface + `FilterMatch` DTO
2. Implement a `FuzzyMatch` class (start with Textual-style heuristic, upgrade to skim V2 later)
3. Integrate filter state machine into Model
4. Add filter-aware rendering via `matchedIndexes()`

---

## References

- **bubbles/list:** https://github.com/charmbracelet/bubbles/blob/master/list/list.go
- **bubblelister:** https://github.com/treilik/bubblelister
- **skim fuzzy-matcher:** https://github.com/skim-rs/fuzzy-matcher
- **Textual fuzzy.py:** https://github.com/Textualize/textual/blob/main/src/textual/fuzzy.py
- **PHP Fuse:** https://github.com/Loilo/Fuse
- **PHP Fuzzio:** https://github.com/nullform/fuzzio
