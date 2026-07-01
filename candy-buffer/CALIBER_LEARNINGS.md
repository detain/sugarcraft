# candy-buffer — Caliber Learnings

## Accumulated Learnings

### 2026-07-01 — candy-core dev-master dependency carries stability risk
Pattern: When a Sugarcraft lib depends on `sugarcraft/candy-core`, prefer a stable version constraint once available. Using `dev-master` risks pulling breaking changes in CI.
Anti-pattern: Do not ship production code that depends on `@dev` dependencies without a plan to pin.
Source: Phase 6 Item 8.1 (findings/plan_candy-buffer.md)

### 2026-05-31 — DiffEncoder tracks cursor + SGR state across ops
Pattern: DiffEncoder carries running cursor position and SGR style between ops; transitions are only emitted when state actually changes — skip an unnecessary MoveCursorOp if already there, skip an SGR if style hasn't changed.
Anti-pattern: Don't reset cursor or SGR state between op emits; that discards the context the encoder needs to stay minimal. The optimiser is what makes the byte stream minimal; don't bypass it.
Source: step-26 ai/buffer-diff-impl

### 2026-05-28 — Buffer/Cell intentionally minimal
Pattern: Buffer/Cell are the shared cell-grid model — rich styling logic belongs in candy-sprinkles, not here.
Anti-pattern: Don't add rendering concerns, SGR emission, or terminal-specific behaviour to this package.
Source: step-02 ai/candy-buffer-new
