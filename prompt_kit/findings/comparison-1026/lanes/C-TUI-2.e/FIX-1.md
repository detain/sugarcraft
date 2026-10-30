# C-TUI-2.e — round-1 review fixes

Mechanical: reviewer-verified content was already uncommitted in the worktree; committed exactly, nothing adjudicated or redesigned.

- MAJOR-1 `candy-code/tests/App/DiffReviewWiringTest.php:145` — `assertSame($wheeled, $clicked)` (structurally unsatisfiable: update() mints a new Chat instance per turn) replaced with `assertSame($app->diffReview, $clicked->diffReview, 'the band is keyboard-only: clicks are inert')`.
- MAJOR-2 `candy-code/tests/Chat/UndoRedoDiffCommandsTest.php` — five methods' bare-`/diff` host cases flipped to `--print` per design (bare form's shell slot is pinned in DiffReviewWiringTest); class docblock records the split. 16 lines.

Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe):
- `--filter DiffReviewWiringTest` → 9T/87A green (expected 9T/87A, exact).
- `--filter UndoRedoDiffCommandsTest` → 10T/97A green (expected 10T/97A, exact).

No adjustments needed as-committed. NITs (inline FQNs in UndoRedoDiffCommandsTest) left as-is — the reviewer prescription covered only the two MAJORs and no behavior change was authorized beyond them.
