# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:fuzzy-smith-waterman-two-row]** FuzzyMatcher uses a two-row DP matrix instead of a full O(m×n) table for Smith-Waterman local alignment scoring. Rows are swapped (not copied) on each query-character iteration, keeping space at O(c) where c is candidate length. Adjacent consecutive matches receive a +5 bonus; mismatches cost -3. This is the canonical pattern for any in-memory fuzzy string scoring in PHP where memory must stay bounded.
