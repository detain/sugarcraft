# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:sugar-table]** i18n via `SugarCraft\Table\Lang::t()`. Each library
  carries its own thin `Lang` facade (e.g. `src/Lang.php`) that wraps
  `SugarCraft\Core\I18n\T` with the library namespace baked in. Translation files
  live in `lang/<code>.php` (e.g. `lang/en.php`). The facade calls
  `T::register(namespace, __DIR__ . '/../lang')` on every invocation — this is
  idempotent so no static bootstrap is needed. See `sugar-wishlist/src/Lang.php`
  and `sugar-calendar/src/Lang.php` for the same pattern.
