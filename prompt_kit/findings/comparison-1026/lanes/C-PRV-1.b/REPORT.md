# C-PRV-1.b — honest /effort and an honest launch tier — DONE

Commit: `bc8289f54427af9bd6e8451f86437a775119f63d` on `cc/C-PRV-1.b` (base 8d9814130), author/committer Joe Huss <detain@interserver.net>, zero trailers. Worktree clean after commit.

## Shipped (11 files, +435/−35)
- `src/Chat.php` handleEffortCommand: non-shaping refusal now branches ShapesReasoningEffort-implementers → `chat.effort.unsupported_model` (names provider+model); off-roster classes keep `chat.effort.unsupported`. withEngineSettings: a SET persisted tier applies only where `EffortGate::verdict(provider,model)` shapes; a non-shaper gets NO tier plus ONE uiOnly transcript row (`chat.effort.settings_unshaped`), deduped via LaunchNotices::reported/markReported; null still means "ask nothing". Docblock 'applies UNCONDITIONALLY' claim flipped to truth.
- `src/Cli/LaunchContext.php` withResolvedReasoningEffort → `array{0: EngineBackend, 1: ?string}`: tier null → [engine,null]; verdict no → [engine UNTOUCHED, launch_unshaped reason]; shapes → [engine-with-tier,null]. backendFor() destructures [$engine] (switch-path silence documented as by-design: shelf is read once at launch).
- `src/Cli/Bootstrap.php` private delegate records the reason via `self::warnLaunchRows([$reason])` — pre-built string, zero new sprintf/emitter (StderrEmitterCensus + BootstrapLaunchFormatConstantsTest unmoved, verified green).
- `lang/en.php`: fixed false claim `chat.effort.shape.request` ("...of every request this pair sends."); added launch_unshaped / settings_unshaped / unsupported_model keys.
- `docs/SETTINGS.md`: hand prose paragraph for `reasoningEffort` outside generated regions (after maxToolSteps); regenerated table row notices.transcriptLimit 47→48 via `tools/gen-settings-doc.php --write`; `--check` rc0.
- §1.8 in-step guard moves: `tests/Providers/EffortGateTest.php` (SHAPE_REQUEST const synced); `tests/Cli/BootstrapLaunchNoticeCapCensusTest.php` (roster += withResolvedReasoningEffort=>1, sum 46→47, CapCensus test figure pin 46→47, cap docblock prose, Bootstrap::LAUNCH_NOTICE_LIMIT 47→48); `tests/Cli/LaunchContextDelegatesTest.php` (reason-SPEAKER re-key: door no longer single-return — pinned at floor: exactly one LaunchContext call, one warnLaunchRows funnel, narrowed return); `tests/Chat/ApplySettingsTest.php` (two effort tests re-pinned on a SHAPING pair — old echo pins pinned the falsified unconditional spec; + new unshaped-switch test proving one-row/dedupe/no-silent-tier; LaunchNotices::reset both sides).
- Tests: `tests/Chat/EffortCommandTest.php` +2 (openai+gpt-4o refuses AND persists nothing; gpt-5-codex pair shapes → set+save). NEW `tests/Cli/LaunchEffortNoticeTest.php` (4T: non-shaper tuple shape, shaper, nothing-persisted, delegate-records-exactly-one-row-deduped).

## DURATIONS DUTY — new test file for weld
- `candy-code/tests/Cli/LaunchEffortNoticeTest.php` (NEW; needs a scripts/parallel-tests-durations.tsv row + suite-figure re-pin by the gate; lane never touched those files).

## Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS)
- `--filter 'Effort'` → Tests: 128, Assertions: 434, OK (Deprecations 2 = tree-wide ambient).
- `--filter 'Bootstrap|Launch'` → Tests: 1068, Assertions: 6152, OK.
- `--filter 'DocFigureProseDrift|TrustKey'` → Tests: 109, Assertions: 4486, OK.
- five-guard → Tests: 133, Failures: 2 = ONLY the ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live — +7T this lane: EffortCommand +2, LaunchEffortNotice +4, ApplySettings +1).
- gen-settings-doc `--check` rc0.

## Disclosed
- `SettingsWriterCensusTest::testConfigJsonIsWrittenOnlyThroughItsKnownDoors` is RED AT BASE (inherited from merged cd835b3f5 P-TABS.d: writeUserConfig site moved chat→buildChat without a re-key). Not this lane's file; weld/repair seam.
- LaunchEffortNoticeTest leaks ONE cosmetic stderr line in-process (the funnel's once-channel writes directly); sibling notice tests assert stderr via child processes; not worth the fork harness.
- C-WEB-2 seam (report-only): the web `EffortHostCommand` must route through EffortGate::verdict/shape too, or web reopens the same set-but-ignored hole.
- ApplySettingsTest second provider-switch nuance: mid-session custom→anthropic re-applies and the tier returns onto the wire (by design — verdict yes); anthropic→custom drops it + one row.

## Round-2 (review fixes, 2026-10-27) — tip 265aa26bf
- MINOR (Chat.php:7786 docblock): false clause "…asks for nothing, row included" dropped — nonsense maps to null in resolvedReasoningEffort, so the $effort !== null door never prints a row. Prose now "…asks for nothing, silently, exactly like a cleared tier." One line, docblock-only; commit 265aa26bf ("C-PRV-1.b: review fixes (round 2)", 1 file +1/-1, %ae=%ce detain, zero Co-Authored).
- NIT (EffortCommandTest:113 gpt-5-codex vs brief's openai+o3): reviewer judged immaterial — NO action, as directed.
- Gates after fix (worktree candy-code, plain pipe, -u LINES -u COLUMNS): `--filter 'Effort'` 128T/434A OK; `--filter 'ApplySettings'` 14T/185A OK; five-guard 133T/15579A Failures: 2 = ONLY the ReadmeSuiteFigureDrift staleness pair (25,537 pinned vs 25,941 live — figure +7T carried by this lane, weld re-pin duty). No DocFigure-style pin accuses the edited sentence (grep: no test pins "asks for nothing"/"row included"); no pin change needed.
