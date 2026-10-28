# C-TUI-2.b — DONE · APPROVE-READY

Tip: d3d5e7825cb7a6ae26a7b6e14082cd10f03d96cb on cc/C-TUI-2.b (base 55d66b158, never pushed, tree clean).

## Files
- create `sugar-diff/src/Writer/SideBySideFormat.php` (rows(Diff) / fromUnified(string), shared pairing rule)
- create `sugar-diff/src/Writer/SideBySideRow.php` (value object: ?Line left / ?Line right + isPaired())
- create `sugar-diff/tests/Writer/SideBySideFormatTest.php` (46 tests / 113 assertions incl 18-fixture parity battery x2 paths)
- modify `sugar-diff/README.md` (Role bullet + API-surface row; README carries no suite figure — nothing to re-pin)

## Suite figures (cwd=sugar-diff, linked vendor, plain pipe)
- before: OK (70 tests, 225 assertions)
- after:  OK (116 tests, 339 assertions)  — lib-local, no durations/suite-figure rows owed

## Guards
- `php tools/check-one-type-per-file.php <2 new src files>`: 0 problems (rc 0)
- php -l all three new files: clean
- author/committer: %ae and %ce each grep-count 1 for detain@interserver.net; zero Co-Authored/Co-developed trailers

## Semantics pinned
Pairing: removals queue, additions take oldest queued partner; leftovers stand alone; context pairs with itself (same Line instance both sides); never pairs across hunk/@@ boundary; `\ No newline` skipped INSIDE a run (parity preserved under withNoNewlineMarker); fromUnified kinds come from UnifiedScan counter movement (no marker re-derivation), text = writer rows minus marker col, mirroring the scan's empty-row-as-context rule; unnumbered rows (headers, @@, uncountable hunks) are boundaries/omissions (fail-closed, per UnifiedScan posture).

## Mutations (cp+md5 restore, no stash)
- M1 annotation-as-boundary → 2 reds (annotation test + its parity fixture) ✓
- M2 no context-run flush → initially GREEN (gap found) → added testRunsSeparatedByContextDoNotPairAcrossIt → now 1 red ✓

## Deviations
- None from brief. Added `isPaired()` convenience accessor on SideBySideRow (pure data, documented).
- rows() walks hunk bodies (not all Diff->lines) so the pairing regions align 1:1 with unified output — required for fromUnified parity.
