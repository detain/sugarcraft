# CALIBER_LEARNINGS — candy-hermit

Accumulated patterns and gotchas for this library.

[pattern:item-interface-numbered] — Structured `Item` interface (number() + value()) enables numbered lists with arbitrary ordinals, not just 1-based sequential indices. `FilteredItem` readonly impl satisfies the contract. Custom impls can encode database IDs, file paths, or any structured key alongside display text.

[pattern:filehistory-jsonl] — `FileHistory` stores one JSON-encoded item per line (`{"n":1,"v":"apple"}\n`) using `FILE_APPEND | LOCK_EX` for safe concurrent writes. Reading uses `fgets()` in a loop — line-based I/O avoids loading the entire file into memory for large histories.

[pattern:setfilterfn-post-fuzzy] — `setFilterFn(Closure): bool` is applied *after* the text-based fuzzy filter in `applyFilter()`. Both conditions must pass for an item to appear. This ordering lets you combine user typing (substring match) with programmatic filtering (e.g., category, date range, visibility flag).
