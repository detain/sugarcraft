# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:smith-waterman-two-row]** `FuzzyMatch` uses a two-row DP matrix instead of a full O(m×n) table for Smith-Waterman local alignment. Rows are swapped (not copied) on each query-character iteration, keeping memory at O(c) where c is candidate length. Consecutive character matches receive a +5 adjacent bonus; mismatches cost -3; gap open -5; gap extend -1. Use this pattern for any bounded-memory fuzzy string scoring in PHP.
- **[pattern:filter-state-machine]** List filter state is modelled as a three-state enum (`FilterState::unfiltered → filtering → filtered`). `withFilterFn()` clones the model, saves `originalItems`, applies the filter, and transitions the enum. `withoutFilter()` restores `originalItems` and resets to `unfiltered`. The enum documents the transition contract explicitly — never infer state from item count alone.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

## Mouse hit-testing

- Mouse hit-testing self-contained via candy-mouse. Don't pass Managers around for new code.

## Buffer diffing

- `Model::View()` holds a `?Buffer $previousFrame`; on each render it diffs against the prior frame and emits only delta ops via `DiffEncoder`.
- Reset `previousFrame` on window resize, cursor-position-lost, or first paint — diffing across these boundaries produces visual corruption.
- **Source:** step-27 ai/buffer-diff-consumers
