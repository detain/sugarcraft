# W21 — wave loop review 1 (2026-10-29, reviewer; tip reviewed: efb97a1b5)

VERDICT: FINDINGS (0 CRITICAL / 2 MAJOR / 0 minors)

W21 = C-WEB-2.b d54bd76e0 · C-WEB-4.f ddfb9b97b · C-CTX-3.c 0b7cac1c4+5ad8f8572 ·
C-CTX-3.e fdcb23bb1+f2d31b13a · C-CTX-4.f c1fd3757b+f0a3413e4 · C-CTX-4.g b84ed2118 ·
C-EXT-3.e cf017b14c+985c84e07+fdfb6ad96 · C-EXT-4.d 0273a6178+eea50728c ·
C-EXT-5.b 7730f0672+08f72a2df · C-EXT-5.c 3ba0a5ce8+d159b2cfd.
All 18 commits real, ancestors of master, %ae=%ce=detain@interserver.net, zero Co-Authored trailers.
Welds aa38e06b1/b9a3b0fff/965be930f/718aa187f/40e8de2fd/44ff31314/93cfa58b9/ed5e7aa83 carried them;
weld-18/18d/18e worktrees are master-ancestors (shared batch welders, prunable at program close).

## MAJOR-1 — SettingsWriterCensusTest red at tip: the W21-routed roster rows never landed
Repro: `--filter SettingsWriterCensusTest` → 1F at tests/Config/Settings/SettingsWriterCensusTest.php:145;
derived census carries `+ 'Cli/Bootstrap.php::stampBundleRecommendations->write' => 1` (W21's OWN C-EXT-4.d
sink, 0273a6178) and `+ 'Chat.php::persistFast->write' => 1` (W22 C-PRV-3.b sink riding the W21 route —
W18/WAVE-REVIEW-1.md:7 routed both, W18C/BATCH-WELD-18C.md:13 kept it as "standing W21/closeout red").
FIX (master, no in-flight collision — file unclaimed): add the two roster rows with per-row verdict
comments at the write-sites map beside 'Chat.php::persistEffort->write' (its sibling shape).

## MAJOR-2 — `/fast` (C-PRV-3.b, landed in this wave's batch-18a) roster carry never landed — 4 reds at tip
Same shape as the W15 r1 MAJOR (/pr), which the wave loop cured in 8dab423f0. The REPORT said "no /fast
palette action declared (sibling /effort precedent)" but the sibling precedent REQUIRES roster rows; none
shipped. Reds (deterministic, isolated 1F each):
- tests/Chat/PaletteSupersetDispatchTest.php:200 (testThePaletteIsTheSupersetAndTheSurvivorsAreExactlyTheJudgedSet):
  derived survivors list has 'fast' at index 1, roster lacks it. FIX: add
  `'fast' => 'bare /fast reads the lane; on|off takes the argument — /effort sibling judgement'` to
  SLASH_ONLY_SURVIVORS (:69) after 'effort'.
- tests/Tui/Menu/ShellMenuTest.php:108 (EveryRegistryRowIsPlacedExactlyOnce...) + :N (NoMoreSubmenu...) —
  'fast' absent from src/Tui/Menu/ShellMenu.php placement table. FIX: place it beside 'effort' per R-MENU-1.
- tests/Commands/MenuBarLabelDerivationTest.php (testEverySlashOnlyRowReadsAsThisTable) — FIX: carry the
  derived-label row for 'fast' in the same commit.
(Plus the SettingsWriterCensus persistFast row = MAJOR-1; one fixer commit can land all five rows.)
Attribution: rows belong to C-PRV-3.b's (W22) carry; W21 loop is the live loop and the family-reds rode
W21's own batch-18a weld (which also carried W21's C-EXT-5.c) — W14/W15 precedent = current loop's fixer.

## Verified clean (do not re-litigate)
- Generated surfaces at HEAD: `scripts/gen-protocol-schema.php --check`, `tools/gen-command-docs.php
  --check`, `gen-settings-doc`, `gen-tool-docs` (all "up to date"); `npm run gen:protocol -- --check`
  "generated.ts is up to date"; dist bundle carries instructionWrite (fresh vs batch-18b rider a82e19a4e).
- Protocol lanes C-WEB-4.f + C-CTX-3.c coherent in Definitions.php/MethodSchemas.php/ServerContext.php
  beside landed C-AGT-4.c (18a) — trio 33T + ServerProtocolDocumentationDrift + ProtocolSchemaDrift green.
- durations.tsv: all 14 new *Test.php files carry rows (grep-verified rows 64..946).
- Drift guards green at tip: ReadmeRoster/EnvRoster/TrustKey/ConfigWriteProducer/KeyBinding (299T OK).
- Wave test families green merged: palette prefill/keybinding-dispatch, memory export/import, skills
  learned/workshop/undo, skill-slash served, references, append-system-prompt, bundle-notice (272T across
  two filter runs, sole reds = MAJOR-2 roster arms).
- Docs coexistence: MEMORY.md carries C-CTX-3.e export section + C-CTX-3.f standing-intent + C-CTX-3.c
  quick-add lines; SETTINGS.md references/keybindings rows gen-consistent.
- No new proc_open/fork/timeout lines in any wave diff (child-lifetimes + E646 uncharged).
- Standing, NOT findings: ReadmeSuiteFigureDrift/README staleness pair (§7 closeout); ContainedPathInventory
  x2; ForkedChildReaperAdoption x1 (BackgroundShellRegistryTest, C-TOOL-3.c era, per 18c roster);
  ReadPathCensus x1 at tip is the NEW foreign 'Session/Import/RemoteSessionPush.php|file_get_contents' row
  from batch-18g C-SES-7.g (W25 — not W21; Chat fgc row already cured at 5406bce93); MediaSettingsTest x1;
  BootstrapLaunchFormatConstants doc-roster arm x1.

Fixer note: all fix touches (SettingsWriterCensusTest.php, PaletteSupersetDispatchTest.php,
ShellMenu.php, MenuBarLabelDerivationTest.php) are OUTSIDE every in-flight cc/* claim region.
