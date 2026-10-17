# Step 10.30 — candy-code real-time streaming UI + context compaction

**Source:** `leftover_updates_later.md` candy-code H2 + H3
**Branch:** `ai/crush-streaming-compaction`

## Deliverable

- Real-time streaming render — Chat/Renderer renders incremental
  tokens as they arrive (today `StreamingCommandBackend` exists but
  not wired to UI).
- Context compaction — when context exceeds threshold, fold older
  turns into a summary.

## Files

**Modify:** `candy-code/src/Chat.php`, `Renderer.php`,
`Backend/StreamingCommandBackend.php`.

**Create:** `candy-code/src/Compaction/ContextCompactor.php`.

## Tests

- One per feature.

## Acceptance

- `cd candy-code && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
