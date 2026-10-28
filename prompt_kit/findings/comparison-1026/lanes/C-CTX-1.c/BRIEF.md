### C-CTX-1.c — Track `@import` children in the instruction diff
- item: C-CTX-1 (sources: p1-miss: CTX-3 `@import` children untracked)
- kind: fix · MINOR · effort S
- approach:
  - The tracker's declared limit (`InstructionDiffTracker.php:59-63`) leaves a child-only edit to an `@import`ed file invisible until the next refresh point.
  - Capture records each root document's resolved import children as stat-only entries. The children come from the loader's existing expansion, through `ImportResolver`.
  - `recheck()` stat-gates the children like roots. A changed child renders as the existing degradation line with a `Read <path>` pointer. There is no re-expansion per turn, so the cost model holds.
  - Children tracked per document are capped at 32. The docblock limit is removed.
- files.create: none
- files.modify: `candy-code/src/Context/InstructionDiffTracker.php` (`capture()`, `recheck()`, docblock), `candy-code/src/Context/InstructionFileLoader.php` (`loadDocuments()` rows carry their import children), `candy-code/src/Context/ImportResolver.php` (expose resolved child paths)
- tests: modify `candy-code/tests/Context/InstructionDiffTurnContextTest.php`. A child-only edit renders one pointer line; an unchanged child costs one stat and renders nothing.
- docs/guards: `candy-code/docs/MEMORY.md` (instruction-diff paragraph)
- serialized: [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionDiffTracker.php] [hot:candy-code/src/Context/InstructionFileLoader.php]
- deps: C-CTX-1.a
- phase: normal
- ruling: none
- risks: deep import trees add stat calls on every step. The cap bounds them, and the docs state the cap.

