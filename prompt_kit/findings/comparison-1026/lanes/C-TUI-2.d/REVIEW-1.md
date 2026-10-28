# C-TUI-2.d REVIEW-1 (r1) — branch cc/C-TUI-2.d @ c49418587 (parent 878047472)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 3 MINOR (all step-local). Otherwise CLEAN; change is correct, honest, and in-brief.

## Scope (clean)
9 committed files == brief contract exactly: 5 created src/Tui/DiffReview/ classes
(DiffReview, DiffReviewAction, DiffReviewActionKind, DiffReviewSource, OpenDiffReviewMsg),
CheckpointDiff.php + lang/en.php modified, 2 test files added. No wiring
(grep: zero DiffReview refs outside src/Tui/DiffReview/ and its tests; DiffCommand untouched) —
3.e owns it. No sugar-diff lib edits, no weld-only files (suite-figure.json/durations.tsv/dist absent).
en.php = soft-appended own block at EOF, 5 keys, Lang::t in model, en.php is the only locale (§6.3 C-TUI-6.h later).
E646: zero timers/spawns in new code; all git runs ride existing bounded GitRunner.
§1.9: no glob-shaped/denial-shaped fixture literals (GlobDialect+DenialPrefix green).

## Core judgments — verified
1. **between()/files() git semantics**: of() refactor-pure (base body moved verbatim onto private
   fromTrees; old CheckpointDiffTest 3 methods unchanged, green inside filter). between() resolves
   snapshot TARGET trees both sides (correct frozen per-turn semantics, pinned by
   testBetweenShowsTheTurnOnlyTheWorktreeStaysOutOfIt) + cross-repo/wildside refusals pinned.
   Join premise real: GitRunner.php:77 pins core.quotepath=false; diff-tree -r -z --no-renames
   (WorkspaceCheckpointer.php:840) → raw paths match section paths byte-for-byte.
   I probed the two risky shapes THROUGH the tests' own real-git path: binary blob.dat →
   section found, '--- /dev/null' lexes fileHeader=true (UnifiedScan) so the equal-sides regex
   fallback names the path, zero counted lines, no NUL; 2100-line file → MAX_LINES=2000 cap drops
   the tail section, zero-count git row re-appended from changes (pinned). ±counts gated on
   UnifiedScan new/old non-null — headers and >9-digit unnumbered hunks excluded; semantics
   confirmed against sugar-diff/src/Reader/UnifiedScan.php.
2. **clamp-not-wrap**: selectRelative/scrollBody/Home/End clamp both ends; stuck-key assertSame
   (same-instance) pins first/last; empty list no-ops; Esc closes. Pinned.
3. **scroll/selection reset on context change**: selection→scroll reset pinned
   (testSelectingAnotherFileResetsTheBodyScroll); base-switch reset pinned (testBaseStepping...
   'a new base means a new file list'). Turn-flip reset NOT pinned → MINOR-1.
4. **'t' ≥2-bases refusal leg**: pinned (single-base same-instance null-action; turn-off always allowed).
5. **DiffReviewSource**: captured-only filter + newest-first (assertSame [$second,$first]) + clamped
   load(9→2) + turn-view scoping all pinned against REAL temp EnhancedSessionStore + real git;
   hermetic (useHomeSandbox before git, rmrf/forgetDisabled/forgetAnnouncedSnapshots tearDown, no HOME writes).
   Mixed captured/uncaptured hole never exercised → MINOR-2.

## Gates — all figures reproduced EXACT from the lane worktree (plain pipe)
CheckpointDiff 11/91 · DiffReview 19/190 · combo(CheckpointDiff|DiffReview|LangParity|GlobDialect|
DocFigure|SymbolCitation) 152/24969 (my superset with TuiLang: 161/26381; TuiLang alone 9/1412 — decomposes exact) ·
consumers DiffCommand|DiffGutter|Checkpoints 71 · census trio 130/25406 · sugar-diff lib 116/339.
"PHPUnit Deprecations: 2" on every leg = declared baseline, not lane-introduced.
one-type: check script not executable in this reviewer map (php tools/... refused) — verified statically:
1 type per file across all 5 new src files + both test files.
§1.8: TuiLang roster derives keys from source live — no manual roster flip needed; green at tip.
DuplicatedTestHelperDrift: exactly 1 failure, CacheTtlIdleFoldTest vs ModelMetadataTest at :2829 —
disclosed base-red CONFIRMED base-inherited (neither file in this lane's diff; master weld-cure in flight). NOT step-local, accepted.

## MINOR findings (step-local, fix-before-weld or lead-accept)
- MINOR-1 src/Tui/DiffReview/DiffReview.php toggleTurn: resets selected/scroll but no test asserts it.
  Fix: in testTheTurnViewNeedsAPairAndClampsWhenItFlips, PageDown+Down first, then 't' and assertSame(0, scroll())/selected().
- MINOR-2 src/Tui/DiffReview/DiffReviewSource.php bases(): captured-only leg untested with a mixed hole.
  Fix: one extra source test — capture checkpoint A, save B WITHOUT workspace capture, assert bases()==[A].
- MINOR-3 REPORT precision (welder-relevant): CheckpointDiffFilesTest has 7 new methods (report says 6);
  total new = 14+7 = **21**, so the suite-figure carry is ≈+21T, not +19T (the 19 is the filter count incl. 5 non-new matches).
  Welder must re-pin from the green full run, per standing law.

## Mutation coverage note (review-harness limitation, not a lane issue)
Decisive-mutation EXECUTION was denied by this reviewer's permission map (shell cp/mkdir and lane-tree edit refused;
only /tmp writes + read/phpunit allowed). Discrimination argued structurally, pins named:
M1 wrap-instead-of-clamp → cursor-stuck assertSame($at,$stuck) reds (2 files, Down at last → wraps to 0);
M2 drop 'selected'=>0,'scroll'=>0 from stepBase mutate → base-stepping reset asserts red (after PageDown scroll=6≠0);
M3 neuter UnifiedScan non-null filter → '+++ b/fresh.txt' header counted, added=1 assertSame reds.
All three target-legs have real, named pins — non-vacuous.

## Welder carries
- scripts/parallel-tests-durations.tsv: rows for candy-code/tests/Workspace/CheckpointDiffFilesTest.php + candy-code/tests/Tui/DiffReview/DiffReviewTest.php.
- Suite-figure re-pin: ~+21T from this lane (green-run authoritative; lane report's +19T undercounts).
- §4 mark: C-TUI-2.d ✅ c49418587. No en.php roster/manual-guard flips owed.
- No diff collision with sibling W18 lanes expected (owns only CheckpointDiff.php; DiffReview/** is new dir; en.php own appended block — serialized [lang:tui.diffreview.]).
