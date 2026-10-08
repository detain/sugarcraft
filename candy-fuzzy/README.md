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
- **SahilmMatcher** — Implements the `sahilm/fuzzy` scoring algorithm. Includes separator bonus, camelCase bonus, exact-prefix bonus.

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
so passing no profile (or `ScoringProfile::canonical()`; `default()` is a deprecated
alias) preserves existing output byte-for-byte:

```php
use SugarCraft\Fuzzy\ScoringProfile;

$default = new SmithWatermanMatcher();                          // canonical scores
$strict  = new SmithWatermanMatcher(ScoringProfile::strict());  // higher rewards, harsher penalties
$lenient = new SmithWatermanMatcher(ScoringProfile::lenient()); // lower rewards, gentler penalties
$custom  = new SmithWatermanMatcher(
    ScoringProfile::canonical()->withAdjacentBonus(8)
);
```

| Weight            | default | strict | lenient |
|-------------------|--------:|-------:|--------:|
| `matchScore`      |       3 |      4 |       2 |
| `mismatchPenalty` |      −3 |     −4 |      −2 |
| `gapOpen`         |      −5 |     −6 |      −3 |
| `gapExtend`       |      −1 |     −2 |      −1 |
| `adjacentBonus`   |       5 |      6 |       3 |

## Full-query mode

Smith-Waterman is a LOCAL alignment: by default a candidate matches as soon as
any run of the query aligns with it, so `match('bxz', 'abcdef')` scores 3 on the
`b` alone. A type-to-filter picker usually wants every typed character to land —
opt in and a candidate survives exactly when it contains the query as an
in-order subsequence (case-folded), with one matched index per query character:

```php
$picker = SmithWatermanMatcher::new()->withRequireFullQuery();
$picker->match('bxz', 'abcdef');     // null — "x" and "z" never land
$picker->match('app', 'apple');      // indices [0, 1, 2], score as in plain mode
$picker->match('gst', 'git status'); // indices [0, 4, 5] — plain mode only finds "st"
```

When plain mode's best alignment already covers the whole query, the result is
identical to plain mode. Otherwise (initials-style queries such as `gst`, where
a short adjacent run outscores the spread-out path) the matcher scores the best
alignment forced to cover every query character: the highest-scoring in-order
placement, with the same weights and no restart inside the path. It runs from
the first matched character to the last, and every candidate character skipped
between two matches costs `gapExtend` (what plain mode charges any skip inside
an alignment; `gapOpen` only applies when an alignment starts from nothing).
That score is floored at 1, so a gap-heavy in-order match ranks last rather
than disappearing.

## DoS length caps

An alignment costs O(queryLen × candidateLen) time. To bound worst-case cost,
only the first `maxQueryLength` query characters (default 128) and the first
`maxCandidateLength` candidate characters (default 1000) take part in it:

```php
$matcher = SmithWatermanMatcher::new(maxQueryLength: 64, maxCandidateLength: 4000);
```

Over-cap input is **truncated**, never handed to a different algorithm, so the
match contract and score scale are the same on both sides of the cap and the
reported indices stay valid in the original haystack. The trade-off: text past
the candidate cap is not searched — a candidate whose only match lies beyond it
does not match. Under full-query mode, a query longer than the query cap never
matches. The traceback costs one byte per cell (≈1 MB at 1000×1000).

## Sahilm weights

`SahilmMatcher`'s bonuses are injectable via the immutable `SahilmScoring`; the
canonical weights (the default) are the historical sahilm/fuzzy constants:

```php
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\SahilmScoring;

$gum   = new SahilmMatcher();                                                   // canonical
$tuned = SahilmMatcher::new(scoring: SahilmScoring::canonical()->withFirstCharBonus(0));
```

| Weight             | canonical |
|--------------------|----------:|
| `matchScore`       |         1 |
| `consecutiveBonus` |         5 |
| `separatorBonus`   |        10 |
| `camelBonus`       |        10 |
| `firstCharBonus`   |        15 |
| `lowerCaseBonus`   |         1 |

## Selecting a matcher by name

```php
use SugarCraft\Fuzzy\Matcher\FuzzyMatcherFactory;

FuzzyMatcherFactory::named('smith-waterman', ScoringProfile::strict());
FuzzyMatcherFactory::named('sahilm', SahilmScoring::canonical());
```

Each matcher takes its own weight type; passing the other one (or an unknown
name) throws `InvalidArgumentException`. `create()` is a deprecated alias.

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
ecosystem. Consumer migration is **complete** — no duplicate scoring core
remains outside this library:

- **`sugar-prompt`** — `SugarCraft\Prompt\Fuzzy\FuzzyMatcher` is a
  `class_alias` to `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`.
- **`candy-forms`** — `SugarCraft\Forms\Fuzzy\FuzzyMatcher` is a
  `@deprecated` shim whose former hand-rolled DP core was removed; every score
  now delegates to `SmithWatermanMatcher` with the default `ScoringProfile`
  (bit-equivalent to the historical constants).
- **`candy-lister`** — `SugarCraft\Lister\ScoringProfile` is a `class_alias` to
  this library's `ScoringProfile`, and `SugarCraft\Lister\FuzzyMatch` is a thin
  delegating shim over `SmithWatermanMatcher` (keeping candy-lister's own
  `\Stringable`-item contract and input-order tiebreak).

New code should depend on `SugarCraft\Fuzzy\*` directly.

## Security note

The highlighter is presentation-neutral and forwards unmatched haystack segments verbatim. Callers **must** sanitize candidate text (strip `\x1b`/control bytes) before display — the styler callback receives raw matched substrings only and does not sanitize. This is the correct responsibility division: sanitization belongs to the TUI render layer.

## Links

- [Smith-Waterman algorithm](https://en.wikipedia.org/wiki/Smith%E2%80%93Waterman_algorithm)
- [sahilm/fuzzy (Go)](https://github.com/sahilm/fuzzy)

[![codecov](https://codecov.io/gh/sugarcraft/candy-fuzzy/branch/master/graph/badge.svg?flag=candy-fuzzy)](https://codecov.io/gh/sugarcraft/candy-fuzzy)

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

Design antecedent: the `sahilm/fuzzy` Go matcher (see Links).
