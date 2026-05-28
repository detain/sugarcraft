# Step 07.04 — candy-vt scrollback buffer (P1.1)

**Source:** `leftover_updates_later.md` candy-vt P1.1
**Branch:** `ai/vt-scrollback`

## Deliverable

Scrollback buffer — preserves rows that scroll off-screen for later
retrieval. Today `Screen/Screen.php` drops scrolled-off rows. Add a
ring-buffer scrollback with configurable size (default 1000 lines).

## Files

**Create:**
- `candy-vt/src/Screen/Scrollback.php` — ring buffer of `Row[]`.
  Configurable max size; default 1000.

**Modify:**
- `candy-vt/src/Screen/Screen.php` — `scrollUp(int $n)` pushes the
  evicted rows into `Scrollback` before dropping them. Add accessor
  `scrollback(): Scrollback`.
- `candy-vt/src/Terminal/Terminal.php` — `withScrollbackSize(int): self`.

## Tests

- `candy-vt/tests/Screen/ScrollbackTest.php` — write 1100 lines into a
  24-row screen; assert first 100 lines are in scrollback at the right
  positions.

## Acceptance

- `cd candy-vt && vendor/bin/phpunit --filter Scrollback` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
