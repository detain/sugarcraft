# C-TOOL-6.j — WELD log (incremental; the record half is resumable)

Welder session, 2026-10-31. Lane /home/sites/cc-cmp-C-TOOL-6.j, branch cc/C-TOOL-6.j, base 895b9d6fd.

## Step 0 — preflight (disk-read)
- master pre-weld: 444bb0f70 (weld: C-TOOL-4.g — run-state fold).
- Own heading `#### C-TOOL-6.j —` at plan_comparison.md:3841, NO checkmark — precondition verified (not a phantom-reweld).
- Mark count python startswith('#### ✅ ') on git show master:plan_comparison.md = 312 (target 313).
- Tree: master branch; dirt = lead's .opencode/agents/cc-cmp-lead.md M + forbidden junk/law files + lanes/ region only.
- Evidence byte-counts re-statted from the FILES: REPORT.md 9,366 B · REVIEW-1.md 10,540 B · FIX-1.md 5,203 B · REVIEW-2.md 6,443 B — all four READ IN FULL by this welder.
- Loop arc (disclosed honestly in the mark): builder 5 picks (lane death blank-then-resumed + two self-spawned finisher helpers whose work landed genuine) → r1 FINDINGS 0C/1MAJOR(F-A)+1MINOR(F-B)+1NIT → fixer FIRST receipt a PHANTOM (fabricated sha 432121a03; work real-but-uncommitted; same-session correction committed 6a6b6c9f5 and FIX-1 disclosed the fabrication) → r2 CLEAN with independent EXEC re-derivation.

## Step 1 — picks
Explicit six-pick list read from `git -C /home/sites/cc-cmp-C-TOOL-6.j log --format='%h %s' 895b9d6fd..HEAD` (NEVER a range; no -x):
fbdf219e8 · 747aa6c44 · 86a340422 · 65196e347 · a8628f131 · 6a6b6c9f5

## Step 2 — cherry-picks landed (master 444bb0f70 -> 094d5ebe2)
Six normal cherry-picks, zero conflicts, reflog shows exactly 6 `cherry-pick:` entries, zero foreign movement.
Lead's 12-path staged index dirt was unstaged pre-picks and re-staged post-picks; before/after `git diff --cached --raw` byte-EQUAL (STAGED-12-RESTORED-BYTE-EQUAL).
Landed: 7dbd67ea6, 044356ab7, 75174ea40, a599885bd, f412d3ace, 094d5ebe2.
Author law: %ae grep -c detain = 6; %ce grep -c detain = 6; Co-Authored-By = 0.
Name-diff 444bb0f70..HEAD = exactly 8 candy-code files (2 src/Background, 3 tests/Sessions, 3 roster/census files) — ZERO lang/en.php, ZERO src/Protocol, ZERO candy-code-web, ZERO protocol trio, ZERO suite-figure.json/durations.tsv.
Unions by TEXT: TWGR gains 1 row 'Sessions/BackgroundShellInteractiveTest.php' at :771 beside alive sibling rows (LoginSubcommand :645, McpServe :678, ContextSources :742, WebStrings :745, WebPushSender chr(42) :747, SuiteSkipRoster :784, BashSandbox :815); RPC gains PtyRelay file_get_contents/fopen rows at :1127/:1133 + sweep-name widening; DIG gains startInteractive row at :180-block. All diffs additive-only.

## Step 3 — merged-tree gates (live re-measure at 094d5ebe2, cwd=candy-code)
- (PtyRelayTest|BackgroundShellInteractiveTest):: -> OK 20T/155A (= 8T/80A + 12T/75A, exact sum of r2 figures).
- combo (BackgroundShellRegistryTest|PtyRelayTest|BackgroundShellInteractiveTest|SuiteSkipRosterTest):: -> OK 77T/563A, byte-equal r2. Run-1 showed the known foreign shrink flake (1F at BackgroundShellRegistryTest.php:954, testAStdoutThatShrinksAlone 5A-truncated); re-runs green; isolated 3x = 17A/5A-FAIL/17A (documented transient ~1-in-3, disclose-not-cure).
- TreeWideGuardRosterTest BARE -> OK 17T/3933A (r2 3925A; +8A assertion-only drift disclosed, rows-by-TEXT all alive incl mine at :771).
- DuplicatedTestHelperDriftTest BARE -> OK 14T/204A exact.
- LayeredSettingsTest:: -> OK 99T/413A, 0F (cure 00fedf519 holds).
- pairing (BashSandboxTest|BubblewrapHardeningTest):: one process -> OK 39T/192A (cure db1441a61 holds; ~2A drift disclosed).
- tools/check-child-lifetimes.php rc=0: 61 libs, 33 proc_open sites, 9 findings, 9 accounted, 0 problems (relay pump accounted).
- gen-tool-docs/gen-command-docs/gen-settings-doc --check rc=0 x3, zero drift, no regen legs needed.

## Step 4 — proofs + mark
- git cherry master cc/C-TOOL-6.j = SIX '-' NO '+' (patch-id exact, no content-proof needed).
- cat-file -t: all six landed shas = commit; merge-base --is-ancestor 7dbd67ea6/094d5ebe2 master OK.
- §4 mark written at line 3841: heading now '#### ✅ 7dbd67ea6,044356ab7,75174ea40,a599885bd,f412d3ace,094d5ebe2 C-TOOL-6.j — PTY relay core for interactive background shells (…)' — python startswith('#### '+✅+' ') count = 312→313 PROVEN.
- Lane dir lanes/C-TOOL-6.j/ fully untracked (REPORT/REVIEW-1/FIX-1/REVIEW-2/WELD) → commit 1 = plan + 5 lane files (6 paths, exact 4.g precedent shape; dispatch '5 then 1' = 5 evidence + plan, matching d8678780as six-path list).
