# Step 07.07 — candy-vt BCE + combining chars + synchronized output 2026 (P2.2-4)

**Source:** `leftover_updates_later.md` candy-vt P2.2 + P2.3 + P2.4
**Branch:** `ai/vt-bce-combining-sync`

## Deliverable

- **P2.2 BCE** (Background Color Erase) — when `CSI Ps J` / `K` erases,
  fill with the current background color, not just default.
- **P2.3** Combining-char composition — Unicode combining marks attach
  to the prior base glyph; cell stores both.
- **P2.4** Synchronized output 2026 — `CSI ? 2026 h` / `l` lets a
  client batch updates atomically.

## Files

**Modify:**
- `candy-vt/src/Handler/EraseHandler.php` — uses current SGR bg.
- `candy-vt/src/Cell/Cell.php` — `combining: string` field; setter
  honors graphemes.
- `candy-vt/src/Mode/Mode.php` — `$syncOutput` field; when set, defer
  Buffer mutations into a batch; flush on disable or timeout.

## Tests

- One test per feature under `tests/Handler/` and `tests/Cell/`.

## Acceptance

- `cd candy-vt && vendor/bin/phpunit --filter "Bce|Combining|Sync"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
