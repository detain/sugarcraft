# candy-buffer — Caliber Learnings

## Accumulated Learnings

### 2026-05-31 — DiffEncoder tracks cursor + SGR state across ops
Pattern: DiffEncoder carries running cursor position and SGR style between ops; transitions are only emitted when state actually changes — skip an unnecessary MoveCursorOp if already there, skip an SGR if style hasn't changed.
Anti-pattern: Don't reset cursor or SGR state between op emits; that discards the context the encoder needs to stay minimal. The optimiser is what makes the byte stream minimal; don't bypass it.
Source: step-26 ai/buffer-diff-impl

### 2026-05-28 — Buffer/Cell intentionally minimal
Pattern: Buffer/Cell are the shared cell-grid model — rich styling logic belongs in candy-sprinkles, not here.
Anti-pattern: Don't add rendering concerns, SGR emission, or terminal-specific behaviour to this package.
Source: step-02 ai/candy-buffer-new
