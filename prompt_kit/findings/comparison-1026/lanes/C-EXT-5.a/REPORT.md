VERDICT: C-EXT-5.a DONE — PaletteCatalog core landed, one commit, all gates green.
Tip: d37514fe828a812343053713a6ed1bcf761cc789 on cc/C-EXT-5.a (base e4a705244; authors %ae+%ce detain, zero trailers; NEVER pushed).
Files (all created, none modified):
  candy-code/src/Palette/PaletteCatalog.php   — pure rows(): action segment first (PaletteAction::all() order/labels byte-identical), then one Prefill row per slash-visible spec WITHOUT paletteAction; dedupe on first-seen label; badge = CommandSpec::tierBadge() verbatim (built-ins/MCP get null/user/[mcp]).
  candy-code/src/Palette/PaletteEntry.php     — final readonly row (kind,label,category,shortcut,badge,commandName).
  candy-code/src/Palette/PaletteEntryKind.php — enum Action|Prefill.
  candy-code/tests/Palette/PaletteCatalogTest.php — 8 pins: order/labels+purity, survivor 'status', no double-emit for action specs, file tier badge, MCP tier badge, no-hint label, hidden+dedupe first-wins, slash-prefix collision impossibility + uniqueness.
NEW *Test.php (gate owes durations.tsv row): candy-code/tests/Palette/PaletteCatalogTest.php.
Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS):
  RED-BEFORE: 8 errors "Class ...PaletteCatalog not found" (Tests: 8, Errors: 8).
  Targeted:    OK (8 tests, 401 assertions).
  Guards:      GlobDialect+DuplicatedTestHelperDrift+TreeWideGuardRoster OK (44 tests, 4087 assertions).
  repo:        tools/check-one-type-per-file.php → 62 libs, 3238 files, 0 problems.
Mutations (cp+md5 restore clean, cf5a4ac6): drop paletteAction-guard → exactly 1F (no-double-emit pin); neuter slashVisible → exactly 1F (hidden-row pin).
Deviations: none from the design block. suite-figure/durations/dist untouched; no protocol surfaces in file-set (no regen); no Lang::t needed (zero new user strings); test literals carry no glob-shaped brackets (badges asserted via $spec->tierBadge() computed).
