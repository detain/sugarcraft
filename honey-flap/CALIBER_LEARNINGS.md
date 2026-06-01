# Caliber Learnings — honey-flap

Accumulated patterns and gotchas from implementing this library.

---

[pattern:variable-pipe-gap] — Difficulty scaling via variable gap height: gap shrinks from 6→3 as score increases (1 cell per 5 points, floor at 3). Implemented in `PipeGenerator::gapHeightForScore()` — called by `PipeGenerator::makePipe()` every time a new pipe spawns. This keeps the game challenging without becoming impossible.

[pattern:snapshot-testing-assert-golden] — Use `candy-testing`'s `assertGolden*` for any renderable/serializable output — ANSI playfield renders via `assertGoldenAnsi`, numeric trajectories via `assertGolden` file equality. This pins canonical output so refactors are intentional rather than accidental regressions.
