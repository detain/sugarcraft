# C-EXT-6.d — weld log (incremental, sole master writer)

## Step 1 — preflight (DONE)
- master @ `7e042da7b` (`weld: W33 — run-state fold`), branch master, toplevel /home/sites/sugarcraft.
- Porcelain = lead-sanctioned lanes-dir dirt + `.opencode/agents/cc-cmp-lead.md` M + junk/untracked law files — untouched, unstaged.
- Lane `cc/C-EXT-6.d` base `56893049b`, TWO explicit commits read from disk: `86fb200fb` + `e43c6a684`; `git cat-file -t` = commit/commit.
- REVIEW-1.md stat'd 8,045 B — VERDICT: CLEAN, C=0 M=0 MINOR=1 NIT=3, read IN FULL. REPORT.md stat'd 7,544 B, read IN FULL.
- §4 baseline: `^#### ✅` python-count on master blob AND working file = 298. Heading `#### C-EXT-6.d — `type: prompt` hooks (LATE)` located BY TEXT at :3891.
- §3 note by TEXT: C-EXT-6.d is listed in the `W37 (LATE)` row (:173) and item index (:4125 says W37) — dispatch prose said "W36-era member"; §3 is NOT touched by this weld (wave-close duty).

## Step 2 — cherry-pick (DONE)
- EXPLICIT LIST, never a range, no `-x`: `86fb200fb`→master `03d4b9908` (core, 8 files +1167/−31), `e43c6a684`→master `c860e8927` (dialect+wiring+docs+guards, 8 files +408/−51). ZERO hand-conflicts; en.php + DocFigureProseDriftTest auto-merged.
- Weld-range name-diff = exactly the 14 lane files; candy-code-web: 0 → vitest/dist chain UNOWED; src/Protocol: 0 → gen-protocol-schema NOT owed (ran --check anyway: up to date).
- Law on both picks: %ae grep -c detain = 1, %ce grep -c detain = 1 (SEPARATE greps), Co-Authored-By = 0.

## Step 3 — merge duties (DONE)
- en.php: 7 `hooks.prompt.*` keys in ONE sorted run (:3908-3914); python dupscan over the key column: 3,415 keys, ZERO duplicates. `CliLangCatalogueTest::|LangParityTest::` 15T/3654A GREEN at merge (sorted-run placement law holds).
- TWGR: lane never touched the file; merged blob keeps ALL sibling rows — AgentLiveRegistryTest :587, LoginSubcommandTest :645, SubcommandsMcpServeTest :678, BashSandboxTest :806. Guard accuses nothing.
- HookConfig/HookManager/HOOKS.md: merged blob re-derived — HookConfig ENTRY_KEYS = 13 (name…prompt,model), ClaudeSettingsHooks ENTRY_KEYS = 10, HOOKS.md carries 'may carry ten keys' (:86) + 'Those thirteen' (:154). withPromptBackend install live at Bootstrap.php:6359.

## Step 4 — merged-tree gates (DONE) (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe; anchored :: filters except TWGR/DTH bare; PtyRun expect-miss never selected)
- Hooks family union (r1's 6 classes):: 237T/699A OK — r1-EXACT. PromptHookTest:: 21T/107A; BootstrapPromptHookWiringTest:: 3T/11A — LIVE merged-tip figures (§7 hand-off).
- DocFigureProseDriftTest:: 92T/4214A (r1 4209A: +5A assertion-only merged drift, tests identical — disclosed, chased nothing).
- TreeWideGuardRosterTest BARE 17T/3886A GREEN (4.f-era 3879A: +7A merged drift). DuplicatedTestHelperDriftTest BARE 14T/204A EXACT.
- StderrEmitterCensusTest::|ReadPathCensusTest:: 114T/23943A green (lane-report 23866A: +77A tree-scan recount of the 14 moved files — identical test counts, normal).
- GlobDialectDifferentialTest:: 13T/364A, EXACTLY the accepted foreign 1F at :336 — MEASURED: doc quotes 310,245 vs derived 311,360 (560×556); assert NEITHER number, battery rider.
- ReadmeSuiteFigureDriftTest:: 4T/26A/2F = accepted staleness pair; LIVE enumeration at this merge: 29,396 tests (pinned 25,537). §7 battery re-pin; never edited those two files.
- Every OK-line carries the accepted AccessorDeprecationTest PHPUnit Deprecations: 2 baseline.
- ACCEPTED-FOREIGN set carried WITHOUT re-running (lane touches none of their fixtures): keepWarm 1F · 5 KeyHelpTest datasets+1 risky · HelpTest[CANDY_CODE_SERVICE_TIER] · BinCandyCodeDispatchTest --worktree :1893 · AttachPagingTest 2F · OneSidedHomeSandboxTest :435 · GlobDialect 1F (re-ran: unchanged shape) · SettingsEditorTest :189 · ChildStderrCaptureTest 2F · BootstrapLaunchFormatConstantsTest doc-roster leg · Cli/HelpTest env-var leg. No NEW red family joined; no accepted red changed shape.
- Generators --check: gen-command-docs / gen-tool-docs / gen-settings-doc / gen-protocol-schema ALL up to date rc0 → NO regenerate leg.

## Step 5 — §4 mark + commits
