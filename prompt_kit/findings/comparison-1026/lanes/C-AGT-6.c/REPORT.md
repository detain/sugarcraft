# C-AGT-6.c — `--worktree [name]` main sessions — DONE

Commit `f2534f502d4cb80778fb0dab5a20f583bdc98c3a` on `cc/C-AGT-6.c` (base d3536ac91, /home/sites/cc-cmp-C-AGT-6.c). Author/committer detain@interserver.net verified on separate `%ae`/`%ce` greps (1/1), zero Co-Authored-By, tree clean, NOT pushed.

Implements design-agt.md:493 exactly: ArgvParser+ParsedArgs learn `--worktree [name]` (spaced/attached/bare like `--resume`; bare auto-names `ymd-His-<6hex>`); new `Cli\MainWorktree::enter()` called from bin/candy-code after subcommand dispatch, BEFORE `NonInteractive::run`/`Bootstrap::app`, swapping `$args->root` to the tree; reuses `WorktreeManager::createWorktree('main-<name>', 'candy-code/<name>')`; shutdown hook (pid-guarded, double-fire-guarded) runs `releaseIfUnused()` — unused tree removed, tree with commits/diff kept + announced on stderr; refused outside a git work tree (two GitRunner doors) and beside `serve` (parser usage error, exit 2); Bootstrap untouched.

Files (13): src/Cli/{MainWorktree(NEW),ArgvParser,ParsedArgs}.php, src/Agents/WorktreeManager.php (+mainAgentId/mainBranchName statics, docblock de-numeral), bin/candy-code (--worktree in $strictValueFlags + enter block), lang/en.php (5 keys + help-screen Options entry + Usage line; en is the only locale), README.md (flag prose + usage sample + exit-2 row), tests/Cli/{MainWorktreeTest(NEW),ArgvParserTest,LaunchFlagsTest,StderrEmitterCensusTest}.php, tests/Agents/WorktreeRemovalReportingTest.php, tests/Integration/BinCandyCodeAutoloadGuardTest.php.

Guards flipped in-step (§1.8): StderrEmitterCensus DIRECT_SITES +MainWorktree=1, MESSAGE_SHAPES bin 7→8 + ArgvParser 36→37, seam roster MainWorktree=4 (scanner counts each `WorktreeManager::` call), header prose THIRTY-FOUR→THIRTY-FIVE; BinCandyCodeAutoloadGuard roster+prose; WorktreeRemovalReporting three→FOUR builders (method renamed). Help flag-section satisfied via `cli.help.screen` page edit (Help.php is a renderPage of that key). ReadmeRosterDrift covers no argv flags (verified) — README rows added per design anyway.

Gates (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS):
- `--filter 'MainWorktreeTest|ArgvParserTest|LaunchFlagsTest'` → Tests: 260, Assertions: 949, 0F.
- full battery `--filter 'MainWorktreeTest|ArgvParserTest|LaunchFlagsTest|HelpTest|StderrEmitterCensusTest|WorktreeManager|WorktreeRemovalReportingTest|BinCandyCodeAutoloadGuardTest|ReadmeRosterDriftTest|LangParityTest|DocFigureProseDriftTest'` → 730T/132,978A/6F — all 6 base-identical non-owned (below).

Mutations (all restored, md5-verified):
- M1 pid-guard line neutered → testTheShutdownHookCarriesTheForkPidGuard 1F.
- M2 both not-git doors neutered → refusal test 1F (door #2 `--show-toplevel` alone catches the path; proven as a pair).
- M3 `releaseIfUnused()` leg forced → 4 reds (commit-kept, edit-kept, reuse-announce, hook-dirty).
- M4 `--worktree` dropped from bin flags → bin pre-scan pin 1F.
- M5 serve-refusal neutered → usage test 1F.

Base-identical non-owned reds: DocFigureProseDriftTest ARCHITECTURE 59-vs-60 (foreign C-AGT-6.a /commit batch; their weld-cure a2d5d0e3f not an ancestor of d3536ac91) + KeyHelpTest ×5 (same 60-command drift). Proven by cp-harness: with my lang/en.php reverted to d3536ac91, the same 6 fail.

Welder notes: NEW test file `candy-code/tests/Cli/MainWorktreeTest.php` needs a scripts/parallel-tests-durations.tsv row (never touched here, nor suite-figure.json); suite-figure live-enum drift possible from the new test file. All 13 owned files listed above; targeted gates green on the lane tree.
