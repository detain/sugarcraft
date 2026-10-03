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

### 2026-10-03 — SGR colour operands clamp, never mask
Pattern: `Buffer::styleFromSgr()` clamps 38;2/48;2 components and 38;5/48;5 indices into 0-255 (`channel()`), matching sugar-veil's `penFromSgr()`; terminals saturate, so `300` is 255. A truncated extended colour (`38;5`, `38;2;1`) ends the parse.
Anti-pattern: Don't `& 0xFF` a colour component (300 wraps to 44, an unrelated colour), and don't let an unconsumed `5`/`2` operand fall through to the attribute branches (it set blink/faint).
Source: crush_libs follow-up (sugar-veil #4 parity)
