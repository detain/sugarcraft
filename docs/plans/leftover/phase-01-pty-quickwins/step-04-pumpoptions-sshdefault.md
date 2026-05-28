# Step 01.04 — PumpOptions::sshDefault() named constructor

**Source:** `leftover_updates.md` P2-LO-01
**Branch:** `ai/pumpoptions-sshdefault`
**Bundle hint:** small, ~50 LOC

## Deliverable

Add `PumpOptions::sshDefault(): self` named constructor that bakes the
SSH-tuned values currently duplicated in
`candy-wish/src/Transport/InProcessTransport.php` comments. After this,
candy-wish calls `PumpOptions::sshDefault()` rather than reconstructing
the constants.

## Files

**Modify:**
- `candy-pty/src/PumpOptions.php` — add the static `sshDefault()`
  method. Values come from the originals tracked in
  `InProcessTransport`:
  - `chunkBytes` default
  - `selectTimeoutUs` default
  - `flushDeadlineSec` (SSH-tuned)
  - `stdinEofGraceSec` (SSH-tuned)
  - `veof` default
- `candy-wish/src/Transport/InProcessTransport.php` — replace the
  inline constants / numeric literals with `PumpOptions::sshDefault()`.
- Doc-comment on `sshDefault()` cites: "matches values previously
  hardcoded in InProcessTransport for SSH session behaviour".

## Tests

- `candy-pty/tests/PumpOptionsTest.php` — add test for `sshDefault()`
  returns the expected DTO shape.
- `candy-wish/tests/InProcessTransportRunChildTest.php` should still
  pass byte-for-byte.

## Acceptance

- `grep -n "FLUSH_DEADLINE_SEC\|STDIN_EOF_GRACE_SEC" candy-wish/src`
  returns nothing (the constants are gone; they live as
  `PumpOptions::sshDefault()` defaults).
- candy-wish SSH end-to-end suite passes with identical timing
  behaviour.

## Notes

- Do **not** change the actual numeric values. The SSH suite is
  sensitive to these.
- If you discover that other consumers (candy-vcr, future SSH-server
  middleware) need different presets, add separate named-constructors
  (`PumpOptions::recordingDefault()`, etc.) in this step or a follow-up.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
