# ParaTest for SugarCraft: review of the 2026-11-21 sweep, plus a speed and flaky-test playbook

Scope: the opencode paratest sweep in `/tmp/opencode/paratest-1121/` (candy-code, sugar-core, sugar-image,
sugar-colorprofile, sugar-async, sugar-mcp-client in progress), the installed ParaTest 7.4.9 source, upstream
ParaTest (README, issues, discussions), and new measurements and experiments made for this report. Every claim
marked **verified** was run or read in this session. Claims marked **not verified** are reasoned but not run.

Tools written for this report are in `prompt_kit/tools/paratest/` (section 9). The fixes it led to are in
section 11.

---

## 1. Summary

1. **ParaTest does not pre-assign batches. It hands out one file at a time to whichever worker is free.**
   Verified in `WrapperRunner::assignAllPendingTests()`. The lane notes say "eager batch queueing" and
   "~40 paths/worker", and that is wrong. Runs are slow because files are dispatched in **alphabetical path
   order**, so the heaviest files start late. The 2 heaviest candy-code files sit at 66% and 86% of the
   alphabet.
2. **A simple model reproduces every measured candy-code wall:** wall ≈ fixed startup (35-47 s) + a greedy
   one-file-at-a-time schedule in alphabetical order. The schedule is simulated from that run's own junit
   durations. The simulated part is 349.8 / 372.4 / 444.6 / 595.0 s for K=64/32/16/8, and the measured walls
   are 384.7 / 407.2 / 488.7 / 641.7 s.
3. **Longest-first (LPT) ordering works on stock ParaTest 7.4.9 with no patch.** ParaTest dispatches the
   config's `<file>` entries in the order they are listed (verified). `lpt-config.php` writes such a config.
   On candy-code it selects exactly 33,821 tests, the full suite (verified with `--list-tests`).
4. **One slow file sets the floor for both harnesses, not the harness choice.**
   `tests/Renderer/KeyHelpTest.php` takes 296-313 s alone. The old harness's K=24 wall of 301.77 s is that
   file's duration. With LPT, paratest reaches the same floor at K=16. Two fixes, KeyHelpTest's 200-step
   render loops and BuiltInToolTest's per-test corpus rebuild, bring the simulated K=32 schedule from 372 s
   to about 132 s.
5. **The sugar-core "crash at every K" has a different root cause from the lane's guess.**
   `PosixBackendInjectedTermiosTest` builds `new PosixBackend(\STDIN, $stub)`, which calls
   `stream_set_blocking(STDIN, false)`. In a paratest worker STDIN is the command pipe, so the wrapper's next
   `fgets(STDIN)` returns `false` and the worker exits 0. ParaTest then blames whichever file it had just
   handed out. **Verified:** that file followed by another crashes; the reverse order passes.
6. **A no-vendor-patch fix exists for the stdin problem.** `paratest-worker-prelude.php`, injected with
   `--passthru-php`, replaces the worker loop. It reads commands through a private handle and forces it back
   to blocking mode before every read. **Verified** on a synthetic case and on the real sugar-core crash
   pair, both on unpatched ParaTest. **Not yet verified** on the full candy-code suite.
7. **Don't use `--functional` on 7.4.9.** It **runs some tests more than once and silently skips others.**
   Verified: 23 executions where 18 were expected, data set `#12` ran 3 times, and a data set named `a/b`
   never ran. It also re-runs a class's data providers for every test it dispatches; BuiltInToolTest's
   provider alone costs 4.5 s. Upstream fixed the filter regex, but only for PHPUnit 13 / PHP 8.4 or later.
8. **Halving K from 64 to 1 mixes 2 opposite causes.** High K adds CPU contention, which exposes timing
   flakes. Low K means longer-lived workers that accumulate leftover state from earlier tests. Changing K also
   changes which files share a worker. Section 6 replaces the sweep with runs that each test one cause, plus
   a ledger, a worker-sequence extractor and a bisect tool that finds the polluting test file automatically.
9. **Pick K per lib as roughly total test time ÷ longest file.** Above that, extra workers add load-induced
   flakes and no speed. It fits every swept lib: sugar-colorprofile is flat from K=2 (one 59 s test),
   sugar-async plateaus at K=4, sugar-image is near its floor at K=8, and candy-code reaches its floor at
   K=16 with LPT.
10. **A new class of failure applies to the whole repo:** a helper class declared inside one test file and
    used from another. Serial phpunit loads every file up front, so this works by luck. Paratest workers load
    one file at a time, so it fails with "class not found" (sugar-async `FakeClock`). The namespace-aware scan
    found 4 real cases: sugar-async, candy-git (`FixtureGit`, 13 users), sugar-mouse and sugar-pty.
    `tools/check-one-type-per-file.php` only reads `autoload.psr-4`, so it misses `tests/`.

---

## 2. The lanes' work: what holds and what to correct

| Lane claim | Status |
|---|---|
| Pin `"brianium/paratest": "~7.4.9"` on PHPUnit 10.5 | **Holds.** 7.4.9 is the last release that accepts PHPUnit 10 (`^10.5.47`); 7.5.0 requires `^11.2.1`. The latest, 7.29.0 (2026-11-14), needs PHP ~8.4 and PHPUnit ^13.4.7. Upstream fixes will never reach this line, but its internals are also frozen. |
| Parent EventFacade-sealed crash, fixed with the compat bootstrap | **Holds.** `WrapperRunner::run()` calls `EventFacade::instance()->seal()` before `SuiteLoader` includes the bootstrap. A simpler permanent fix is in §7. |
| candy-code stdin collision; the v5 vendor patch works | **Holds.** The patched wrapper's private `fopen('php://stdin')` handle is a dup of fd 0. **It is inherited by every child process**: verified, a child sees `5 -> pipe:[…]`. Note that a dup shares O_NONBLOCK with fd 0, so v5 alone would not survive the sugar-core failure shape. The prelude re-asserts blocking mode before each read. |
| "Greedy batching / eager `assignAllPendingTests` queues all paths per worker" | **Wrong.** A worker gets the next file only when `isFree()` is true: the count of bytes in its status file equals the count of files assigned. The pipe never holds more than one path. The batch-line-theft theory doesn't fit that. Children reading or setting O_NONBLOCK on an inherited fd 0 does. |
| "paratest NEVER beat the old harness (no LPT)" | **Partly.** The ordering is fixable (§4). Both harnesses are floor-bound by KeyHelpTest. Paratest also pays 35-47 s of startup that the old harness spreads across shards. |
| sugar-core "TTY tests exit 0 and the wrapper misreads it" | **Wrong cause.** The real cause is the O_NONBLOCK leak (§1.5). The file named in each crash is the *next* file handed out, which is why it moved with K. |
| sugar-core "not fixable without test edits" | **Two fixes:** a one-line test fix (pass a private stream, not `\STDIN`), or the prelude, which needs no test change. |
| sugar-image needs no shims | **Holds.** |
| `DescriptorInheritanceGuardTest` flake "may be the v5 private fd" | **Probably not.** The test's own failure message says the marker fd "landed above fd 40, the whole window the child searches". That is descriptor accumulation from earlier tests in a long-lived worker (fails at K=8/4, passes elsewhere). One extra fd does not explain it. Find the leakers with the residue census (§6.4). |
| K=1 OOM is serial accumulation, never widen `memory_limit` | **Holds.** `--max-batch-size` (§5.4) bounds it under paratest. |
| junit root `<testsuites>` has no counts; sum the nested suites | **Holds.** |

---

## 3. How ParaTest 7.4.9 works

All verified by reading `candy-code/vendor/brianium/paratest/` (7.4.9) and `vendor/phpunit/phpunit` (10.5.67).

**Parent process** (`WrapperRunner::run()` → `SuiteLoader`):
1. Seals the PHPUnit event facade, then `include_once`s the config bootstrap. Bootstraps that register
   subscribers throw here.
2. Builds the **whole** suite with `TestSuiteBuilder`, which loads every test file and **runs every data
   provider**. On candy-code that is 15.7 s single-process (measured, unloaded); 5 files account for 11.9 s
   (§5.3).
3. Sorts only if `--order-by` is not the default, using `new TestSuiteSorter(new NullResultCache())`. With an
   empty result cache, **`--order-by=duration` and `--order-by=defects` do nothing.** `random` works
   (seeded by `--random-order-seed`), and so do `reverse`, `size` and `depends`.
4. Turns the suite into dispatch units: one per **file** by default, or one per test / data set with
   `--functional`.
   - Directory files come out **sorted alphabetically by full path** (php-file-iterator `sort($files)`).
   - `<directory>` matches come before `<file>` entries.
   - `<file>` entries keep their listed order.
5. Starts K workers with env `PARATEST=1`, `TEST_TOKEN=<1..K>` and `UNIQUE_TEST_TOKEN=<token>_<uniqid>`.
   `--no-test-tokens` drops the 2 token variables.
6. Loop every 10 ms: for each worker, if it has crashed, throw `WorkerCrashedException` naming the file it
   was *last given*. If it is free, collect its output and, with `--max-batch-size=N`, restart it after N
   units. Then give it the next unit.

**Worker** (`bin/phpunit-wrapper.php` + `ApplicationForWrapperWorker`):
- Reads one path per line **from `STDIN`** (`fgets(STDIN)`). Reaching EOF, reading `false` or reading
  `EXIT\n` ends the worker with exit 0. A test that closes, reads, or sets non-blocking on the real STDIN, or
  a child that inherits it, can therefore kill the worker. "Exit Code: 0(OK)" in a `WorkerCrashedException`
  is this signature.
- Bootstraps once per worker process, so `--max-batch-size` restarts re-bootstrap.
- Runs each file **without** `TestSuiteSorter`. Inside a file, methods always run in declaration order, even
  with `--order-by=random` (verified: 2 seeds gave different file orders and identical method order).
- Test stdout/stderr is discarded by design (discussion #922). Put diagnostics into assertion messages.
- Writes one status byte per unit. The parent merges per-worker junit, teamcity and coverage files at the
  end.

**Logs that show which worker ran what:**
- `--verbose` prints `Starting process N: …` and `Process N executing: <file>` for every dispatch. That is
  the exact per-worker file sequence (discussion #931; verified).
- `--log-teamcity` events carry `flowId` = worker pid (PHPUnit `TeamCityLogger`). This gives method-level
  sequences and durations. Not exercised in this session.

---

## 4. Speed: why runs are slow and what fixes them

### 4.1 Model and evidence (candy-code)

`sched-sim.php` replays a run's junit durations through paratest's queue, in alphabetical order and in LPT
order:

| K | Measured wall | Simulated alphabetical schedule | Residual = startup | Simulated LPT schedule |
|---|---|---|---|---|
| 64 | 384.7 s | 349.8 s | 34.9 s | 312.8 s |
| 32 | 407.2 s | 372.4 s | 34.8 s | 303.7 s |
| 16 | 488.7 s | 444.6 s | 44.1 s | 295.9 s |
| 8 | 641.7 s | 595.0 s | 46.7 s | 444.1 s |

- At K=32 in alphabetical order, KeyHelpTest (alphabet position 65.9%) starts at 68.7 s and ends at 372.4 s.
  BuiltInToolTest (85.6%) starts at 97.2 s and ends at 357.5 s. Most workers are idle from about 116 s on.
- Total test time grows with K: 3,553 s at K=8, 3,945 s at K=16, 4,460 s at K=32 and 5,973 s at K=64.
  Tests spawn child processes, so K=64 oversubscribes 64 cores, and every test, including its timeouts,
  runs slower.

### 4.2 What-if, using K=32 durations (`split-whatif.php`; simulated schedule, add 35-45 s startup)

| Scenario | K=8 | K=16 | K=24 | K=32 | K=48 | K=64 |
|---|---|---|---|---|---|---|
| Today (alphabetical) | 682.9 | 464.1 | 398.0 | 372.4 | 345.5 | 333.2 |
| LPT order only | 557.5 | **303.7** | 303.7 | 303.7 | 303.7 | 303.7 |
| LPT + KeyHelpTest split per test | 557.5 | 278.8 | 260.3 | 260.3 | 260.3 | 260.3 |
| LPT + KeyHelp split + BuiltInToolTest at 10 s | 526.2 | 263.1 | 175.4 | **131.6** | 87.7 | 76.2 |
| LPT + every file ≥ 40 s split per test | 557.5 | 278.8 | 185.8 | 139.4 | 92.9 | 69.7 |

The K=48/64 cells are optimistic, because durations inflate at high K (§4.1).

### 4.3 The 2 files that matter in candy-code

- **`tests/Renderer/KeyHelpTest.php`: 63 tests, about 304 s; median test 0.08 s.** 6 tests take 31-40 s each,
  and the rest are fast. `testScrollingThroughItShowsEveryLiveBinding` (40.4 s) and
  `testArrowsAndPagesScrollAndStopAtTheEnd` (38.4 s) loop `for ($i = 0; $i < 200; $i++)` over a **full
  `view()` render** at 120×40, at about 0.2 s per frame.
  - Bound the loop by the real scroll range, e.g. stop once `keyHelp()` stops changing or at
    `Renderer::keyHelpMaxOffset() + 1`, instead of 200.
  - Move these 6 tests into their own file (e.g. `KeyHelpScrollTest.php`) so they spread across workers.
  - 0.2 s per frame is also worth profiling as a product issue; a TUI frame should cost milliseconds.
- **`tests/Tools/BuiltInToolTest.php`: 196 tests, about 260 s, median 1.6 s.** Even
  `testAllBuiltInToolsImplementToolInterface`, which only asserts `instanceof`, takes 1.6-2.8 s.
  - `createTool()` calls `BuiltInToolCorpus::instances()`, which re-scans `src/` and builds **every** tool,
    then picks one. It does this once per test.
  - Fix: memoize `classNames()` per process and build only the requested class. Shared instances would
    create order dependence, so don't share them.
  - The same corpus scan is why this file's data provider costs 4.5 s of parent startup.

After both fixes, the longest files are about 75 s (`Agents/ProcessExecutorTest.php`, `Tui/ShellContrastTest.php`,
`PaletteClickTest.php`). Total work, not one file, then sets the wall. That also helps CI, which runs
K=min(nproc,4) on 2-4 vCPU runners: there, total work is the cost, and these 2 files are about 14% of it.

### 4.4 Other libs

| Lib | Floor | What sets it | Fix |
|---|---|---|---|
| sugar-colorprofile | 90 s at every K (serial 127 s) | `GreyRampEquivalenceTest::testEveryGreyRampMidpointColorResolvesToTheBruteForceBin`, **one method, 59.5 s**, plus `testStrideSweepAgreesWithBruteForceOnEveryColor` (27.9 s) | Chunk the brute-force sweep into a data provider of N slices and move it to its own file. Or sample in the main suite and run the full sweep in a nightly `#[Group('exhaustive')]` leg. |
| sugar-image | about 55 s | `Renderer/SixelStreamTest.php` (55 s), `Dither/DithererTest.php` (50 s); slowest single test 20 s | Split both files. With LPT, K=8 already reaches the floor (57.5 s simulated vs 68.7 s alphabetical). |
| sugar-async | about 46 s plateau from K=4 | `ChildRunLaunchTest` (45.9 s, 41 tests) | Split that file. |

### 4.5 Choosing K

**K ≈ total test time ÷ longest file, then a little more.** Above that, the longest file sets the wall and
extra workers only add contention, which creates timing flakes (§6.1).

| Lib | Total test time | Longest file | K suggested | Measured |
|---|---|---|---|---|
| candy-code | 4,460 s | 304 s | 15-16 (LPT) | LPT K=16 simulated at the floor |
| sugar-image | 343 s | 55 s | about 8 | K=64 57.1 s vs K=8 70.8 s alphabetical; LPT would close the gap |
| sugar-async | 108 s | 46 s | about 3-4 | K=4 47.9 s, K=64 46.9 s |
| sugar-colorprofile | 127 s | 90 s | 2 | flat at 89-98 s from K=2 to 64 |

Redo this after each heavy-file fix: the ratio rises as the floor drops. For candy-code after the §4.3 fixes,
expect about 32.

### 4.6 Answering "slow tests clump onto one worker"

With paratest no worker is handed a pile of slow tests in advance; assignment is dynamic. What actually
happens:

1. Slow files start late because of alphabetical order. **LPT fixes it.**
2. One file holds many slow tests, and a file can't be split across workers. **Only splitting the file, or
   speeding it up, fixes it.** `--functional` would on a fixed ParaTest, but not on 7.4.9 (§5.2).
3. Contention: slow tests are usually slow because they spawn processes, and 64 of them at once slow each
   other down. **Pick K by §4.5 rather than nproc.**

The old harness's static LPT shards can clump, if durations drift after the TSV was recorded. Paratest's
dynamic queue corrects for that automatically. That is one real advantage of switching.

---

## 5. ParaTest options: what each one is good for here

### 5.1 Options worth using

| Option | Use |
|---|---|
| `-c <lpt.xml>` | Longest-first scheduling (§4). Generate it with `lpt-config.php` from the last run's junit. It is self-updating and can't drop a file: files with no recorded duration go first. |
| `--log-junit` | Per-test durations, the source for LPT, the ledger and contention analysis. Sum the nested `<testsuite>` elements. |
| `--verbose` | Per-worker file sequence: the input to `worker-seq.php` and `bisect-polluter.php`. **Use it on every diagnostic run.** It costs one log line per file. |
| `--order-by=random --random-order-seed=N` | Shuffles **file** order across workers, reproducibly. **Record N.** It replaces LPT order, so these runs are slower, which is fine for detection. |
| `--max-batch-size=N` (`-m`) | Restarts each worker after N files. `-m 1` means a fresh process per file (cost not measured on candy-code). As a mitigation it caps accumulation: K=1 OOM, K=2 WsUpgrade. As a diagnostic, compare `-m 1` with `-m 0` (§6.3). |
| `--passthru-php="'-d' 'auto_prepend_file=…'"` | Injects the worker-loop prelude (§7.2). Can also inject `-d memory_limit`/ini for experiments. |
| `TEST_TOKEN` / `UNIQUE_TEST_TOKEN` | Give per-worker resources a namespace: tmp dirs, ports, lock files, sockets. |
| `--stop-on-defect` | For repeat loops that should stop at the first reproduction. |
| `--group` / `--exclude-group` | Quarantine leg: `#[Group('flaky')]`, excluded from the main leg and run in a repeat loop. |
| `--log-teamcity` | Method-level sequence and timing per worker pid. Not tried here. |

### 5.2 Options to avoid on 7.4.9

| Option | Why not |
|---|---|
| `--functional` | Verified with `DupProbeTest`: 13 int-keyed data sets ran as 16 executions (`#12`×3, `#10`×3, `#11`×2), a test named `testPlain…testPlain` ran twice, and data set `a/b` **never ran**. The suite still printed "OK (23 tests)". It also re-runs every data provider of the class for each unit: BuiltInToolTest would rebuild its 4.5 s provider 196 times, and HelpTest its 3.0 s provider 145 times. Use it only for a targeted `--filter` repro, never for a run whose counts matter. |
| `--order-by=duration` / `defects` | No-ops: the result cache is a NullResultCache. Upstream refused this in #502 ("requires the PHPUnit cache") and #846. |
| `-p auto` | Picks the core count; see §4.5. |

### 5.3 Parent startup (35-47 s)

Measured per-file suite-build cost (`suite-build-cost.php`, single process, unloaded): **15.7 s total**.

| File | Build cost | Tests |
|---|---|---|
| `Tools/BuiltInToolTest.php` | 4.55 s | 196 |
| `Cli/HelpTest.php` | 3.00 s | 145 |
| `Providers/ToolSchemaEncodingTest.php` | 2.23 s | 63 |
| `Tools/ToolDescriptionSentenceFloorTest.php` | 1.53 s | 45 |
| `Integration/BinCandyCodeWiringTest.php` | 0.60 s | 1,453 |
| every other file | < 0.05 s each | |

Making those 5 providers cheap (memoized corpus or source scans) cuts about 10 s of startup from every
paratest **and** plain phpunit invocation, including tiny `--filter` runs.

The rest of the 35-47 s residual is worker bootstrap under load, junit merging (12 MB) and the run tail.
That part is not broken down.

### 5.4 Version note

Upgrading to PHPUnit 12/13 + PHP 8.4 would bring:
- a fixed `--functional` filter (`preg_quote` plus a `::` anchor, issue #958),
- duration-based **sharding** (`--shard-test-distribution=duration` from PHPUnit's test-run history, for
  splitting across CI machines).

Upstream still reads worker commands from `STDIN` (HEAD `bin/phpunit-wrapper.php`). Issue #1160 (a related
wrapper deadlock) was closed as wontfix, so the stdin hazard has to be handled on our side either way.

---

## 6. Finding flaky tests with paratest

### 6.1 Failure classes in this sweep (and how each shows up)

| Class | Trigger | Signature | Examples from the sweep |
|---|---|---|---|
| **A. Timing under load** | high K, CPU oversubscription | test duration inflates 3-5× and crosses a hard-coded wall-clock ceiling | `ServeStatusStopTest::testDetachStatusUrlLogsAndStopRoundTrip` 4.9 s at K≤8 → 20.7 s at K=64, against a 20 s ceiling. `JsonSchemaEnvelopeTest::testBatchRows…` 7.2 → 28.1 s. `SessionIdPresetTest::testUnsetPreset…` 6.7 → 24.2 s. `LspConnectionShutdownTest::testDroppingTheLastReference…` 1.8 → 8.3 s against an 8 s budget. |
| **B. Accumulation / pollution** | long-lived worker (low K, `-m 0`) | normally fast tests fail with timeouts or resource errors deep into a worker | `WsUpgradeTest` ×8: 0.1 s everywhere, 5.1 s (its 5 s transport timeout) at K=2 only. `DescriptorInheritanceGuardTest` (fd > 40). K=1 OOM at `TuiLangTest.php:235` after 58 min. |
| **C. Placement / order** | which files share a worker | non-monotonic in K; pairs that move together or in opposite directions | sugar-image: Chafa + PacedAsync fail at K=8/4/2, KittyApc at K=16/1, never more than 2 at once. sugar-async `FakeClock` (deterministic at K≥4). |
| **D. Worker context** | paratest's own process model | `WorkerCrashedException … Exit Code: 0(OK)`, `EventFacadeIsSealedException` | sugar-core O_NONBLOCK on STDIN; candy-code `fclose(\STDIN)` and inherited fd 0. |
| **E. Shared resources between workers** | K ≥ 2, independent of load | collisions on fixed paths, ports, lock files | none confirmed here. `TEST_TOKEN` is the prevention. |
| **F. Intrinsic nondeterminism** | any K, including alone | fails in a repeat loop of the test on its own | `PacedAsyncRendererTest` has a **variable assertion count** (118,762 vs 118,761), a sign that the test itself takes different paths. |

### 6.2 Why the halving sweep is a blunt tool

From 64 down to 1, each step changes 3 things at once. Load drops, which hides class A. Workers live
longer, which exposes class B. File placement changes, which moves class C. A failure at one K therefore
doesn't say which cause it is. The candy-code sweep also cost about 2.3 hours of wall time (384 + 407 + 489 +
642 + 1,047 + 1,824 + 3,501 s), and K=1 only reproduced the known serial OOM.

### 6.3 A replacement protocol that tests one cause per run

Per lib, with `E` = evidence dir, `T=/home/sites/sugarcraft/prompt_kit/tools/paratest`. Every run: one
command, output redirected to one file, `</dev/null`, and the lib's env prefix (candy-code:
`CANDY_CODE_MCP_DISABLE=1 env -u LINES -u COLUMNS`).

```sh
cd /home/sites/sugarcraft/<lib>
php $T/lpt-config.php . <last-junit.xml or scripts/parallel-tests-durations.tsv> $E/lpt.xml   # once per durations refresh
# one paratest invocation; drop the CANDY_CODE_MCP_DISABLE prefix for libs other than candy-code
pt() { CANDY_CODE_MCP_DISABLE=1 env -u LINES -u COLUMNS vendor/bin/paratest -c "$E/lpt.xml" --colors=never --verbose \
         --passthru-php="'-d' 'auto_prepend_file=$T/paratest-worker-prelude.php'" "$@" </dev/null; }

# R0 baseline at the chosen K (§4.5): the reference run for the ledger
pt -p $K --cache-directory $E/c-r0 --log-junit $E/j-r0.xml > $E/r0.out 2>&1; echo rc=$?
php $T/flake-ledger.php add $E/ledger $E/j-r0.xml r0 k=$K order=lpt batch=0

# R1 LOAD (class A): K = nproc. Optional CI-shaped variant: prefix with `taskset -c 0-3` and use -p 4
pt -p $(nproc) --cache-directory $E/c-r1 --log-junit $E/j-r1.xml > $E/r1.out 2>&1; echo rc=$?
php $T/flake-ledger.php add $E/ledger $E/j-r1.xml r1 k=$(nproc) order=lpt batch=0

# R2 ISOLATION (class B/C): every file in a fresh worker
pt -p $K --max-batch-size 1 --cache-directory $E/c-r2 --log-junit $E/j-r2.xml > $E/r2.out 2>&1; echo rc=$?
php $T/flake-ledger.php add $E/ledger $E/j-r2.xml r2 k=$K order=lpt batch=1

# R3..R5 ORDER (class C): three seeds; keep the seed in the run id
for s in 101 202 303; do
  pt -p $K --order-by=random --random-order-seed=$s --cache-directory $E/c-s$s --log-junit $E/j-s$s.xml > $E/s$s.out 2>&1; echo "seed $s rc=$?"
  php $T/flake-ledger.php add $E/ledger $E/j-s$s.xml s$s k=$K order=random seed=$s batch=0
done

# R6 ACCUMULATION (class B): few long-lived workers
pt -p 2 --cache-directory $E/c-r6 --log-junit $E/j-r6.xml > $E/r6.out 2>&1; echo rc=$?
php $T/flake-ledger.php add $E/ledger $E/j-r6.xml r6 k=2 order=lpt batch=0

php $T/flake-ledger.php report $E/ledger > $E/FLAKES.txt
php $T/contention-sensitivity.php $E/j-r1.xml $E/j-r0.xml $E/j-r6.xml > $E/watchlist.txt   # duration ratio across load levels
```

Reading the matrix:

| Failing runs | Class | Next step |
|---|---|---|
| every run, including R2 | tree red | not a flake |
| R1 only | A (timing) | `contention-sensitivity.php` shows the inflation. Fix with §8.A. |
| R0/R6 but **not** R2 | B (pollution / accumulation) | Bisect (§6.4). |
| R2 only | depends on an earlier file: missing setup, or a cross-file type | `cross-file-test-types.php`; read the test's setup. |
| some seeds only | C (order) | Re-run that seed; `worker-seq.php` on its `--verbose` log, then bisect. |
| `WorkerCrashedException … Exit Code: 0` | D | The *culprit* is usually the file run **before** the named one in that worker (`worker-seq.php`). Grep it for STDIN use. |

Run K=1 only when hunting the OOM, and then with `-m 200` to confirm a batch limit removes it.

The ledger replays the lanes' existing junits correctly: it classifies the 6 candy-code drift reds as
ALWAYS and the 13 flaky tests with the K values they failed and passed at.

### 6.4 Reproducing and narrowing one flaky test

- **Repeat alone** (class F): one plain phpunit per iteration; no paratest startup cost.
  ```sh
  for i in $(seq 1 30); do vendor/bin/phpunit --filter 'Class::method' --log-junit $E/rep-$i.xml </dev/null > $E/rep-$i.out 2>&1; echo "$i rc=$?"; done > $E/rep.summary
  ```
- **Repeat under load** (class A): the same loop while an R1-shaped run is going, or pinned to few cores
  with competing busy loops, e.g. `taskset -c 0,1` on the loop plus `stress-ng --cpu 2 --taskset 0,1` if
  installed. This is the closest local model of a 2-vCPU CI runner.
- **Find the polluter** (class B/C), verified end to end on a synthetic suite (8 probes found the polluter
  among 15 suspects):
  ```sh
  php $T/worker-seq.php $E/r0.out                                   # per-worker summary
  php $T/worker-seq.php $E/r0.out WsUpgradeTest > $E/suspects.txt   # files that ran before the victim in its worker
  php $T/bisect-polluter.php . $E/suspects.txt tests/Server/WsUpgradeTest.php $E/bisect --filter='/testEveryUpgradeRefusal/' \
      --env=CANDY_CODE_MCP_DISABLE=1
  ```
  - Each probe is **one plain phpunit process** running `[subset…, victim]` in order, the same shape as a
    worker but without the 35 s parent cost.
  - It reports `victim fails ALONE` (not order-dependent) or `needs files from both halves`
    (accumulation, not one polluter). For the latter, use the residue census below.
- **Residue census** (recommended, not built): a PHPUnit extension in `sugar-testing` subscribing to
  `Test\Prepared`/`Test\Finished`. It would record, per test:
  - the change in open fds (`/proc/self/fd`) and in child processes,
  - `getcwd()`, `umask()`, and a hash of `getenv()`,
  - `stream_get_meta_data(STDIN)['blocked']`,
  - the error-handler stack and timers armed on `Loop::get()`.

  Any change goes into a TSV named by an env var. One run at production K then lists the **polluters**
  directly, instead of victims 10k tests later. Precedents: `sugar-pty/tests/Support/SharedLoopResidue.php`
  and `candy-code` `RuntimeNoticeSinkResetExtension`. This is the cheapest way to find what pushes
  `DescriptorInheritanceGuardTest` past fd 40 and what breaks `WsUpgradeTest` deep in a worker.

---

## 7. Making paratest work without vendor patches

### 7.1 Parent bootstrap

Replace `cfg/bootstrap-compat.php` with a guard inside the real bootstrap. Wrap the subscriber registration
(candy-code `SuiteSkipRoster::install()`, `tests/bootstrap.php:81`) in
`try { … } catch (\PHPUnit\Event\EventFacadeIsSealedException) {}`. The parent never runs tests, so
skipping the subscriber there costs nothing. sugar-core's `HangWatchdog` already does this
(`catch (\Throwable)`).

### 7.2 Worker stdin

There are 2 separate hazards:
- **(a) O_NONBLOCK:** a test sets the real STDIN non-blocking (sugar-core).
- **(b) Inherited fd 0:** a child process inherits fd 0 and reads it, or sets non-blocking on it (candy-code).

**`paratest-worker-prelude.php`** is injected with
`--passthru-php="'-d' 'auto_prepend_file=/abs/…/paratest-worker-prelude.php'"`. It runs before
`phpunit-wrapper.php`, does that file's job, and exits. The differences from stock are a private command
handle opened before the bootstrap runs, and `stream_set_blocking($control, true)` before every read. That
fixes (a) by itself.

For (b), it is needed **together with** candy-code's own bootstrap `fclose(\STDIN)` + `/dev/null`
reopen. That pairing is exactly the lane's v5 setup, minus the vendor edit.

- It refuses to run on any paratest other than 7.4.x. 7.4.x is frozen, so the copied internal API can't
  drift.
- **Verified:** on unpatched paratest, a synthetic non-blocking test crashes stock (`Exit Code: 0(OK)`) and
  passes with the prelude. The real sugar-core pair (`PosixBackendInjectedTermiosTest` →
  `PosixBackendTest`) crashes stock and passes with the prelude (26 tests).
- **Not verified:** the full candy-code suite with the prelude replacing the v5 vendor patch. Do one K=32
  validation run before relying on it. The candy-code vendor wrapper is currently patched; the prelude takes
  over before the patched loop runs.
- **Limit:** the private handle is still inherited by children. PHP can neither set close-on-exec on a dup
  nor reopen a pipe through `/proc/self/fd` (verified: `fopen('/proc/self/fd/0')` fails because PHP resolves
  the symlink).

The test-side fixes, which are better and should be done regardless:
- sugar-core `PosixBackendInjectedTermiosTest`: pass a private stream (e.g. `fopen('php://memory', 'r+')`)
  instead of `\STDIN`, or restore blocking mode in `tearDown()`.
- In general: no test touches the process's real STDIN, and code that spawns children passes an explicit
  stdin descriptor.

### 7.3 Cross-file test types (paratest "class not found")

`cross-file-test-types.php` scans the repo. It is namespace-aware, ignores comments and imports, and exits 1
on findings. It currently flags 4 cases:

- `sugar-async`: `FakeClock` (declared in `tests/AsyncCacheBoundedTest.php`), used by
  `tests/AsyncCacheTest.php`. Confirmed red by the lane.
- `candy-git`: `FixtureGit` (declared in `tests/AppTest.php`), used by 13 files, e.g. `RendererTest.php`
  (`new FixtureGit(...)`).
- `sugar-mouse`: `ZoneRoutingModel` (declared in `tests/Zones/ManagerTest.php`), used by `ZonesTest.php`.
- `sugar-pty`: `FixtureMaster` (declared in `tests/ExpectTest.php`), used by
  `ExpectRecorderIntegrationTest.php`.

Fix: move each to `tests/Support/<Name>.php` (autoload-dev). To keep it fixed, add the scan to the
`tools-guards` CI job, or extend `tools/check-one-type-per-file.php` to read `autoload-dev.psr-4`.

### 7.4 Coarse stdin pre-flight

A grep of test files that touch the real STDIN finds candy-code 50, sugar-core 4, sugar-async 4, and 1 each
in sugar-video, sugar-ssh-server, sugar-image, sugar-dashboard, candy-vcr, candy-starter, candy-ssh-launcher,
candy-kv, candy-git-server and candy-git. Many are child-script strings. With the prelude this becomes a
review list rather than a blocker.

---

## 8. Fixing flaky tests, by class

**A. Timing under load**

1. A ceiling's job is "never hang", not "be fast". Set it from the **K=nproc** observed duration ×3, or drop
   it in favor of PHPUnit's `defaultTimeLimit=60` (candy-code enforces it). ServeStatusStopTest's 20 s
   ceiling was beaten by a 20.7 s run at K=64.
2. Wait on events, not time. The child prints a readiness line and the test blocks on `stream_select()` for
   it. Poll `waitpid(WNOHANG)` against a generous ceiling. Never `usleep(n)` and assume it has happened.
3. Don't assert elapsed wall time in the main suite. "`__destruct()` took 3.57 s against an 8.0 s request
   timeout" asserts a fraction of a budget. Assert the outcome instead (the server pid is reaped), or move
   real performance assertions into a `#[Group('perf')]` leg run at K=1.
4. If a ceiling must stay, scale it with one shared env factor (e.g. a `sugar-testing` helper reading
   `TEST_TIME_SCALE`) and set it on high-K runs.
5. The 161 tests whose duration inflates ≥3× under load (72 ≥5×) are the queue for this class
   (`contention-sensitivity.php`). The top entries are LSP/MCP child-process tests (20-24×).
6. With `enforceTimeLimit=60` the slowest test at K=64 already takes 46 s. Running candy-code above about
   K=32 risks time-limit failures too.

**B. Accumulation / pollution**

- Restore what the test changed: env, cwd, umask, static registries (e.g. `KeyBindingRegistry::useOverrides`
  must be reset), error handlers, signal handlers.
- Close every fd the test opens. Reap every child, including forked servers. Cancel loop timers.
- Find leakers with the census (§6.4), not by guessing.
- `--max-batch-size` (e.g. 50-200) is a **mitigation** that bounds damage; it doesn't fix anything. It is
  also the paratest answer to the K=1 OOM.

**C. Placement / order**

- Every helper type in its own autoloadable file (§7.3). No test relies on another file having run first.
- Static caches need a reset hook, or should be keyed so that order can't matter.
- Fixed-number assumptions about the process (e.g. "the marker is below fd 40") should be computed from the
  actual state: search up to the marker fd plus a margin.

**D. Worker context**

- Tests never touch the real STDIN (§7.2).
- Bootstraps tolerate a sealed event facade (§7.1).

**E. Shared resources**

- Name per-worker resources with `getenv('TEST_TOKEN')` (stable per worker) or `UNIQUE_TEST_TOKEN`
  (unique per process and run).
- Bind servers to port 0 and read the real port back.
- candy-code's bootstrap already sandboxes TMPDIR per process. Check that every lib bootstrap does the same.

**F. Intrinsic nondeterminism**

- Inject clocks and seeds. A **variable assertion count** (PacedAsyncRendererTest) means the test takes
  different branches between runs. Make each branch an explicit case.

**Verification standard for a fix:** the test is green in the run that used to trigger it (same K, seed and
batch), and in a 30-iteration repeat under that condition. Record it in the ledger.

---

## 9. Tools (`prompt_kit/tools/paratest/`)

| Tool | What it does | Status |
|---|---|---|
| `lpt-config.php` | Writes a phpunit config listing every selected test file longest-first; files without a recorded duration go first. `--bootstrap=` overrides the bootstrap. | Verified: dispatch follows file order; candy-code config = 33,821 tests (= suite) |
| `paratest-worker-prelude.php` | Replaces the worker loop without vendor edits (§7.2) | Verified on a synthetic test and the sugar-core pair; candy-code full suite pending |
| `sched-sim.php` | Simulates paratest's queue in alphabetical vs LPT order from a junit; top files with start/end times | Verified against 4 measured walls |
| `split-whatif.php` | LPT schedule if named files are split or shrunk (scenarios are candy-code specific) | Used for §4.2 |
| `suite-build-cost.php` | Per-file suite-build cost (class load + data providers) | Used for §5.3 |
| `contention-sensitivity.php` | Per-test duration ratio across runs at different K; the class A watch list | Used for §6.1/§8 |
| `flake-ledger.php` | Append runs from their junits (crashed runs recorded as such); report ALWAYS/FLAKY and the failing vs passing run settings | Verified by replaying the candy-code sweep |
| `worker-seq.php` | Per-worker file sequence from a `--verbose` log; suspects that ran before a victim | Verified (synthetic) |
| `bisect-polluter.php` | Bisects suspects with plain phpunit probes to find the single polluter | Verified (synthetic, 8 probes) |
| `cross-file-test-types.php` | Repo scan for helper types shared across test files | Run; 4 findings (§7.3) |

---

## 10. Suggested order of work

1. **Unblock with no vendor edits:** the §7.1 bootstrap guard (candy-code), the prelude (all libs), and the
   sugar-core test fix. Then fix the 4 cross-file types and gate the scan.
2. **Validation run:** candy-code at K=16 and K=32 with `lpt-config.php` + prelude + `--verbose`, against
   the stock (unpatched) wrapper. Expect the schedule to sit at the KeyHelpTest floor (about 300 s) plus
   startup.
3. **Speed:** fix KeyHelpTest (loop bound + split) and BuiltInToolTest (memoize the corpus), then the 4
   expensive providers. Re-run step 2 and re-derive K (§4.5). Then the floor files in sugar-colorprofile,
   sugar-image and sugar-async.
4. **Flakes:** run the §6.3 protocol per lib with the ledger, fix by §8, and use the census for class B.
5. **Harness decision** (owner's call): after step 3, both harnesses should be close (old K=24 simulated
   about 175 s; paratest K=32 about 132 s plus startup).
   - Paratest's advantages: a dynamic queue, no manifest that silently drops new files, and native coverage
     merging.
   - Its costs: startup tax, the prelude, no per-shard resume, and a junit-vs-suite-figure conservation check
     that would have to be written.

**Not verified in this session:**
- the prelude on the full candy-code suite;
- `--max-batch-size` restart cost;
- `--log-teamcity` output;
- real (not simulated) LPT walls;
- the residue census, which was proposed, not built.

---

## 11. Results (2026-11-21)

Steps 1-3 of §10, the §4 and §7 leftovers, and a sweep for exact-budget waits were done in the copy
`/home/sites/sugarcraft2/sugarcraft`, on master, as 26 commits after `a7bcbd4a3` (not pushed). Every number
below was measured there. paratest 7.4.9 runs with the stock wrapper, the worker prelude and an LPT config,
through `scripts/paratest.sh`.

### 11.1 Walls

| Lib | Before (lane sweep, alphabetical) | After | Tests |
|---|---|---|---|
| candy-code | old harness K=24 301.8 s; paratest K=32 407.2 s | **K=32 144.8 s**, K=58 111.0 s | 33,821; only the 6 known drift reds at K=32 |
| sugar-colorprofile | about 90 s at every K | **K=8 19.5 s** | 871 OK, 2 skipped |
| sugar-image | K=8 70.4 s, K=64 57.1 s | **K=13 15.3 s** (K=8 27.7 s before the Chafa fix) | 1,787 OK, 8 skipped |
| sugar-async | about 46 s from K=4 | **K=5 21.7 s** | 568 OK, 6 skipped |

candy-code by stage (stock wrapper, prelude, LPT):

| Run | Wall | Total test time | Longest file |
|---|---|---|---|
| Step 2, K=16, before the speed fixes | 354.3 s | n/a | KeyHelpTest |
| Step 2, K=32, before the speed fixes | 332.4 s | 4,118 s | KeyHelpTest 297.7 s |
| 3d, K=32, after 3a-3c | 144.8 s | 3,561 s | ProcessExecutorTest 76.8 s |
| 3d, K=58 (the rule's suggestion) | 111.0 s | 4,389 s | KeyHelpTest 82.2 s |

The 3d runs predate the LSP fixture fix (21e202db4, about 54 s less total test time).

### 11.2 What each fix bought

| Fix | Commit | Before | After |
|---|---|---|---|
| KeyHelp: an untouched row's chord comes from the row itself, not from `chordFor()`, which rebuilt every binding | db6379c03 | KeyHelpTest 297.7 s; `/keys` frame 193 ms | 73.6 s; 12 ms |
| BuiltInToolCorpus scans the real `src/` once per process | ce2673f08 | BuiltInToolTest 245.2 s | 1.0 s |
| Real-tree env scanner built once per process | 224bb4ce3 | EnvRosterDriftTest 48.0 s | 7.0 s |
| sugar-async ChildRunLaunchTest lost stop (§11.4) | 41a0a4e07 | 3 tests at 10.01 / 10.00 / 5.00 s; file 45.9 s | under 1 s each; file 21.0 s |
| sugar-colorprofile grey-ramp sweeps over 6 slice files | b953abb7c | GreyRampEquivalenceTest 91.0 s | slices 13.7-14.9 s |
| sugar-image Ditherer: grad and noise replayed in their own files | 088d96265 | DithererTest 57.5 s | 7.4 + 10.9 + 10.8 s |
| sugar-image SixelStream corpus pins as 4 files | bd7821006 | SixelStreamTest 53.7 s | longest piece 7.6 s |
| sugar-image ChafaRendererAsyncTest lost stop | d18f1e2d1 | 20.6 s | 0.6 s |
| sugar-async ChildRunControlTest lost stop | 7723570b9 | 1 test 10.00 s; file 26.5 s | under 1 ms; 16.5 s |
| sugar-pty ChildPollWaitpidTest: SIGKILL before `proc_close()` | 8ddd06993 | 10.00 s | 1 ms |
| sugar-image ChafaRendererChildTest uses the reap-budget seam | d0291e78d | 5.02 s | 0.21 s |
| candy-code LSP stderr fixtures: 1 s request timeout | 21e202db4 | 6 tests at about 11 s | about 2 s each |

Every split file has a test that pins the partition, so a fixture or slice cannot silently drop out. The
cross-file gate (75 libs, 5,737 test files) and the one-type gate (3,903 PSR-4 files) both report 0
problems after the splits.

### 11.3 Choosing K: the rule needs a cap

- **The model holds.** The §4.1 simulation said LPT K=16 = 295.9 s and K=32 = 303.7 s, plus 35-47 s of
  startup. The measured step 2 walls, 354.3 s and 332.4 s, land within about 3.5% of that range.
- **After the fixes the rule points high.** K = ceil(1.25 × total ÷ longest) gave K=58 from the K=32 junit.
  - K=58 saved 34 s of wall over K=32.
  - Total test time rose 23% (3,561 → 4,389 s). That is the §4.1 contention effect.
  - It added one timing red, `McpConcurrentStartupTest::testTwoSlowServersStartInLessThanTheSumOfTheirDelays`
    (3.97 s against a 3.5 s bound).
  - Fed its own K=58 junit, the rule asks for K=67. It ratchets, because contention inflates the total
    faster than the longest file.
- **The runner's default is now `min(suggested K, nproc / 2)`** (b7d8fae96). `-p` still overrides it.
- **The runner takes durations from its own last run first** (bf245174d). The committed
  `scripts/parallel-tests-durations.tsv` still lists KeyHelpTest at 302 s and would suggest K=15.

### 11.4 New finding: tests that sit out a whole budget

Seven tests in sugar-async, sugar-image and sugar-pty each took exactly a budget (5.00-5.02 s, 10.00 s,
20.00 s), and six candy-code LSP tests took a 10 s budget plus about 1 s. None of them fails; each just pays
its whole budget. The detector is cheap: list junit testcases at 5 s or more that sit within 0.03 s of a
whole second. Every hit was one of 4 shapes:

1. **Lost stop** (5 tests in 3 files). The promise settles synchronously, so its `then()` callback, and with
   it `$loop->stop()`, runs before `$loop->run()`. That stop is lost, and the loop runs until the guard timer
   fires. Fix: start the loop only while the promise is still pending. The same 5-line `settle()` helper
   now exists in 3 files; a shared one in `sugar-testing` would stop a fourth copy from carrying the bug.
2. **`proc_close()` on a live child.** It waits for the child to exit. Kill first.
3. **A test seam left unused.** ChafaRenderer has `setReapBudgetsForTesting()`; one test did not call it
   and paid the 5 s production wait.
4. **An unanswered protocol request.** `LspConnection::connect()` marks the connection initialized, so
   `disconnect()` sends `shutdown` and waits the full request timeout on a fixture that never speaks LSP.

Left as they are, because the time is the point of the test:
- `GrepTimeoutTest::testAComfortableDeadlineLetsTheSameSearchFinish` (27.5 s) greps the same 32 GiB sparse
  fixture its 1 s twin times out on, and reusing that fixture is how it proves the marker comes from the
  deadline.
- Judged by their names only, not read: the 5-6 s lock-contention and own-timeout tests in
  WorktreeManagerTest, LoginSubcommandTest and LspConnectionShutdownTest, and
  `ServeStatusStopTest::testRotatingTheTokenReloadsTheRunningServer` (6.01 s).

**Lead, not investigated:** `LspConnectionTest::testProcessDiesMidRead` waits its full 5 s request timeout
after the SIGKILL instead of seeing EOF. If the kill lands on the containment wrapper (`setsid`) rather than
the server, the test does not test what its name says.

### 11.5 §7.4 stdin review

All in-process `STDIN` uses outside candy-code are harmless except one:
- **Harmless:** `Tty(STDIN)` construction and `size()`; a registration on a loop that never runs; methods
  that throw "unsupported"; an argument check that throws first.
- **The real one:** sugar-pty `PosixPumpTest::testRunWithNullChildReturnsZero` ran the pump on the real
  STDIN. In a worker that is the command pipe, which never reaches EOF, so HangWatchdog killed the worker
  (exit 137) at every K. Fixed with private `/dev/null` handles (fd6be85a3). The old version was confirmed
  to hang with stdin held open.

### 11.6 Not-verified items, revisited

- **Now verified:**
  - the prelude on the full candy-code suite (4 full runs, 33,821 tests each);
  - real LPT walls (§11.3);
  - the parent bootstrap guard (4cb93c45a), which lets the stock wrapper run with no compat bootstrap;
  - the paratest HOME inheritance (e8d83578f).
- **Still not verified:**
  - `--max-batch-size` restart cost;
  - `--log-teamcity` output;
  - the residue census;
  - a full sugar-pty paratest run (the copy has no paratest in `sugar-pty/vendor`);
  - CI-shaped runs (2-4 vCPU).

### 11.7 What is left

- **The candy-code floor** is now 74-82 s, shared by KeyHelpTest, ShellContrastTest, ProcessExecutorTest,
  BinCandyCodeDispatchTest and the Palette pair. Splitting only one of them buys about 4 s. Only splitting or
  speeding up all of them together moves the wall again.
- **Decided:** paratest `~7.4.9` went into `require-dev` for the 7 libs it was run on (9fc52970c).
- **Owner decisions still open:**
  - the §10 step 5 harness choice;
  - when to refresh `scripts/parallel-tests-durations.tsv` and the ci.yml TSV-PIN figures. That belongs to
    the deferred end-of-plan figure refresh.
- **§10 step 4 (flakes)** was not started. Class A starts with McpConcurrentStartupTest.

Round 2 took on the floor (§11.8), the flakes and the CI switch (§11.9).

### 11.8 Round 2: candy-code wall-time fixes

Round 2 was done in the same copy after an upstream merge (`25b118c5c2`). Its baseline is the K=32 run on
that merged tree: wall 146.0 s, total test time 3,611 s, longest file ProcessExecutorTest 77.4 s. At K=32,
3,611 / 32 = 113 s is more than the longest file, so total work set the wall. The fixes therefore went for
root causes in the expensive files rather than for splits. Each was profiled first with a SIGUSR1 sampling
profiler (2 ms ticks).

| | Commit | Root cause and fix |
|---|---|---|
| a | 4db7b9bc22 | `PaletteAction::label()` re-mapped every built-in command per palette row, several times a frame. Now a per-locale index, `BuiltInCommands::forPaletteAction()`. |
| b | 9e8e149be1 | `StartupSummary::cheapFacts()` spawned `git rev-parse` + `git status` for the version string on every render. Now memoized per process. |
| c | dccd125fd1 | `ContextMeter::rawTokens()` re-estimated the whole transcript twice a frame. Each message's share is now kept in a WeakMap (messages are readonly). |
| d | 49ae58dd8e | `SettingsSchema::byKey()` scanned every definition. Now a per-locale index. |
| e | c6231e8d51 | The menu bar re-derived every label each frame. Now memoized by description text. |
| f | de55eb23f7 | Two E646 tests sat out the real 15 s heartbeat window. `ProcessExecutor` takes `heartbeatTimeoutSeconds` (default 15, pinned); the tests use 3 s. |
| g | 550dea10e6 | Each spawned `bin/candy-code` compiled its 429 files from nothing (CLI opcache dies with the process). `tests/Support/ChildOpcache` gives spawn sites a per-test-process opcache file cache, with temp directories blacklisted. |
| h | e8a0b350e6 | sugar-core `Width::wrapAnsi()` walked plain ASCII grapheme by grapheme. Runs of printable ASCII are now taken in slices. |
| i | b55b63055e | Same shortcut for `truncateAnsi()`, `takeAnsi()` and `dropAnsi()`, through `Width::asciiRun()`. |
| j | 89ebfb82d9 | TreeWideGuardRosterTest's taint resolver re-scanned the tokens on every fixpoint pass and compiled one pattern per tainted name. Sites are now read once, and the names are matched with one alternation. |
| k | 160a325667 | DuplicatedTestHelperDriftTest tokenized each source about ten times. Its declaration scan is memoized for the class's lifetime. |

Evidence that behavior did not change:
- **h and i:** a differential fuzz against the pre-change `Width` class, 600,020 inputs across the four
  walkers, found 0 differences.
- **j:** a snapshot of the resolver's output over all 2,249 files under `tests/` is identical.
- **Full run:** the K=32 run after a-k (33,816 tests) has only the 6 known drift reds.

Per file, baseline K=32 vs the K=32 run after a-k:

| File | Before | After | Fixes |
|---|---|---|---|
| PaletteScrollAnchorTest | 61.5 s | 15.2 s | a, b, e |
| PaletteClickTest | 57.9 s | 12.0 s | a |
| RendererTest | 33.8 s | 6.7 s | h (one case 24.9 → 0.8 s alone) |
| KeyHelpTest | 73.7 s | 49.9 s | c, i |
| ProcessExecutorTest | 77.4 s | 54.8 s | f |
| ShellContrastTest | 71.4 s | 51.0 s | i |
| AgentSplitCompositorTest | 41.6 s | 21.7 s | b |
| BinCandyCodeDispatchTest | 59.3 s | 46.0 s | g (50 → 32 s alone) |
| DuplicatedTestHelperDriftTest | 29.5 s | 15.5 s | k (34.7 → 14.8 s alone) |
| UntouchedDockWidthSweepTest | 20.8 s | 9.4 s | a-e |
| ScreenReaderPaletteTailTest | 12.4 s | 1.9 s | a-e |
| TreeWideGuardRosterTest | 35.3 s | 29.3 s | j |

Whole run: wall 152.6 s; total test time 3,686 s; longest file ProcessExecutorTest 54.8 s.
- **Why the totals did not improve:** the per-file gains add up to 398 s. They are offset by 473 s of small
  losses spread over files no fix touched, mostly process- and timing-bound ones (LSP, serve, session).
- **Why that is contention:** other sessions were running phpunit and phpdup on the same 64-CPU box, and
  load reached 56 during the run. Across the 427 files over 2 s, the after/before ratio has a median of 1.01,
  with p10 0.82 and p90 1.32: noise around unchanged.
- **What would settle the wall:** an A/B run on a quiet box. The per-file rows above are the attributable
  result. The floor dropped from 77 s to 55 s, so at K=32 total work now sets the wall by a wider margin.

Bare `php -r ''` costs 97 ms on this host (about 70 extensions; `php -n` 15 ms). That share of every spawn
is the environment, not the code.

**Product finding, not fixed (owner's call):** `ToolIpcFiles::sweepOnce()`, which runs at every launch,
globs the real temp directory 7 times:
- 5 prefixes in `sweep()`;
- `BackgroundSupervisor::sweepStaleIpcDirs()`;
- `sweepStalePoolDirs()`.

Each glob reads the whole directory. On this box's `/tmp` (472,439 entries) that adds 2.4 s to every launch:
an offline `-p` run takes 3.3 s, against 0.83 s with an empty TMPDIR. One readdir pass would remove about six
sevenths of that. The suite is unaffected, because its children get a 1.5k-entry sandbox TMPDIR.

### 11.9 Round 2: flaky tests and the CI switch

**The §6.3 protocol** ran on the tree after §11.8's fixes. It made 7 full runs of 33,816 tests, each with
`--display-warnings`, `--display-notices` and `--display-deprecations`:

| Run | Cause it tests | Shape | Wall |
|---|---|---|---|
| r0 | baseline | K=32, longest-first (the §11.8 run) | 152.6 s |
| r1 | load | K=64 | 117 s |
| r2 | isolation | K=32, `--max-batch-size 1` | 251 s |
| s101, s202, s303 | order | K=32, random order with those seeds | 157, 159, 166 s |
| r6 | accumulation | K=2 | 1,415 s |

All 7 completed. The ledger (`flake-ledger.php`) found:
- **ALWAYS, 7/7:** the 6 known drift reds and nothing else.
- **FLAKY, 3/7:** McpConcurrentStartupTest's timing test, red at K=64 and at K=32, under both orders.
- **FLAKY, 1/7:** three AgentWorkerPoolCancelKeepsLoopLiveTest tests, only in s101.
- **Not seen again:** the 2 unidentified warnings of the merged-tree run, and BackgroundShellRegistryTest's
  orphaned-group red (one sighting before the protocol).

**The contention watchlist** (`contention-sensitivity.php`, K=64 against K=32 and K=2) covers the 1,730 tests
whose fastest time is at least 0.3 s:
- 213 of them ran 3x or more slower at K=64 than at K=2, and 65 ran 5x or more.
- Nearly all spawn children: MCP and LSP servers, or `bin/candy-code`. None of them failed. Watch them if K
  goes up.
- The tool labels runs by file basename, so give its inputs distinct names. Three files all named
  `junit.xml` produce an empty table.

Fixes:

| Commit | Test | Cause and fix |
|---|---|---|
| d91a372783 | McpConcurrentStartupTest | **Cause:** a §8 class A stopwatch. It timed two 2 s handshakes against a 3.5 s bound, and they took 5.72 s at K=64. **Fix:** the test is now `testTwoSlowServersHandshakeAtTheSameTime`. Each fixture server waits at a 2-party barrier and writes `arrived.<pid>`; it writes `alone.<pid>` if the other has not arrived within 20 s. The test asserts two arrivals and no `alone` file, which is the claim (the handshakes overlap) with no clock in it. The negative control is a lone fixture, which writes `alone.*`. 30/30 green at load 39-67. |
| 9a781dfe59 | AgentWorkerPoolCancelKeepsLoopLiveTest x3 | **Cause, a product bug:** `EgressProxy::servePolicy()` installed SIGTERM and SIGHUP handlers and made STDIN non-blocking, and never restored either. EgressProxyTest runs it in-process. A worker that ran EgressProxyTest first then forked pool workers that inherited the handler, so cancel's SIGTERM only interrupted their `sleep()`, and they reported Completed instead of Stopped. **How it was found:** logging `pcntl_signal_get_handler()` changes after each test, then replaying worker 13's 65-file sequence serially, which reproduced the 3 reds. **Fix:** servePolicy() saves both handlers and the STDIN mode and restores them in a `finally`. A new EgressProxyTest case pins it. |
| 7048410906, 12a8094d1a | BackgroundShellRegistryTest | **Cause:** one-shot `posix_kill(-$pgid, 0)` asserts right after a KILL. A killed group's members are reaped one at a time, so the group can still answer for a moment. **Fix:** the five call sites wait on `groupGone()`, a bounded poll through the class's existing `waitFor()`. The race was not reproduced in 1,600 local trials, and the docblock says so. The first version had its own `usleep` loop, which BoundedPollCensusTest flagged: 3 sleep sites against a roster of 2. |
| bfa2e485f8 | ForkChannelReplyAfterCancelTest | **Cause:** red once at load 62 in a probe run. The test asserted that the cancel reaped the cancelled child, but EngineBackend's documented contract is that the child is either reaped or handed to the straggler sweep (`reapChildAsync()` polls only 20 x 5 ms). **Fix:** the test drives the sweep, bounded at 5 s, then asserts that the child is gone. |

**Suite-wide leak probe.** The EgressProxy leak raised a broader question: does any other test leave process
state behind for the next test in the same worker?
- **The probe:** a PHPUnit extension that records changes after every test against a baseline taken when it
  starts. It watches 10 signal handlers, `pcntl_async_signals()`, STDIN's blocking mode, the cwd and the
  umask. It is registered as an extension because PHPUnit 10 seals the event facade before the ParaTest
  parent loads the bootstrap.
- **Positive control:** a test that leaks on purpose was caught.
- **Result after the fixes,** at K=32 with 33,817 tests and only the 6 known reds: the only change in any of
  the 32 workers is PHPUnit's own SIGALRM handler, once per worker. No other test leaks that state.

**The CI switch.** The candy-code test cell now runs `scripts/paratest.sh -p $K --durations
scripts/parallel-tests-durations.tsv --against-json candy-code/tests/Config/Support/suite-figure.json
--print-failures --timeout 2400`, with K = cores capped at 4, as before. New in `paratest.sh`:
- `--against-json` runs `scripts/paratest-conservation.php` on the run's junit. Tests, assertions, failures
  and skipped must equal the figure, and errors must be 0: the shard runner's rule, with skipped counted as
  `<testcase><skipped/>` elements. A miss turns exit 0 into 1. A junit that is missing or unreadable is
  exit 2, never a pass. `tools/tests/ParatestConservationTest.php` pins every exit.
- `--print-failures` prints the failure section of `paratest.log` after a red run, since CI shows only the
  console.
- A stale `$OUT/junit.xml` is removed before the run, so a killed run cannot be judged on an old log.
- `scripts/affected-libs.php` treats `paratest.sh` and the three scripts it calls like `parallel-tests.sh`:
  a change to any of them runs the full matrix.

Under ParaTest, conservation catches less than it did under the shard runner. Every file runs, and an
unlisted one is queued first, so the check no longer guards the durations table. It catches a run that lost
tests, and a suite whose shape changed without a figure refresh.

**Coverage stays on the shard runner.** The coverage cell was first switched to
`paratest.sh -- --coverage-clover` too, but at K=32 that run died with rc 255:
- **Why it died:** the ParaTest parent applies phpunit.xml's `memory_limit=1G`. It ran out in
  `CoverageMerger` while including a 107 MB worker `.cov`.
- **Measured with no limit:** the run completes, but the parent peaks at 3.17 GB RSS on 1.74 GB of serialized
  `.cov`. Each `.cov` holds every covering test id for each line, so the total is set by the suite, not by K.
- **Decision:** the coverage cell stays on `parallel-tests.sh --clover` plus `scripts/merge-clover.php`, whose
  shard clovers are small, and phpunit.xml's 1G guard stays. `ci.yml` records why.
- **Alternative (owner's call):** a `--memory-limit` override for coverage runs only. It is unverified on a
  16 GB, 4-vCPU runner.

### 11.10 Round 2: the refresh

The last round 2 step re-pinned what the earlier steps had moved: the drift pins, the suite figure and README
headline, the durations table, and `ci.yml`'s TSV-PIN line. Getting a run green enough to refresh from
turned up four more problems, each fixed first.

**Drift pins (04271a0a2c).** These are the 6 drift reds every §11.9 run carried:
- DocFigureProseDriftTest's hook arms now read the retry figure from sugar-async's `ChildPump::SELECT_RETRIES`.
  The drain slice is StatusLineCommand's own 200 ms; ScriptHook has none.
- `ARCHITECTURE.md` names 23 siblings, and its word map reaches twenty-five.
- The PathGlob census reads 320,352 (568 × 564).
- `docs/_data/candy-code.api.html` and `docs/lib/candy-code.html` were regenerated.

**Assertion counts that vary between runs (3cd77b3aa6, 993b1c9765).**
- **The problem:** an exact conservation check needs the same assertion total every run. `assert-diff.php`
  over 6 junits of one tree found 15 tests that varied by 1-4 assertions, and run totals moved by up to 17.
- **The cause:** asserting inside a poll loop, or once per element of a timing-dependent list.
- **The fix:** six files now assert once after the wait (`if (...) self::fail()` inside the loop), or once over
  the filtered list (`assertSame([], $lost)`): McpLoginForkTest, McpLoginServedTest, ProcessExecutorTest,
  WorkflowStageResourceLockTest, TaskCallIdThreadingTest and ChildSessionCreatedOnFinishTest.
- **Follow-up (993b1c9765):** the edit shifted the line of an accepted stacked doc-comment pair, so
  RuntimeNoticeSinkDeliveryTest's pin moved with it.
- **Check:** assert-diff across pt-r27 to pt-r30 prints nothing.

**Assertion counts that depend on what ran first (b6414ff58c).**
- **How it was found:** the new figure had 4,706 fewer assertions than the 2026-11-17 pin, despite 684 more
  tests. Per-class totals across the scratchpad's junits put almost all of the gap at one round 1 commit,
  224bb4ce31: between pt-cc-k32 and pt-cc-3d-k32, EnvRosterDriftTest lost 12,717 and HelpTest 4,239.
- **The cause:** 224bb4ce31 builds `EnvRosterDriftTest::realScanner()` once per process, and the source walk
  under it asserted once per file (1,410 in `src/`, 2 in `bin/`, plus the root: 1,413). Those assertions land
  on whichever test builds the scanner first. When HelpTest's data provider builds it at suite load, they land
  on no test at all.
- **Measured:** EnvRosterDriftTest counted 43,204 alone, and 41,791 with HelpTest in the same process.
- **Why no run showed it:** all 15 ParaTest runs agreed, because EnvRosterDriftTest (6.6 s) is queued before
  HelpTest (1.5 s), and the K=4 shard manifest puts them in different shards. A serial run reads 1,413
  lower, and a durations refresh could have flipped CI's exact compare.
- **The fix:** the walk throws instead of asserting. The file now counts 40,378 alone and after HelpTest.
- **Checked:** the other static `??=` caches in candy-code's tests (BuiltInToolCorpus,
  DocFigureProseDriftTest's function spans, UiOnlyWireFilterTest) and round 2's DuplicatedTestHelper memo
  do not assert.
- **Manual:** §7.2 now describes this second kind of unstable count and how to find it.

**Swoole (47cad22304, 14e1084fa5).**
- **The problem:** with swoole 6.2.0 loaded for the CLI, every php started with `TMPDIR=/` aborts
  (`std::out_of_range`, `basic_string::at`, exit 134).
- **Proven:** the same command exits 0 with every other conf.d ini loaded.
- **Effect:** SuiteRootRunRefusalTest's two child arms, which start php with `TMPDIR=/` to prove the guard
  refuses it, went red. php died before the guard ran.
- **The fix (the owner's idea):** `SuiteSkipRoster::rootTmpdirChildStarts()` probes `php -r 'exit(0);'` with
  `TMPDIR=/` once per process. When the probe fails, both arms skip, and two conditional roster entries are
  lifted exactly where it succeeds. The probe is also the skip gate in the test bodies, so the roster cannot
  disagree with the skip.
- **Follow-up (14e1084fa5):** the predicate first sat between another method's doc-comment and its
  declaration, which RuntimeNoticeSinkDeliveryTest's stacked-doc guard caught, so it was moved.

**Deadline bound (a352dac45f).**
- **The problem:** ClaudeCodeMcpClientDeadlineTest's unanswered-handshake test took 5.64 s at load 49,
  against a 5.0 s upper bound. Alone it takes 1.0 s, 30 of 30 runs.
- **The fix:** the bound only has to tell the 0.5 s budget apart from the 60 s `HANDSHAKE_TIMEOUT_SECONDS`
  that a lost budget would fall back to, so it is now 15 s. The manual's §6.7 has the rule: size a "never
  hangs" bound against the alternative it rules out, not against a speed.

**The green runs.**

| Run | Shape | Result |
|---|---|---|
| pt-r25 | K=4, before 3cd77b3aa6 | 2 swoole reds, wall 734.8 s, total 2,737.7 s. The durations source. |
| pt-r27 | K=32 | 3 reds: 2 swoole, plus the shifted stacked-doc pin |
| pt-r28 | K=32 | only the 2 swoole reds; assert-diff against pt-r27 empty |
| pt-r30 | K=32, a352dac45f | **green:** 33,817 tests, 797,197 assertions, 12 skipped, wall 140.0 s |
| pt-r31 | K=32, b6414ff58c | **green:** 33,817 tests, 794,371 assertions, 12 skipped, wall 176.6 s (load 56) |
| pt-r32 | K=32, after the upstream pull (440966830a) | 1 red: StatusLineCommandTest's dead-reader test; 794,383 assertions |
| pt-r33 | K=32, dee1de3135 | **green:** 33,817 tests, 794,383 assertions, 12 skipped, wall 159.2 s (load 63); assert-diff against pt-r32 empty |

**An upstream pull mid-refresh.**
- **What happened:** at 20:07 a `git pull --all` in the copy, not made by this work, merged 17 upstream
  commits (440966830a): sugar-pty's TerminationPolicy, SIGCHLD-driven `onExit` and kill-on-drop. No candy-code
  file changed, but candy-code runs against the linked sugar-pty, so the suite was run again (pt-r32).
- **The count moved:** DescriptorInheritanceGuardTest's 6 repo-wide scans each counted 2 more files, +12
  assertions.
- **A red:** StatusLineCommandTest's dead-reader test, never red in about 20 earlier full runs, failed once at
  load 46.
  - The test's own error handler did not check `error_reporting()`, so it caught the `@fwrite` EPIPE that
    `run()` gets when the child (`exit 9`) exits before the payload write. That warning is silent in
    production.
  - A probe that lets the child exit first reproduces it every time.
  - dee1de3135 makes the handler honor `@`, as PHP's display and PHPUnit's handler do. This is a test fix,
    not a sugar-pty one.

**The figure is pinned in CI's shape** (d8a503b68d, then d851dc3d80, then 58c360851e from pt-r33):
**33,817 tests / 794,390 assertions, 0 failures, 10 skipped.**
- **Why not the local counts:** CI has no swoole, so the two child arms run there. CI's `--against-json`
  compares exactly, and it did under the shard runner too, so the figure always had to match CI.
- **The adjustment from pt-r33:** skipped 12 − 2, and assertions + 7, because the arms assert 4 + 3 when they
  run (pt-r22g ran them).
- **Checked first:** SuiteSkipRosterTest's per-entry loops walk `EXPECTED`, which is the same on every host,
  so nothing else asserts once per host-dependent entry.
- **Recorded in the figure:** a `host_adjustment` key, and the README headline says the same.
- **Consequence:** a local `--against-json` on a swoole host fails by exactly −7 assertions and +2 skipped.
  The adjusted totals were checked by hand and all four match.

**The refresher (ad14cbda81)** documents the ParaTest run, `bash scripts/paratest.sh --out <dir>`, whose merged
junit has the one top-level `<testsuite>` its gate expects. The gate itself is unchanged.

**The durations table and TSV-PIN (5307d8167b).**
- **Source:** the table now comes from pt-r25 at K=4, CI's K: 2,162 files, 33,817 tests, the same file set as
  the tree, and a KeyHelpTest row for the tuning test's mutation arm.
- **Old pin → new pin:**

  | Value | Old | New |
  |---|---|---|
  | `serial_sum` | 3,540.7 s | 2,737.7 s |
  | floor | 302.5 s (KeyHelpTest) | 50.4 s (ProcessExecutorTest) |
  | `lpt_k4_max` | 885.2 s | 684.4 s |

- **Timeout:** `--timeout 2400` still clears 1.2 × the max bucket (821.3 s) and the coverage cell's 2 × (1,368.8
  s), and stays under the serial sum.
- **Open:** the table holds this box's per-file times. CI's runners are slower, which is what the headroom is
  for, but no CI run of the ParaTest cell has happened yet: nothing is pushed.

**Checks:** tools/tests ran 1,010 tests with only the 5 environmental reds:
- CheckDocsAssets;
- PrefixPolicy and ReleaseSiblingPins (the `wt-*` worktree directories);
- RootRowsCheck ×2 (the lane-dirtied seed file).

ParallelShardTuningTest and GenApiReferenceTest are green. ReadmeSuiteFigureDriftTest and
DocFigureProseDriftTest: 107 tests OK on the final figure and headline.

**Still unverified, and older than this round:**
- whether CI's own `phar.readonly` and bwrap setup matches this box's, so that the premise and rename arms run
  there and the jail arms skip;
- the ToolIpcFiles::sweepOnce product finding from §11.8 (7 globs of `/tmp` per launch), which is left to the
  owner.

---

## Sources

- ParaTest README: https://github.com/paratestphp/paratest
- Packagist (versions / constraints): https://packagist.org/packages/brianium/paratest
- Upstream `bin/phpunit-wrapper.php` and `src/WrapperRunner/SuiteLoader.php` (HEAD): https://github.com/paratestphp/paratest/tree/HEAD
- Issues: #958 functional + data-set regex https://github.com/paratestphp/paratest/issues/958 ·
  #502 order-by https://github.com/paratestphp/paratest/issues/502 ·
  #1160 wrapper deadlock (wontfix) https://github.com/paratestphp/paratest/issues/1160 ·
  #431 stdin in tests https://github.com/paratestphp/paratest/issues/431
- Discussions: #931 https://github.com/paratestphp/paratest/discussions/931 ·
  #846 https://github.com/paratestphp/paratest/discussions/846 ·
  #922 https://github.com/paratestphp/paratest/discussions/922 ·
  #936 https://github.com/paratestphp/paratest/discussions/936 ·
  #905 https://github.com/paratestphp/paratest/discussions/905 ·
  #820 https://github.com/paratestphp/paratest/discussions/820 ·
  #819 https://github.com/paratestphp/paratest/discussions/819 ·
  #815 https://github.com/paratestphp/paratest/discussions/815
- PHPUnit `--repeat`: removed in 10 (https://github.com/sebastianbergmann/phpunit/issues/5174); a
  reintroduction is a draft PR (https://github.com/sebastianbergmann/phpunit/pull/6591). Not available on the
  PHPUnit 10.5 line, so use repeat loops (§6.4).
