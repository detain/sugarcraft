### C-AGT-6.c — `--worktree [name]` main sessions
- item: C-AGT-6 (sources: GAP-GIT-08)
- kind: gap · effort M
- approach:
  - `--worktree [name]` (TUI and `-p`) parses in `ArgvParser` and `ParsedArgs`.
  - Before `Bootstrap::app()`/`NonInteractive`, `bin/candy-code` calls a new `Cli\MainWorktree::enter($root, $name)`. It reuses `WorktreeManager::createWorktree()` keyed `main-<name>` (branch `candy-code/<name>`, auto-name when bare) and returns the worktree path as the root.
  - A shutdown hook runs `releaseIfUnused()`, so the worktree is removed when it has no commits or diff and kept (and announced) otherwise.
  - Refused outside a git work tree, and with `serve`.
  - Bootstrap itself is untouched; the root swap happens in bin.
- files.create: `candy-code/src/Cli/MainWorktree.php`
- files.modify: `candy-code/bin/candy-code` (root swap, the `$strictValueFlags` list :189 if valued), `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/Help.php`, `candy-code/src/Agents/WorktreeManager.php` (main-session naming helper), `candy-code/lang/en.php` (`cli.argv.worktree.*`, `cli.worktree.*`), `candy-code/README.md` (CLI flags)
- tests: create `candy-code/tests/Cli/MainWorktreeTest.php` (temp repo: create, root is the worktree, an unused one is removed at release, one with commits is kept, non-git refused); modify `candy-code/tests/Cli/ArgvParserTest.php`, `candy-code/tests/Cli/LaunchFlagsTest.php`
- docs/guards: `candy-code/tests/Config/ReadmeRosterDriftTest.php` (flag roster); the ArgvParser/bin flag mirror in `ArgvParserTest`. WorktreeManager's git calls are existing accounted sites, so no child-lifetime row.
- serialized: [headless] [lang: `cli.*worktree*`] [docs:README.md]
- deps: C-AGT-2.a, C-AGT-2.b, C-AGT-2.c (item order)
- phase: normal
- ruling: none
- risks: [headless] is also claimed by C-AGT-7.b, C-AGT-4.d and C-SES-1. One owner per wave.

