---
paths:
  - '*/src/**/*.php'
---

# SugarCraft TUI Model + value-object pattern

- `declare(strict_types=1);` first line, PSR-4 namespace `SugarCraft\<Sub>\` (quirk: `candy-core` → `SugarCraft\Core\`).
- Public classes `final` unless extension is part of contract. Public `readonly` properties for state.
- **Immutable + fluent**: every `with*()` returns a NEW instance through a private `mutate(...)` helper — never mutate `$this`. Canonical: `candy-sprinkles/src/Style.php`.
- For nullable fields, the `mutate()` helper can't distinguish "passed null" from "omitted"; add a paired `bool $XSet = false` sentinel parameter. Canonical: `sugar-bits/src/TextInput/TextInput.php` `withValidator()` + `validateSet`.
- Bare-named accessors (no `get` prefix). Factory methods mirror upstream: `Theme::ansi()`, `Spinner::line()`, `Spring::fps(60)`, `Projectile::gravity()`.
- TUI Model contract (`SugarCraft\Core\Model`): `init(): ?\Closure` · `update(Msg $msg): array` returning `[Model, ?Cmd]` · `view(): string`. `update()` returns a NEW model; side effects live in `Cmd`s, never in `view()`. Tutorial in `candy-core/README.md`.
- Doc-comment cites upstream: `Mirrors charmbracelet/<repo>.<Method>` — required when porting from a Go upstream.
- Don't comment WHAT — only WHY (non-obvious constraints, upstream issue links, invariants).
- i18n: `Lang::t($key, $params)` wraps `SugarCraft\Core\I18n\T` with namespace baked in; never throw raw English strings. Canonical: `sugar-wishlist/src/Lang.php`.
