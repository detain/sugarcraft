# CALIBER_LEARNINGS — candy-hermit

Accumulated patterns and gotchas for this library.

[pattern:item-interface-numbered] — Structured `Item` interface (number() + value()) enables numbered lists with arbitrary ordinals, not just 1-based sequential indices. `FilteredItem` readonly impl satisfies the contract. Custom impls can encode database IDs, file paths, or any structured key alongside display text.

[pattern:filehistory-jsonl] — `FileHistory` stores one JSON-encoded item per line (`{"n":1,"v":"apple"}\n`) using `FILE_APPEND | LOCK_EX` for safe concurrent writes. Reading uses `fgets()` in a loop — line-based I/O avoids loading the entire file into memory for large histories.

[pattern:setfilterfn-post-fuzzy] — `setFilterFn(Closure): bool` is applied *after* the text-based fuzzy filter in `applyFilter()`. Both conditions must pass for an item to appear. This ordering lets you combine user typing (substring match) with programmatic filtering (e.g., category, date range, visibility flag).

[pattern:sigwinch-signalforwarder-attachsigwinchtofd] — Hermit's `attachSigwinch()` wires `SignalForwarder::attachSigwinchToFd()` to forward terminal resize events. The closure captures `$hermit` by value so it stays alive across the signal. The callback reads `COLUMNS`/`LINES` env vars as the initial size tuple before calling the stored `$onResize` closure. This pattern is safe for use in long-running TUI processes.

[pattern:border-style-composition-sprinkles] — Hermit's `withBorder()` and `withStyle()` accept `candy-sprinkles` `Border` and `Style` objects directly — composition rather than inheritance. Both are nullable; `null` means no decoration. The border/style is stored and can be queried back via `border()`/`style()` accessors. This follows the same composition pattern used in `sugar-boxer` and `sugar-stickers`.
