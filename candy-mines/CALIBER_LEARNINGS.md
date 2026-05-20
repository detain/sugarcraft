# CALIBER_LEARNINGS — candy-mines

Accumulated patterns and gotchas from building and shipping this lib.

---

[pattern:atomic-json-tmp-rename] — `DifficultyStats::save()` writes JSON to a random-named temp file under the same directory, then `rename()`s it over the target. The `rename()` is atomic on POSIX so the target file is never in a partially-written state even if a crash or power loss occurs mid-write. The temp file is cleaned up on any exception. This is the Homestead pattern for immutable domain-object persistence.

[pattern:microtime-true-timer] — `Game::elapsed()` uses `microtime(true)` (not `time()` or `hrtime(true)`) because it returns a sub-second `float` while still being monotonic enough for gameplay timing. The `startedAt` is captured as `?float` from `microtime(true)` on first reveal, then frozen as an `?int` in `elapsedSeconds` on win/lose so the final time is stable and persists cleanly to `DifficultyStats`.
