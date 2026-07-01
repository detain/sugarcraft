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
