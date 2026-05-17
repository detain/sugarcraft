# Step 01.13 — candy-mosaic Termios::isAtty() + WezTerm detection fix

**Source:** `leftover_updates.md` CC-LO-06 + research candy-mosaic
WezTerm detection bug (bundle)
**Branch:** `ai/mosaic-detect-fix`
**Bundle hint:** two small bugs in `candy-mosaic/src/Detect.php`

## Deliverable

1. Replace direct `posix_isatty(0)` + `posix_isatty(1)` calls with
   the canonical `\SugarCraft\Pty\Contract\Termios::isAtty()` (or a
   new `\SugarCraft\Core\Util\TtyDetect::isAtty()` static that
   delegates to candy-pty). Single-source-of-truth violation.
2. Fix the WezTerm detection: `Detect.php` currently classifies
   WezTerm as BOTH a Kitty candidate AND an iTerm2 candidate when
   `TERM_PROGRAM=WezTerm`. WezTerm should be its own protocol family
   (Kitty graphics + iTerm2 inline both work, but only Kitty is
   actively supported; default to Kitty).

## Files

**Modify:**
- `candy-mosaic/src/Detect.php`:
  - Lines ~236-237 — replace `posix_isatty(0) && posix_isatty(1)`
    with `Termios::isAtty(STDIN) && Termios::isAtty(STDOUT)` via
    `TermiosFactory::open(...)`. Or, equivalently, call a new
    `\SugarCraft\Core\Util\TtyDetect::isAtty($stream)` that wraps
    the candy-pty call.
  - Lines ~386-400 — for `TERM_PROGRAM=WezTerm`, return Kitty
    protocol family only; do not also list iTerm2 candidate.
- `candy-mosaic/composer.json` — confirm `sugarcraft/candy-pty` is
  in `require` (likely transitive via candy-core).

**Create (recommended):**
- `candy-core/src/Util/TtyDetect.php` — static helper:
  `isAtty(resource $stream): bool` delegating to candy-pty. Single
  call site for every lib that needs "is this a TTY?". Other libs
  (candy-flip, candy-freeze) can adopt later.

## Tests

- `candy-mosaic/tests/DetectTest.php`:
  - Mock or fixture `TERM_PROGRAM=WezTerm`; assert protocol family
    is Kitty only.
  - For non-TTY input, assert `isAtty()` returns false without
    invoking `posix_isatty` directly (verify via reflection if
    needed, or just verify behaviour parity).

## Acceptance

- `grep -n "posix_isatty\|stream_isatty" candy-mosaic/src` returns
  nothing.
- `cd candy-mosaic && vendor/bin/phpunit` green.
- `php tools/check-path-repos.php` green.

## Notes

- The WezTerm fix is a behaviour change — if anyone depended on the
  "WezTerm is also iTerm2" classification, this breaks them. Verify
  with `grep -rn "TERM_PROGRAM=WezTerm" /home/sites/sugarcraft` first.
- TtyDetect goes in candy-core because other consumers (sugar-bits,
  sugar-prompt, candy-log) will want it without taking a direct
  candy-pty dep. candy-core already depends on candy-pty.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
