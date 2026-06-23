# CandyFuzzy

Fuzzy string matching library with scored matched character indices — enables filter highlighting UI across the SugarCraft ecosystem.

## Installation

```bash
composer require sugarcraft/candy-fuzzy
```

## Role

Extracts the canonical Smith-Waterman fuzzy matcher from `candy-forms` and adds the key feature that was previously impossible: **ranked matches WITH scored matched character indices**, enabling UI filter highlighting.

Provides two algorithms:
- **SmithWatermanMatcher** — Smith-Waterman local alignment with adjacency bonus. Bit-equivalent to the original `candy-forms` implementation.
- **SahilmMatcher** — Ports the `sahilm/fuzzy` algorithm used by `charmbracelet/gum` filter. Includes separator bonus, camelCase bonus, exact-prefix bonus.

## Quickstart

```php
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\Highlighter;

$matcher = new SmithWatermanMatcher();

// Match a single candidate
$result = $matcher->match('foo', 'foobar');
// MatchResult(needle: 'foo', haystack: 'foobar', score: 16, matchedIndices: [0, 1, 2])

// Match against multiple candidates (sorted by score desc)
$results = $matcher->matchAll('app', ['apple', 'applet', 'application', 'apricot']);
// Returns list of MatchResult sorted by score

// Highlight matched runs
$highlighter = new Highlighter();
$styled = $highlighter->highlight($result, fn($matched) => "\033[1m$matched\033[0m");
// Returns 'foobar' with matched chars styled
```

## MatchResult

```php
final class MatchResult
{
    public readonly string $needle;      // Search query
    public readonly string $haystack;   // Matched candidate
    public readonly int $score;         // Higher = better match
    public readonly array $matchedIndices; // 0-based char indices of matched chars
}
```

## Interface

Swap matchers without touching call-sites — type-hint the `FuzzyMatcher` interface, implemented by both `SmithWatermanMatcher` and `SahilmMatcher`:

```php
use SugarCraft\Fuzzy\FuzzyMatcher;

function filter(FuzzyMatcher $matcher, string $query, array $candidates): array
{
    return $matcher->matchAll($query, $candidates);
}
```

## Algorithm Differences

| Feature | SmithWaterman | Sahilm |
|---------|---------------|--------|
| Local alignment | ✅ | ❌ |
| Adjacent bonus | ✅ (5) | ✅ (consecutive: 5) |
| Separator bonus | ❌ | ✅ (10) |
| CamelCase bonus | ❌ | ✅ (10) |
| First-char bonus | ❌ | ✅ (15) |
| Case sensitive | ❌ | Optional |

## Backward Compatibility

The existing `SugarCraft\Forms\Fuzzy\FuzzyMatcher` and `SugarCraft\Lister\FuzzyMatch` classes remain as deprecated shims that delegate to `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`. Consumers will migrate in subsequent steps.

## Links

- [Smith-Waterman algorithm](https://en.wikipedia.org/wiki/Smith%E2%80%93Waterman_algorithm)
- [sahilm/fuzzy (Go)](https://github.com/sahilm/fuzzy)
- [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea)

[![codecov](https://codecov.io/gh/sugarcraft/candy-fuzzy/branch/master/graph/badge.svg?flag=candy-fuzzy)](https://codecov.io/gh/sugarcraft/candy-fuzzy)
