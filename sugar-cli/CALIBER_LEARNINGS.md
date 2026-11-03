# Caliber Learnings — sugar-cli

Accumulated patterns and gotchas from implementing this library.

---

[pattern:snapshot-testing-assert-golden] — Use `sugar-testing`'s `assertGolden*` for any renderable/serializable output — ANSI presenter renders via `assertGoldenAnsi`, numeric data via `assertGolden` file equality. This pins canonical output so refactors are intentional rather than accidental regressions. Goldens are CREATE-ONLY: `UPDATE_GOLDENS=1` mints a missing fixture but never rewrites an existing one — to accept an intended render change, delete the fixture and re-record it.
