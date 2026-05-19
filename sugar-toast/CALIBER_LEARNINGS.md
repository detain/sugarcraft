# SugarToast — Caliber Learnings

Accumulated patterns and gotchas discovered while building and auditing
sugar-toast.

---

## [pattern:sugar-toast-i18n] Per-library i18n facade

When adding i18n to a leaf library that has no internal deps other than
`candy-core`, follow the canonical pattern established by sugar-wishlist,
sugar-calendar, and sugar-table:

1. **`lang/en.php`** — flat `array<string, string>` keyed by translation
   key (e.g. `'type.info'`, `'dismiss'`). Return type hint via
   `@return array<string, string>` docblock. `declare(strict_types=1)` at
   top.

2. **`src/Lang.php`** — `final class Lang` wrapping
   `SugarCraft\Core\I18n\T`. Bakes the library namespace (`'toast'`) into
   every key. Mirrors the sugar-wishlist/calendar/table facade pattern.
   Registers the namespace + lang dir on every call (safe; `T::register`
   is idempotent).

3. **Translation keys** — use dot notation (`type.info`), not camelCase.
   Avoid generic keys like `'label'` — prefix with context (`type.label`).

4. **`ToastType::label()`** — the canonical i18n accessor for toast type
   labels. Returns `Lang::t('type.' . $this->value)`. Call sites use this
   instead of hardcoding `'Info'` / `'Warning'` etc.

5. **`LangCoverageTest`** — scans `src/` for `Lang::t()` call patterns
   and verifies every key exists in `lang/en.php`. Prevents silent missing
   translations. Include one test per key + a wildcard-pattern test for
   dynamic keys.

6. **`candy-core` dep** — the `T` class lives in `candy-core`, so every
   i18n lib needs `sugarcraft/candy-core: dev-master` in `require` plus a
   path-repo entry in `repositories`.

---

## [gotcha:toast-type-symboldata] SymbolSet data lives on the enum

`ToastType` provides `nerdIcon()`, `unicodeIcon()`, `asciiPrefix()`, and
`color()` directly on the enum case. The `icon(SymbolSet $set)` method
dispatches to the appropriate method. No separate data class is needed.
