# C-TOOL-4.h — WELD record (welder subagent, 2026-10-31)

## Step 1 — grounding (pre-write)
- master start: `3177c1873` (weld: C-TOOL-6.f — run-state fold). Branch `master`, toplevel `/home/sites/sugarcraft`. Tree clean apart from sanctioned `lanes/` dirt + lead's `.opencode/agents/cc-cmp-lead.md` (untouched, never staged).
- Lane `/home/sites/cc-cmp-C-TOOL-4.h` (branch `cc/C-TOOL-4.h`, base `00fedf519`): porcelain 0, tip `df58a9b87`. Explicit pick list read from `git log --format='%h %s' 00fedf519..df58a9b87` (NEVER a range): 40479d9ae → 8fc84812a → 01caa75fc → 790d955d3 → 1da1b7a44 → df58a9b87. All six `git cat-file -t` = commit; all six lane-side %ae/%ce = detain@interserver.net, Co-Authored 0.
- Artifacts re-stat'd by welder BEFORE writing the mark: REVIEW-1.md **8,829 B**, FIX-1.md **5,159 B**, REVIEW-2.md **10,758 B**, REPORT.md **11,198 B** — sizes match the dispatch. All three review artifacts read end-to-end; r2 verdict line: `VERDICT: CLEAN — 0C/0M/0 step-local MINOR/1 NIT... Weld-ready.`

## Step 2 — merge
- Cherry-picked the six in listed order, no `-x` (ruling t646). ZERO conflicts. Landed: 40479d9ae→`2806bee47`, 8fc84812a→`dedae3032`, 01caa75fc→`181c48e0b`, 790d955d3→`d5b8026ea`, 1da1b7a44→`fa63cd30e`, df58a9b87→`cbbcebb80`.
- Law greps per landed commit (separate counts): %ae=1, %ce=1, Co-Authored-By=0 each; raw committer identity `Joe Huss <detain@interserver.net>`.
- Merged-tree re-derivation duties: lane name-diff = 8 files +802/−74. ZERO `candy-code/lang/` hunks (r2's zero-lang-keys claim name-diff-proven → no en.php union, no dupscan). ZERO `candy-code-web` (no npm/vitest/dist). `git log 00fedf519..3177c1873 -- <file>` EMPTY for each of the 8 files incl. `.github/workflows/ci.yml` + `candy-code/docs/TROUBLESHOOTING.md` → union-hunk duty vacated; report: no recent weld touched the lane's files.
- TreeWideGuardRosterTest: lane touched 0 lines; merged blob carries ZERO new rows and ALL four sibling rows (:587 AgentLiveRegistryTest, :645 LoginSubcommandTest, :678 SubcommandsMcpServeTest, :806 BashSandboxTest). C-TUI-6.f's `Protocol/WebStringsTest.php=>['scandir($this->dir)']` row NOT yet present on master (that lane in flight) — nothing to preserve, reported.

## Step 3 — merged-tree checks (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; all filters NON-ZERO; every run carried accepted AccessorDeprecationTest :168/:202 deprecation legs)
- **PAIRING (era-defining):** `(BashSandboxTest|BubblewrapHardeningTest)::` → **OK 39T/190A GREEN** (db1441a61 cure live; lane-base 1F at :273 pre-cure = foreign, per r2 Q5).
- `(BackgroundShellWallFallbackTest|ProcessContainmentPhpSetsidTest)::` → 12T/46A EXACT vs r2.
- `StdioMcpServerTest::` → 19T/47A EXACT (cure pick cbbcebb80 alive).
- `(BackgroundShellRegistryTest|ShellStopTest)::(?!(?:testAStdoutThatShrinksAloneDoesNotStrandTheUnreadStderr))` → 36T/233A GREEN = r2 37T/250A minus flake-method −1T/−17A. Flake excluded per dispatch (accepted FOREIGN, O_TRUNC race BackgroundShellRegistry.php:474; NEVER cured). Combined with the 12-class: 48T/279A vs banked 49T/296A (flake-inclusive).
- `TreeWideGuardRosterTest` BARE → 17T/3892A GREEN (+17A wobble vs 3875A, test count identical — disclosed, not chased).
- `DuplicatedTestHelperDriftTest` BARE → 14T/204A EXACT GREEN.
- `DescriptorInheritanceGuardTest::` → 15T/5158A (+1A wobble) GREEN.
- `(ReadPathCensusTest|StderrEmitterCensusTest|FixtureLifetimeCensusTest)::` → 117T/23972A GREEN (+113A wobble vs 23859A; asserted NEITHER banked figure, per r1 Q6/r2 Q4).
- `ReadmeSuiteFigureDriftTest::` → 4T/26A/2F = accepted staleness pair (message: 25,537 pinned vs 29,424 live; battery re-pin, json never touched).
- `(EnvRosterDriftTest|ReadmeRosterDriftTest|DocFigureProseDriftTest)::` → 139T/45497A GREEN (+144A wobble vs 45353A, counts identical).
- Generators: gen-command-docs / gen-tool-docs / gen-settings-doc / gen-protocol-schema `--check` ALL rc0 "up to date" → zero drift, NO regenerate leg in the weld commit.
- `php tools/check-child-lifetimes.php` (repo root): 61 libs, 2073 files, 33 proc_open sites, 9 findings, 9 accounted, 0 problems, rc=0 — discharges both reviews' UNVERIFIED-BY-EXEC carry.
- Accepted foreign red set carried WITHOUT re-run per dispatch/r2 Q5 (keepWarm 1F, KeyHelp 5F+1R, HelpTest tier, BinCandyCodeDispatch :1893, AttachPaging 2F, OneSidedHome :435, GlobDialect 1F, SettingsEditor :189, ChildStderrCapture 2F, BootstrapLaunchFormat doc-leg, Cli/HelpTest env-leg, staleness pair). NEW roster entry disclosed: RegistryTest shrink-stdout flake.

## Step 4 — §4 mark + commit
- plan_comparison.md line 3683 heading located BY TEXT, flipped to `#### ✅ 2806bee47,dedae3032,181c48e0b,d5b8026ea,fa63cd30e,cbbcebb80 C-TOOL-4.h — …` with full narrative (loop bytes, merge facts, builder fixes, NIT disclosures, pairing proof, gate deltas, §7 hand-offs LISTED, WARN block, absorption). Python count `^#### ✅` 300→**301** verified on working tree, then on landed blob.
- Commit 1: `weld: C-TOOL-4.h — §4 mark + evidence` = plan_comparison.md + lanes/C-TOOL-4.h/{REPORT,REVIEW-1,FIX-1,REVIEW-2,WELD}.md (git add -N for untracked; explicit -o paths).
- Commit 2: `weld: C-TOOL-4.h — run-state fold` = lanes/run-state.json re-read FRESH from disk immediately before commit, folded VERBATIM; md5 disk==blob proof recorded below.

## Step 5 — absorption proof + cleanup
- `git cherry master cc/C-TOOL-4.h`: recorded below. Zero conflict resolutions → content-verify of `+` sides vacuous.
- Then `git worktree remove ../cc-cmp-C-TOOL-4.h` + `git branch -D cc/C-TOOL-4.h`. Surviving cc/* branches exactly: C-TOOL-4.d, C-TOOL-6.c, C-TUI-6.f, C-TUI-6.j, C-WEB-5.c.

## Proof log
(pending — appended as steps land)
