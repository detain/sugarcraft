---
paths:
  - '*/src/**/*.php'
---

# SugarCraft TUI Model + value-object pattern

- `declare(strict_types=1);` first line, PSR-4 `SugarCraft\<Sub>\` (quirk: `candy-core` → `SugarCraft\Core\`).
- Public classes `final` unless extension is part of contract. Public `readonly` properties for state.
- **Immutable + fluent**: every `with*()` returns a NEW instance through a private `mutate(...)` helper — never mutate `$this`. Canonical `candy-sprinkles/src/Style.php`; trait `candy-core/src/Concerns/Mutable.php`.
- Nullable fields need a paired `bool $XSet = false` sentinel — `mutate()` can't distinguish passed-null from omitted. Canonical `sugar-bits/src/TextInput/TextInput.php`.
- Bare-named accessors (no `get`). Factories mirror upstream: `Theme::ansi()`, `Spinner::line()`, `Spring::fps(60)`. `::new()` is default — never `::create()`/`::make()`/`::default()`.
- TUI `Model` (`candy-core/src/Model.php`): `init(): ?\Closure` · `update(Msg): [Model, ?Cmd]` · `view(): string`. `update()` returns a NEW model; side effects → `Cmd`s, never `view()`. Tutorial in `candy-core/README.md`.
- Doc-comment cites upstream: `Mirrors charmbracelet/<repo>.<Method>`. Comment WHY not WHAT.
- i18n via `Lang::t($key,$params)` wrapping `SugarCraft\Core\I18n\T` (`candy-pty/src/Lang.php`).
