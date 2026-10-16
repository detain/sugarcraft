# Step 05.08 — sugar-flexbox i18n via Lang::t()

**Source:** `leftover_updates_later.md` §2 i18n
**Branch:** `ai/stickers-i18n`

## Deliverable

i18n surface for sticker labels and any scroll-indicator strings.
(Trivial in volume — sticky widgets have very little user-facing text.)

## Files

**Create:**

- `sugar-flexbox/lang/en.php`.
- `sugar-flexbox/src/Lang.php`.

**Modify:**

- Files under `sugar-flexbox/src/`.

**Tests:**

- `sugar-flexbox/tests/LangCoverageTest.php`.

## Acceptance

- `cd sugar-flexbox && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
