# Step 03.09 — sugar-dashboard NotificationQueue (Homedash dual-ring)

**Source:** `leftover_updates_later.md` Dash-07
**Branch:** `ai/dash-notification-queue`

## Deliverable

Replace single-shot `Components/Toast/Notification.php` with a dual-ring
NotificationQueue per Homedash's pattern:
- `items[max 20]` — active, dismissable.
- `history[max 50]` — append-only.
- `current()` returns head of items.
- `recent(int $n)` returns last n from history newest-first.

## Files

**Create:**

- `sugar-dashboard/src/Components/Toast/NotificationQueue.php` — final,
  immutable, fluent.
- `sugar-dashboard/src/Components/Toast/Level.php` — enum: `Info`,
  `Warning`, `Error`.

**Modify:**

- `sugar-dashboard/src/Components/Toast/Notification.php` — keep as the
  single-notification DTO that the queue holds.
- `sugar-dashboard/src/Components/Toast/Toast.php` — adapter that wraps
  a NotificationQueue and renders the current head as a toast.
- `sugar-dashboard/examples/dashboard-status.php` — replace any single-shot
  notification with the queue; show a 5-notification alert panel.

## Tests

- `sugar-dashboard/tests/Components/Toast/NotificationQueueTest.php`:
  - Push 25 items; assert items count caps at 20, history caps at 50.
  - `current()` returns head; popping advances.
  - `recent(5)` returns last 5 newest-first.
  - Withers return new instance (immutability).

## Acceptance

- `cd sugar-dashboard && vendor/bin/phpunit --filter Notification` green.
- `dashboard-status.php` shows accumulating notifications.

## Notes

- Use ring-buffer semantics or two slices — either works for these
  sizes. Document choice in CALIBER_LEARNINGS.md.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
