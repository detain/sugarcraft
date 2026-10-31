# C-TOOL-6.f — WELD record

STEP=C-TOOL-6.f ROLE=welder · master pre-weld `db1441a61` · lane base `e62fd7f49` (= master~1; only db1441a61 rides atop base, touching solely src/Tools/Sandbox/Bubblewrap.php + tests/Tools/Sandbox/BubblewrapHardeningTest.php — zero overlap with the pick's 14 files).

## Evidence read (authoritative for the §4 mark)
- `lanes/C-TOOL-6.f/REVIEW-1.md` 10,147 B @ 2026-10-31 10:56:54 — line 1 `VERDICT: CLEAN`; 0C/0M/0MINOR/0NIT; Q1-Q6 all PASS; Q6 `WELD-ELIGIBLE NOW`. Reviewer measured at lane tip: CanvasToolTest|MediaStoreTest 36T/152A, CanvasToolTest bare 7T/43A, RuntimeTest 153T/595A, ToolDocDrift|PermissionsIntroDrift 10T/40A, ReadmeRosterDrift|ReadmeSettingsTierClaim 16T/119A, DocFigure 92T/4219A, StderrEmitter 95T/23225A, TWGR bare 17T/3888A GREEN, DTH bare 14T/204A GREEN; ReadmeSuiteFigureDrift 2F staleness pair pin 25,537 vs live-enum (era residue, NOT attributed by arithmetic); GlobDialect 1F attributed foreign (proven not-lane: only new bracket literal `doc-[number]` is not corpus-admitting — isGlobShaped needs a `*?` strpbrk; PathGlob.php untouched).
- `lanes/C-TOOL-6.f/REPORT.md` 6,640 B — builder §7: NEW test file candy-code/tests/Tools/BuiltIn/CanvasToolTest.php LIVE 7T/43A → durations.tsv row + suite-figure/README pair re-pin are GATE duties (forbidden to welder); lane share +10T (Canvas 7 + MediaStore 3); gen-tool-docs --write regen of README/ARCHITECTURE/AGENTS_AUTHORING/PERMISSIONS/SETTINGS + --check rc=0 rode the pick; NoAsk position-45 doctrine (design-tool.md :995-1036, GenerateImage mirror); Advisor base-red cure provenance (testTheWriteToolRosterDoesNotDriftFromThePermissionGate red at pristine base: Advisor row missing since C-PRV-3.d 8defce213-era, cured in-step; exactly two names at RuntimeTest.php:4212 + divergence exact-list :3957 + GenerateImage-doctrine prose, sweep NOT widened).

## Step 1 — pick
`git cherry-pick bc04c994f` (explicit SHA read from lane: `git log e62fd7f49..bc04c994f` = ONE commit; no `-x`): landed `d7b3654b5`, 14 files +530/−27 exactly matching Q5's declared list. Law: %ae grep -c detain =1, %ce =1, co-authored =0.

## Step 1b — name-diff duty resolution (from THIS lane's diff, not hazard stories)
- `candy-code/lang/en.php` NOT in the pick (grep count 0) → NO append-union, NO dupscan needed. r1's "no lang keys" confirmed by name-diff; builder REPORT deviation-4 says the same.
- No `src/Protocol/**`, no `candy-code-web`, no `dist/` in the pick (grep count 0) → protocol regen + web npm chain NOT applicable; only tool/command/settings doc generators relevant.
- TreeWideGuardRosterTest.php untouched by the pick → the four WALKS/roster rows survive by construction; verified alive in merged blob at :587 (AgentLiveRegistryTest), :645 (LoginSubcommandTest), :678 (SubcommandsMcpServeTest), :806 (BashSandboxTest). Lane carries ZERO guard rows (r1 TWGR bare green at tip corroborates).
- RuntimeTest cure verified in merged blob: :4212 roster ends `'Canvas', 'Advisor'`; :3957 divergence list carries both + prose. (File is tests/RuntimeTest.php, not tests/Cli/ — brief's path label stale, content identical to r1's verification.)

## Step 2 — generators (merged tree, cwd=candy-code)
gen-tool-docs / gen-command-docs / gen-settings-doc `--check` → all `up to date` rc=0 → NO regenerate leg (exactly two weld commits). The weld's exec run discharges r1's §7 carry 'gen-tool-docs --check UNVERIFIED-BY-EXEC' (r1's shell map denied the php runner; builder REPORT:27 had claimed rc=0). Protocol schema + web chain UNOWED per name-diff above.

## Step 3 — merged-tree gates (plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml; anchored `\Class::` filters, TWGR+DTH BARE names; PtyRun expect-miss never in scope)
ALL EXACT vs r1 at tip (zero drift — lane base == master~1, nothing moved its shared surfaces):
- CanvasToolTest 7T/43A OK · CanvasToolTest|MediaStoreTest 36T/152A OK · RuntimeTest 153T/595A OK
- ToolDocDrift|PermissionsIntroDrift 10T/40A OK · ReadmeRoster|ReadmeSettingsTier 16T/119A OK · DocFigure 92T/4219A OK · StderrEmitterCensus 95T/23225A OK
- TreeWideGuardRosterTest BARE 17T/3888A GREEN · DuplicatedTestHelperDriftTest BARE 14T/204A GREEN (required post-merge greens — no STOP)
- PAIRING PROOF: BashSandboxTest|BubblewrapHardeningTest co-run in-process 39T/190A OK GREEN — db1441a61 cure holds at merged tip (r1 Q6 caveat satisfied; red would have STOPped the weld)
- Accepted-red shape check: ReadmeSuiteFigureDrift 4T/26A/2F = the staleness pair (pin 25,537 vs live-enum era residue; NEITHER number attributed from arithmetic); GlobDialect 13T/364A/1F accepted foreign (not-lane proven by r1). Every run carries accepted PHPUnit Deprecations: 2 (AccessorDeprecationTest :168/:202 family). Foreign accepted set (keepWarm/KeyHelp/HelpTest-TIER/BinCandyCode:1893/AttachPaging/OneSided:435/SettingsEditor:189/ChildStderrCapture/BootstrapLaunchFormat doc-leg/Cli-Help env-leg) not re-driven — lane files reach none of those fixtures.

## Step 4-5 — §4 mark + commits
Mark count python-verified 299→300; heading reads `#### ✅ d7b3654b5 C-TOOL-6.f — ...` with full disclosure parenthetical built from REVIEW-1.md/REPORT.md (r1 CLEAN 10,147 B verdict line quoted; Advisor base-cure provenance 'missing at base since C-PRV-3.d 8defce213-era, cured in-step'; NoAsk position-45 GenerateImage doctrine; putDocument :212 reuse chain; refusal arms :158/:161/:165/:170 renderable-not-fabricated; pairing-green line; gen-tool-docs exec discharge; §7 riders listed — durations row 7T/43A + +10T-class pair re-pin at battery, COMPARISON cells partial until 6.g, media.fetch octet-stream accepted risk; WARN NEXT WELDER block). §3 untouched. Commit 1 = plan mark + REPORT/REVIEW-1/WELD evidence; commit 2 = run-state fold VERBATIM from disk (md5 disk==blob reported in return).

## Step 6 — absorption proof (done pre-commit; cherry uses patch-ids and the pick applied with ZERO hand-resolution)
`git cherry master cc/C-TOOL-6.f` = `- bc04c994f...` with NO '+' line. `git diff bc04c994f d7b3654b5` = exactly db1441a61's 2 Sandbox files (+29) — i.e. the only tip↔landed delta is the cure content already on master below the pick; no conflict resolutions exist, so the comparison duty for '+'-from-resolution is vacuous. d7b3654b5 cat-file = commit, ancestry-verified on master. Reflog accounts both weld commits in the verifying shell. Prune (worktree + branch) executes after both commits land; surviving lanes must read exactly cc/C-TOOL-4.d cc/C-TOOL-4.h cc/C-TOOL-6.c cc/C-TUI-6.f cc/C-TUI-6.j cc/C-WEB-5.c (+ foreign cc/C-TOOL-6.a if present, untouched).
