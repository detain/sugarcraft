# C-TOOL-7.b — Ls bounded heap — BUILD REPORT (W11)

## Landed
- `candy-code/src/Tools/BuiltIn/Ls.php`: `collect()` now keeps a bounded
  `SplMaxHeap` (anon subclass, `compare()` = strcmp on basename) of the
  `maxEntries` smallest basenames + a `$total` counter, instead of
  accumulating every surviving path and `usort`ing it. The heap root is the
  largest name retained; a smaller arrival evicts it, a larger one is
  dropped. Basenames are unique in a directory, so the extracted+reversed
  order is exactly the old `usort` prefix. The return tuple gained the total
  (`array{0: list<string>, 1: int, 2: int, 3: int}`); `execute()` dropped
  its post-hoc cap break and the truncation note now reports
  `; %d more entries not shown` (prefix `[truncated: stopped after %d entries`
  kept byte-identical so `testCapStopsAtMaxEntriesAndAnnouncesItself` never
  moves).
- **:306 comment truth-flip:** the old docblock claimed "the walk IS the
  cost being bounded" — false. Filtering, `is_dir` and the jail `realpath`
  test stay full-walk (ignored/escaped counts must be exact, per the block's
  risks; the design's "defer realpath where possible" resolves to NOT
  possible on the main path, said out loud in the jail-filter comment). What
  is bounded now is RETENTION and sorting: O(maxEntries), not O(directory
  size).
- `candy-code/tests/Tools/BuiltIn/LsTest.php`: +1 test
  `testHugeDirectoryKeepsTheFirstNByNameWithoutHoldingAll` — 20k-file
  fixture (bijection `i*7919 % 20000`, `str_pad` names, fopen loop, built
  in-test not in class setUp so the other 18 tests never pay the ~2s;
  class runs ~2s total), expected first-50 derived from arithmetic alone
  (no filesystem walk, no tool call feeds the oracle, no walker call site
  added for TreeWideGuardRoster to chase), memory leg =
  `memory_get_usage(false)` baseline + `memory_reset_peak_usage()` window
  around the capped call.

## Measured (evidence/measure.php, same box)
- heap capped run peak-held: 23,712 B → bound is 1,500,000 B.
- faithful collect-all mutant (caller break restored, answer identical):
  peak-held 4,291,112 B → memory leg EXACTLY 1 red; answer legs green.
  Coarse mutant (heap-only revert): also reddened the same-first-N pin —
  both risk legs discriminate. Restored via cp, md5-verified.

## Gates (plain pipe, cwd worktree candy-code, env -u LINES -u COLUMNS)
- `tests/Tools/BuiltIn/LsTest.php`: OK (19 tests, 41 assertions) incl. new.
  Brief's "34T-class `--filter 'Ls'`" did not reproduce as stated —
  `--filter 'Ls'` selects 6639 tests repo-wide (Lsp*/other substrings); the
  file-level run is the 19T truth. Disclosed for the reviewer.
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudgetTest|DuplicatedTestHelperDriftTest`):
  133T, failures = the staleness pair ONLY (25,537 pinned vs 26,288 live —
  foreign-era drift + this lane's +1 rides it; weld re-pin duty).
- PRE-EXISTING base reds in the wide `Ls` window, NOT this lane and NOT in
  scope: `TreeWideGuardRosterTest` x2 — unrostered walker
  `Context/MemoryRecallBlockTest.php => RecursiveDirectoryIterator($this->dir,FilesystemIterator::SKIP_DOTS)`
  (failing at base 2ad862baf with zero lane edits; owner/foreign glance).

## DUR
none (modify-only; no new `*Test.php` file, durations TSV row for LsTest.php
already present and its duration class unchanged ~2s).
