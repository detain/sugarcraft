# WELD-1030T — C-SEC-7.e (live, incremental)

## Step 0 Preflight — DONE
- master @ start: 6fb38eef4 (re-read live). Branch master, root worktree. Tree clean apart from lanes/ + known foreign dirt (cc-cmp-lead.md, AGENT_LAW_SNIPPET.md, LEAD_LAWS.md, ?? text, redacted-?? path).
- Range 9fb981c5c..f3ccb76c6 EXCLUSIVE-START: git rev-list --count = 2 EXACTLY (2c8a0865e code/docs, f3ccb76c6 lane REPORT). Parent of first pick = 9fb981c5c2e6393427c9c7be8ded7aed5f0c6602 (verified) and is an ancestor of master.
- REVIEW-1.md stat'd on disk: 5398B (matches brief claim).
- Lane commits author/committer detain@interserver.net, author name Joe Huss.

## Step 1 Cherry-picks — DONE
- c9e5cbde3 = 2c8a0865e (12 files, code+docs+tests+seam cascades). Single conflict as briefed: StderrEmitterCensusTest NUMBER_WORDS — master's forty-eight/forty-nine re-split line kept, lane's roster row 47→48 + FORTY-EIGHT docblock merged clean (verified in lane diff: hunks at :233 docblock, :652 roster, :1043 NUMBER_WORDS).
- a3f48a540 = f3ccb76c6 (lane REPORT.md). Initially blocked on byte-identical main-tree untracked copy (diff -q IDENTICAL proven) — removed copy, branch version landed.
- Author law on both: %ae=%ce=detain@interserver.net verified separately; zero trailers (plain cherry-pick, no -x).
- PERMISSIONS.md/SETTINGS.md/Chat.php auto-merged, zero conflicts (fuse clean as briefed).
- cat-file: c9e5cbde3 commit OK.

## Step 2 Regenerate — DONE
- gen-command-docs/gen-tool-docs/gen-settings-doc --write: ALL "up to date", ZERO drift; all three --check rc=0. No src/Protocol touch, no candy-code-web src touch -> schema regen + npm chain skipped by design (lane is PHP docs/code/tests only).

## Step 3 Merged-tree gates — DONE (cwd=candy-code -c phpunit.xml env -u LINES -u COLUMNS plain pipe)
- Combined filter AutoReviewScopeTest|BootstrapPermissionGateTest|PermissionsIntroDriftTest|StderrEmitterCensusTest|BootstrapTranscriptSeamCallSiteCensusTest: 205T/23061A OK 0F (exit "OK, but there were issues" = deprecations only).
- Per-class: AutoReviewScopeTest 15T/52A (= brief expect 15T). BootstrapPermissionGateTest 68T/154A (= brief expect 68T). StderrEmitterCensusTest 95T/22698A. PermissionsIntroDriftTest GREEN at merged tree (lane's disclosed base red = foreign-cured).
- 2 PHPUnit deprecations in every run = AccessorDeprecationTest private data providers (pre-existing, listed-not-chased). Foreign standing reds roster (staleness pair, KeyHelp, ApplySettings, MediaSettings, SettingsEditor-categories, ReadPathCensus, SwallowingCatchSrcCensus, BootstrapLaunchFormatConstants, DTHD): NOT triggered inside these gates; suite-figure.json + battery NOT run per batch law.

## Step 4 DUR re-time (welder-minted rows) — DONE
- BootstrapPermissionGateTest 0.547@65 -> 0.803@68 (junit testcase-sums 2.928/0.803/0.768, median-of-3; run-1 cold outlier).
- AutoReviewScopeTest 0.050@12 -> 0.068@15 (sums 0.068/0.055/0.107, median 0.068).
- StderrEmitterCensusTest 95 cases unchanged (no row flip). No new test files -> no MINTs.
- §4 mark flipped at plan_comparison.md:3130 `#### ✅ c9e5cbde3 C-SEC-7.e` (+Weld 1030T carry note; loose-anchor count = 1). first-pick-new-sha per lead brief.
