---
paths:
  - '*/lang/*.php'
  - '*/src/Lang.php'
  - LOCALES.md
---

# i18n locale files

- File at `<slug>/lang/<code>.php`. Codes from `LOCALES.md` recommended set: `en`, `fr`, `de`, `es`, `pt`, `pt-br`, `zh-cn`, `zh-tw`, `ja`, `ru`, `it`, `ko`, `pl`, `nl`, `tr`, `cs`, `ar`.
- Returns `array<string, string>` keyed by short id (e.g. `'color.invalid_hex' => 'invalid hex color: {hex}'`). Keep `{placeholder}` names intact across translations.
- Lookup chain: exact locale → base language (`fr-fr` → `fr`) → `en` → raw key. A single `fr.php` covers `fr-fr`/`fr-ca`/`fr-be`/`fr-ch`/`fr-lu`. Only add regional variants (`pt-br`, `zh-cn`, `zh-tw`) when wording diverges.
- Each lib has a thin `Lang::t($key, $params)` wrapper with its namespace baked in (canonical in `sugar-wishlist/src/Lang.php`):

```php
use SugarCraft\Core\I18n\T;
final class Lang {
    public static function t(string $key, array $params = []): string {
        T::register('<ns>', __DIR__ . '/../lang');
        return T::translate('<ns>.' . $key, $params);
    }
}
```

- App-level overrides via `T::overrideNamespace('<ns>', '/path/to/lang')`.
- Translation PRs: one language across every lib that has `en.php`, not per-lib.
