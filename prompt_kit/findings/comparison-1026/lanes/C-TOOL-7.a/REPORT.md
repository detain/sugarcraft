# C-TOOL-7.a — four background-shell registry nits — DONE

Verdict: all four brief items shipped; r1-review findings 1-4 cured. Branch `cc/C-TOOL-7.a`
on base `8d9814130`, chain (re-read from git): `3eb40269a` -> `da99eeabb` -> `2710d9d0b` ->
`b5f3aeefb` (HEAD). NEVER pushed. Files touched (nothing else):
`candy-code/src/Background/BackgroundShellRegistry.php`, `candy-code/src/Cli/Bootstrap.php`,
`candy-code/src/Tools/BuiltIn/Bash.php`, `candy-code/tests/Sessions/BackgroundShellRegistryTest.php`.

## Items

1. **forgetHandles wired** (`3eb40269a`): registry records `private int $ownerPid` (set at
   `forSession`); entry to start/status/read/stop/teardown from any other pid runs
   `dropForkInheritedHandles()` -> `forgetHandles(localIds())`; `start()` adopts the child's
   pid so a child's own spawns stay observable. No ownsSpawn gate on the stop ladder — the
   cross-fork group signal is the documented Bash.php design. Class fork paragraph +
   forgetHandles docblock rewritten from the "dropped on merge" lie to entry-ownership truth.
2. **Honest test names** (`da99eeabb`): `...FifteenHundredSeconds` -> `...ThirtyMinutes`
   (asserts 1800/2.0); `...SixtyFirstStart` -> `...SixtyFifthStart` (MAX_SHELLS=64 seeds 64,
   refuses the 65th). Renames are count-neutral.
3. **Launch-scoped dirs + sibling sweep** (`da99eeabb` + pin `2710d9d0b`):
   `Bootstrap::backgroundShells()` mints `forSession('launch-' . $pid)` (one dir per launch,
   docblock rewritten); `sweepDeadLaunchDirs()` removes past-retention sibling `launch-<pid>`
   dirs whose pid no longer answers signal 0 (posix-gated fail-open, live/name-gate/own-dir
   skips). `testTwoLaunchesKeepSeparateShellDirs` plants dead/fresh/live/name/session
   survivors and pins every gate. DISCLOSED: `da99eeabb`'s message named this pin one commit
   before it landed (`2710d9d0b` carries it).
4. **Cap the sinks, not the job** (`b5f3aeefb`, mainline branch — NOT the documented
   fallback): `buildWrapper` inner routes `> >(trap "" TERM; ulimit -f N; cat > "${EXIT%.exit}.out";
   : > "${EXIT%.exit}.outd")` (+ err twin); global `ulimit -f` gone from the outer sh. The
   trailer captures `$?` before the drain, `exec >&- 2>&-`, waits both DONE MARKERS bounded by
   `SINK_DRAIN_SECONDS=3` (bash `SECONDS` arithmetic + `sleep 0.05` poll — no `wait -n`,
   version-agnostic to bash 3.2), then writes the exit file LAST. `sinkCapped()` (settled sink
   size >= `OUTPUT_LIMIT_BYTES`, no upper bound) or the legacy `OUTPUT_LIMIT_EXIT_CODE` code
   branch derives the note; markers join the corpse-sweep regex. Bash.php answer prose flipped
   to sink-cap truth; ShellOutput.php untouched (its 153 sentence stays true via the kept code
   branch; `ShellOutputTest` :246 natural-153 pin green).

## r1-review findings cure map

1. MAJOR item-(4) unimplemented -> shipped as recorder mainline (above).
2. MAJOR fork door unpinned -> `testAForkedChildDropsTheParentsHandles` (pcntl-gated;
   child reports `before=[$id]` (inherited copies), `after=[]` (entry dropped them),
   `state='running'` (route-4); parent's `localIds()` still `[$id]` — one-way door;
   `ForkedChild::exitNow()` house discipline, verdict via file).
3. MINOR `SINK_DRAIN_SECONDS` dead -> used as the drain bound.
4. NIT EPERM caveat -> added to `sweepDeadLaunchDirs` docblock (false-dead reading is
   bounded by this user's own 0700 HOME shells root).

## Measurements (host ground truth; corrects earlier notes)

- bash here: **GNU bash 5.2.21(1)-release x86_64-pc-linux-gnu** (an earlier note said
  5.3.15 — wrong). PHP 8.3.6.
- **procsub reliability / why DONE MARKERS**: bash never lists process substitutions in
  `jobs -p` (measured empty on 5.2.21 both as script children and after `exec > >(...)`), so a
  `jobs`- or `wait`-based recorder drain is vacuous on this host; the marker files are observed
  as filesystem facts instead. Flush-before-settle re-probed under markers: 3/3 late-write
  bytes present in the sink when the exit file appeared (settle 0.46s).
- flood `cat /dev/zero 2>/dev/null` through the real path: settled ~1.4-2.1s, sink landed
  82767872 bytes (>= cap; kernel lets the crossing burst land — shell-probe shapes even saw
  2x134217728; base global-cap path landed exactly 67108864 exit 153), writer's code came back
  **1** here (141/153 equally possible through other plumbings) — the pin asserts int code +
  size + note, never the number. err sink 0.
- daemon boundary `( ... ) & exit 0` (TERM-proof holder): exit file landed at 2.40s under the
  3s bound, orphan ALIVE after settle — the `X & exit 0` settled-row boundary stays pinned
  (existing :301-era test green; settle may now trail a true exit by up to the bound when a
  daemon inherits the pipes — docblock states).
- recorder TERM-ignore: without it the stop-ladder's first rung killed the err recorder before
  it drained the leader's `got-term` note (test :247 red); `trap "" TERM` makes the
  disposition inherited-ignored, only the KILL rung ends a recorder.

## macOS /bin/bash 3.2 carry

3.2 lacks `wait -n` — deliberately not used; markers + `SECONDS` + `[ -f ]` + `sleep 0.05` all
exist there. Process substitution is a bash (not POSIX-sh) feature, so the recorders live
inside the inner `bash -c` and the outer plain-sh arm (single-bound `SHELL_INNER` +
`command -v timeout` probe + dual `exec`) keeps its no-bashisms law unchanged. Hosts without
coreutils `timeout` lose only the parent-independent wall; the sink caps and recorder markers
still apply (wrapper docblock final paragraph).

## Gates (cwd worktree candy-code, linked vendor, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'BackgroundShellRegistry'`: **OK 29 tests / 225 assertions** (renamed + new pins
  all ran green; 2 pre-existing PHPUnit deprecations).
- `--filter 'BashBackground|ShellOutput'`: OK 23 / 85.
- `--filter 'DescriptorInheritanceGuard'`: OK 15 / 5111 — NO new proc_open token (recorders
  are inside the existing wrapper string).
- `--filter 'Shell|Background'`: 816 tests, **1 failure — INHERITED, NOT OURS**:
  `Tui\ShellContrastTest::testNoFileUnderSrcTuiNamesAColourItself` accuses
  `src/Tui/AgentColor.php:93` + `src/Tui/WindowStrip.php:362`; reproduces isolated on this
  base, zero of my hunks touch src/Tui — reported for the weld/owner.
- five-guard `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'`:
  133 tests, **ONLY the staleness pair red** (headline+artifact 25,537 vs live 25,937 — the
  gap includes foreign post-pin growth plus my +4 methods: twoLaunches + flood-count-neutral
  + bigfile + fork; weld's re-pin duty, figure files untouched per law).

## Deviations / disclosures

- r1 finding-1 cure arrived as a fourth commit (`b5f3aeefb`) on the reviewed tip, per the cure
  order ("ONE commit on top of 2710d9d0b") — total 4 commits, not 3.
- `testTheSinkCapStillFellsARunawayLog` is the flood test RENAMED and re-pinned, not an added
  duplicate; same for the wrapper pin rename.
- `sanitizeSessionId` stays private (launch names are constructed already-safe upstream).
- The fork test's child asserts only via its report file; a child-side assertion failure fails
  the parent through `assertArrayNotHasKey 'thrown'`.
