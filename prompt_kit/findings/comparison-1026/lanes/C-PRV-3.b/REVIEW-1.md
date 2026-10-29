# C-PRV-3.b — /fast — REVIEW-1 (fresh reviewer, diff + live source only)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 0 MINOR / 2 NIT. Nothing weld-blocking; lane may proceed as CLEAN under §1.6 (exit condition is 0C/0M).

## Verified on disk (worktree /home/sites/cc-cmp-C-PRV-3.b @ 3d49d2488, base 86f80fef8)
- Scope: exactly the 10 files the block names + the two §1.8 guard carries (ARCHITECTURE.md, MenuBarLabelDerivationTest.php); no forbidden files (suite-figure.json, durations.tsv, dist/, protocol generated outputs all untouched). Author A/C both detain@interserver.net, zero Co-Authored trailers.
- Semantics per brief: bare reports (no-backend / ON {provider,model} / OFF-saved / OFF-unset ± eligibility); `on` arms EngineBackend::withServiceTier(WORD_FAST) overlay AND persists user tier via SettingsWriter::write(You, ['serviceTier'=>…]) — persistFast mirrors persistEffort (Chat.php:12883) byte-for-byte in shape, deliberate no-delete-arm documented; `on` refuses out loud with nothing landing when the carried pair shapes no lane; FallbackProvider named `fallback(...)` mirroring LaunchContext::withResolvedServiceTier (:2278, wrapper leg :2301). READ_ONLY_COMMANDS += 'fast' with sibling-/effort justification docblock. FastHostCommand: overlay-only, zero SettingsWriter references in the file; bare read admitted in readOnly, sets refused (host.command.read_only), effect = CommandEffect::setModel with names restated — check order byte-faithful to ModelHostCommand.
- Registration: builtin-commands/0360-fast.php, slot 0360 free at base (per-command file, §2-free); handler handleFastCommand/Parsed + withHostCommand pinned by testTheCommandIsRegisteredWithBothSurfaces.
- Gates (my runs, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe):
  - --filter 'FastCommand|FastHostCommand': OK, 27 tests, 151 assertions (2 = BashTimeoutTest filter-overlap tests; lane classes = 18+7).
  - --filter '…|SubcommandsEffort|Effort|ReadmeRosterDrift|CommandCatalogue|SlashDispatch|MenuBar': 276T/2670A, 1F = MenuBarLabelDerivationTest::testEverySlashOnlyRowReadsAsThisTable — diff shows ONLY foreign 'instructions'+'commit' rows missing (both spec files proven at 86f80fef8 via git show); lane's own fast row passes.
  - --filter 'CommandDocsDrift|DocFigureProseDrift|RegistryTranslation|ConfigWriteProducerDocumentationDrift|CommandFailureTranscript': 128T/5034A, 1F = CommandFailureTranscriptTest 'line 13825: echo' — tip-shifted twin of base :13676 `echo: true` named-arg in cyclePrimaryAgent (git show 86f80fef8 content-matched; lane diff adds no echo). Both disclosed foreign base-reds reproduce and are NOT-lane.
  - 'BuiltInCommands|EnvRosterDrift': 47T/40343A OK. php -l all 10 files clean.
  - ARCHITECTURE 62 re-derived by DocFigureProseDriftTest:717-718 live (regex vs CommandRegistry::slashCommands()) — green, not hand-bumped.
- Mutation-reasoning: persist removal → testOnAppliesTheLaneLiveAndSavesTheWordOnce readUserConfig assert reddens; refusal-door removal → 4 refusal tests (serviceTier null + assertArrayNotHasKey + wording) redden; served settings-write → config-before/after byte compare + 2 arrayNotHasKey redden; effect-leg removal → SetModel effect pins redden. Hand-traced edges: `/fast on now` (usage), `/fast   ON ` (case/trim), null-engine off still persists, wrapper refusal, ui-only rows.

## NITs
- NIT (out-of-scope, report file only): REPORT.md:10 claims "16 tests"/"9 tests" per file; actual on-disk counts are 18 (FastCommandTest) + 7 (FastHostCommandTest) — total 25 correct. Fix: welder/closeout corrects the report lines; no code change.
- NIT (step-local, take-or-leave): the note_failed arm (writer->write throws) is unpinned in both suites — consistent with the /effort sibling (EffortCommandTest pins it neither) and the design's test list only requires "persists only on success". No action needed; if taken, a throwing-doubles SettingsWriter pin would close it.

## Carries (recorded for welder/gate, not findings)
- durations.tsv rows owed for candy-code/tests/Chat/FastCommandTest.php and candy-code/tests/Host/Commands/FastHostCommandTest.php (unlisted files run zero shards).
- suite-figure + README pair re-pin: lane adds +25T (measured 27 incl. 2 filter-overlap; class-true 25) / class assertions 151 on its filter — closeout re-pin on the merged tree; pinned pair already stale pre-lane (report read 25,537 vs 28,093 live). Staleness pair observed as expected shape.
- Locale carry: lang/en.php is the ONLY file in candy-code/lang/ at this base — EN-only additions cost no parity guard today; C-TUI-6.h locale wave must carry cmd.fast.*/host.fast.* to all 11 codes when it lands (disclosed in REPORT.md).
- Foreign reds for their owning lanes/closeout: MenuBarLabelDerivationTest instructions+commit rows; CommandFailureTranscriptTest echo: true (C-AGT-4.b region, Chat.php:13676 base / 13825 tip).
