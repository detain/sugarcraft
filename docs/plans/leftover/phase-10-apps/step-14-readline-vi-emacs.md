# Step 10.14 — sugar-readline vi mode + emacs mode

**Source:** `leftover_updates_later.md` sugar-readline MEDIUM
**Branch:** `ai/readline-vi-emacs`

## Deliverable

- Vi mode (normal / insert / visual, with hjkl movements).
- Emacs mode (default; Ctrl-A / Ctrl-E / Ctrl-W etc.).

## Files

**Create:**
- `sugar-readline/src/Mode/ViMode.php`, `EmacsMode.php`.

**Modify:** main key dispatch.

## Tests

- One per mode.

## Acceptance

- `cd sugar-readline && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
