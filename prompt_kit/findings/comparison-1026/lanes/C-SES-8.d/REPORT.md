# C-SES-8.d — /insights command wiring — lane REPORT (live)

## State: BUILD LANDED (skeleton commit)
- Branch cc/C-SES-8.d @ worktree /home/sites/cc-cmp-C-SES-8.d, base 410f5ac58.
- Commit 4cd94890317e48b59a944b981addb630b3c73e9b (cat-file-verified 'commit'):
  "C-SES-8.d: wire /insights — 2215 spec, Chat handler, read-only row, docs"
  7 files: builtin-commands/2215-insights.php (new), lang/en.php
  (cmd.insights.description + cmd.insights.hint, alphabetical slot),
  src/Chat.php (handleInsightsCommand after handleUsageCommand +
  'insights' in READ_ONLY_COMMANDS), README.md + docs/COMMANDS.md
  (gen-command-docs --write, marked regions only),
  tests/Commands/MenuBarLabelDerivationTest.php ('insights' =>
  'Show insights' row), tests/Chat/PaletteSupersetDispatchTest.php
  (survivor row + spec-recorded judgement comment).
- Derived label re-derived live off CommandRegistry::all(): "Show insights"
  (unique, fits 22-cell budget). RegistryTranslationTest NOT touched —
  verified in-step it enumerates live, counts no rows (design's "if" arm).
- Gates so far: Commands filter MenuBar|RegistryTranslation|BuiltInCommands|
  SlashDispatch 49T/2050A OK; CommandDocsDrift|ReadmeRosterDrift|
  PaletteSuperset|Insights 40T/453A OK (both pre-existing "PHPUnit
  Deprecations: 2" + bypass-permissions env noise unchanged).
## TODO in-lane: five-guard set, mutation proof, protocol-trio check, base
## red attribution, final figures.

## CLOSED — final state (2026-10-29)
- FINAL COMMIT 38f097f4b121e027704065c8f1dfb33f12cdc67d (cat-file: commit;
  amend folded docs/ARCHITECTURE.md 62->63 (slash-count) into the step).
  8 files, author+committer detain (separate greps 1/1), zero Co-Authored.
  Supersedes skeleton 4cd948903 (also real: cat-file 'commit').
- READ_ONLY_COMMANDS: 'insights' row added beside the usage/status family
  ("the way /export is" per design — reads stores, writes only an export
  file). handleInsightsCommand sits after handleUsageCommand, thin
  runHostCommand delegate.
- lang: cmd.insights.description + cmd.insights.hint ([days]) en.php only
  (candy-code ships one locale; alphabetical slot kept).
- MUTATION PROOF: renaming handleInsightsCommand -> exactly 1 error at
  SlashDispatchTest::testEverySlashVisibleRegistryRowHasALiveDispatchHandler
  (dispatch through Chat.php:2067); cp-restore md5 ecd40bf0 verified green.
- GATES (measured, cwd=candy-code -c phpunit.xml, plain pipe):
  Commands+dispatch family 89T/2503A OK; guard set
  134T/17184A with ONLY the two foreign staleness reds
  (ReadmeSuiteFigureDrift 25537-vs-live-28402 — live identical at base,
  lane adds zero test files/methods); deprecations x2 pre-existing.
- PROTOCOL TRIO: gen-protocol-schema --check "up to date" — command specs
  do not enter the RPC schema; zero trio diff.
- WELD CARRIES: durations.tsv rows NEEDED: NONE (no new *Test.php).
  ARCHITECTURE 63 numeral + README/COMMANDS rosters all landed in-lane.
- Foreign standing reds NOT reproduced in this lane's filters:
  PermissionsIntroDrift/ForeignAgentPresetWiring/SwallowingCatch/
  ReadPathCensus/ChatHostLangCatalogue not in touched families.
