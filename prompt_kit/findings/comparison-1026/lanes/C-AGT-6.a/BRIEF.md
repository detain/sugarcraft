### C-AGT-6.a — `/commit` with a drafted message
- item: C-AGT-6 (sources: GAP-GIT-03, p2-miss: built-in `/commit` (×2))
- kind: gap · effort M
- approach: `/commit [message]` is a builtin with a host body, so serve and web get it as `runsIn: server`.
  - `CommitHostCommand` returns `CommandEffect::async`, which collects every changed path (`git status --porcelain -z`).
  - With no message, it drafts the subject on the title backend through `CommitMessageWriter::request()`/`subjectFrom()`, falling back to `CommitMessageWriter::fallback()`.
  - It commits through a new public `AutoCommitter::commitAll(string $subject)`: the hardened `commit()` path, recorded as kind `command`, so `/undo` can revert it.
  - It replies with the sha, subject and file count. "Nothing to commit" is a plain reply.
  - It is not read-only-safe: the default, so no roster edit.
  - The Chat handler is one line through `runHostCommand`. The new spec file is per-command-disjoint.
- files.create: `candy-code/builtin-commands/2850-commit.php`, `candy-code/src/Host/Commands/CommitHostCommand.php`
- files.modify: `candy-code/src/Workspace/AutoCommitter.php` (`commitAll`), `candy-code/src/Chat.php` (new `handleCommitCommand`), `candy-code/lang/en.php` (`cmd.commit.*`, `host.commit.*`), `candy-code/docs/COMMANDS.md` + `candy-code/README.md` (regenerated slash rosters)
- tests: create `candy-code/tests/Host/Commands/CommitHostCommandTest.php` (temp repo: given message → commit and sha; no message → scripted title backend drafts `feat: …`; clean tree → reply, no commit; read-only window refused; `/undo` reverts it); modify `candy-code/tests/Workspace/AutoCommitterTest.php` (`commitAll`)
- docs/guards: `php tools/gen-command-docs.php --write` + `candy-code/tests/Commands/CommandDocsDriftTest.php`, `candy-code/tests/Config/ReadmeRosterDriftTest.php`, `candy-code/tests/Commands/BuiltInCommandsTest.php`
- serialized: [chat:new handleCommitCommand] [lang: `cmd.commit.*`, `host.commit.*`] [docs:COMMANDS.md] [docs:README.md]. The new `builtin-commands/2850-commit.php` is per-command-disjoint, not [commands].
- deps: C-AGT-2.a, C-AGT-2.b, C-AGT-2.c (item order)
- phase: normal
- ruling: none
- risks: a project's own `.claude/commands/commit.md` already shadows by precedence rules. The lane checks that the R7 strict-bottom foreign-command rule keeps the built-in visible.

