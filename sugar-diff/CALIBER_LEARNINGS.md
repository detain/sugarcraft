# Caliber Learnings — sugar-diff

<!--
  Accumulated lessons from implementing and maintaining sugar-diff.
  Add entries as they are discovered — do not delete anything.
-->

## Provenance (2026-09-30)

Ported from candy-code: `src/Tools/Concerns/BuildsUnifiedDiff.php` (LCS →
unified `diff -u` engine) and the pure region/numbering model of
`src/Tui/DiffGutter.php` (`number()` / `isFileHeader()` / `numberable()` →
`Reader\UnifiedScan`). The gutter's fixed-width ANSI formatting is VIEW code
and deliberately stays downstream in candy-code.

## Key Insights

- **Defaults are the contract.** `DiffOptions::new()` must reproduce the
  candy-code trait byte-for-byte — every downstream pin (EditTest, WriteTest)
  is an implicit golden file. New GNU affordances (`\ No newline` markers,
  `/dev/null` labels) ship opt-in only.
- **Tie-break direction matters.** The LCS walk prefers `>=` at the backtrack
  fork, which deletes the old-side line first; flipping to `>` swaps every
  del/ins pair's order and silently breaks diff consumers.
- **`maxLcsCells` fallback is behavioural, not cosmetic.** Over budget the
  middle collapses to delete-all-then-reinsert-all; candy-code relies on it
  to bound memory on huge files. Test fixtures trip it with a tiny budget.
- **Empty hunk sides anchor at 0** (`@@ -1,1 +0,0 @@`, git's shape) —
  inherited from the source, NOT GNU's mid-file zero-length convention.
  SUPERSEDED 2026-11-03: the docs-fix era made headers GNU-faithful — a
  zero-length mid-file side now anchors at the line the hunk sits AFTER
  (`@@ -1,0 +2 @@`, oracle-verified vs `/usr/bin/diff -U0`, pinned by
  `tests/GnuHunkHeaderParityTest.php`), GNU never drops a `,0`, and single-line
  ranges elide their count (so even the old example string would print
  `@@ -1 +0,0 @@` today). The quoted 0-anchor/always-`,1` shape must never be
  restored: it is the pre-GNU-faithfulness ported rule, inverted from current
  behaviour.
- **Newline termination is invisible** to the line diff (git `splitLines`
  semantics): `"a\n"` vs `"a"` is empty; the final-newline facts are exposed
  separately as `beforeHasFinalNewline`/`afterHasFinalNewline` for writers
  that want markers.
- **UnifiedScan honours declared counts.** Each `@@` header's `,b`/`,d` are
  budgets (elided means 1); a hunk closes when both are spent and any row
  that would consume an exhausted side — or carries an out-of-vocabulary
  marker — ends the walk fail-closed as `OutsideHunk` rather than being
  numbered. Counters still reset on file headers (`diff `/`index `/
  out-of-hunk `--- `/`+++ `); `--- `/`+++ ` read as content INSIDE an open
  hunk (deleted `-- comment` lines) — the marker column protects every real
  header, in any diff flavour, from being mistaken for content.

## House Notes

- Pure engine: zero user-facing strings → no `lang/` directory (sugar-fuzzy
  precedent). Non-visual → exempt from `.vhs/` demos; README/docs promise none.
- Zero sibling deps (not even sugar-core) — the lib is standalone PHP +
  ext-mbstring, so its composer.json carries no `repositories[]` block.
