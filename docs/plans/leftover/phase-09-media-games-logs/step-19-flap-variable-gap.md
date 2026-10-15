# Step 09.19 — candy-flappy variable pipe-gap height

**Source:** `leftover_updates_later.md` candy-flappy §5.2
**Branch:** `ai/flap-variable-gap`

## Deliverable

Vary pipe-gap height across pipes for increasing difficulty / variety.
Today gap is constant.

## Files

**Modify:**

- `candy-flappy/src/PipeGenerator.php` — gap-height varies (smaller as
  score increases, with a floor).

## Tests

- `candy-flappy/tests/PipeGeneratorTest.php`.

## Acceptance

- `cd candy-flappy && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
