# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:façade-delegation]** `FuzzyMatch` is a thin delegating shim over the candy-fuzzy SSOT (`SmithWatermanMatcher` + `ScoringProfile`). The two-row DP scorer and its Smith-Waterman constants (adjacent +5, mismatch -3, gap open -5, extend -1) live in candy-fuzzy, not here — the pre-delegation note describing them as lister internals was stale. Keep scoring-algorithm learnings in candy-fuzzy's CALIBER_LEARNINGS; this lib only owns the `\Stringable`-item contract and the input-order tiebreak in `match()`.
- **[pattern:filter-state-machine]** List filter state is modelled as a two-state enum (`FilterState::unfiltered ⇄ filtering`). `withFilterFn()` clones the model, saves `originalItems`, applies the filter, and sets `filtering`. `withoutFilter()` restores `originalItems` and resets to `unfiltered`. There is no separate `filtered` resting state — a live filter stays `filtering`. The enum documents the transition contract explicitly — never infer state from item count alone.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

## Mouse hit-testing

- Mouse hit-testing self-contained via candy-mouse. Don't pass Managers around for new code.

## Buffer diffing

- `Model::View()` holds a `?Buffer $previousFrame`; on each render it diffs against the prior frame and emits only delta ops via `DiffEncoder`.
- Reset `previousFrame` on window resize, cursor-position-lost, or first paint — diffing across these boundaries produces visual corruption.
- **Source:** step-27 ai/buffer-diff-consumers
