# SugarFuzzy

> Formerly published as `sugarcraft/candy-fuzzy` (renamed 2026-10). <!-- @rename-keep -->

Fuzzy string matching library that returns matched character indices plus the match score — enables filter-highlighting UIs across the SugarCraft ecosystem.

## Installation

```bash
composer require sugarcraft/sugar-fuzzy:@dev
```

Releases: pre-1.0 — consume `dev-master` (the `:@dev` form above).

Requires PHP 8.3+.

## Role

Extracts the canonical Smith-Waterman fuzzy matcher from `sugar-forms` and adds the key feature that was previously impossible: **ranked matches WITH scored matched character indices**, enabling UI filter highlighting.

Provides two algorithms:
- **SmithWatermanMatcher** — Smith-Waterman local alignment with adjacency bonus. Bit-equivalent to the original `sugar-forms` implementation for ASCII input within the default length caps; the legacy empty-candidate score and the per-codepoint case-fold deviations are documented under "Canonical matcher & consumer migration".
- **SahilmMatcher** — Implements the `sahilm/fuzzy` scoring algorithm. Includes separator bonus, camelCase bonus, first-character bonus (`firstCharBonus`), and lower-case bonus (`lowerCaseBonus`).

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
$candidates = ['apple', 'applet', 'application', 'apricot'];
$results = $matcher->matchAll('app', $candidates);
// Returns array of MatchResult sorted by score

// The generator form yields the same ranked output — note it drains and sorts
// the whole candidate list before the first yield (ranking is global), so it
// streams results, it does not skip or stagger scoring
foreach ($matcher->matchAllGenerator('app', $candidates) as $result) {
    // ...
}

// Limit results to top N with optional minScore threshold
$top5 = $matcher->matchAll('app', $candidates, limit: 5);
$highQuality = $matcher->matchAll('app', $candidates, minScore: 10);

// Highlight matched runs
$highlighter = new Highlighter();
$styled = $highlighter->highlight($result, fn($matched) => "\033[1m$matched\033[0m");
// Returns 'foobar' with matched chars styled
```

Both construction idioms below build the same object: `new SmithWatermanMatcher(...)`
passes constructor arguments explicitly, while `SmithWatermanMatcher::new()` is the
house zero-argument factory mirroring upstream. Each example sticks to one idiom.

**Rank contract.** `matchAll()` (and `matchAllGenerator()`, same output) order
results by score descending, then by haystack ascending as the tiebreak — both
compared with `<=>`, so digit-only candidates order numerically (`"10"` lands
after `"9"`, not lexicographically before it). Perfect ties — equal score and
equal haystack — keep their input order (`usort` is stable as of PHP 8.0).
`limit: 0` yields no results; a negative `limit` is treated as unlimited.

## Scoring profiles

The Smith-Waterman scoring weights are injectable via an immutable `ScoringProfile`.
The **default** profile is bit-equivalent to the historical hard-coded constants
for ASCII input, so passing no profile (or `ScoringProfile::canonical()`; `default()`
is a deprecated alias) preserves existing ASCII output byte-for-byte — the two
documented deviations are listed under "Canonical matcher & consumer migration":

```php
use SugarCraft\Fuzzy\ScoringProfile;

$default = new SmithWatermanMatcher();                          // canonical scores
$strict  = new SmithWatermanMatcher(ScoringProfile::strict());  // higher rewards, harsher penalties
$lenient = new SmithWatermanMatcher(ScoringProfile::lenient()); // lower rewards, gentler penalties
$custom  = new SmithWatermanMatcher(
    ScoringProfile::canonical()->withAdjacentBonus(8)
);
```

| Weight            | canonical | strict | lenient |
|-------------------|----------:|-------:|--------:|
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

When plain mode's best alignment already covers the whole query, full-query mode
returns exactly that same alignment — nothing is forced. Otherwise (initials-style
queries such as `gst`, where a short adjacent run outscores the spread-out path)
the matcher scores the best
alignment forced to cover every query character: the highest-scoring in-order
placement, with the same weights and no restart inside the path. It runs from
the first matched character to the last, and every candidate character skipped
between two matches costs `gapExtend` (what plain mode charges any skip inside
an alignment; `gapOpen` only applies when an alignment starts from nothing).
That score is floored at 1, so a gap-heavy in-order match ranks last rather
than disappearing.

## Smith-Waterman length caps

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
matches. The traceback keeps one byte per cell, so it costs ≈1 KB per query row
at the 1000-character candidate cap: ≈128 KB at the default caps, reaching
≈1 MB only if you raise `maxQueryLength` to 1000.

These caps belong to `SmithWatermanMatcher` alone. `SahilmMatcher` is a
single-pass linear scan and intentionally **uncapped** — every candidate costs
O(queryLen × candidateLen) time only in the shared sense that each character is
visited once per query advance; bound hostile-size candidates host-side if the
input source is untrusted.

## Sahilm weights

`SahilmMatcher`'s bonuses are injectable via the immutable `SahilmScoring`; the
canonical weights (the default) are the constants this port has always
used — the bonus shape mirrors sahilm/fuzzy, the values are pinned by the
characterization suites:

```php
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\SahilmScoring;

$gum   = new SahilmMatcher();                                                  // canonical
$tuned = new SahilmMatcher(scoring: SahilmScoring::canonical()->withFirstCharBonus(0));
```

| Weight             | canonical |
|--------------------|----------:|
| `matchScore`       |         1 |
| `consecutiveBonus` |         5 |
| `separatorBonus`   |        10 |
| `camelBonus`       |        10 |
| `firstCharBonus`   |        15 |
| `lowerCaseBonus`   |         1 |

Note on thresholds: `match()` treats a non-positive score as no match and
returns `null`, while `matchAll()` filters only by `$minScore` (default 1).
Under the canonical weights the two agree; passing `minScore: 0` or injecting
negative weights can rank a candidate through `matchAll()` that `match()`
refuses.

## Selecting a matcher by name

```php
use SugarCraft\Fuzzy\Matcher\FuzzyMatcherFactory;

$matcher = FuzzyMatcherFactory::named('smith-waterman', ScoringProfile::strict());
$gum     = FuzzyMatcherFactory::named('sahilm', SahilmScoring::canonical());

$matcher->matchAll('app', ['apple', 'apricot']); // list<MatchResult>, ranked
```

Each matcher takes its own weight type; passing the other one (or an unknown
name) throws `InvalidArgumentException`. `create()` is a deprecated alias.

## MatchResult

```php
final class MatchResult
{
    public readonly string $needle;         // Search query
    public readonly string $haystack;       // Matched candidate
    public readonly int $score;             // Higher = better match
    public readonly array $matchedIndices;  // 0-based CODE-POINT indices into the original haystack (not byte offsets)

    public function indices(): array;       // the same offsets as a list<int> — accessor quickpick-style consumers use
    public function isMatched(): bool;      // score > 0
    public function isEmpty(): bool;        // no characters matched
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

The interface is shared, the **behavior is not interchangeable**: Sahilm matches
only when the whole query appears in order — equivalent to Smith-Waterman
full-query mode permanently on — so a call-site swap silently changes scattered
partial queries from a scored hit to `null`.

## Algorithm Differences

| Feature | SmithWaterman | Sahilm |
|---------|---------------|--------|
| Local alignment | ✅ | ❌ |
| Adjacent bonus | ✅ (5) | ✅ (consecutive: 5) |
| Separator bonus | ❌ | ✅ (10) |
| CamelCase bonus | ❌ | ✅ (10) |
| First-char bonus | ❌ | ✅ (15) |
| Whole query must land | ❌ (opt-in via `withRequireFullQuery()`) | ✅ (always) |
| Case sensitivity | none (always folded) | optional |
| Matching style | Best-alignment | Greedy first-occurrence |

## Canonical matcher & consumer migration

`sugar-fuzzy` is the single source of truth for fuzzy matching across the
ecosystem. Consumer migration is **complete** — no duplicate scoring core
remains outside this library:

- **`sugar-forms`** — `SugarCraft\Forms\Fuzzy\FuzzyMatcher` is a
  `@deprecated` shim whose former hand-rolled DP core was removed; every score
  now delegates to `SmithWatermanMatcher` with the default `ScoringProfile`
  (bit-equivalent to the historical constants for ASCII input). One legacy
  quirk stays shim-only: an empty candidate scores negative through the shim,
  while this library returns 0.
- **`sugar-treelist`** — `SugarCraft\TreeList\ScoringProfile` is a `class_alias` to
  this library's `ScoringProfile`, and `SugarCraft\TreeList\FuzzyMatch` is a thin
  delegating shim over `SmithWatermanMatcher` (keeping sugar-treelist's own
  `\Stringable`-item contract and input-order tiebreak). Scores are preserved
  within the length caps apart from `CharFold`'s intentional per-codepoint case
  folds (U+0130 and final sigma), where the old whole-string fold desynced
  match indices.

New code should depend on `SugarCraft\Fuzzy\*` directly.

## Security note

The highlighter is presentation-neutral and forwards unmatched haystack segments verbatim. Callers **must** sanitize candidate text (strip `\x1b`/control bytes) before display — the styler callback receives raw matched substrings only and does not sanitize. This is the correct responsibility division: sanitization belongs to the TUI render layer.

## Accessibility note

Highlighting conveys the match through color alone, which fails color-blind
users — pair it with shape cues (bold/underline) in the styler callback. ANSI
styling is never announced to screen readers: the accessible channels are the
`matchedIndices` on each result and `matchAll()`'s deterministic ranking
(ordering is reproducible at equal scores), and carrying that rank into
non-visual channels (plain-text listings, ARIA live regions in web UIs) is
the consumer's responsibility.

## Links

- [Smith-Waterman algorithm](https://en.wikipedia.org/wiki/Smith%E2%80%93Waterman_algorithm)
- [sahilm/fuzzy (Go)](https://github.com/sahilm/fuzzy)

[![codecov](https://codecov.io/gh/sugarcraft/sugar-fuzzy/branch/master/graph/badge.svg?flag=sugar-fuzzy)](https://codecov.io/gh/sugarcraft/sugar-fuzzy)

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

Design antecedent: the `sahilm/fuzzy` Go matcher (see Links).
