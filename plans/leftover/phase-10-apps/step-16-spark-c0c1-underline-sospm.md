# Step 10.16 — sugar-spark C0/C1 descriptions + SGR underline 4:1-4:5 + SOS/PM

**Source:** `leftover_updates_later.md` sugar-spark §5 P1-P3
**Branch:** `ai/spark-c0c1-underline-sospm`

## Deliverable

- C0 (0x00-0x1F) and C1 (0x80-0x9F) control-code descriptions in the
  Inspector output.
- SGR underline-style variants 4:1–4:5 (consume from candy-vt
  step 07.05's enum).
- SOS / PM (start-of-string / privacy-message) sequence handling.

## Files

**Modify:** `sugar-spark/src/Inspector.php`.

**Create:** `sugar-spark/src/C0C1.php` lookup table.

## Tests

- One per feature.

## Acceptance

- `cd sugar-spark && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
