# C-AGT-6.c — WELD (batch-8 step 4)

Pick (master): `237224a7a` ← lane `f2534f502` (base d3536ac91, parent old — collision scan showed only README.md + lang/en.php drifted since base and even those hunks were disjoint: picked CLEAN, no conflicts).
Weld-cure (own commit): `c36143800` — r1 step-local MINOR (C-TUI-3.f precedent): `--worktree x <verb>` beside any verb was carried-and-ignored (bin exits at verb dispatch before the worktree enter); parser refusal broadened `$subcommand === 'serve'` → `$subcommand !== null`, keys renamed `with_serve`→`with_subcommand` / `hint.worktree_serve`→`hint.worktree_subcommand` with truthful general wording (serve's per-client-root reason kept as the deeper example in comment/README), LaunchFlagsTest pin renamed+widened — RED pre-cure on the session/models/doctor/mcp/completion legs, green post (demonstrated in order). stream-json leg unchanged in shape (same usageError+hint channel → JSON error document).

## Gates (merged tree, cwd candy-code)
- `--filter 'MainWorktreeTest|ArgvParserTest|LaunchFlagsTest'`: **260T/962A green** (lane era 260T/949A; +13A = cure legs).
- Battery `MainWorktree|ArgvParser|LaunchFlags|Help|StderrEmitterCensus|WorktreeManager|WorktreeRemovalReporting|BinCandyCodeAutoloadGuard|ReadmeRosterDrift|LangParity|DocFigure`: **730T/133,166A, 5F = KeyHelp standing set ONLY** — the DocFigure 59-vs-60 red the lane carried did NOT appear (a2d5d0e3f cure on master), exactly as briefed.
- Census guards re-run: StderrEmitterCensus green in battery; BootstrapLaunchFormatConstantsTest 18T/**1F** — triaged by full-checkout of test+docs+src+lang at 302e99ac2: red PRE-EXISTS batch-8 (foreign standing, `PAGE_QUOTES` PROJECT_TIER_TOOL_REMOVAL_FORMAT entry drift; not attributable to this batch; lead: batch-7 era carry, closeout must re-pin or roster).

## Bookkeeping
- durations.tsv: MINTED `candy-code/tests/Cli/MainWorktreeTest.php 6.058 17` (17T/61A measured), descending; asc-violations 4 (parity).
- §4: `✅ f2534f502` + dated weld note recording the refusal broadening + stream-json leg (pre-rebuild §4 wording served).
- suite-figure re-pin NOT owed here (§7/wave-close duty): test-adding steps = +9T (EXT-4.c), +16T (SES-7.e), +19T (AGT-6.c: MainWorktreeTest 17 + LaunchFlagsTest net +1 after serve→widened rename + ArgvParserTest row leg — trust the wave-close live enumeration, not this arithmetic).

## Harness incident (recorded honestly)
A `git stash -q push <file>` (invalid arg order → fatal, no stash created) was followed by a chained `git stash pop` which PULLED THE FOREIGN stash@{0} ("WIP on cc/C-CTX-5.c …"). Apply aborted on untracked-restore conflict; tracked tree verified untouched except my own edits (per-file diffs inspected); the sole residue `run-state.json` at repo root was parked to /tmp/opencode/b8-park/stray-run-state-root.json; stash@{0} remains intact. LAW RE-PROVEN: never stash in this main tree; use cp-park + git checkout.
