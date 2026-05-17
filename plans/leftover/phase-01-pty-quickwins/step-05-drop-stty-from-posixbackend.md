# Step 01.05 — Drop stty shell-outs from candy-core::PosixBackend

**Source:** `leftover_updates.md` P1-LO-01 + P1-LO-02 (bundle)
**Branch:** `ai/posixbackend-no-stty`
**Bundle hint:** these two go together — same file, same surface

## Deliverable

`candy-core/src/Util/Tty/PosixBackend.php` still shell-outs to
`stty size`, `stty -g`, `stty $saved`, and `command -v stty`. The PTY
consolidation plan said this should leave the primary path. Rewire
both `size()` and `restoreLast()` to use candy-pty primitives.

## Files

**Modify:**
- `candy-core/src/Util/Tty/PosixBackend.php`:
  - `size()` — when `posix_isatty($this->stream)` is false, try
    `openTty()` → `SizeIoctl::query($fd)` against `/dev/tty`. Drop
    the `shell_exec('stty size')` branch. Keep env-var
    (`COLUMNS`/`LINES`) fallback, then 80×24 default. Drop `hasStty()`
    entirely.
  - `restoreLast()` — replace static `$lastSttyState` string with a
    static `?\SugarCraft\Pty\Contract\Termios $rescueSnapshot`
    obtained via `TermiosFactory::open(STDIN)`. First call saves;
    second+ calls apply.
  - Drop `private static function hasStty()` and its `static $cached`.
- `candy-core/composer.json` — verify `sugarcraft/candy-pty` is already
  in `require` (should be from P1.4 of the original PTY plan). If not,
  add it + path-repo per `tools/check-path-repos.php`.

## Tests

- `candy-core/tests/Util/Tty/PosixBackendTest.php` — verify `size()`
  on a non-TTY stream falls back via env vars, not stty.
- New `candy-core/tests/Util/Tty/PosixBackendRestoreLastTest.php` —
  call `restoreLast()` twice; verify termios round-trips identically
  without invoking `stty -g`.
- `SUGARCRAFT_TERMIOS=stty vendor/bin/phpunit` still green (forces
  candy-pty's stty fallback, which is the only stty caller left).

## Acceptance

- `grep -n "shell_exec\|exec.*stty\|hasStty" candy-core/src` returns
  nothing.
- `cd candy-core && vendor/bin/phpunit` green.
- Visual confirmation that an interactive `php examples/program.php`
  in candy-core still gives sane raw mode + sane restore on exit.

## Notes

- The `SttyTermios` class in candy-pty is where the stty fallback now
  lives — single rescue path. candy-core no longer has its own.
- The `Backend` interface contract is unchanged; this is purely
  implementation cleanup.
- If `TermiosFactory::open(STDIN)` throws because STDIN is closed
  (CI runner), `restoreLast()` should silently no-op, not propagate.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
