# Caliber Learnings — sugar-diff

<!--
  Accumulated lessons from implementing and maintaining sugar-diff.
  Add entries as they are discovered — do not delete anything.
-->

## Provenance (2026-09-30)

Ported from sugar-crush: `src/Tools/Concerns/BuildsUnifiedDiff.php` (LCS →
unified `diff -u` engine) and the pure region/numbering model of
`src/Tui/DiffGutter.php` (`number()` / `isFileHeader()` / `numberable()` →
`Reader\UnifiedScan`). The gutter's fixed-width ANSI formatting is VIEW code
and deliberately stays downstream in sugar-crush.

## Key Insights

- **Defaults are the contract.** `DiffOptions::new()` must reproduce the
  sugar-crush trait byte-for-byte — every downstream pin (EditTest, WriteTest)
  is an implicit golden file. New GNU affordances (`\ No newline` markers,
  `/dev/null` labels) ship opt-in only.
- **Tie-break direction matters.** The LCS walk prefers `>=` at the backtrack
  fork, which deletes the old-side line first; flipping to `>` swaps every
  del/ins pair's order and silently breaks diff consumers.
- **`maxLcsCells` fallback is behavioural, not cosmetic.** Over budget the
  middle collapses to delete-all-then-reinsert-all; sugar-crush relies on it
  to bound memory on huge files. Test fixtures trip it with a tiny budget.
- **Empty hunk sides anchor at 0** (`@@ -1,1 +0,0 @@`, git's shape) —
  inherited from the source, NOT GNU's mid-file zero-length convention.
- **Newline termination is invisible** to the line diff (git `splitLines`
  semantics): `"a\n"` vs `"a"` is empty; the final-newline facts are exposed
  separately as `beforeHasFinalNewline`/`afterHasFinalNewline` for writers
  that want markers.
- **UnifiedScan counters reset on file headers**; `--- `/`+++ ` read as
  content INSIDE an open hunk (deleted `-- comment` lines), which is safe
  because git always emits `diff --git `/`index ` ahead of a real header.

## House Notes

- Pure engine: zero user-facing strings → no `lang/` directory (candy-fuzzy
  precedent). Non-visual → exempt from `.vhs/` demos; README/docs promise none.
- Zero sibling deps (not even candy-core) — the lib is standalone PHP +
  ext-mbstring, so its composer.json carries no `repositories[]` block.
