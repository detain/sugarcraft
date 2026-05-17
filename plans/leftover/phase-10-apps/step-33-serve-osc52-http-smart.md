# Step 10.33 — candy-serve OSC 52 clipboard + HTTP smart protocol

**Source:** `leftover_updates_later.md` candy-serve §2.5 + §3.2
**Branch:** `ai/serve-osc52-http-smart`

## Deliverable

- OSC 52 clipboard wiring — route through candy-vt OSC handler (after
  candy-vt OSC support exists per its research; if missing, add a
  minimal OSC handler in candy-serve and document the dependency).
- HTTP smart protocol server (git-over-HTTP).

## Files

**Create:** `candy-serve/src/Clipboard/Osc52.php`,
`HttpSmartProtocol/Server.php`.

## Tests

- One per feature.

## Acceptance

- `cd candy-serve && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
