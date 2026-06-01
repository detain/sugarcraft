# Caliber Learnings — candy-kit

Accumulated patterns and gotchas from implementing this library.

---

[pattern:snapshot-testing-assert-golden] — Use `candy-testing`'s `assertGolden*` for any renderable/serializable output — ANSI slide renders via `assertGoldenAnsi`, numeric data via `assertGolden` file equality. This pins canonical output so refactors are intentional rather than accidental regressions.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
