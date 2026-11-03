# Caliber Learnings — candy-flappy

Accumulated patterns and gotchas from implementing this library.

---

[pattern:variable-pipe-gap] — Difficulty scaling via variable gap height: gap shrinks from 6→3 as score increases (1 cell per 5 points, floor at 3). Implemented in `PipeGenerator::gapHeightForScore()` — called by `PipeGenerator::makePipe()` every time a new pipe spawns. This keeps the game challenging without becoming impossible.

[pattern:snapshot-testing-assert-golden] — Use `sugar-testing`'s `Assertions::assertGoldenAnsi` for ANSI playfield renders (`tests/fixtures/*.golden`). Comparison is byte-exact and CREATE-ONLY: a differing golden fails and is never rewritten — re-baseline by deleting the fixture and running once with `UPDATE_GOLDENS=1`. The old numeric `assertGolden` file-equality helper is retired; do not teach it in new docs.
