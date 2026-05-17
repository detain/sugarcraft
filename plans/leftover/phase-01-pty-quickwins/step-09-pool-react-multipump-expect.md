# Step 01.09 — PtyPool ReactPHP test + MultiPump docs/example + Expect↔Recorder

**Source:** `leftover_updates.md` P6-LO-02 + P6-LO-03 + P6-LO-04 (bundle)
**Branch:** `ai/p6-followups`
**Bundle hint:** three small follow-ups on P6 stretch items

## Deliverable

Three loose ends from phase P6:

1. **PtyPool ReactPHP integration test** — verify the pool works
   under a `React\EventLoop\Loop::run()` session without signal
   double-handling.
2. **MultiPump example + README section** — `MultiPump` shipped but
   has no `examples/multi-pump.php` or doc; users won't discover it.
3. **Expect ↔ Recorder integration** — `Expect` is a synchronous
   wrapper; it cannot tee into a Recorder today. Add a
   `withRecorder(?Recorder)` wither.

## Files

**Create:**
- `candy-pty/examples/multi-pump.php` — spawn two shells; tee each
  master to stdout with a prefix; quit on Ctrl-C.
- `candy-pty/tests/PtyPoolReactLoopTest.php` — boots a ReactPHP loop,
  acquires/releases from the pool, asserts no signal double-handling.
- `candy-pty/tests/ExpectRecorderIntegrationTest.php` — `Expect` with
  a `?Recorder` attached records a scripted dialog; `Player::play()`
  reproduces it.

**Modify:**
- `candy-pty/src/Expect.php` — add `withRecorder(?Recorder $r): self`
  via the `mutate()` helper. Calls to `send()` and matched `expect()`
  also call the recorder's `recordInputBytes()` and `recordOutput()`.
- `candy-pty/src/Posix/MultiPump.php` — add an example reference in
  the class doc-comment.
- `candy-pty/README.md` — add an "Multi-pump" section with the
  example snippet.
- `candy-pty/composer.json` — `require-dev` `sugarcraft/candy-vcr`
  (for the ExpectRecorder test); add path-repo if missing.

## Acceptance

- `cd candy-pty && vendor/bin/phpunit --filter "PtyPool|MultiPump|Expect"` green.
- `php candy-pty/examples/multi-pump.php` runs interactively.
- README "Multi-pump" section renders correctly.
- `php tools/check-path-repos.php` green.

## Notes

- Use the PHPUnit watchdog pattern (see brief) — these tests touch
  PTY/FFI.
- The ReactPHP loop interaction is the Risk #3 item from the original
  plan that was never tested; this finally closes it.
- Keep `MultiPump` API surface unchanged — this step is docs + example,
  not a refactor.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
