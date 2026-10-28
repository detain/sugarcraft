# BRIEF — C-SEC-8.c (wave 16)

## `Sanitize::stripInvisibleFormatting()` in sugar-core
- area: sec · kind: gap · effort S · effort:  · phase: normal
- item: C-SEC-8 (sources: GAP-UI-N5)
- deps: none
- serialized: none
- ruling: none

## Files to create
`sugar-core/tests/Util/SanitizeStripInvisibleFormattingTest.php`

## Files to modify
`sugar-core/src/Util/Sanitize.php`

## Tests
the new test covers the always tier removed, a ZWJ inside an emoji kept, `pay\u{200D}pal` stripped, the count, and empty input.

## Docs / guards
the sugar-core suite. A `Mirrors` citation is not needed; this is a SugarCraft original.

## Approach

- `Sanitize::markInvisibleFormatting()` (`sugar-core/src/Util/Sanitize.php:359`) only marks. It has a two-tier rule: bidi, ZWSP, WJ and BOM are always marked, while ZWJ/ZWNJ/LRM/RLM/ALM are kept after a non-ASCII byte.
- Add a sibling `stripInvisibleFormatting(string $s, ?int &$removed = null)`. It applies the same tiers, deletes instead of marking, and reuses `invisibleFormattingMaps()` so the two can never disagree.

## Risks
candy-code consumes it in 8.d. In Packagist mode the new method is not in vendor until sugar-core publishes, so 8.d's lane must run `refresh-deps.php --mode=linked`.
