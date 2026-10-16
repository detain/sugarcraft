# Caliber Learnings — sugar-cli

Accumulated patterns and gotchas from implementing this library.

---

[pattern:snapshot-testing-assert-golden] — Use `candy-testing`'s `assertGolden*` for any renderable/serializable output — ANSI slide renders via `assertGoldenAnsi`, numeric data via `assertGolden` file equality. This pins canonical output so refactors are intentional rather than accidental regressions.
