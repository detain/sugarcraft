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

// Match a single candidate (full traceback → matched indices for highlighting)
$result = $matcher->match('foo', 'foobar');
// MatchResult(needle: 'foo', haystack: 'foobar', score: 19, matchedIndices: [0, 1, 2])

// Score-only fast path (two-row DP, no traceback allocation) — same score,
// for ranking/filtering when you don't need matched indices
$score = $matcher->score('foo', 'foobar'); // 19

// Match against multiple candidates (sorted by score desc)
$results = $matcher->matchAll('app', ['apple', 'applet', 'application', 'apricot']);
// Returns array of MatchResult sorted by score

// Limit results to top N with optional minScore threshold
$top5 = $matcher->matchAll('app', $candidates, limit: 5);
$highQuality = $matcher->matchAll('app', $candidates, minScore: 10);

// Highlight matched runs
$highlighter = new Highlighter();
$styled = $highlighter->highlight($result, fn($matched) => "\033[1m$matched\033[0m");
// Returns 'foobar' with matched chars styled
```

## Scoring profiles

The Smith-Waterman scoring weights are injectable via an immutable `ScoringProfile`.
The **default** profile is bit-equivalent to the historical hard-coded constants,
so passing no profile (or `ScoringProfile::default()`) preserves existing output
byte-for-byte:

```php
use SugarCraft\Fuzzy\ScoringProfile;

$default = new SmithWatermanMatcher();                          // canonical scores
$strict  = new SmithWatermanMatcher(ScoringProfile::strict());  // higher rewards, harsher penalties
$lenient = new SmithWatermanMatcher(ScoringProfile::lenient()); // lower rewards, gentler penalties
$custom  = new SmithWatermanMatcher(
    ScoringProfile::default()->withAdjacentBonus(8)
);
```

| Weight            | default | strict | lenient |
|-------------------|--------:|-------:|--------:|
| `matchScore`      |       3 |      4 |       2 |
| `mismatchPenalty` |      −3 |     −4 |      −2 |
| `gapOpen`         |      −5 |     −6 |      −3 |
| `gapExtend`       |      −1 |     −2 |      −1 |
| `adjacentBonus`   |       5 |      6 |       3 |

## DoS length caps

The full-traceback path allocates an O(queryLen × candidateLen) matrix. To bound
worst-case memory/time, queries or candidates longer than the caps (default 1000
characters each) are delegated to the O(1)-memory `SahilmMatcher` instead of
building the quadratic matrix:

```php
$matcher = new SmithWatermanMatcher(maxQueryLength: 200, maxCandidateLength: 4000);
```

Fallback scores are on the Sahilm scale, so this is a safety valve for pathological
input, not a source of scores comparable with the sub-cap path.

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
| Matching style | Best-alignment | Greedy first-occurrence |

## Canonical matcher & consumer migration

`candy-fuzzy` is the single source of truth for fuzzy matching across the
ecosystem. Consumer migration is in progress and NOT yet complete:

- **`sugar-prompt`** — already delegates: `SugarCraft\Prompt\Fuzzy\FuzzyMatcher`
  is a `class_alias` to `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`.
- **`candy-forms`** — `SugarCraft\Forms\Fuzzy\FuzzyMatcher` is marked
  `@deprecated` but still ships its own standalone copy (its `Select` field
  already uses candy-fuzzy's `SmithWatermanMatcher` directly). Conversion to a
  shim is tracked for a follow-up batch.
- **`candy-lister`** — `SugarCraft\Lister\FuzzyMatch` and its `ScoringProfile`
  remain independent copies; this library's `ScoringProfile` is a superset port
  of candy-lister's, so the follow-up batch can alias it here.

Until those follow-ups land, treat the copies above as duplicates pending
removal — new code should depend on `SugarCraft\Fuzzy\*` directly.

## Security note

The highlighter is presentation-neutral and forwards unmatched haystack segments verbatim. Callers **must** sanitize candidate text (strip `\x1b`/control bytes) before display — the styler callback receives raw matched substrings only and does not sanitize. This is the correct responsibility division: sanitization belongs to the TUI render layer.

## Links

- [Smith-Waterman algorithm](https://en.wikipedia.org/wiki/Smith%E2%80%93Waterman_algorithm)
- [sahilm/fuzzy (Go)](https://github.com/sahilm/fuzzy)
- [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea)

[![codecov](https://codecov.io/gh/sugarcraft/candy-fuzzy/branch/master/graph/badge.svg?flag=candy-fuzzy)](https://codecov.io/gh/sugarcraft/candy-fuzzy)
