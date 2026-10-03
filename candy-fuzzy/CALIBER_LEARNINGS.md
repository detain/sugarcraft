# Caliber Learnings — candy-fuzzy

<!--
  Accumulated lessons from implementing and maintaining candy-fuzzy.
  Add entries as they are discovered — do not delete anything.
-->

## Key Insight (2026-05-28)

**Matched-indices is the value-add over the existing impl.** The original
`candy-forms/src/Fuzzy/FuzzyMatcher.php` computed a score but discarded the
traceback, making UI filter highlighting impossible. This library adds the
traceback walk to extract matched character indices.

Do NOT drop the traceback walk — it is the reason this library exists.

## Algorithm Notes

- Smith-Waterman uses two-row matrix optimization for memory efficiency, but
  traceback requires the full matrix. When adding matched-indices output,
  keep the full matrix for traceback.
- UTF-8 safety: use `mb_substr` and `mb_strlen` throughout, not raw byte
  indexing. Character indices (not byte offsets) are the contract.

## Future Enhancements (Pre-1.0)

- **Configurable scoring in SahilmMatcher**: The scoring constants
  (MATCH_SCORE, CONSECUTIVE_BONUS, SEPARATOR_BONUS, CAMEL_BONUS,
  FIRST_CHAR_BONUS, LOWER_CASE_BONUS) are hardcoded. Consider adding a
  `SahilmMatcherConfig` class or constructor parameters to allow tuning
  these values at instantiation time. This would be a non-breaking
  addition if default values preserve existing behavior.

## Testing

- Golden parity tests: every fixture from `candy-forms/tests/Fuzzy/FuzzyMatcherTest.php`
  must pass as-is (except the new `indices` field).
- UTF-8 fixtures: query `"中"` against `"中文"` must return indices `[0]`
  (character index, not byte).

## DoS cap + hot-path rework (2026-10-03)

- **Over-cap input is truncated, not delegated.** The old cap swapped in
  SahilmMatcher (full in-order subsequence), so a local alignment that scored at
  999 chars became `null` at 1001 — a candidate flipped out of a filtered list by
  growing one char, on a different score scale. Now only the first
  `maxQueryLength`/`maxCandidateLength` chars are aligned; prefixes keep indices
  valid in the original haystack. Do not reintroduce an algorithm switch at a cap.
- **Traceback is one byte per cell** (`'0'..'3'` per row string) and scores are
  two rows — supersedes "traceback requires the full matrix" above: it requires
  the full ORIGIN grid, not the score matrix. Peak memory at 1000×1000 went
  ~40 MB → ~1.5 MB. Pin tie-break diag > up > left and first-maximum selection;
  `tests/SsotBehaviorTest.php` and the characterization corpora catch drift.
- `CharFold::foldSplit()` has an ASCII fast path (`str_split(strtolower())`);
  `strtolower` is locale-insensitive on PHP 8.2+, so it is 1:1 by construction.
- Linear pre-screen (no shared folded char → 0) is only sound when no step but
  a char match can be positive (`mismatchPenalty`, `gapOpen`, `gapExtend` <= 0)
  — gate it like `ceilingPruneIsSound()`.
- `requireFullQuery` refuses an over-cap query before aligning (a query the cap
  shortened is never "fully" matched) and any pair where the query is not an
  in-order subsequence. Filtering on the UNCONSTRAINED best alignment is wrong:
  adjacency bonuses make a short run beat the spread-out covering path
  (`gst`/`git status` → "st"), dropping real in-order matches. When the
  unconstrained best falls short, `alignFullQuery()` runs a semi-global pass
  (query global, candidate local, no zero clamp, same step rules as `align()`),
  floored at 1. Pinned by a brute-force check in `tests/RequireFullQueryTest.php`.
- Do NOT copy `align()`'s `$left === 0 ? gapOpen : gapExtend` into a DP without
  a zero clamp: in `align()` a 0 cell IS the clamped restart state, but in the
  covering DP a running total can merely pass through 0 (or go negative), the
  skip cost turns non-monotone in the predecessor, and keeping the per-cell max
  is no longer exact (a 0 beat a -1 that paid less for the next skip). The
  covering DP charges `gapExtend` for every skip between matches — exactly
  `align()`'s charge on any unclamped path — and takes no skip in the last row
  (the path ends on its last match; a positive `gapExtend` would otherwise
  credit trailing chars). Brute-force candidates must be long (~20 chars) for
  a path to dip through 0; 8-char candidates never exposed this.
- The "Configurable scoring in SahilmMatcher" enhancement above is DONE:
  `SugarCraft\Fuzzy\SahilmScoring`, injected as the 2nd constructor arg.
- `ScoringProfile::default()` / `FuzzyMatcherFactory::create()` are deprecated
  forwarding aliases of `canonical()` / `named()` (banned factory spellings).
  `default()` stays until candy-lister's `FuzzyMatch` migrates.

