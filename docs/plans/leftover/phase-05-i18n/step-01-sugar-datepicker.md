# Step 05.01 — sugar-datepicker i18n via Lang::t()

**Source:** `leftover_updates_later.md` §2 i18n + sugar-datepicker
**Branch:** `ai/calendar-i18n`

## Deliverable

`sugar-datepicker` lacks `Lang.php` / `lang/`. Add the canonical i18n
pattern from `candy-ssh-launcher/src/Lang.php`, replace inline English
strings with `Lang::t($key, $params)`.

## Files

**Create:**

- `sugar-datepicker/lang/en.php` — assoc array `key => string`. Keys
  for: month names, day-of-week names, "Today", "Yesterday", "Tomorrow",
  weekday abbreviations, error strings ("Invalid date", etc.), keyboard
  hint strings.
- `sugar-datepicker/src/Lang.php` — namespace baked in:
  ```php
  final class Lang {
      public static function t(string $key, array $params = []): string {
          return T::namespaced('sugar-datepicker', $key, $params);
      }
  }
  ```

**Modify:**

- Every PHP file in `sugar-datepicker/src/` — replace inline English
  strings with `Lang::t('key')`.

**Tests:**

- `sugar-datepicker/tests/LangCoverageTest.php` — assert every key
  referenced in `src/` exists in `lang/en.php`.

## Acceptance

- `grep -rn "echo \|return ['\"][A-Z]" sugar-datepicker/src` returns at
  most fixture strings and class names — no user-facing English
  literals.
- `cd sugar-datepicker && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
