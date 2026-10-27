### C-TOOL-7.a — Background-shell registry nits
- item: C-TOOL-7 (sources: SHL-1-P2, SHL-1-P3, SHL-1-P4)
- kind: fix · MINOR · effort M
- approach: Four fixes on one file family, kept in one lane so the registry is touched once.
  - (1) Wire, don't delete, the dead `forgetHandles()` (`candy-code/src/Background/BackgroundShellRegistry.php:692`; zero callers). The registry remembers the pid that owns `$handles`. On entry to `start/status/read/stop/teardown`, a process whose `getmypid()` differs (a forked tool or turn child) calls `$this->forgetHandles($this->localIds())`, so a child never `proc_get_status`/closes handles it did not spawn. Rewrite both docblocks (:109-114, :684-690) to say exactly that.
  - (2) Rename the lying tests: `tests/Sessions/BackgroundShellRegistryTest.php:388` `…FifteenHundredSeconds` (it asserts 1800) and `:473` `…SixtyFirstStart…` (`MAX_SHELLS = 64`).
  - (3) Per-launch shell dir: `Bootstrap::backgroundShells()` (`candy-code/src/Cli/Bootstrap.php:8405`) mints `forSession('main')` for every launch on the machine. Use `forSession('launch-' . getmypid())` (through the existing `sanitizeSessionId`), and have `sweepCorpseFiles()` also remove sibling `launch-*` dirs whose pid is dead and whose mtime is past `CORPSE_RETENTION_SECONDS`.
  - (4) The `ulimit -f` cap (`buildWrapper()` :371-387) fells any job that writes a >64 MiB file. Cap the SINKS, not the job: inside the inner `bash -c`, route stdout and stderr through process-substitution recorders `>(ulimit -f N; exec cat > "$OUT")`. The cap is scoped to the recorders, and the trailer `wait`s for them before writing the exit file. The OUTPUT_LIMIT narrative is then derived from sink size ≥ cap at settle. Measured fallback, if bash's procsub `wait` proves unreliable: keep `ulimit -f` and state the cap in Bash's schema sentence (`Bash.php` ~:268) and in ShellOutput's exit note.
- files.create: none
- files.modify: `candy-code/src/Background/BackgroundShellRegistry.php` (class docblock OUTPUT/fork paragraphs, start(), buildWrapper(), sweepCorpseFiles(), forgetHandles() docblock, observe exit narrative), `candy-code/src/Cli/Bootstrap.php` (backgroundShells() :8396-8407 + docblock), `candy-code/src/Tools/BuiltIn/Bash.php` (background schema sentence ~:268 if the fallback is taken), `candy-code/src/Tools/BuiltIn/ShellOutput.php` (exit-note wording)
- tests: modify `candy-code/tests/Sessions/BackgroundShellRegistryTest.php` (two renames; add `testAForkedChildDropsTheParentsHandles` (pcntl-gated: the child's `localIds()` is empty after first use), `testABigFileTheCommandWritesIsNotCapped` (65 MiB `dd` to a non-sink path succeeds), `testTheSinkCapStillFellsARunawayLog`, `testTwoLaunchesKeepSeparateShellDirs`), `candy-code/tests/Tools/BashBackgroundTest.php` (schema prose pin if it moves)
- docs/guards: no new proc_open token (the recorders live inside the existing wrapper); re-run `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`
- serialized: [bootstrap:backgroundShells] [hot:candy-code/src/Background/BackgroundShellRegistry.php]
- deps: C-TOOL-1.a (same file; sequence)
- phase: normal
- ruling: none
- risks: procsub semantics differ on bash 3.2 (macOS stock); the lane must measure there or take the documented fallback for that host.

