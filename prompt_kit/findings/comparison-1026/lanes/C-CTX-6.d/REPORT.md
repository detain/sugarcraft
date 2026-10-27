# C-CTX-6.d — /memory open edits enter the journal

LANDED: commit `6fcfd0db8` on `cc/C-CTX-6.d` (base `c36cc1982`), worktree `/home/sites/cc-cmp-C-CTX-6.d`. Author/committer detain verified (%ae/%ce separate greps = 1/1), zero Co-Authored trailers, tree clean after commit (porcelain 0).

## Change
- `candy-code/src/Commands/EditorCommand.php`: `openFile(string $path, ?\Closure $onSaved = null)` captures the file hash at arm time through an `is_file()` door (absence is the ordinary VIS-2b shape — first pass used bare `sha1_file` and tripped a PHP warning in `EditorCommandTest::testOpenFilePutsTheGivenPathOnTheExecChannel`; fixed to is_file-guarded reads at BOTH arm and completion so `failOnWarning` stays green); `openCollect()` takes the `$onSaved`/`$hashBefore` pair and runs the hook exactly once on clean exit 0 AND changed hash; a warning string returned by the hook rides under the opened row, never swallowed. Docblocks truth-flipped for the hook (C-CTX-6.d). No new Lang keys. Existing 4-arg `openCollect` calls unaffected (params optional).
- `candy-code/src/Chat.php` (memoryOpenArm region ONLY, 2 hunks @15826/@15860): passes `static fn (): ?string => MemoryHistoryCommand::record($history, "memory: /memory open {$id}")`, with `$history = MemoryHistory::forStore($home)` — the arm answers before `MemoryCommand::run()`'s commit-wrap ever sees `open`, which was the root cause.
- NEW `candy-code/tests/Chat/MemoryOpenJournalTest.php` — 3 tests / 29 assertions: edited-file-via-open lands in `/memory log` under its own subject AND not under `OUTSIDE_SUBJECT`; unchanged open records nothing; hook-gate precision at EditorCommand level (clean+unchanged → 0 runs, clean+changed → 1, exit 2 → no extra, start-failure → no extra).

## DUR gate row (for scripts/parallel-tests-durations.tsv at closeout — NOT edited here)
- `tests/Chat/MemoryOpenJournalTest.php` (3T; observed ~5s file time)

## Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml)
- `--filter 'Memory|Editor'`: Tests: 737, Assertions: 6402, Failures: 2 — BOTH FOREIGN BASE-REDS, each re-proven red at pristine base source (`git show HEAD:` swap + cp restore, md5-verified): `DreamPassTest::testChatSchedulesThePassAtTurnCloseAndReportsWhatChanged` and `SettingsEditorTest::testSearchingTypesFiltersAcrossCategoriesAndEscClearsBeforeClosing` (foreign `git` settings-category drift). Zero failures in `MemoryOpenJournal`/`EditorCommandTest`/`MemoryLogRestoreCommandTest`/`MemoryCommandTest`.
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`): Tests: 133, Assertions: 15720, Failures: 2 = staleness pair ONLY.
- Staleness figures: pinned 25,537 vs live — base tree already 26,121 (pre-existing +584 foreign drift at base c36cc1982); my lane moves live enum +3T (→ 26,124). Closeout re-pin owns the pair; suite-figure.json/durations.tsv untouched here.
- Red-before-fix proof: at base src, MemoryOpenJournalTest reds tests 1 and 3 (2F, 24A); test 2 (negative pin) green both worlds. With fix: 3/3 green.
- PHPUnit Deprecations: 2 on every run = pre-existing `AccessorDeprecationTest` private data-provider baseline (foreign era), not this lane.
- `php -l` clean on all three files. Structural pin `testOpenFileWiresItsCompletionToOpenCollectAndNeverToThePasteCollector` still green (openFile slice has exactly one `self::openCollect(`, zero `self::collect(`).

## Notes
- No lang-key change needed (record-failed warning key `cmd.memory.record-failed` already exists and rides openCollect).
- Repo-tier notes open through this path record against the home history store, mirroring exactly how the `/memory` router's mutating sub-commands record (`forStore($home)`); repository stores are never versioned by design (pre-existing pin).
- Chat.php touched only inside `memoryOpenArm` docblock+return. No other files.
