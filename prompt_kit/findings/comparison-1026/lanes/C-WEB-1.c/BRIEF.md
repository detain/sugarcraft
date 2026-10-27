### C-WEB-1.c — `@`-mention index refreshes at every turn end (TUI + serve)
- item: C-WEB-1 (sources: WEB-5-P2, TUI-1-P1, p2-miss: @-mention freshness)
- kind: fix · MAJOR · S
- approach:
  - **Root cause.** `MentionIndex::files()` (`candy-code/src/Attachments/MentionIndex.php:103-111`) memoizes one `git ls-files` per root per process. `forget()` (:540-560), "the hook a future refresh command would call", has only test callers. `FsMethods.php:136-137` routes every git-root `fs.search` through the memo.
  - **Effect.** A file the agent writes never appears in the TUI popup or in the web `@` list until restart.
  - **Fix.** Wire the dormant hook. `TurnRunner::complete()` (`src/Host/TurnRunner.php:891`) calls `MentionIndex::forget()` as its first statement, so cancelled turns count too. TurnRunner is the one settle seam both Chat and SessionHost share, which fixes TUI and serve together. The next `@` re-lists, `--others` included, so untracked files from Write, ApplyPatch or Bash appear.
  - **Docblock.** Truth-flip the class docblock (:19-27, "invisible … until the process restarts").
- files.create: `candy-code/tests/Host/TurnRunnerMentionFreshnessTest.php`
- files.modify: `candy-code/src/Host/TurnRunner.php` (`complete()`), `candy-code/src/Attachments/MentionIndex.php` (class + `forget()` docblocks)
- tests: create `candy-code/tests/Host/TurnRunnerMentionFreshnessTest.php`: a file written while a started turn runs is absent before `complete()` and present after it, untracked included. Modify `candy-code/tests/Server/FsSearchTest.php`: search, create an untracked file, finish a turn, search again, and the file is listed with no `forget()` in the test body.
- docs/guards: MentionIndex docblock only (no README/docs prose pins the staleness).
- serialized: [hot:candy-code/src/Host/TurnRunner.php]
- deps: none
- phase: normal
- ruling: none
- risks: `forget()` is process-wide, so in serve every root re-lists once after any session's turn. That costs one bounded `git ls-files` (10 s GitRunner ceiling), the same as today's first `@`.

