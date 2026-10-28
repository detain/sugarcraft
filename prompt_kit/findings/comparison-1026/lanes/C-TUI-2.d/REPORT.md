# C-TUI-2.d — diff-review core (model, per-file stats, per-turn diff)

VERDICT: COMPLETE. Commit c4941858784f093430d6975a0eb8000bdc7e5170 on cc/C-TUI-2.d (base 878047472), author/committer Joe Huss <detain@…> both greps ==1, zero trailers. Never pushed.

## What landed
- src/Workspace/CheckpointDiff.php — of() refactored onto new private fromTrees() (byte-pure: old suite green untouched); public between(older,newer): self|string (snapshot-vs-snapshot per-turn diff; refuses cross-repo pairs 'the two checkpoints do not share a repository'; refusal reasons pass through); public files(): per-file {status,path,added,removed,patch} joined by raw path (quotepath=false + --no-renames make diff/diff-tree names byte-identical), counts only inside numbered hunks via sugar-diff UnifiedScan (headers/'Binary files'/unnumbered rows excluded), binary path via diff --git fallback only when both sides equal, bounded-tail files keep their git row with zero counts+empty slice; screenSafe/bounded unchanged.
- src/Tui/DiffReview/ — DiffReview (immutable Mutable-trait model; open() normalises; update(KeyMsg) returns [self, ?DiffReviewAction]: Esc close, ↓/j ↑/k clamp-not-wrap cursor, PgUp/PgDn(±20)/Home/End body scroll clamped to last line, selection/base/turn flips reset scroll, -/+ /= base step reloads, t flip needs ≥2 bases and always reloads, clamps to bases−1 in turn view); DiffReviewActionKind enum; DiffReviewAction (reload/close); OpenDiffReviewMsg (review|reason); DiffReviewSource (bases() captured-only newest-first via Checkpoints::fileCheckpointPositions MAX_BASES=1000, baseCount(), load() clamps stepsBack, turnOnly → between(k−1→k), else of(); reason strings for zero/one-checkpoint sessions).
- lang/en.php — tui.diffreview.{title,turn_suffix,empty,omitted,hints} soft-appended own block.
- No wiring, no sugar-diff changes, no manifest change (require already present; symlink resolved first).

## Tests (targeted, plain pipe, cwd candy-code)
- --filter CheckpointDiff: OK 11 tests / 91 assertions (old 3 pass = refactor-pure; new CheckpointDiffFilesTest 7: counts+slices, empty, binary zero-count+no NUL, between per-turn-only, between screen-safe \\u{FFFD}, cross-repo+refusal, truncated tail zero-count row).
- --filter DiffReview: OK 19 tests / 190 assertions (model motion/clamp/reload semantics + source on real EnhancedSessionStore+git).
- --filter 'CheckpointDiff|DiffReview|LangParity|GlobDialect|DocFigure|SymbolCitation': OK 152/24,969.
- Consumers --filter 'DiffCommand|DiffGutter|Checkpoints': OK 71. Census trio StderrEmitterCensus|BootstrapLaunchFormatConstants|TreeWideGuardRoster: OK 130/25,406. tools/check-one-type-per-file rc=0. sugar-diff lib: OK 116/339.
- php -l clean all 9 files.

## Disclosed
- DuplicatedTestHelperDrift has 1 FAIL at base (CacheTtlIdleFoldTest vs ModelMetadataTest pair, :2829) — proven inherited by removing all my files and re-running at 878047472. Mine byte-copy gitIn/rmrf verbatim (house law).
- "PHPUnit Deprecations: 2" in every candy-code run — pre-existing baseline (identical on untouched HistorySearch filter).
- ReadmeSuiteFigureDrift staleness pair will move +21 tests (r1 MINOR-3 correction: the new methods are 7 in CheckpointDiffFilesTest + 14 in DiffReviewTest = 21; the filter's 19/11 counts included 5/4 non-new matches, and the original 6 undercounted the files-test methods). suite-figure.json/README/durations.tsv deliberately untouched (welder duty, green-run authoritative). NEW test files needing durations rows: tests/Workspace/CheckpointDiffFilesTest.php, tests/Tui/DiffReview/DiffReviewTest.php.
- gitIn fixture helpers copied per house discipline; no /tmp path pins; §1.9 literal scan clear (GlobDialect green).
## FIX ROUND 1 (r1 — 3 MINORs, 0 CRIT / 0 MAJOR from REVIEW-1)

New tip 6697ce6672a28c480fd83fc87248e08af82b1cba on cc/C-TUI-2.d (follow-up commit on c49418587, never amended/pushed). Author+committer Joe Huss <detain@interserver.net>, both greps ==1, zero Co-Authored trailers. One file: candy-code/tests/Tui/DiffReview/DiffReviewTest.php (+19/−2), zero src or weld-only files.

- MINOR-1: testTheTurnViewNeedsAPairAndClampsWhenItFlips now dirties state (Down → selected 1, PageDown → scroll >0) before 't' and asserts the flip lands selected 0 / scroll 0. Mutation M1 (drop the 'selected'=>0,'scroll'=>0 pair from toggleTurn mutate) reddens exactly this pin.
- MINOR-2: testTheSourceReadsTheRealCheckpointHistoryBackwards now saves an UNCAPTURED checkpoint between two captured ones (hole at the newest end of the middle of the list — the newest checkpoint stays captured, so a stop-at-first-miss filter cannot pass): baseCount 2, index list [second, first], assertNotContains hole. Mutation M2 (remove the captured-only filter) reddens baseCount first. Extended existing methods — no new test FILE, no durations row owed from this round; suite test-count neutral.
- MINOR-3: figures corrected above (7+14 = 21 new methods, ≈+21T carry; welder re-pins from the green run per standing law).

Gates after the fix (targeted, plain pipe, cwd=candy-code): DiffReview 19/195 (was 19/190, +5A exact) · CheckpointDiff 11/91 · combo CheckpointDiff|DiffReview|LangParity|GlobDialect|DocFigure|SymbolCitation 152/24,974 (was 152/24,969, +5A) · consumers DiffCommand|DiffGutter|Checkpoints 71/489 · census trio 130/25,406 — all OK, "PHPUnit Deprecations: 2" declared baseline each leg. DuplicatedTestHelperDrift NOW-pair base-red untouched (inherited, master cure in flight). M1/M2 both cp+md5-restored pre-commit.
