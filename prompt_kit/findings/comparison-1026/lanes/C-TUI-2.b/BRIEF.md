# C-TUI-2.b — sugar-diff side-by-side writer
wave: 15 (from plan §3) · area: tui · gap · effort S
deps: none · serialized: none · ruling: none

## Item
C-TUI-2 (sources: GAP-UI-05)

## Files to create
`sugar-diff/src/Writer/SideBySideFormat.php`; `sugar-diff/src/Writer/SideBySideRow.php` (one type per file)

## Files to modify
`sugar-diff/README.md` (writer list)

## Tests
create `sugar-diff/tests/Writer/SideBySideFormatTest.php` (pairing, uneven runs, context passthrough, `fromUnified` parity with `rows()`)

## Docs/guards
None
None

## Approach
sugar-diff writes unified output only (`sugar-diff/src/Writer/UnifiedFormat.php`). Add `SideBySideFormat`:
- `rows(Diff)` and `fromUnified(string)`, the latter over `Reader/UnifiedScan`.
- Both return paired `[left?, right?]` rows. Each side carries its line number and kind, so a removed/added run pairs line by line and the leftover lines stand alone.
- It is pure data. Width and paint belong to the caller.


## Risks
none notable.
