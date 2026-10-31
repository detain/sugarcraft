# C-TOOL-6.c — WELD (welder record, incremental)

PRE-WELD master `e3ca3d2b9` (after C-TUI-6.f run-state fold). Lane base `97201c6af`.
Evidence re-stated by the welder from disk: REPORT.md 7,656 B @10:41 · REVIEW-1.md 11,884 B @10:54 (`FINDINGS — 0 CRITICAL / 0 MAJOR / 2 MINOR / 1 NIT`, Weld-eligible YES, engine-only scope DESIGN-SANCTIONED from design-tool.md text) · FIX-1.md 1,989 B @11:00 · REVIEW-2.md 6,329 B @11:06 (`CLEAN — 0/0/0/0`, WELD-ELIGIBLE YES). Heading was UNMARKED at :3768 and `^#### ✅` count 302 at preflight (phantom-reweld check passed).

## Step 1 — picks (EXPLICIT list, no range, no -x)
`1cea10804 → 150508180` core · `0cb93e3de → 54456046a` bridge refusal + signal-time kill + roster · `0fb92d81d → b69707992` clip-inside-cap measured fix · `6a40ea08c → 29929c305` MINOR-1 docblock reaper. All four `git cat-file -t commit` in the citing shell; cherry-pick AUTO-MERGED ReadPathCensusTest only, ZERO conflicts; law greps per landed commit ae=1 ce=1 (detain by COUNT), Co-Authored=0; range diff = exactly the 10 declared files, lang/en.php and candy-code-web untouched (proven — no hunks, no web leg owed).

## Step 2 — union verification
`cc/C-TOOL-6.c..master` diff on the four shared support files: only ReadPathCensusTest is +12 lines on master (foreign additions preserved); the other three byte-identical — UNION holds, nothing dropped. Lane rows live in landed blob by TEXT: DescriptorInheritanceGuardTest.php:647 `Tools/Script/ScriptRunner.php::run` · ReadPathCensusTest.php:1402 `ScriptPrelude.php|require` + :1412/:1640 ScriptRunner scandir legs · SuiteSkipRoster.php:214/:302/:600 SCRIPT_JAIL arm. TreeWideGuardRosterTest ZERO lines changed by the weld; sibling WALKS_A_DIRECTORY_THE_TEST_MADE rows re-read by TEXT in the landed blob at :587 (AgentLiveRegistryTest) · :645 (LoginSubcommandTest) · :678 (SubcommandsMcpServeTest) · :742 (ContextSourcesTest) · :745 (Protocol/WebStringsTest) · :809 (BashSandboxTest) — all alive.

## Step 3 — merged-tree gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- Script family anchored `(ScriptRunnerTest|ScriptBridgeTest)::` → **26T/145A/1 skipped** — EXACT vs r2 (skip = host-conditional jail leg, Deprecations:2 = accepted-foreign AccessorDeprecation pair).
- `DescriptorInheritanceGuardTest|ReadPathCensusTest|SuiteSkipRosterTest` → **62T/6080A** = 15+19+28 tests EXACT (assertions vs lane-era 5159+743+173 = +42A foreign-line wobble, disclosed, assert NEITHER).
- BARE `TreeWideGuardRosterTest` → **17T/3901A GREEN** (non-zero confirmed; assert NEITHER lane 3886A nor 4.h-era 3892A). BARE `DuplicatedTestHelperDriftTest` → **14T/204A EXACT GREEN**.
- `StderrEmitterCensusTest` alone → **95T/23315A GREEN**.
- **PAIRING REQUIRED:** `BashSandboxTest|BubblewrapHardeningTest` co-run → **39T/190A GREEN** at merged tip (db1441a61 cure holds).
- en.php python dupscan (quoted-key lines, re-stated method): **3,467 keys, 0 dups**; `CliLangCatalogueTest|LangParityTest` → **15T/3706A GREEN**.
- `tools/check-child-lifetimes.php` rc0 — 61 libs / 2,073 files / 33 proc_open sites / 9 findings / 9 accounted / 0 problems (rerun EXECUTED at merged tip — discharges the brief's rc0-claim option).
- Generators `gen-command-docs|gen-tool-docs|gen-settings-doc --check` all rc0 'up to date' → ZERO drift, NO `weld: regen` leg. Protocol trio NOT owed: name-diff proves zero `src/Protocol/` + zero candy-code-web touches.
- KNOWN-FOREIGN RED re-encountered: `ForkedChildReaperAdoptionTest::testNoDirectoryWithUnreapedForksIsUnaccountedFor` 1F naming `Sessions/BackgroundShellRegistryTest.php` — PROVEN foreign: guard file and offender blob md5-identical `e3ca3d2b9` vs master; offender's fork site (:870, inside the shrink-stdout O_TRUNC method :937) predates this weld and is the SAME method family the C-TOOL-4.h mark banked as the §7 battery-chase flake rider. Report-never-cure honored; this weld introduces no fork site (lane adds none under the guard's predicate — my files never appear in its offender list).

## Step 3.5 — absorption proof (BEFORE mark commit)
`git cherry master cc/C-TOOL-6.c` = `+ 1cea10804…` then `-` ×3. The single `+` is the known patch-id false positive (pick 1 lands on a drifted context after the auto-merged ReadPathCensusTest): CONTENT-VERIFIED — all four created src blobs byte-identical `1cea10804` vs landed `150508180`, and pick 1's added lines in the two union test files diff-empty against the landed pick's additions (`PICK1-ADDITIONS-IDENTICAL`). Absorption proven; branch+worktree then removed. Surviving named lanes hold: cc/C-TOOL-4.d, cc/C-TOOL-6.g, cc/C-TUI-6.j, cc/C-WEB-5.c (two extra pre-existing foreign refs cc/C-TUI-6.c, cc/C-TUI-6.g left exactly as found — not this weld's lanes). (This paragraph + the two final steps were authored after commit 1 and ride commit 2 as the record tail.)

## Step 4 — §4 mark + commit
Heading :3768 flipped to `#### ✅ 150508180,54456046a,b69707992,29929c305 C-TOOL-6.c — …`; python `^#### ✅` count **302 → 303** (blob recount by welder). Commit 1 = `35250a500 weld: C-TOOL-6.c — §4 mark + evidence` — 6 paths: plan_comparison.md (single heading line, numstat 1/1) + lanes/C-TOOL-6.c/{REPORT,REVIEW-1,FIX-1,REVIEW-2,WELD}.md; law ae=1 ce=1 0-Co-Authored. §3 untouched. No push/fetch/pull.

## Step 5 — run-state fold
Commit 2 = `weld: C-TOOL-6.c — run-state fold`, path-scoped to `prompt_kit/findings/comparison-1026/lanes/run-state.json` committed VERBATIM as found on disk — re-read fresh in the same shell immediately before the commit (no lead-state clobber); this weld authored no edits to it. md5 disk==blob proof in the weld return. Post-fold master tip = commit 2 (a commit cannot contain its own SHA; the §4 mark names the four picks).
