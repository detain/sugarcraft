# CALIBER_LEARNINGS — candy-minesweeper

Accumulated patterns and gotchas from building and shipping this lib.

---

[pattern:atomic-json-tmp-rename] — `DifficultyStats::save()` delegates to sugar-core `AtomicJsonFile`: an exclusive flock on a `.<name>.lock` sidecar serialises writers, the payload goes to a same-dir temp that is `fsync`ed and `rename()`d over the target. The `rename()` is atomic on POSIX so the target file is never in a partially-written state even if a crash occurs mid-write; the temp is cleaned up on any exception. The lock sidecar intentionally persists (reusable inode — tests must carve it by exact name). This is the house pattern for immutable domain-object persistence; earlier prose here described a hand-rolled tmp+rename from before the fold.

[pattern:microtime-true-timer] — `Game::elapsed()` uses `microtime(true)` (not `time()` or `hrtime(true)`) because it returns a sub-second `float` while still being monotonic enough for gameplay timing. The `startedAt` is captured as `?float` from `microtime(true)` on the first action that actually CHANGES the board — reveal/chord paths compare the returned `Board` by reference identity against `$this->board` first, so a no-op attempt (space on a flagged cell, chord on an unrevealed one) leaves the clock unarmed. Then it freezes as an `?int` in `elapsedSeconds` on win/lose (custom boards too) so the final time is stable and persists cleanly to `DifficultyStats`.

[pattern:o1-win-revealedCount] — `Board::isWon()` is O(1) because `revealedCount` is incremented on every cell reveal during flood-fill and chord. The alternative — scanning all cells on every `isWon()` call — would be O(width×height) per move. The counter is stored as a constructor parameter so every `with*()` call preserves it.

[pattern:board-atomic-serialization] — `Board::serialize()` emits a versioned JSON payload (`{v:1,...}`) covering every cell field. `Board::unserialize()` validates all required keys and array shapes before constructing a `Board`, throwing `InvalidArgumentException` with a generic message on any malformation. The `v` version tag is enforced on read: payloads tagged with a different version are rejected with a clear "Unsupported board serialization version" error rather than half-parsed — a future format bump mints `v:2` and widens the accepted set deliberately instead of silently misreading old/new files.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

### 2026-05-31 — mouse-zone-per-cell
Pattern: Zone-tag each interactive cell via `Mark::zone("cell:$row:$col", $glyph)` — `Scanner::hit($x, $y)` then maps directly to a cell address without any coordinate arithmetic. The renderer returns `(string, Scanner)` so the model can own the scanner.
Anti-pattern: Raw x/y mouse coordinates without zone abstraction require the click handler to reverse-engineer grid layout.
Source: step-32 ai/games-shared
Correction (docs-fix-1023): the "renderer returns (string, Scanner)" shape above describes `Renderer::renderWithScanner()`, which exists and is tested but has ZERO production callers — the live click path goes `Game::onMouse` → `Renderer::resolveClick()`, which rebuilds and rescans the interior per click (fine at these board sizes; a per-frame memo is the ready-made amortization if it ever matters). The learning stands as the zone-tagging idiom, not as a description of wired data flow.
