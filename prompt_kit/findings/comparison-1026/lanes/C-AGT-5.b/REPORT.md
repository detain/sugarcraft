# C-AGT-5.b — DONE · commit 5f551912e (1 over base 8fd05587a, branch cc/C-AGT-5.b, unpushed)

Shipped: ScheduleFactory::deleteRefusal (root-scoped per owner ruling §6.3: same-root model free; cross-root model needs include_operator_rows; operator rows keep the byte-frozen legacy sentence; /schedules delete human surface unchanged) — closes C-AGT-5.a r1 MINOR-2. ScheduleTool::deleteRow routes through it; docblock/description/inputSchema spell root-scope. Registry fireDue RECORDS every outcome (fired/error/skipped-foreign/-busy/-locked/-stale, in-memory) + lastOutcome()/lastOutcomes(); BackgroundEvents pumpSchedules docblock truth-flipped. /schedules add <name> <interval> <prompt> [--mode][--provider] via the factory (READ_VERBS stays ['list'] → mutating, read-only-refused via existing gate); listing appends ' — last: {outcome}'. en.php cmd.schedules.*/host.schedules.* (en-only). docs/SCHEDULES.md flips + gen-command-docs --write (COMMANDS.md row; --check rc0). Web: BackgroundPanel.vue Schedules subsection (thin command.exec /schedules client, Enable/Disable/Delete gated on write scope) + spec.

NEW test files (welder mints durations rows): candy-code/tests/Background/ScheduleCrossRootDeleteTest.php (8T/25A); candy-code-web backgroundSchedules.spec.ts (5 tests).

Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS): schedule family '…|ScheduleLoopFire|ScheduleCrossHostFire|ScheduleRegistry' 93T/396A OK; amended sanity 58T/238A OK; CommandDocs|ReadmeRoster|DocFigure 107T/4115A OK; GlobDialect|DupTestHelper|DenialPrefixRoster 45T/718A OK; StderrEmitter|ReadPathCensus 114T/22050A OK; vitest 16/16 + vue-tsc rc0; Deprecations:2 = standing house noise.
Red-before: base-src overlay → 'Tests: 31, Errors: 7, Failures: 9' (unknown add verb, undefined deleteRefusal/lastOutcome) → restore mine → 'OK… 31/135'.

Standing reds observed, NOT mine (identical at base): ChatHostLangCatalogue unasked chat.* keys 1F; MenuBarLabelDerivation 'pr' row + PaletteSuperset missing bg/shells/loop/fork/websearch rows 2F (foreign in-step flips incomplete at 8fd05587a — touchers 734f97dda/8fd10d4cf/9dd900bff). I flipped only my own schedules row (in-step law).

DEVIATIONS:
1. ⚠ LEAD SWEEP INCIDENT: at 10:34:02 a process committed 8fd05587a and effectively ran checkout/clean over candy-code/tests in MY worktree — reverted uncommitted test edits and deleted the untracked new test file. Src/lang survived; everything rebuilt green, but lanes' uncommitted work is at risk under this pattern.
2. /schedules list stays line-based with ' — last:' suffix, not a TranscriptTable rebuild: tool list reuses listing() with no pane width, and the table rework breaks pins in non-ownership files.
3. Outcome recording lives inside fireDue (one record path for TUI + serve pumps); BackgroundEvents change is docblock-only.
4. builtin-commands/2910-schedules.php file unchanged — hint is a Lang value, flows via regen.
5. No --include-operator-rows flag on human /schedules delete (ruling: operator rows unchanged).
6. /schedules add rows are model-tagged → share the per-project model cap (disclosed in code comment; conservative).
7. MenuBarLabelDerivationTest row flip added beyond plan file list (law-required).
