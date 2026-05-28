# Step 05.03 — sugar-toast i18n via Lang::t()

**Source:** `leftover_updates_later.md` §2 i18n
**Branch:** `ai/toast-i18n`

## Deliverable

Add i18n surface for sugar-toast: alert level labels (Info / Warning /
Error / Success), default dismiss prompts, "X notifications" counters.

## Files

**Create:**
- `sugar-toast/lang/en.php`.
- `sugar-toast/src/Lang.php`.

**Modify:**
- Every file under `sugar-toast/src/` using English literals.

**Tests:**
- `sugar-toast/tests/LangCoverageTest.php`.

## Acceptance

- `cd sugar-toast && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
