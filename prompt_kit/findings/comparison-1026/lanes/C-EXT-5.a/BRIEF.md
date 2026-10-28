# BRIEF — C-EXT-5.a (wave 16)

## PaletteCatalog core (action + prefill rows)
- area: ext · kind: gap · effort S · effort:  · phase: normal
- item: C-EXT-5 (sources: GAP-CMD-08)
- deps: none
- serialized: none
- ruling: none

## Files to create
`candy-code/src/Palette/PaletteCatalog.php`, `candy-code/src/Palette/PaletteEntry.php`, `candy-code/src/Palette/PaletteEntryKind.php`

## Files to modify
none

## Tests
create `candy-code/tests/Palette/PaletteCatalogTest.php`

## Docs / guards
none (one type per file)

## Approach
a pure `PaletteCatalog::rows(list<CommandSpec> $slashRows): list<PaletteEntry>`. It emits every `PaletteAction` row, with order and labels unchanged. It then adds one Prefill row per slash-visible spec that has no `paletteAction`: built-in survivors, file commands with their tier badge, and MCP prompts badged `[mcp]`. A Prefill label is `/<name> <argument-hint>`. The slash prefix means it can never collide with an action label. Rows are deduped. `PaletteEntryKind` is an enum, in its own file.

## Risks
none
