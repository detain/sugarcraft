# Step 05.01 — sugar-calendar i18n via Lang::t()

**Source:** `leftover_updates_later.md` §2 i18n + sugar-calendar
**Branch:** `ai/calendar-i18n`

## Deliverable

`sugar-calendar` lacks `Lang.php` / `lang/`. Add the canonical i18n
pattern from `sugar-wishlist/src/Lang.php`, replace inline English
strings with `Lang::t($key, $params)`.

## Files

**Create:**
- `sugar-calendar/lang/en.php` — assoc array `key => string`. Keys
  for: month names, day-of-week names, "Today", "Yesterday", "Tomorrow",
  weekday abbreviations, error strings ("Invalid date", etc.), keyboard
  hint strings.
- `sugar-calendar/src/Lang.php` — namespace baked in:
  ```php
  final class Lang {
      public static function t(string $key, array $params = []): string {
          return T::namespaced('sugar-calendar', $key, $params);
      }
  }
  ```

**Modify:**
- Every PHP file in `sugar-calendar/src/` — replace inline English
  strings with `Lang::t('key')`.

**Tests:**
- `sugar-calendar/tests/LangCoverageTest.php` — assert every key
  referenced in `src/` exists in `lang/en.php`.

## Acceptance

- `grep -rn "echo \|return ['\"][A-Z]" sugar-calendar/src` returns at
  most fixture strings and class names — no user-facing English
  literals.
- `cd sugar-calendar && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
