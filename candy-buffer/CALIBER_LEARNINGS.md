# candy-buffer — Caliber Learnings

## Accumulated Learnings

### 2026-05-28 — Buffer/Cell intentionally minimal
Pattern: Buffer/Cell are the shared cell-grid model — rich styling logic belongs in candy-sprinkles, not here.
Anti-pattern: Don't add rendering concerns, SGR emission, or terminal-specific behaviour to this package.
Source: step-02 ai/candy-buffer-new
