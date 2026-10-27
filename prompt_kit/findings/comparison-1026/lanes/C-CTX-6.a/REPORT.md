# C-CTX-6.a — served idle auto-compaction alarm survives a running turn — DONE

Commit: 527f958cf87ad78ee0277afada4e49604908d9a3 on cc/C-CTX-6.a (worktree /home/sites/cc-cmp-C-CTX-6.a, base master 09da7d3e9). 2 files, +183/-13. Author/committer detain@interserver.net verified (separate greps), zero Co-Authored trailers.

## What shipped (SessionHost.php, regions exactly as declared)
- New private stamp field `lastSettledAt` (second idle clock, C-CTX-6.a docblock).
- `settleWith()`: stamps `lastSettledAt` + `armIdleAutoCompaction()` after releaseQueue — every settle re-arms.
- `onIdleAutoCompaction()`: busy early-return split — a fire into a running turn now re-arms instead of dying with the nulled slot; verdict measures idleness from max(lastPromptAt, lastSettledAt) via new `$idleAnchor`.
- Docblock truth-flips (timer field, arm method) in same commit. 60-min threshold constant untouched; single-slot preserved (arm cancels first); release()/teardown regions untouched.
- Tests (existing file, no new files): 3 pins — mid-turn fire re-arms (watch alive, nothing billed); idleness counts from settle (fold NOT billed moments after reply); settle replaces the busy-fire alarm with a fresh single-slot one + stamps the clock. Red-first confirmed: exactly 3 failures on base code, 10 existing green throughout.

## Gates (plain pipe, cwd worktree/candy-code, env -u LINES -u COLUMNS)
- --filter 'SessionHost': OK 57T/283A, exit 0 (2 config-scan load deprecations pre-existing, not from this file — direct run clean).
- --filter 'Idle|AutoCompact|Compaction': OK 461T/22159A, exit 0 (same 2 deprecations).
- Five-guard: 133T/15752A, ONLY the staleness pair red (pinned 25,537 vs live 26,181 — foreign-era lag; closeout re-pin carries this lane's +3T). DocFigure/GlobDialect/DuplicatedTestHelper/ChildWallClock all green.

## DUR / seams
- DUR: none (no new test files; no durations/suite-figure/dist/protocol touches).
- Seam for closeout: README/suite-figure.json re-pin (staleness pair predates this lane by +641T; mine adds +3).
- No doc rows owed (brief: docs/guards none for this step).
