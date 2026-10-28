# W7 wave review (round 1, fresh reviewer, 2026-10-27)

VERDICT: FINDINGS
(1 MINOR + closeout carries; zero CRITICAL/MAJOR)

## Scope
W7 roster derived from plan §3 wave table (line 143): P-CTX.e · P-AGENT.c · P-CTX.g · C-AGT-7.a · C-AGT-2.b · C-AGT-3.b · C-EXT-7.b · C-SEC-5.a · C-SES-6.a · C-SES-9.a. Weld point 33d015d4a; battery run on the master worktree (HEAD c8346f674 = tip; W7 picks all ancestors; post-W7 C-EXT-7.a commits sit above and did not mask any check).

## Axis 1 — ✅ marks / ancestry / authors
All 10 §4 marks resolve: 66559688e e68d0e69b 1ce007b2d f2db15f0a bb1f488d1 09da7d3e9 066d6e8ce b20852ec8 60bc8f0a4 98b862e9f — every one is an ancestor of 33d015d4a, `%ae`/`%ce` = detain@interserver.net on all 10, zero Co-Authored trailers (per-commit grep = 0). Round-2 fixer picks all landed: d4e814e87 (P-CTX.e), 4d960c382 (C-AGT-7.a), 455025890 (C-SEC-5.a), d4d3e808c (C-SES-6.a), 7971828b8 (C-SES-9.a), 19bdf571b (P-CTX.g hardening). Weld records exist per step; P-AGENT.c protocol regen commit 2392ed1d2; P-CTX.g dist rebuild 8622554ea. §3 line 143 correctly still lacks the ✅ W7 mark (loop in progress).

## Axis 2 — Cross-lane coherence + generated chain
Generated chain CURRENT at tip: gen-command-docs / gen-tool-docs / gen-settings-doc / gen-protocol-schema all `--check` rc0. Chat.php three-region claims coexist intact (SCHEDULE_WATCH_SUBSCRIPTION ×2, swapPermissionGate/reviewModeRestore ×10, ctxmeter handler ×1 + Renderer zone ×2). lang/en.php: zero duplicate keys tree-wide (uniq -d clean); cmd.review.clamped, cli.bundles.install.dot_name, server.trust.ask.*, host.schedules.*, keys.contextPane.* all present once. In-step guard flips all landed: KeyBindingDriftTest contextPane.expand observations (+ registry, 459T shell filter green); INERT[AGENT] emptied (FrontmatterKeyAudit.php:127); PromptFence twelfth tag agent-memory (:171/:194 + docs/PROMPT_ENGINEERING.md:376); ReadPathCensus CONTAINED_UPSTREAM:Cli/McpDecision.php rows (:302/:650); SettingsWriterCensus 'Cli/Bootstrap.php::grantProjectTrust->grantTrust' (:150); SuiteSkipRoster PHAR_RENAME in both roster files. P-CTX.e's disclosed foreign ShellContrast colour-adjudication debt is CURED on current master (green in battery).

## Axis 3 — Wave battery (plain pipe, cwd candy-code, env -u LINES -u COLUMNS)
- 'AgentsColorAndInterrupt|ProtocolSchemaDrift|ServerProtocolDocumentationDrift' → 16T/350A OK
- 'ReviewCommand|SessionHostModeCeiling|ProjectTrust|BootstrapProjectTrust|McpDecision' → 41T OK
- 'AgentMemory|TaskToolAgentMemory|ForeignAgentPresetRegistry' → 71T/245A OK EXACT vs lane gate
- 'BinCandyCodeAutoloadGuard|PharCompat|SuiteSkipRoster|UsageRecords|BundleInstall' → 118T/626A OK, 1S (heavy phar arm, expected skip)
- 'ContextPane|MenuBar|KeyboardHandler|KeyBinding' → 459T/18077A OK
- 'Schedule|ForeignAgentPresetWiring' → 135T/592A, 1F = DreamPassTest.php:288 (allowed foreign)
- five-guard → 133T/15809A, 2F = staleness pair ONLY (pinned 25,537 vs live 26,270 — closeout re-pin)
- 'ChatHostLangCatalogue|RuntimeNoticeSinkDelivery|ShellContrast' → 42T, 1F = chat.effort.launch_unshaped exactly (allowed foreign)
- vitest serial contextPanel+statusBar+generated.spec → 3 files / 39 tests passed
Every red observed is on the allowed list; no unexpected reds.

## Axis 4 — Claims vs landing
All 7 DUR-listed new test files exist on master: tests/Tui/ContextPaneShellTest.php, tests/Server/AgentsColorAndInterruptTest.php, tests/Host/SessionHostModeCeilingTest.php, tests/Memory/AgentMemoryTest.php, tests/Tools/TaskToolAgentMemoryTest.php, tests/Config/Trust/ProjectTrustCensusTest.php, tests/Cli/BootstrapProjectTrustGrantTest.php. All recorded as run-state carries (durations_owed / DUR duty entries; P-CTX.g/C-AGT-7.a/C-EXT-7.b/C-SES-6.a/C-SES-9.a correctly list no new files — P-CTX.g entry confirms "No new PHP files: no durations duty").
[MINOR] candy-code/src/Session/EnhancedSessionStore.php:1987-1989 + :2376-2378 — C-SES-9.a's tombstone gate covers appendUsageRecord only; saveTranscript and appendSessionEvent still lazily `createSession($id,'candy-code','unknown')` for missing rows, so the same resurrection class the step's title promises gone ("no resurrection") remains open on the two sibling write paths. Already disk-tracked ("wave-loop/fixer owed", run-state + weld note 0f6dd7506); fix = consult sessionTombstoned() before the lazy mint on both paths (mirroring the usage-ledger polarity pins), with one red-first test per path in UsageRecordsTest.php.

## Axis 5 — Hygiene
No W7-member worktrees or cc/ branches remain (only live later-wave lanes: cc/C-AGT-7.b, cc/C-EXT-7.a). Across all 16 W7 step/fix commits: zero touches to suite-figure.json or parallel-tests-durations.tsv. Weld regen commits carry the generated-file duty.

## Closeout carries
1. durations.tsv rows for the 7 new W7 test files (files exist, rows 0 — gate duty).
2. suite-figure + README staleness-pair re-pin (25,537 → live, currently 26,270).
3. C-SES-9.a MINOR resurrection fix (or owner ruling to scope the step's title to the usage ledger).
4. Record in §3 `✅ W7` only after the MINOR is cured or ruled; nothing else blocks.
