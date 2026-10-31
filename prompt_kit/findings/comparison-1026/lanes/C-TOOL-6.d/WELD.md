# C-TOOL-6.d — WELD record (welder session, 2026-10-31)

## Weld-open state (pre-pick, read from disk in the dispatch shell)
- master tip: b008cc14c `weld: C-TUI-6.g — run-state fold` (moved past lane base c53098787 — expected: C-WEB-5.c, C-TUI-6.c (a997c78d4→f4a0b327e→828aab3eb→...→07593d459 era) and C-TUI-6.g welds landed between; base c53098787 is an ancestor of master, verified same-shell).
- Lane: /home/sites/cc-cmp-C-TOOL-6.d branch cc/C-TOOL-6.d, tip 6d12b9b81, porcelain 0.
- Picks read from lane disk (`git -C ... log --format='%h %s' c53098787..HEAD`): d5bd4823c registration+cascade / 4b9873e86 tests+skip-roster / 6d12b9b81 nested+standins — THREE, matching the dispatch (the brief's generic "FOUR-disk-read" wording is template-era; disk authority = 3 picks here).
- Heading read BEFORE welding: `#### C-TOOL-6.d —` at plan_comparison.md:4028 UNMARKED; python mark count 308 (startswith('#### '+✅+' ')). NOT a phantom-reweld.
- Evidence stat'd by welder: REPORT.md 7,043 B @2026-10-31 13:02:53; REVIEW-1.md 9,949 B @13:16:30 — dispatch bytes match.
- Name-diff pre-flight: lane range c53098787..6d12b9b81 = 17 files; ZERO hunks under candy-code/lang/, candy-code-web/, src/Protocol/, tests/Config/TreeWideGuardRosterTest.php (TWGR file actually lives at tests/TreeWideGuardRosterTest.php — checked there too: 0), suite-figure.json, durations.tsv. ZERO master-side movement since base on ANY of the 17 lane files (loop over `git diff --name-only c53098787..HEAD -- <f>` = empty for each) → clean-apply expected; the brief's ci.yml/affected-libs/MATCHUPS/sugar-pty union duty is VACUOUS for this lane (touches none of them).

## Picks (explicit list, no -x per t646 law, zero conflicts)
- d5bd4823c → **764624ea8** (12 files, +644/−31)
- 4b9873e86 → **874497c87** (5 files, +569/−17)
- 6d12b9b81 → **413a0b456** (2 files, +650/−0)
- Weld range b008cc14c..413a0b456 = exactly 17 files, matching lane union by name.
- Law greps per landed pick: %ae detain-count 1, %ce detain-count 1, Co-Authored grep 0 (3/3/0 each).
- Engine reuse re-derived on MERGED tree: `git diff c53098787..413a0b456 -- candy-code/src/Tools/Script/` = 0 lines (r1 Q2 claim holds post-merge).
- New src files ScriptTool.php + DispatchesNestedTools.php: proc_open count 0/0 — no spawn/emit surface, census rosters untouched by construction.

## Merged-tree gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored exact-class full Test stems; 'PHPUnit Deprecations: 2' = the accepted AccessorDeprecationTest :168/:202 baseline on every line)
- TARGET (ScriptToolTest|NestedToolDispatchTest|ToolCatalogTest):: → **OK 42T/291A, Skipped: 1** — r1-EXACT; the skip is the rostered host-conditional bubblewrap jail leg (scriptJailRuns()=false on this host), expected and disclosed.
- Per-file live re-measure for the §7 riders: ScriptToolTest alone **22T/75A/1 skip**; NestedToolDispatchTest alone **10T/64A** — both EXACT to builder/r1 counts.
- GUARD FAMILY (ToolDocDriftTest|PermissionsIntroDriftTest|ToolCatalogTest|DocFigureProseDriftTest|SuiteSkipRosterTest):: → **OK 140T/4608A** — r1-EXACT; write-roster leg standalone (testTheWriteToolRosterDoesNotDriftFromThePermissionGate) **OK 1T/14A** — r1-EXACT.
- TreeWideGuardRosterTest BARE file run (tests/TreeWideGuardRosterTest.php) → **OK 17T/3920A** GREEN (r1-EXACT). Sibling WALKS_A_DIRECTORY rows alive BY TEXT at current positions: :587 AgentLiveRegistry · :645 LoginSubcommand · :678 SubcommandsMcpServe · :742 ContextSources · :745 WebStrings · :780 SuiteSkipRoster · :811 BashSandbox (brief era-notes :778/:809 drifted by 2 — positions drift, text alive; the lane adds ZERO TWGR rows, name-diff-proved).
- DuplicatedTestHelperDriftTest BARE → **OK 14T/204A** GREEN (r1-EXACT).
- LayeredSettingsTest:: → **99T/411A 0F** pinned==derived at merged tip (cure 00fedf519 in force; nothing folded).
- PAIRING BashSandboxTest|BubblewrapHardeningTest co-run ONE process → **OK 39T/190A 0F GREEN** (db1441a61 universal cure holds at this merged tip).
- SuiteSkipRoster rows + SCRIPT_JAIL/SCRIPT_JAIL_TOOL pair stay as landed (guard family green above).
- GENERATORS on merged tree: gen-tool-docs --check **rc0 'tool docs: up to date'**; gen-command-docs --check rc0; gen-settings-doc --check rc0 → NO regenerate leg; the lane's --write regen rode pick 1 and survives the merge.
- vitest/web chain UNOWED: name-diff proves ZERO candy-code-web/* and ZERO src/Protocol/* in the 17-file weld range.
- PROTOCOL trio/SETTINGS docs/SERVER.md/generated.ts untouched (name-diff 0).

## Foreign reds (accepted roster — carried, NOT cured, NOT chased)
- ReadmeSuiteFigureDrift staleness pair: standalone run shows **2F, 'Failed asserting that 25537 is identical to 29568'** — THIS weld's +32T-class (22+10 new tests) rides inside the live 29,568; battery re-pin owns the pair (suite-figure.json/README pair NEVER touched here).
- GlobDialectDifferentialTest (tests/Context/) → **13T/364A 1F** at merged tip, shape UNCHANGED: the test's own message quotes PathGlob.php doc-block 310,245 vs derived corpus 311360 (560×556) — welder asserts NEITHER number from arithmetic; base-proven foreign (builder proved red at c53098787 in a fresh worktree; PathGlob untouched by this lane).
- Carried WITHOUT re-run (lane files reach none of these fixtures): keepWarm 1F · 5 KeyHelpTest datasets+1 risky · HelpTest[CANDY_CODE_SERVICE_TIER] 1F · BinCandyCodeDispatchTest --worktree :1893 1F · AttachPagingTest 2F (lead base-proven 364a52876) · OneSidedHomeSandboxTest :435 · SettingsEditorTest :189 · ChildStderrCaptureTest 2F · BootstrapLaunchFormatConstantsTest doc-roster leg · Cli/HelpTest env-var-roster leg · RegistryTest O_TRUNC shrink-stdout flake + ForkedChildReaperAdoptionTest/Sessions BackgroundShellRegistryTest 1F (accepted-foreign, report-only) — no NEW family joined the red set. PtyRunToolTest::testExpectMiss + RegistryTest shrink-stdout never entered any filter (excluded by law).

## Mutation honesty (both sides, per house law)
- Builder EXECUTED M1-M4: bind-arm neuter → 8F/4W; leaf-arm neuter → exactly 1F; approver pass-through nulled → 3F; catalog-filter neuter → exactly 1F; all cp+md5 restores clean (REPORT §"Mutation proofs").
- r1 did NOT re-exec (permission map denies src edits/bare php; no scratch worktree): its Q4 mutation map is STATIC/green-cover-confirmed, explicitly UNCONFIRMED-BY-RUN — EXEC-level mutation credit deferred to the §7 battery. Disclosed both-sides honestly.

## Deviations adjudicated at weld (from the FILES, not prose)
- position 46 (not design's 44): r1 Q3 JUDGMENT benign — Advisor:68 holds 44, Canvas:59 holds 45, ToolCatalog.php:290 assertUnique LogicExceptions on 44; ScriptToolTest:64 pins 46 with an honest comment; commit message names it. Positions are ordering metadata, gloss otherwise verbatim.
- RESTRICTED_TOOL_NAMES gap (Bootstrap.php:9646 lacks 'Script'): r1 Q1(d) — NOT a lane defect, gate-by-call still decides every nested call and findTool reads the filtered app set; the residue is `--restricted` hard-drop semantics vs an arbitrary-PHP executor surviving (jail limits blast, worktree writes bypass per-path Edit rules). r1 RECOMMENDS a §7 follow-up step: RESTRICTED_TOOL_NAMES += 'Script' + RestrictedSafeModeFlagsTest:153 mirror flip, one step. LEAD RULING PENDING — logged as follow-up-step candidate, never fixed here.
