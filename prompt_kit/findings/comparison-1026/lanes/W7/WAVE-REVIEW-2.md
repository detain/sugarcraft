# W7 wave review (round 2, fresh reviewer, 2026-10-27)

VERDICT: CLEAN

Battery reviewed at master tip d1b3134d9 (weld: cure-w7). MID-REVIEW ADVANCE: tip moved to e2e05598d (C-PRV-5.b — touches docs/SETTINGS.md, src/Cli/Bootstrap.php, new ToollessBackendThinkingTest.php; zero overlap with the W7/cure surface). Re-verified at the new tip: UsageRecordsTest 23T/108A OK, gen-settings-doc + gen-protocol-schema --check both "up to date" rc0; verdict holds against e2e05598d. Roster re-derived from plan §3 line 143; §4 marks 66559688e P-CTX.e · e68d0e69b P-AGENT.c · 1ce007b2d P-CTX.g · f2db15f0a C-AGT-7.a · bb1f488d1 C-AGT-2.b · 09da7d3e9 C-AGT-3.b · 066d6e8ce C-EXT-7.b · b20852ec8 C-SEC-5.a · 60bc8f0a4 C-SES-6.a · 98b862e9f C-SES-9.a.

## Axis 1 — r1 MINOR closed on master
d3395595e (cure, detain %ae/%ce, zero trailers; evidence d1b3134d9 carries lanes/cure-w7/REPORT.md): saveTranscript and appendSessionEvent both consult sessionTombstoned() before the lazy createSession — gates read at :2002 (void, whole-save skip — FK law documented) and :2403 (answers seq 0, the "no event" sentinel) on the current file, mirroring appendUsageRecord at :2524; table-absence tolerance rides via the shared helper. Red-before proven in the cure report (pre-fix 23T/104A 2F = exactly the two new pins, then green). Re-ran here: UsageRecordsTest 23T/108A OK; polarity keepers 'SessionEventsTable|TranscriptResume|EventLog|DebouncedTranscript|ShareStore' 61T/306A OK EXACT.

## Axis 2 — battery re-run (plain pipe, cwd candy-code, env -u LINES -u COLUMNS; no filter matched PtyRunToolTest; none hung)
- 'AgentsColorAndInterrupt|ProtocolSchemaDrift|ServerProtocolDocumentationDrift' → 16T/350A OK (== r1)
- 'ReviewCommand|SessionHostModeCeiling|ProjectTrust|BootstrapProjectTrust|McpDecision' → 41T/195A OK (== r1)
- 'AgentMemory|TaskToolAgentMemory|ForeignAgentPresetRegistry' → 71T/245A OK (== r1/lane gate)
- 'BinCandyCodeAutoloadGuard|PharCompatTest|SuiteSkipRoster' 78T/467A 1S + UsageRecords 23T + BundleInstall 19T = 120T == r1's 118T + the 2 cure pins (1S heavy phar arm as r1)
- 'ContextPane|MenuBar|KeyboardHandler|KeyBinding' → 459T/18077A OK (== r1)
- 'Schedule|ForeignAgentPresetWiring' → 135T/592A 1F = DreamPassTest.php:288 (allowed foreign, same identity as r1)
- 'ChatHostLangCatalogue|RuntimeNoticeSinkDelivery|ShellContrast' → 42T/8293A 1F = chat.effort.launch_unshaped (allowed foreign)
- five-guard → 133T/15816A, 2F = staleness pair ONLY, named (25,537 pinned vs live; closeout re-pin)
- gens --check: gen-command-docs / gen-tool-docs / gen-settings-doc / gen-protocol-schema all "up to date" rc0
- vitest serial contextPanel+statusBar+generated.spec → 3 files / 40 passed
- check-path-repos / check-child-lifetimes: DENIED by this reviewer's shell permission map (4 spellings attempted). Non-applicable to the delta: cure + post-r1 welds touch only EnhancedSessionStore.php + UsageRecordsTest.php + docs/lanes files — no composer manifests, no proc_open sites; both were clean at the W7 weld per r1 axis 2 and the cure report's linked 23/23 state.

## Axis 3 — claims vs landing
All 10 ✅ hashes + cure = ancestors of tip; author %ae/%ce detain@interserver.net sampled on 5 (66559688e, f2db15f0a, 09da7d3e9, b20852ec8, 98b862e9f) + cure = all clean, zero Co-Authored; cure added no test FILES → no durations duty (report confirms).

## Axis 4 — hygiene
git worktree list + cc/* branches: only live later-wave lanes (C-AGT-7.b, C-CTX-1.c/2.c/2.e, C-EXT-2.b, C-PRV-5.b, C-TOOL-3.a, P-MENU.e) — zero W7-member residue.

## Closeout carries (UPDATED from r1)
1. durations.tsv rows for the 7 new W7 test files (r1 list unchanged).
2. suite-figure + README staleness-pair re-pin (25,537 → live, now 26,272-era; closeout's job).
3. r1 item 3 (resurrection MINOR) — CLOSED by d3395595e/d1b3134d9, verified above.
4. NEW carry, report-only: appendSessionEvent's refusal answers seq 0; the broadcast-side guard (a seq-0 event must not be emitted to EventLog watchers) lives in the EventLog/Host seam the cure lane deliberately did not edit — owner/closeout note, no W7 blocker.
5. §3 line 143 may take `✅ W7` now.
