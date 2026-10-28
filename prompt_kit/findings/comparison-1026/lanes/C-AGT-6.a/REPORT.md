# C-AGT-6.a — `/commit` builtin — DONE

- Commit: `96f14da66` on `cc/C-AGT-6.a` (base `c4c13f101`), 11 files, +713/−8. `%ae`/`%ce` grep==1 detain, 0 Co-Authored. Never pushed.
- Shipped: `CommitHostCommand` (echo + `CommandEffect::async`, off-turn; given message → commit, none → title-model draft via `CommitMessageWriter::request()/subjectFrom()` with `fallback()` on absent/failed model); NEW `AutoCommitter::commitAll($subject)` = hardened `commit()` recorded kind `command` (+ same-region `changedPaths()` whole-tree porcelain `-z` walk keeping rename sources, `draftDiff()`); sha+subject+file-count reply; 'Nothing to commit' plain reply; read-only refusal via shared `host.command.read_only` (NOT read-only-safe = default, no roster edit); one-line `Chat::handleCommitCommand`; `builtin-commands/2850-commit.php` (no paletteAction, judgement recorded); en.php `cmd.commit.*`+`host.commit.*` EOF block; COMMANDS.md+README via `tools/gen-command-docs.php --write`.
- Guards in-step: R7 — `ForeignCommandDiscoveryTest::testForeignRowsCannotShadowABuiltInAndLoseSilently` now plants `.claude/commands/commit.md` and asserts the built-in still answers; `PaletteSupersetDispatchTest` SLASH_ONLY_SURVIVORS gained the `commit` row (census is ORDER-sensitive = registry filename order — row sits before `pr`).
- Gates (cwd candy-code, env -u LINES -u COLUMNS, -c phpunit.xml, plain pipe):
  - `OK (71 tests, 1529 assertions)` — BuiltInCommands+CommandDocsDrift+ReadmeRosterDrift+ForeignCommandDiscovery+PaletteSupersetDispatch+InFlightInputQueue.
  - `OK (146 tests, 2147 assertions)` — tests/Host/Commands/ family + tests/Workspace/AutoCommitterTest.php.
  - Target pair pre-family: `OK (22 tests, 313 assertions)` (CommitHostCommandTest 7T + AutoCommitterTest 15T).
- DURATIONS ROW OWED (welder): NEW `candy-code/tests/Host/Commands/CommitHostCommandTest.php` (7T). No suite-figure.json/durations.tsv touched.
- Deviations / findings:
  (i) AutoCommitter modification = changedPaths+draftDiff+commitAll trio, one region under the plan's `(commitAll)` parenthetical.
  (ii) One DTO, two landers: the async run resolves `AutoCommittedMsg` (the existing both-host lander that bills title usage) — TUI prints its turn-mode `notice()` wording ('Auto-committed … /undo reverts it.') while served hosts get the `host.commit.*` describe row; chosen to keep zero files outside scope (new Msg class + Chat handler arm would be); disclosed in the class docblock.
  (iii) FINDING (pre-existing, out-of-scope): `undo()`'s ABSENT_IN_PARENT rule refuses whole-undo of any commit introducing a new path — so an add-files `/commit` (or turn auto-commit) is NOT undo-revertable; design-agt's '/undo can revert it' holds only edit-only commits. Corrected honestly in my tests/docblock and `host.commit.committed` says '/undo can take it back.'; `AutoCommittedMsg::notice()`'s unconditional '/undo reverts it.' is the pre-existing over-promise — closeout glance owed.
  (iv) `host.commit.not_repo` reply key added (non-git root answers, no spawn).
  (v) SlashDispatchTest in my brief's gate list is a phantom (no such file in candy-code) — dropped.
  (vi) harness notes: arrow-fn params cannot be bare `\Throwable` (kept `$e`); `git status --porcelain` lists tracked changes before untracked (path-order pins follow it); a stray 0-byte `candy-code/"` from a shell-quoting accident was removed pre-commit.
