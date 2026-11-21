# sugar-serve-protocol — Caliber Learnings

## Accumulated Learnings

### 2026-11-18 — Read side only; the host keeps the write side
Pattern: this package holds the read-only halves of the serve state directory, discovery record and token file. Creating, repairing, or rotating any of them stays with the host (`candy-code`).
Anti-pattern: do not move a write path in here, and do not add a `candy-code` require. The dependency runs one way: the host requires this package.
Source: plan_plugin.md §4.12 (phase 1a, pure additions)

### 2026-11-18 — Constants are the wire contract, pinned by the host
Pattern: `Protocol` values are the wire contract. Change them only together with the host constants that they replace, in the same change.
Source: plan_plugin.md §4.12, §6.2 (ConstantsPinTest lives in candy-code)

### 2026-11-18 — Discovery reader returns a raw array, not a value object
Pattern: `Discovery\DiscoveryRecordReader::read()` checks the boundary (lstat, regular-file, JSON depth 8) and returns the raw array. The host's `fromArray` keeps the field rules, so no `DiscoveryRecord` value object lives in this package.
Source: plan_plugin.md §4.12 (owner decision, 2026-11-18)
