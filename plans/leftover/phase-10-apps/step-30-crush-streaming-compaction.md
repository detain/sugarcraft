# Step 10.30 — sugar-crush real-time streaming UI + context compaction

**Source:** `leftover_updates_later.md` sugar-crush H2 + H3
**Branch:** `ai/crush-streaming-compaction`

## Deliverable

- Real-time streaming render — Chat/Renderer renders incremental
  tokens as they arrive (today `StreamingCommandBackend` exists but
  not wired to UI).
- Context compaction — when context exceeds threshold, fold older
  turns into a summary.

## Files

**Modify:** `sugar-crush/src/Chat.php`, `Renderer.php`,
`Backend/StreamingCommandBackend.php`.

**Create:** `sugar-crush/src/Compaction/ContextCompactor.php`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-crush && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
