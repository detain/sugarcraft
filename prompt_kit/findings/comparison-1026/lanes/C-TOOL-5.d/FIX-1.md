# C-TOOL-5.d FIX-1 — r1 MINOR (step-local): lsp unknown-flag pin

Finding (REVIEW-1.md): tests/Cli/LspSubcommandTest.php lacked the
`lsp list --all` unknownFlags/exit-2 pin its own ParsedArgs entry
rationale claims (brief 7c). Cure: one parse-pin method.

Fix shape (single new method testAnUnknownFlagOnListIsAUsageErrorFromTheBin
in the usage-doors section):
- Parse half (in-process): ArgvParser::parse(['candy-code','lsp','list','--all'])
  -> unknownFlags === ['--all'] and subcommandArgs === ['list'] (recorded,
  never applied as an operand).
- Exit half: the refusal is bin/candy-code's door (non-empty unknownFlags
  exits 2 before dispatch, bin:392), so it cannot be pinned through the
  file's in-process runLsp helper — one inline exec-preceded proc_open of
  the real bin with `lsp list --all`, drain-to-EOF then judge proc_close
  (reap-first, no single-shot proc_get_status race), assert rc
  NonInteractive::EXIT_CONFIG, 'unrecognized option' + '--all' named on
  stderr, stdout empty.
- No new helper method (nothing for DuplicatedTestHelperDrift to accuse);
  no glob-shaped literals (GlobDialect); tests/ proc_open needs no
  child-lifetimes roster row (tool walks autoload roots, not autoload-dev).

Gates: LspSubcommandTest filter (expect 13T, +1 method over 12T/108A),
php -l, git show --stat == exactly 1 file.

## LANDED (round 1)
- New tip: 8cee26a2daca378dc4bd8f414a2580a7c935a3a1 (cat-file type=commit,
  same-command proof), atop 8bf866168. Parent 9fb981c5c chain unchanged.
- git show --stat: candy-code/tests/Cli/LspSubcommandTest.php | 42 +++++++
  1 file changed, 42 insertions(+) — exactly 1 file, weld-only files untouched.
- Author/committer: %ae=detain@interserver.net (grep -c 1), %ce likewise 1,
  Co-Authored trailers 0.
- Gate: cwd=candy-code -c phpunit.xml env -u LINES -u COLUMNS plain pipe
  --filter LspSubcommandTest → Tests: 13, Assertions: 118 (baseline
  12T/108A, delta +1T/+10A), php -l clean; the 2 PHPUnit Deprecations are
  the tree-common pair documented in REVIEW-1 §5.
- git status: no tracked dirt after commit (only the untracked evidence
  dirs pre-existing).
