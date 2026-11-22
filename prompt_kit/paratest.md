# Moving a PHPUnit suite to ParaTest: the manual

This is everything that came up moving the SugarCraft monorepo's suites from serial PHPUnit (and a
home-grown shard runner) to ParaTest, written so the same work can be repeated in another repo. It covers:
- the order to do things in;
- what breaks and how to recognize it;
- how to make the run fast;
- how to find and fix flaky tests;
- how to wire it into CI.

Every fact here was measured on SugarCraft unless it is marked **not verified**. The longer write-up with
all the numbers is `prompt_kit/findings/paratest-report-2026-11-21.md`, and its section numbers are cited as
"report §N". The reference implementation lives in the work copy `/home/sites/sugarcraft2/sugarcraft` on its
master branch (commits named below; not pushed yet):

| File | What it is |
|---|---|
| `scripts/paratest.sh` | The runner: LPT file order, worker stdin prelude, clean env, whole-run timeout, junit, conservation check, failure printing |
| `scripts/paratest-lpt-config.php` | Writes a phpunit config that lists every test file longest-first |
| `scripts/paratest-worker-prelude.php` | Replaces ParaTest 7.4's worker loop so tests cannot break its command pipe |
| `scripts/paratest-conservation.php` | Checks a run's junit totals against a pinned suite figure |
| `tools/check-cross-file-test-types.php` | Finds helper classes declared in one test file and used from another |
| `prompt_kit/tools/paratest/` | Analysis tools: schedule simulator, flake ledger, contention watchlist, worker sequence, polluter bisect, suite-build cost |

Copy the scripts into the new repo and adapt the parts marked "repo-specific" below.

---

## 1. The whole migration, as a checklist

1. **Install** the ParaTest line that matches the repo's PHPUnit (§3).
2. **Make it run at all** (§4):
   - guard every event subscriber the bootstrap registers;
   - add the worker stdin prelude;
   - fix tests that touch the real STDIN;
   - move helper classes out of other tests' files;
   - check what the bootstrap does to the environment, because the parent runs it too;
   - give shared resources per-worker names.
3. **Prove nothing was lost:** `vendor/bin/phpunit --list-tests` and the ParaTest run must report the same
   test count. Also compare assertions with a serial run, if one exists.
4. **Make it fast** (§5):
   - LPT file order;
   - choose K from the numbers, not from nproc;
   - fix the files that set the wall at their root cause, and split a file only when nothing else works;
   - make expensive data providers cheap.
5. **Find the flakes** with one run per cause, then fix each by class (§6).
6. **Wire it into CI** (§7):
   - the runner script;
   - a conservation check against a pinned suite figure;
   - failure output on the console;
   - a whole-run timeout;
   - coverage planned separately, because of memory.
7. **Update everything that names the old runner:** docs, guard tests that roster scripts, CI path triggers
   (§7.6).

Running rules that saved a lot of time (§9): run each suite once, with all output to a file, and inspect that
file. Use `</dev/null` on every run. Never edit the runner script while a run of it is live.

---

## 2. How ParaTest 7.x really works (the facts everything else depends on)

Read from `vendor/brianium/paratest` 7.4.9 and PHPUnit 10.5, and verified by experiment (report §3).

**The parent process:**
1. Seals PHPUnit's event facade, **then** includes your bootstrap. A bootstrap that registers an event
   subscriber therefore throws `EventFacadeIsSealedException` in the parent.
2. Builds the whole suite. That loads every test file and **runs every data provider**. This is the startup
   tax, paid on every invocation, even `--filter` runs (§5.5).
3. Turns the suite into dispatch units, **one per file**:
   - Files under a `<directory>` come out sorted **alphabetically by path**, which is the root cause of slow
     runs.
   - `<file>` entries keep the order they are listed in, and come after `<directory>` matches.
4. Starts K workers with `PARATEST=1`, `TEST_TOKEN=1..K` and `UNIQUE_TEST_TOKEN=<token>_<uniqid>` in their
   environment. Worker env is built from the parent's `$_SERVER`/env, so anything the bootstrap changed in the
   parent is inherited (§4.4).
5. Every 10 ms, hands the **next file** to any worker that is free. It does **not** pre-assign batches.
   - A worker that died raises `WorkerCrashedException`, naming the file the parent **last handed it**. That
     is usually not the file that did the damage.
6. At the end, merges the per-worker junit, coverage and other logs. The merge runs **in the parent**, under
   the config's `memory_limit` (§7.5).

**A worker:**
- Reads its commands, one file path per line, with `fgets(STDIN)`. STDIN is the parent's command pipe.
  - EOF, `false` or `EXIT` ends the worker with exit 0.
  - Anything that closes the real STDIN, makes it non-blocking or reads from it can kill or hang the worker
    (§4.2).
- Bootstraps once per process. `--max-batch-size N` restarts the worker (and re-bootstraps) after N files.
- Runs each file's tests in declaration order. `--order-by=random` shuffles **files**, never the methods
  inside a file.
- Throws away test stdout and stderr. Put diagnostics into assertion messages.

**Useful logs:**
- `--verbose` prints `Process N executing: <file>` for every dispatch: the exact file sequence of each
  worker. Use it on every run. It is the input for finding polluters (§6.5).
- `--log-junit` gives per-test durations. They feed the LPT order, the flake ledger and the contention
  watchlist.
  - The root `<testsuites>` carries **no** totals. Its first child `<testsuite>` does.
  - Per-file `<testsuite file="...">` elements sit below that.

**Options that do not do what they say on 7.4.9:**
- `--order-by=duration` and `--order-by=defects` are no-ops, because the parent sorts with an empty result
  cache. LPT needs a generated config (§5.1).
- `--functional` (one unit per test instead of per file) runs some data sets 2-3 times and silently skips
  others (a data set named `a/b` never ran), yet still prints OK. It also re-runs the class's data providers
  for every unit. **Never use it for a run whose counts matter.**
- `-p auto` picks the core count, which is usually too many (§5.2).

---

## 3. Installing

| PHPUnit | ParaTest | Notes |
|---|---|---|
| 10.5 | `~7.4.9` | 7.4.9 is the last release for PHPUnit 10. It needs `phpunit/phpunit ^10.5.47` and PHP 8.2-8.4, so a repo on an older 10.5.x must bump PHPUnit first. |
| 11.x | 7.5.0 and later | 7.5.0 requires `^11.2.1`. Check the exact line on Packagist. |
| 13.x | 7.29.0 (2026-11-14) | Needs PHP ~8.4. |
| 9.x | 6.x | **Not verified.** Check Packagist before relying on it. |

```sh
composer require --dev "brianium/paratest:~7.4.9"
vendor/bin/paratest --version
```

- Use a tilde pin (`~7.4.9` means at least 7.4.9 and below 7.5). The worker prelude copies 7.4 internals and
  refuses any other line.
- On another ParaTest line, re-read `vendor/brianium/paratest/bin/phpunit-wrapper.php` before reusing the
  prelude. Upstream HEAD still reads commands from STDIN, so the hazard is the same, but the code it copies
  may differ.
- Never patch `vendor/`. Everything below works on the stock package.
- Monorepo note: each lib that runs ParaTest needs it in its own `require-dev` (SugarCraft 9fc52970c added it
  to 7 libs).

---

## 4. Making the suite run under ParaTest

Do these before looking at speed. Each one fails the run in a recognizable way.

### 4.1 Sealed event facade in the parent

- **Symptom:** the run aborts before any worker starts, with `EventFacadeIsSealedException`.
- **Cause:** the bootstrap calls `Facade::instance()->registerSubscriber(...)`, or a helper that does. The
  parent includes the bootstrap after sealing.
- **Fix:** catch exactly that exception around each registration. The parent never runs tests, so nothing
  is lost there. Workers include the bootstrap before sealing, so registration still works in them.

```php
try {
    \PHPUnit\Event\Facade::instance()->registerSubscriber(new MySubscriber());
} catch (\PHPUnit\Event\EventFacadeIsSealedException) {
    // ParaTest's parent seals the facade before it includes this bootstrap to
    // build the suite; it runs no tests, so there is nothing to watch there.
}
```

- Find them all: `grep -rn "registerSubscriber\|registerTracer" tests/ src/ vendor/<your-own-packages>/`. In
  SugarCraft that was `SuiteSkipRoster::install()`, the HangWatchdog bootstraps and `SharedLoopResidueGuard`
  (4cb93c45a). The last one is easy to miss because it lives in a support class, not in `bootstrap.php`.
- **Diagnostic subscribers you add later** (leak probes, timers) must be registered as a PHPUnit
  **Extension** in the config's `<extensions>` block, not from the bootstrap. Extensions bootstrap inside
  each worker (§6.6).

### 4.2 Worker stdin: the "Exit Code: 0(OK)" crash and the hang

The worker reads its commands from STDIN, so four things can break it:

| Hazard | What happens | Seen in |
|---|---|---|
| A test sets the real STDIN non-blocking (`stream_set_blocking(STDIN, false)`, or code that does it to the stream you hand it) | O_NONBLOCK is shared by every handle on the pipe. The next `fgets` returns false on an empty pipe, and the worker exits 0. | sugar-core `PosixBackendInjectedTermiosTest` |
| The bootstrap closes STDIN on purpose (`fclose(STDIN)`) | The next `fgets` reads a closed handle, and the worker exits 0. | candy-code's bootstrap, which keeps spawned children off the developer's terminal |
| A test or child reads the real STDIN | It eats the parent's commands, or blocks forever because the pipe never reaches EOF. | sugar-pty `PosixPumpTest` pumped the real STDIN and hung until the watchdog killed the worker (exit 137) |
| A spawned child inherits fd 0 and reads it or sets non-blocking on it | Same as above, from a process you did not write | candy-code |

- **Signature:** `WorkerCrashedException ... Exit Code: 0(OK)` names a file that passes on its own. The
  culprit is usually the file that ran **just before** it in the same worker (`worker-seq.php`, §6.5).
- **The structural fix is `scripts/paratest-worker-prelude.php`**, injected into workers only:
  ```sh
  vendor/bin/paratest ... --passthru-php="'-d' 'auto_prepend_file=/abs/path/paratest-worker-prelude.php'"
  ```
  - It runs before the stock `phpunit-wrapper.php`, does that file's whole job and exits. In any other PHP
    process it returns at once.
  - It reads commands from a private handle taken before the bootstrap runs, and forces blocking mode on
    that handle before every read.
  - That fixes the non-blocking and `fclose(STDIN)` cases. It was verified on synthetic cases, on the
    sugar-core crash pair, and on full candy-code runs of 33,800 tests.
  - **Limit:** the private handle is a dup of fd 0, so children still inherit it. PHP cannot set
    close-on-exec on it, and cannot reopen a pipe through `/proc/self/fd/0`.
- **The test-side fixes, which you should do anyway:**
  - No test passes `\STDIN` to code under test. Pass `fopen('php://memory', 'r+')`, or a `/dev/null` handle.
    The one-line sugar-core fix is 995b440bb; the sugar-pty one is fd6be85a3.
  - Code that spawns children passes an explicit stdin (`['file', '/dev/null', 'r']`), never an inherited one.
- **Pre-flight list:** `grep -rln "STDIN\|php://stdin" tests/` gives a review list. Most hits are harmless:
  `Tty(STDIN)` construction, size probes, or strings that are child scripts. Look for set-blocking calls,
  reads, pumps and `fclose`.

### 4.3 Helper classes declared inside another test file ("Class not found")

- **Symptom:** `Class "...\FakeClock" not found`, at some K values only.
- **Cause:** serial PHPUnit loads every test file before running any, so a helper declared as a second class
  inside `ATest.php` is visible to `BTest.php` by luck. A ParaTest worker loads only the files it is handed.
- **Fix:** move each such helper to its own autoloadable file (`tests/Support/FakeClock.php` under
  `autoload-dev`).
- **Find them:** `php tools/check-cross-file-test-types.php`. It is namespace- and import-aware and skips
  comments and strings. SugarCraft had 4: sugar-async `FakeClock`, candy-git `FixtureGit` (13 users),
  sugar-mouse `ZoneRoutingModel` and sugar-pty `FixtureMaster`. Commits ad88e8aee, 209c882fe, 3ac5b7041,
  d3f71a0fb.
- **Keep it fixed:** run the scan as a CI gate (e95e298e4). A one-type-per-file checker that only reads
  `autoload.psr-4` misses `tests/`.

### 4.4 The bootstrap runs in the parent too, and the workers inherit what it did

- **Cause:** the parent includes the bootstrap to build the suite, then starts workers whose environment is
  built from the parent's `$_SERVER` and env. Every `putenv()` or `$_SERVER[...] =` the bootstrap made in the
  parent is therefore already in place when a worker's own bootstrap starts.
- **SugarCraft case (e8d83578f):**
  - The bootstrap moved `HOME` into a sandbox and recorded "the launch HOME" so a guard could check that
    tests never wrote to the real one.
  - Under ParaTest every worker recorded the parent's sandbox as its launch HOME. The guard then reported
    "the sandbox IS the launch home", and a sandbox write as a write to the real home.
  - **Fix:** the first bootstrap in a process tree publishes the original value in its own env var. A
    bootstrap that finds that variable set takes the original from it.
- **What to check in a new repo:** every environment change in the bootstrap (`HOME`, `TMPDIR`, `PATH`,
  locale, `XDG_*`, app-specific gates). Each must still be right when the bootstrap runs twice in a
  parent→worker chain.
- **Per-process sandboxes:** if the bootstrap makes a temp sandbox, key it per process (for example by pid),
  so workers do not share it. SugarCraft's TMPDIR sandbox was already per process.

### 4.5 Resources shared between workers

- Fixed paths, ports, lock files and socket names collide once two workers run at the same time.
- Name them with `getenv('TEST_TOKEN')`, which is stable per worker, or with `UNIQUE_TEST_TOKEN`, which is
  unique per process and run.
- Bind servers to port 0 and read the real port back.
- SugarCraft had no confirmed case, because its bootstrap already sandboxed TMPDIR. A repo without that will
  have some.

### 4.6 The launch environment

The runner should launch every worker with:
- `</dev/null` on the parent;
- `--colors=never`;
- `env -u LINES -u COLUMNS`: a terminal size leaking in from the developer's shell changes rendering and
  breaks size-pinned tests;
- any env gate the old runner set. candy-code needs `CANDY_CODE_MCP_DISABLE=1` so the suite cannot spawn the
  repo's own MCP servers.

If a guard test rosters which scripts may set such a gate (candy-code's `BootstrapMcpDisableEnvTest`), add
the new runner to it (f06f82c09).

### 4.7 Prove the run is the whole suite

```sh
vendor/bin/phpunit --list-tests </dev/null > $OUT/list.out 2>&1; grep -c '^ - ' $OUT/list.out
```

- That count must equal the ParaTest junit's `tests`. The generated LPT config was checked this way: 33,821
  of 33,821.
- If a serial junit exists, compare assertions too.
- A config that silently drops a directory is the worst failure this migration can have, because it is
  green.

---

## 5. Speed

### 5.1 Schedule longest-first (LPT)

- **Why:** dispatch is alphabetical, so a slow file late in the alphabet starts late and sets the wall.
  - candy-code at K=32 took 407 s in alphabetical order, against a 304 s longest file.
  - In LPT order the same run simulates to 304 s.
- **Model, verified within about 3.5%:** wall ≈ fixed startup + a greedy schedule replayed from the run's
  own junit durations. The candy-code startup was 35-47 s.
- **Fix:** `scripts/paratest-lpt-config.php <lib> <durations> <out.xml>`.
  - It copies the lib's `phpunit.xml` and replaces its test suites with one `<file>` per test file,
    longest-first.
  - Durations come from any junit (summed per file) or a `path<TAB>seconds` TSV.
  - Files with no recorded duration go **first** (unknown means assume slow), so stale durations can cost
    balance but never drop a test.
  - Its last stderr line is machine-readable: `lpt: files=N unknown=U total=S longest=L suggested-k=K`.
- **Durations source:** use the last run's own junit first, and a committed TSV only as a fallback.
  - A committed table goes stale after every speed fix. It still said KeyHelpTest took 302 s after that file
    dropped to 74 s, and it suggested K=15 where about 58 was right (bf245174d).
  - `scripts/paratest.sh` moves `<out>/junit.xml` aside as `junit.prev.xml` and uses it.

### 5.2 Choosing K

- **Rule:** K ≈ ceil(1.25 × total test time ÷ longest file), then **cap it at nproc / 2**. `-p` still
  overrides.
  - Above total ÷ longest, the longest file sets the wall and extra workers only add contention.
- **Why the cap:** tests that spawn processes slow each other down, so total test time grows with K.
  - Measured on candy-code with 64 CPUs: going from K=32 to the rule's K=58 cut the wall from 144.8 s to
    111.0 s, but raised total test time by 23% (3,561 → 4,389 s).
  - It also added a timing red.
  - Fed its own junit, the rule then asked for K=67. It ratchets.
- **Per-lib examples:**
  - sugar-colorprofile was flat from K=2, because one 59 s test set the floor.
  - sugar-async plateaued at K=4.
  - candy-code, after the fixes, ran at K=32 in 152 s.
- **Re-derive K after every heavy-file fix:** the ratio rises as the floor drops.
- **On CI runners,** K = core count capped at 4, and never oversubscribe. On 4 vCPUs, K=8 grew 1
  wall-clock-race failure and K=16 grew 4.

### 5.3 Find what sets the wall

1. Per-file seconds from the junit (`prompt_kit/tools/paratest/sched-sim.php <junit> <K>` prints the
   schedule and the top files with start and end times).
2. Decide which bound you are on:
   - total ÷ K is more than the longest file: **total work** sets the wall, so make the expensive files
     cheaper;
   - otherwise **one file** sets it, so speed that file up or split it.
3. Profile the expensive tests before touching them. A sampling profiler that needs no extension is in
   Appendix C. It is invoked like this:
   ```sh
   SAMPLER_OUT=$OUT/prof.txt php -d auto_prepend_file=/abs/sampler.php vendor/bin/phpunit --filter 'testName@dataset' tests/X/YTest.php </dev/null > $OUT/run.out 2>&1
   ```
   "PHPUnit ErrorHandler / stream_select" self time in its output is the profiler's signal interrupting a
   select. Read it as time spent waiting on a child.
4. `prompt_kit/tools/paratest/split-whatif.php` simulates "what if this file were split or shrunk" before
   you do it.

### 5.4 Root-cause speedups found in SugarCraft (look for the same shapes)

Prefer these to splitting: they also speed up serial runs and the product.

| Shape | Example and fix | Gain |
|---|---|---|
| Per-frame linear scans in UI code | The palette re-mapped every built-in command per row, several times a frame. Fixed with a per-locale index (4db7b9bc2); the settings `byKey()` scan got the same (49ae58dd8). | 57.9 → 11-21 s |
| A subprocess per render | The startup summary spawned `git rev-parse` + `git status` for the version string on every frame. Now memoized per process (9e8e149be). | 41.6 → 21.7 s |
| Re-estimating the same data every frame | The transcript token estimate is now kept per message in a WeakMap, since messages are readonly (dccd125fd). | KeyHelp cues test 30.9 → 13.7 s |
| Expensive corpus scans per test | `BuiltInToolCorpus` re-scanned `src/` and built every tool for each test. It now scans once per process and builds only the class asked for (ce2673f08). | 245 → 1 s |
| Same for a docs/env scanner | Built once per process (224bb4ce3) | 48 → 7 s |
| A guard test re-tokenizing sources | Memoized per class lifetime and dropped in `tearDownAfterClass()` (160a32566); taint resolver sites read once (89ebfb82d) | 34.7 → 14.8 s |
| Grapheme-by-grapheme walks over plain ASCII | Width functions take printable-ASCII runs in slices (e8a0b350e, b55b63055) | one case 24.9 → 0.8 s |
| A real-time protocol window the test sits out | The heartbeat timeout became a constructor parameter. The default (15 s) is pinned, and the tests use 3 s (de55eb23f). | 33.5 → 9.4 s for the pair |
| Each spawned `php` child compiling the app from nothing (CLI opcache dies with the process) | A per-test-process opcache **file** cache for spawn sites: `-d opcache.enable_cli=1 -d opcache.file_cache=<dir> -d opcache.blacklist_filename=<temp dirs>`. The dir is removed at the owning process's exit, and temp dirs are blacklisted so fixture scripts are never served stale (550dea10e). | 50 → 32 s |
| Tests that sit out a whole budget | See §5.6 | 5-20 s per test |

**Rules for memoizing in or for tests:**
- Memoize per process and per input; key by locale where output is localized.
- A test that swaps a seam between calls needs the memo cleared. Use a static property with a
  `clearCacheForTesting()`, or a shared test trait that flushes named statics by reflection, rather than a
  function-local `static`.
- Never share mutable **instances** between tests to save time. That creates order dependence.

**Prove behavior did not change:**
- Rewriting a hot function: differential-fuzz it against a copy of the old class. The Width rewrite was
  checked over 600,020 inputs, with 0 differences.
- Changing a guard's internals: snapshot its output over the whole tree, before and after.

**Splitting a file** is the last resort, for when one file is above the wall target and nothing is cheap to
fix. Every SugarCraft split file got a test that pins the partition (all slices together cover the whole
set), so a slice cannot silently drop out (b953abb7c, 088d96265, bd7821006).

### 5.5 Parent startup (the tax on every invocation)

- The parent builds the whole suite, including every data provider, before any test runs. On candy-code
  that was 15.7 s of build plus worker bootstrap and junit merging: 35-47 s in all.
- Five providers were 11.9 s of the 15.7 s: corpus or source scans in a provider.
- Make providers cheap by memoizing the scan they share with the tests. That cuts every paratest **and** every
  plain phpunit invocation, including one-test `--filter` runs.
- Measure with `prompt_kit/tools/paratest/suite-build-cost.php`.

### 5.6 The exact-budget detector

A test that takes **exactly** a timeout (5.00 s, 10.00 s, 20.00 s) is not failing, but it is paying its
whole budget. List the candidates from any junit:

```php
<?php // php exact-budget.php <junit.xml>
$d = new DOMDocument(); $d->load($argv[1]);
foreach ((new DOMXPath($d))->query('//testcase') as $tc) {
    $t = (float) $tc->getAttribute('time');
    if ($t >= 5.0 && abs($t - round($t)) <= 0.03) {
        printf("%7.2f  %s::%s\n", $t, $tc->getAttribute('class'), $tc->getAttribute('name'));
    }
}
```

Every hit in SugarCraft was one of four shapes:

| Shape | Cause | Fix |
|---|---|---|
| **Lost loop stop** | The promise settled synchronously, so `then()` ran `$loop->stop()` before `$loop->run()` started. The stop was lost, and the loop ran until its guard timer fired. | Run the loop only while the promise is still pending (41a0a4e07, d18f1e2d1, 7723570b9). Put one shared `settle()` helper in the test-support package so the bug cannot be copied a fourth time. |
| **`proc_close()` on a live child** | It waits for the child to exit. | Kill the child first (8ddd06993). |
| **Unused test seam** | The class had `setReapBudgetsForTesting()`, and one test did not call it. | Use the seam (d0291e78d). |
| **Unanswered protocol request** | `disconnect()` sent an LSP `shutdown` to a fixture that never speaks LSP, and waited out the full request timeout. | Give fixtures a short timeout (21e202db4). The same family turned up a product bug: a request to a dead server cost its whole timeout, because `is_resource()` never saw the death (b91431001). |

Leave a budget in place only when the time is what the test is about (for example a deadline test
whose twin times out on the same fixture).

---

## 6. Finding and fixing flaky tests

### 6.1 The failure classes

| Class | Trigger | Signature | SugarCraft examples |
|---|---|---|---|
| **A. Timing under load** | High K, CPU oversubscription | Duration inflates 3-20× and crosses a hard-coded wall-clock bound | `ServeStatusStopTest` 4.9 → 20.7 s against a 20 s ceiling; `McpConcurrentStartupTest`, two 2 s handshakes "in less than 3.5 s", measured 5.72 s at K=64 |
| **B. Accumulation and pollution** | Long-lived workers (low K, no batch limit) | Fast tests fail with timeouts or resource errors deep into a worker | `WsUpgradeTest` at K=2 only; descriptor count past a fixed fd window; K=1 OOM after 58 minutes |
| **C. Placement and order** | Which files share a worker | Non-monotonic in K; appears under some random seeds only | `AgentWorkerPoolCancelKeepsLoopLiveTest` ×3 under seed 101 only (§6.7) |
| **D. Worker context** | ParaTest's own process model | `Exit Code: 0(OK)`, `EventFacadeIsSealedException` | §4.1, §4.2 |
| **E. Shared resources** | K ≥ 2, independent of load | Collisions on paths, ports, locks | none confirmed (§4.5) |
| **F. Intrinsic nondeterminism** | Any K, including alone | Fails in a repeat loop of the test alone; a **varying assertion count** | `PacedAsyncRendererTest` (118,762 vs 118,761 assertions) |

### 6.2 Why "halve K until it passes" does not work

Each step from K=64 down to K=1 changes three things at once:
- load drops, which hides class A;
- workers live longer, which exposes class B;
- file placement changes, which moves class C.

A failure at one K therefore says nothing about its cause. The SugarCraft halving sweep cost about 2.3
hours, and its K=1 run only reproduced a known OOM.

### 6.3 The protocol: one cause per run

Seven full runs, each with `--display-warnings --display-notices --display-deprecations`, so warnings are
named, not just counted. The junit has no warning elements, so a run that shows only "Warnings: 2" leaves
you nothing to go on.

| Run | Shape | Tests for | candy-code wall (K=32 base, 64 CPUs) |
|---|---|---|---|
| r0 | chosen K, LPT | baseline | 152.6 s |
| r1 | K = nproc | load (A) | 117 s at K=64 |
| r2 | chosen K, `--max-batch-size 1` (a fresh worker per file) | isolation (B, C) | 251 s, so a restart costs about 1.5 s per file per worker |
| s101, s202, s303 | chosen K, `--order-by=random --random-order-seed=N` | order (C) | 157-166 s |
| r6 | K=2 | accumulation (B) | 1,415 s |

A driver for this is in Appendix B. It records each run in the ledger:

```sh
T=prompt_kit/tools/paratest
php $T/flake-ledger.php add $E/ledger $E/r1/junit.xml r1 k=64 order=lpt batch=0   # after each run
php $T/flake-ledger.php report $E/ledger > $E/FLAKES.txt                           # ALWAYS / FLAKY with the run settings
php $T/contention-sensitivity.php $E/k64.xml $E/k32.xml $E/k2.xml > $E/watchlist.txt
```

**Gotcha:** `contention-sensitivity.php` labels runs by file **basename**. Three inputs all named
`junit.xml` produce an empty table, so symlink them to distinct names first.

Reading the result:

| Failing runs | Class | Next step |
|---|---|---|
| Every run, including r2 | The tree is red | Not a flake |
| r1 only | A | Read the watchlist entry; fix by §6.7 A |
| r0/r6 but not r2 | B | Find the polluter (§6.5, §6.6) |
| r2 only | Depends on an earlier file (missing setup, cross-file type) | `check-cross-file-test-types.php`; read the test's setup |
| Some seeds only | C | Re-run that seed and replay the worker's sequence (§6.5) |
| `Exit Code: 0(OK)` | D | Look at the file **before** the named one in that worker |

The contention watchlist matters even when nothing fails. On candy-code, 213 tests ran 3× or more slower at
K=64 than at K=2, and 65 ran 5× or more. Almost all spawn children: MCP and LSP servers, or the app's own
binary. Those are the tests that will go red first on a slow CI runner.

### 6.4 Reproduce one flaky test

- **Alone, repeated (class F):** one plain phpunit per iteration, with no ParaTest startup.
  ```sh
  for i in $(seq 1 30); do vendor/bin/phpunit --filter 'Class::method' </dev/null > $E/rep-$i.out 2>&1; echo "$i rc=$?"; done > $E/rep.summary
  ```
- **Under load (class A):** run the same loop while a K=nproc run is going. A closer model of a 2-vCPU
  runner is to pin the loop with `taskset -c 0,1` next to competing busy loops (**not verified** here).
- **The exact failing condition:** rerun with the same K, seed and batch size as the ledger row.

### 6.5 Find the polluter (classes B and C)

1. **The victim's worker sequence:**
   ```sh
   php $T/worker-seq.php $E/s101/paratest.log                        # per-worker summary
   php $T/worker-seq.php $E/s101/paratest.log VictimTest > $E/suspects.txt
   ```
   This lists the files that ran before the victim in its worker. It needs a `--verbose` log.
2. **Replay that sequence serially:** a phpunit config whose `<file>` entries are the worker's files in
   order, run with plain `vendor/bin/phpunit -c replay.xml`. Plain phpunit runs listed files in order.
   - The AgentWorkerPool reds reproduced this way from worker 13's 65 files.
3. **Bisect automatically:**
   ```sh
   php $T/bisect-polluter.php . $E/suspects.txt tests/X/VictimTest.php $E/bisect --filter='/testName/' --env=GATE=1
   ```
   - Each probe is one plain phpunit process running `[subset..., victim]`.
   - It reports either the single polluter, "victim fails ALONE" (not order-dependent), or "needs files
     from both halves" (accumulation, so use the probe below).

### 6.6 The process-state leak probe

A PHPUnit extension that snapshots process-global state after every test, and logs per worker any test after
which it changed. This finds **polluters** directly, instead of victims 10,000 tests later. It found
the EgressProxy leak (§6.7), and a later full run with it proved that no other test in the suite leaks that
state.

What it watches:
- 10 signal dispositions (`pcntl_signal_get_handler`);
- `pcntl_async_signals()`;
- STDIN's blocking mode;
- the cwd;
- the umask.

Code is in Appendix A. How to wire it:
- Register it as an **Extension**, not from the bootstrap (§4.1).
- For a ParaTest run, generate the LPT config with `--bootstrap=<probe bootstrap>` and add the extension's
  `<bootstrap class=.../>` to the generated `<extensions>` block.
- Take its baseline in `bootstrap()`. Otherwise the first test in each worker goes unchecked.
- **Positive control:** add one test that leaks on purpose, and check that the probe reports it before you
  trust a clean result.
- **Expected noise:** PHPUnit's own SIGALRM handler, installed once per worker by `enforceTimeLimit`.

Worth adding to it in another repo: the open-fd count (`/proc/self/fd`), child processes, a hash of
`getenv()`, the error-handler stack, and timers armed on a shared event loop.

### 6.7 Fixing, by class, with the cases from SugarCraft

**A. Timing under load**
- **A bound's job is "never hang", not "be fast".** Derive it from the K=nproc duration × 3, or rely on
  PHPUnit's `defaultTimeLimit` hang-catcher.
- **Wait on events, not time.** The child prints a readiness line and the test blocks on `stream_select`
  for it, or polls `waitpid(WNOHANG)` against a generous ceiling. Never `usleep(n)` and assume the thing
  has happened.
- **Never assert elapsed time to prove a property.**
  - Case `McpConcurrentStartupTest` (d91a37278): it proved two slow MCP handshakes ran concurrently by
    timing them, and went red at K=64 (5.72 s against 3.5 s).
  - The fix is a **2-party barrier** in the fixture server. Each fixture writes `arrived.<pid>` and waits
    for the other. If the other has not arrived within a 20 s fallback, it writes `alone.<pid>` and carries
    on.
  - The test asserts two arrivals and no `alone` file. That is the property itself (they overlapped), with
    no clock in it.
  - **Negative control:** a lone fixture writes `alone.*`. 30/30 green at load 39-67.
- **An upper bound on elapsed time should separate the two outcomes the test distinguishes, not "fast"
  from "slow".**
  - Case `ClaudeCodeMcpClientDeadlineTest::testAnUnansweredHandshakeFailsOnTheBudgetAndReapsTheChild`
    (a352dac45f): `connect()` with a 0.5 s budget against a silent server was bounded at < 5.0 s.
  - Alone it took 1.01-1.06 s (30/30), but a K=32 run at load 49 measured 5.64 s.
  - What the bound has to tell apart: the budget firing, against the **60 s default** a lost budget would
    fall back to. 15 s does that with a 4× margin and is out of reach of load.
  - Find the alternative the bound guards against before choosing the number, and write it in a comment
    next to the bound.
- **Real performance claims** belong in a separate group run at K=1, not in the main suite.

**B. Accumulation and pollution**
- **Restore whatever the code under test changed:** env, cwd, umask, static registries, error handlers,
  signal handlers, stream modes. That applies to **production code** that changes them for its own lifetime,
  not just to tests.
- **Case `EgressProxy::servePolicy()` (9a781dfe5), a product bug found by ParaTest:**
  - It installed SIGTERM/SIGHUP handlers and made STDIN non-blocking while serving, and restored neither.
  - Its test ran it in-process. Later in the same worker, `AgentWorkerPoolCancelKeepsLoopLiveTest` forked
    pool workers, which **inherited the PHP signal handler**. Cancel's SIGTERM then only interrupted their
    `sleep()`, so they reported Completed instead of Stopped.
  - The fix is to save each handler with `pcntl_signal_get_handler()` and the blocking mode with
    `stream_get_meta_data()`, set your own, and restore both in a `finally`. A test pins it: a sentinel
    handler and `SIG_IGN` installed before the call are still there after it.
  - **Inheritance rules:** a `pcntl_fork()` child inherits PHP signal handlers. An `exec`'d child resets
    caught handlers to default but **keeps** `SIG_IGN` and the signal mask.
- **Close every fd the test opens. Reap every child. Cancel loop timers.**
- **`--max-batch-size` (50-200) only mitigates.** It bounds the damage without fixing anything. It is also
  the ParaTest answer to a K=1 OOM.

**C. Placement and order**
- Every helper type in its own autoloadable file (§4.3). No test may rely on another file having run.
- Static caches need a reset hook, or a key that makes order irrelevant.
- Compute fixed-number assumptions about the process from the actual state. "The marker fd is below 40"
  breaks in a long-lived worker; search up to the marker plus a margin.

**D. Worker context:** §4.1 and §4.2.

**E. Shared resources:** §4.5.

**F. Intrinsic nondeterminism:** inject clocks and seeds. A varying assertion count means the test takes
different branches from run to run; make each branch an explicit case.

**Races that are really two-branch contracts** (both SugarCraft cases were flagged once, under load):
- **Process-group checks after a kill (7048410906, 12a8094d1a):**
  - `posix_kill(-$pgid, 0)` keeps succeeding while any member of the group exists, **zombies included**.
    Orphans are reaped by init one at a time, so a check right after a KILL can still see the group.
  - Poll until it fails, bounded, through the class's existing wait helper. A first version with its own
    `usleep` loop tripped the repo's sleep-site census guard, so look for one in a new repo.
  - Not reproduced in 1,600 local trials; the docblock says so.
- **"Reaped, or handed to the sweep" (bfa2e485f):**
  - The cancel contract said a killed child is either reaped within the short reap window (20 × 5 ms) or
    handed to the straggler sweep. The test asserted only the first branch, and went red once at load 62.
  - The fix asserts the contract: if the child is still tracked, drive the sweep, bounded at 5 s, then
    assert it is gone.

**Verification standard for any flake fix:** the test is green in the run shape that used to fail it (same K,
seed and batch size), and in a 30-iteration repeat under that condition. Record it in the ledger.

### 6.8 Tests that cannot run on some hosts: conditional skips, never silent ones

Some tests can only prove something on hosts where a precondition holds: bubblewrap starts, the OS is
Windows, a phar can be written, or (swoole, above) php can start with `TMPDIR=/` at all. Skip them there,
but in a way that cannot hide a regression:
1. **Probe the precondition, not a proxy for it.** SugarCraft's `SuiteSkipRoster::rootTmpdirChildStarts()`
   runs `php -r 'exit(0);'` in the same minimal environment the test's child gets, **without** the code
   under test. So it answers "can php start", never "does the guard work", and a broken guard still runs
   red. Checking `extension_loaded('swoole')` would be a proxy: wrong once swoole fixes the bug, and blind to
   any other extension with the same crash.
2. **A probe that cannot run answers "the test can run"**, so a broken host reports its real failure instead
   of skipping it away.
3. **Memoize the probe per process,** and make the **same predicate** the test body's skip gate. Then the
   roster below and the skip cannot drift apart.
4. **Roster every allowed skip** in a guard that fails the run on any skip it does not list
   (SugarCraft's `SuiteSkipRoster`, a PHPUnit subscriber plus a shutdown check). A conditional entry is
   lifted exactly where the predicate says the test runs. A rostered test that stops skipping is red too, so
   the roster cannot go stale.
5. **The pinned suite figure stays in CI's shape.**
   - A skip that only happens on some hosts makes the local skipped count higher, and the assertion count
     lower, than CI's. That was +2 and -7 for the swoole skip.
   - Build the figure as CI will count it, write the local difference down next to it, and keep the skipped
     tests assertion-deterministic (no assertions in loops), so the difference is a constant.
6. **Insert new methods between methods, never between a doc-comment and its declaration.** The swoole
   predicate first landed between `windowsSmokeRuns()`'s doc-comment and its `function` line, which orphans
   that doc-comment. SugarCraft has a guard for stacked doc-comments (`RuntimeNoticeSinkDeliveryTest`) that
   caught it (14e1084fa5). Without one, nothing would.


---

## 7. CI

### 7.1 Use a runner script, not a bare `vendor/bin/paratest` line

`scripts/paratest.sh`:

```sh
scripts/paratest.sh [--lib <dir>] [-p <K>] [--durations <junit.xml|tsv|none>] [--out <dir>] [--timeout <secs>]
                    [--against-json <suite-figure.json>] [--print-failures] [--no-prelude] [--config-only]
                    [-- <extra paratest args>]
```

What it does:
- Writes the LPT config from the durations: the last run's junit in `--out`, else the committed TSV, else
  none.
- Picks K = min(suggested, nproc / 2) unless `-p` is given.
- Launches with the prelude, `--verbose`, `--log-junit`, `--colors=never`, `</dev/null`, `env -u LINES -u
  COLUMNS` and the repo's env gate, under `timeout <secs>`, which is a whole-run bound (exit 124).
- Removes a stale `<out>/junit.xml` before the run, so a run killed before writing its own junit cannot be
  judged on an old one.
- Puts all output in `<out>/paratest.log` and prints only the summary lines.
- With `--print-failures`, prints the log from its first `There was/were N` line (at most 4,000 lines) when
  the run is red. CI keeps no `--out` directory, so this is the only copy anyone sees.
- With `--against-json`, runs the conservation check (§7.2). A miss turns exit 0 into exit 1.

Repo-specific parts to change: the default `--lib`, the env gate on the launch line, and the TSV path.

### 7.2 The conservation check

- **Why:** a worker that dies, or a config that leaves a directory out, produces a green run with fewer tests
  in it. The check compares the run's totals with a committed figure.
- **Pinned figure:** a JSON like `{"tests": 33817, "assertions": ..., "failures": 0, "skipped": 10}`,
  produced from a green full run by a refresh script that refuses a red, filtered or self-contradicting junit.
- **`scripts/paratest-conservation.php <junit.xml> <figure.json>`:**
  - tests, assertions, failures and skipped must equal the figure, and errors must be 0;
  - skipped is counted as `<testcase><skipped/>` elements;
  - exits 0 on PASS, 1 on FAIL, and **2 for a missing, empty or unparseable junit, never a pass**.
  - `tools/tests/ParatestConservationTest.php` pins every exit path.
- **Keep the figure honest:** a test that compares the figure's `tests` with live `phpunit --list-tests` on
  every run (`ReadmeSuiteFigureDriftTest`). Adding tests without refreshing the figure then goes red.
- **Under ParaTest the check guards less than it did under a shard runner.** An unlisted file still runs
  (queued first), so it no longer catches a stale durations table. It still catches lost tests and an
  unrefreshed figure.
- **Check that assertion counts are stable before pinning them exactly.**
  - Tests that assert inside a polling loop, or on a timing-dependent path, report a different count from run
    to run.
  - On candy-code, 15 tests varied across 6 full runs of the same tree by 1-4 assertions each. Examples:
    McpLoginForkTest ×6, McpLoginServedTest ×2, ProcessExecutorTest, TaskCallIdThreadingTest. The run
    totals moved by up to 17.
  - An exact assertions check then fails at random. Compare per-test counts across two or more runs (the
    snippet below), and make the varying tests deterministic: assert once after the wait, not in every
    iteration. The alternative is to give the assertions total a tolerance.
  ```php
  <?php // php assert-diff.php <junit1> <junit2> ...: passing tests whose assertion count differs
  $by = [];
  foreach (array_slice($argv, 1) as $f) {
      $d = new DOMDocument(); $d->load($f);
      foreach ((new DOMXPath($d))->query('//testcase') as $tc) {
          if ($tc->getElementsByTagName('failure')->length || $tc->getElementsByTagName('error')->length || $tc->getElementsByTagName('skipped')->length) continue;
          $by[$tc->getAttribute('class') . '::' . $tc->getAttribute('name')][$f] = (int) $tc->getAttribute('assertions');
      }
  }
  foreach ($by as $id => $c) { if (count(array_unique($c)) > 1) { echo $id, '  ', json_encode($c), "\n"; } }
  ```
- **A second kind of unstable count: one that moves with what ran earlier in the same process.**
  - **Cause:** a per-process cache whose builder asserts, for example `self::assertIsString(file_get_contents($f))`
    for every file of a source walk. The builder's assertions land on whichever test builds the cache first.
    When a data provider builds it at suite load, they land on no test at all.
  - **Effect:** the total depends on the runner. A serial run executes every data provider before any test,
    ParaTest loads one file at a time per worker, and a shard runner has one process per shard. They disagree
    by the builder's whole count.
  - **Why assert-diff can miss it:** if the queue order always puts the same file first, every ParaTest run
    agrees, until a durations refresh or a new shard manifest changes the order.
  - **The candy-code case:** `EnvRosterDriftTest::realScanner()` asserted 1,413 times per build. The file
    counted 43,204 alone and 41,791 when `HelpTest`, whose data provider builds the same cache, loaded first.
    All 15 ParaTest runs agreed, and a serial run would not have.
  - **How to find it:** run a suspect file alone, then together with the files that share its cache in one
    `phpunit` process (a small config listing both), and compare per-class counts. To find suspects, grep for
    memo sites (`??=` on a static property, `static $x`) whose builders assert.
  - **Fix:** make the builder throw (`RuntimeException`) instead of asserting. Then it counts zero everywhere.
- **A figure measured on a host that skips what CI runs.** When a conditional skip (§6.8) fires locally but not
  on CI:
  - pin CI's shape: local skipped minus n, and local assertions plus what those tests assert when they run;
  - record the adjustment in the figure itself (a `host_adjustment` key);
  - expect a local `--against-json` to fail by exactly that difference, and check the adjusted totals by hand.
  - Before trusting the added count, confirm that nothing else asserts once per host-dependent item, such as a
    roster test that loops over the conditional entries.
- **Refresh chicken-and-egg:** the refresh script refuses a red run, but the drift test is red until the
  figure is refreshed. Resolve it like this:
  1. Set the figure's (and any README headline's) `tests` to the live `--list-tests` count.
  2. Run the full suite green.
  3. Run the refresh script on that junit.

### 7.3 K and timeout on CI

- K = `nproc`, capped at 4 (`ubuntu-latest` is 2 vCPU on the free tier and 4 on paid). Never oversubscribe.
- The timeout bounds the **whole** run. It must clear the LPT max bucket at K=4, with headroom for slow
  runners.

### 7.4 Durations table

- The committed table drives the queue order on a cold CI checkout. Refresh it from a full run at **CI's K**.
  On a big machine that gives near-serial per-file times, which is the closest local match to a 4-vCPU
  runner. A K=32 run's per-file times are inflated by contention.
- If CI comments or guards quote figures derived from the table (serial sum, floor file, LPT max bucket),
  re-derive and re-pin them in the same change.
- A new test file should get a row. Under ParaTest it runs without one, queued first, but a shard runner
  still in use (coverage below) runs it in **zero shards**.

### 7.5 Coverage: plan for the parent's memory

- **How ParaTest merges coverage:** each worker writes a serialized `.cov` holding every covering test id for
  every line, and the **parent** loads and merges all of them under the config's `memory_limit`.
- **Measured on candy-code at K=32:**
  - 1.74 GB of `.cov` files;
  - parent peak RSS 3.17 GB;
  - under the suite's `memory_limit=1G`, the parent died in `CoverageMerger` with rc 255, and no junit was
    written. The conservation check then failed closed, as designed.
  - The total is set by the suite (tests × covered lines), not by K. At K=4 each worker's `.cov` would be
    about 435 MB.
- **What SugarCraft did:** kept the coverage job on the old shard runner. Each shard writes a small clover
  report, and `scripts/merge-clover.php` unions them. The 1G guard stays.
- **Alternative (not verified on a 16 GB CI runner):** raise the memory limit for coverage runs only, for
  example a scratch config or a runner flag that sets `memory_limit=-1` in the parent.
- **Before switching a coverage job, measure it once:**
  ```sh
  /usr/bin/time -v vendor/bin/paratest ... --coverage-clover <file> </dev/null > $OUT/cov.out 2>&1
  du -sh <coverage temp dir>
  ```
  Small suites will be fine; large ones will not.

### 7.6 Everything that names the old runner

When the runner changes, grep for its name across the whole repo and update every hit:
- **Agent and contributor docs:** `CLAUDE.md`, `AGENTS.md`, rule files, `CONTRIBUTING.md`.
- **CI comments:** both the comments that describe the job and any figures they quote.
- **Guard tests that roster scripts:** SugarCraft's `ScriptsShellHygieneTest` has a reason per script, and
  `BootstrapMcpDisableEnvTest` lists who may set the env gate.
- **Path triggers:** a change to the runner script or the scripts it calls must run the affected job. In
  SugarCraft that meant adding them to `scripts/affected-libs.php` `FORCE_ALL_FILES`.
- **Tuning guards** that regex-match the CI step's flags (`ParallelShardTuningTest` matches `--timeout N ||
  RC` and `--timeout N --clover`).

SugarCraft's switch is def100a6f5.

---

## 8. Gotchas, one line each

- The parent includes the bootstrap: guard subscriber registration (§4.1), and expect the parent's
  environment changes to reach the workers (§4.4).
- `WorkerCrashedException` names the next file, not the guilty one.
- Test output is discarded under ParaTest, so a diagnostic `echo` shows nothing.
- `--functional` and `--order-by=duration` are broken or no-ops on 7.4.9.
- `--order-by=random` never shuffles methods within a file.
- `phpunit.xml` `<ini>` values (memory_limit) apply to the parent too.
- `enforceTimeLimit` installs a SIGALRM handler in every worker. That is expected, not a leak.
- PHPUnit 10 + `enforceTimeLimit` + K=64 on a loaded box: the slowest test took 46 s against a 60 s limit.
  Above about K=32, time-limit failures appear.
- Load from other jobs on a shared box inflates walls and totals. During one candy-code run at load 56-66,
  398 s of per-file gains were hidden by 473 s of losses in untouched files. Record `uptime` with every
  measurement, and compare per file rather than by wall.
- A dup of fd 0 shares O_NONBLOCK with fd 0.
- `fopen('/proc/self/fd/0')` fails on a pipe in PHP, because PHP resolves the symlink first.
- `kill(-pgid, 0)` succeeds while zombies remain.
- Committed duration tables lie after speed fixes; prefer the last run's junit.
- The junit root has no totals; read the first `<testsuite>`.
- `contention-sensitivity.php` needs distinct input basenames.
- A test that was green for hours and goes red with exit **134** (SIGABRT) in a child process: check what
  changed in the PHP install (`ls -lt /etc/php/*/cli/conf.d/`), and rerun the child's command with the new
  extension left out (`PHP_INI_SCAN_DIR=<copy of conf.d without it>`).
  - Seen 2026-11-21: swoole 6.2.0, enabled mid-session, aborted **every** CLI `php` started with `TMPDIR=/`
    (`std::out_of_range ... basic_string::at`). Two tests that prove the suite refuses that temp dir went red.
  - The owner wanted swoole kept on, so the fix was a **conditional skip** (§6.8).
- A test's own `set_error_handler()` that never checks `error_reporting()` also catches diagnostics the code
  suppressed with `@`, because PHP calls a user handler for those too, with the mask lowered. Then a `@fwrite`
  to a child that has already exited (EPIPE) is a red whenever the child wins the race, which happens at high
  load. Honor `@` with `if ((error_reporting() & $errno) === 0) return true;`, as PHP's display and PHPUnit's
  handler do (dee1de3135, StatusLineCommandTest, red once at load 46).
- `git pull` into the tree mid-round can change what the suite counts without touching its tests. On
  candy-code, 2 new sugar-pty files added 2 assertions to each of DescriptorInheritanceGuardTest's 6 repo-wide
  scans. Re-measure the figure after any merge, even one with no test changes.

---

## 9. How to run things (the discipline that kept this tractable)

- **Run each suite once, with all output to a file:**
  `vendor/bin/paratest ... </dev/null > $OUT/run.out 2>&1`.
  - Do all inspection with grep and awk on that file, and delete it when done.
  - Never pipe a suite into `tail` or `grep`: that hides output and forces a re-run.
- **Use `</dev/null` on every test run**, so nothing can wait on a terminal.
- **Put run directories in a scratch dir outside the repo.**
- **Never run a big suite serially** to "check". Use the runner at a sensible K.
- **Run long runs in the background,** and wait for them to finish rather than polling.
- **Never edit the runner script, or the files under test, while a run is live:** the junit has to describe
  one tree.
- **Commit each fix on its own,** with the before and after numbers in the message.
- **Keep a state file** (top block = what is running, what is next) and update it after every step. Runs
  outlive sessions.

---

## 10. Tools reference (`prompt_kit/tools/paratest/` unless noted)

| Tool | Use |
|---|---|
| `scripts/paratest-lpt-config.php <lib> <durations\|none> <out.xml> [--bootstrap=<abs>]` | LPT config from a junit or TSV; unknown files first; prints `suggested-k` |
| `scripts/paratest-worker-prelude.php` | Worker loop with a private, always-blocking command handle (ParaTest 7.4.x only) |
| `scripts/paratest-conservation.php <junit> <figure.json>` | Exit 0/1/2 conservation verdict |
| `tools/check-cross-file-test-types.php` | Helper types used across test files |
| `sched-sim.php <junit> <K>` | Replays the queue in alphabetical vs LPT order; top files with start and end times |
| `split-whatif.php` | Schedule if named files were split or shrunk |
| `suite-build-cost.php` | Per-file suite-build cost: class load plus data providers |
| `flake-ledger.php add <dir> <junit> <id> k=v... \| report <dir>` | ALWAYS/FLAKY classification with the settings that failed and passed |
| `contention-sensitivity.php <j1> <j2> <j3>` | Per-test duration ratio across load levels (the class A watchlist) |
| `worker-seq.php <verbose.log> [victim]` | Per-worker file sequence; suspects that ran before a victim |
| `bisect-polluter.php <lib> <suspects> <victim> <work> [--filter=] [--env=]` | Finds the single polluting file with plain phpunit probes |
| `cross-file-test-types.php` | Report-only version of the cross-file scan |

---

## 11. Case log: what SugarCraft needed (2026-11-21)

| Problem | Symptom | Fix | Commit |
|---|---|---|---|
| Bootstrap registers subscribers | `EventFacadeIsSealedException` before any worker | Catch exactly that exception | 4cb93c45a (+ existing guards) |
| A test set the real STDIN non-blocking | `Exit Code: 0(OK)` blamed on the next file | Private stream in the test; prelude for the rest | 995b440bb, 80b9f6cae |
| A test pumped the real STDIN | Worker hung until the watchdog killed it (137) | Private `/dev/null` handles | fd6be85a3 |
| Bootstrap `fclose(STDIN)` | `Exit Code: 0(OK)` | Prelude | 80b9f6cae |
| Helper classes across test files | `Class not found` at some K | Own autoloadable files + CI gate | ad88e8aee, 209c882fe, 3ac5b7041, d3f71a0fb, e95e298e4 |
| HOME pin ran in the parent | Sandbox guard: "the sandbox IS the launch home" | Publish the launch HOME through an env var | e8d83578f |
| Env gate set by the runner | Roster guard red | Roster the new runner | f06f82c09 |
| Alphabetical dispatch | K=32 wall 407 s vs a 304 s file | LPT config | 80b9f6cae |
| K rule ratchets | +23% total and a timing red at K=58 | Cap at nproc / 2 | b7d8fae96 |
| Stale durations table | Wrong K suggested | Last junit first | bf245174d |
| Heavy files | 300 s, 245 s, 48 s files | Root-cause fixes (§5.4) | db6379c03, ce2673f08, 224bb4ce3, and round 2 a-k (report §11.8) |
| Exact-budget waits | Tests at exactly 5, 10 or 20 s | §5.6 | 41a0a4e07, d18f1e2d1, 7723570b9, 8ddd06993, d0291e78d, 21e202db4 |
| Stopwatch concurrency proof | Red at K=58 and K=64 | Barrier | d91a37278 |
| Leaked signal handlers / STDIN mode | 3 reds under one seed | Restore in `finally` (product) | 9a781dfe5 |
| Zombie window | Rare red on a group check | Bounded poll | 7048410906, 12a8094d1a |
| One-branch assert of a two-branch contract | Red at load 62 | Drive the sweep, then assert | bfa2e485f |
| CI | Shard runner | Test job on `scripts/paratest.sh` + conservation; coverage left on shards (§7.5) | def100a6f5 |
| Assertion counts that vary between runs | Exact conservation check fails at random | Assert once after a wait, not per poll or per beat (§7.2) | 3cd77b3aa6 |
| Swoole aborts php with `TMPDIR=/` | 2 child-php tests red (exit 134) on one host | Conditional, rostered skip on a precondition probe (§6.8) | 47cad22304, 14e1084fa5 |
| A "never hang" bound sized like a speed claim | 5.64 s against 5.0 s at load 49 | Bound against the 60 s fallback it guards against (15 s) | a352dac45f |
| A shared cache whose builder asserts | Assertion total depends on which file ran first in the process (1,413 apart) | Builder throws instead of asserting (§7.2) | b6414ff58c |
| Figure measured on a swoole host | Local skipped +2, assertions -7 against CI | Pin CI's shape with a `host_adjustment` note (§7.2) | d8a503b68d, d851dc3d80, 58c360851e |
| A test's error handler that ignores `@` | EPIPE from a `@fwrite` to an exited child caught as a warning, once at load 46 | Honor `error_reporting()` in the handler (§8) | dee1de3135 |

Results:
- **candy-code:** 33,817 tests went from 407 s (ParaTest K=32, alphabetical) and 302 s (the old K=24 shards)
  to **152.6 s at K=32**. The longest file went from 304 s to 55 s.
- **sugar-colorprofile:** 90 s → 19.5 s.
- **sugar-image:** 70 s → 15.3 s.
- **sugar-async:** 46 s → 21.7 s.

---

## Appendix A: the process-state leak probe

Save it as the probe bootstrap. Point the run at it with `--bootstrap=<this file>` (or the LPT config's
`--bootstrap=`), and add `<bootstrap class="ProcessStateLeakProbeExtension"/>` to the config's
`<extensions>`. Run with `LEAKPROBE_DIR=<dir>`. It writes `<pid>.start` with each worker's baseline, and
`<pid>.log` with one line per test after which something changed.

```php
<?php
declare(strict_types=1);
require '/abs/path/to/the/repo/tests/bootstrap.php';   // the suite's real bootstrap

final class ProcessStateLeakProbe implements \PHPUnit\Event\Test\FinishedSubscriber
{
    private static ?array $last = null;

    public static function snapshot(): array
    {
        $now = [];
        foreach (['HUP' => 1, 'INT' => 2, 'QUIT' => 3, 'USR1' => 10, 'USR2' => 12, 'PIPE' => 13, 'ALRM' => 14, 'TERM' => 15, 'CHLD' => 17, 'WINCH' => 28] as $name => $n) {
            $h = \pcntl_signal_get_handler($n);
            $now['sig' . $name] = \is_int($h) ? (string) $h : 'callable';
        }
        $now['async'] = \pcntl_async_signals() ? '1' : '0';
        $now['stdinBlocked'] = \defined('STDIN') && \is_resource(\STDIN) ? (\stream_get_meta_data(\STDIN)['blocked'] ? '1' : '0') : 'closed';
        $now['cwd'] = (string) \getcwd();
        $now['umask'] = \sprintf('%04o', \umask());
        return $now;
    }

    public static function baseline(): void
    {
        self::$last = self::snapshot();
    }

    public function notify(\PHPUnit\Event\Test\Finished $event): void
    {
        $now = self::snapshot();
        self::$last ??= $now;
        $diff = [];
        foreach ($now as $k => $v) {
            if (self::$last[$k] !== $v) {
                $diff[$k] = self::$last[$k] . '->' . $v;
            }
        }
        if ($diff !== []) {
            \file_put_contents(\getenv('LEAKPROBE_DIR') . '/' . \getmypid() . '.log', $event->test()->id() . "\t" . \json_encode($diff) . "\n", \FILE_APPEND);
        }
        self::$last = $now;
    }
}

final class ProcessStateLeakProbeExtension implements \PHPUnit\Runner\Extension\Extension
{
    public function bootstrap(\PHPUnit\TextUI\Configuration\Configuration $configuration, \PHPUnit\Runner\Extension\Facade $facade, \PHPUnit\Runner\Extension\ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new ProcessStateLeakProbe());
        ProcessStateLeakProbe::baseline();   // without this the first test per worker is unchecked
        \file_put_contents(\getenv('LEAKPROBE_DIR') . '/' . \getmypid() . '.start', \json_encode(ProcessStateLeakProbe::snapshot()) . "\n");
    }
}
```

To read the result:
- `cat $LEAKPROBE_DIR/*.log | cut -f1 | sort | uniq -c | sort -rn` lists the leakers.
- A clean suite shows only PHPUnit's SIGALRM handler, once per worker.

---

## Appendix B: a flake-protocol driver

```bash
#!/bin/bash
# One run at a time; each appended to the ledger. R=repo, T=tools, E=evidence dir, D=durations junit.
R=/abs/repo; T=$R/prompt_kit/tools/paratest; E=/abs/scratch/flake; D=/abs/scratch/last/junit.xml
SHOW=(--display-warnings --display-notices --display-deprecations)
mkdir -p "$E"; cd "$R" || exit 1
run() { # id K timeout [extra paratest args...]
  local id=$1 k=$2 to=$3; shift 3; local s; s=$(date +%s)
  echo "$(date +%T) start $id k=$k load=$(cut -d' ' -f1-3 /proc/loadavg)" >> "$E/drive.log"
  scripts/paratest.sh -p "$k" --timeout "$to" --durations "$D" --out "$E/$id" -- "${SHOW[@]}" "$@" </dev/null > "$E/$id.console" 2>&1
  echo "$(date +%T) end $id rc=$? wall=$(( $(date +%s)-s ))s $(grep -E '^Tests:|^OK' "$E/$id/paratest.log" | tail -1)" >> "$E/drive.log"
}
K=32
php $T/flake-ledger.php add $E/ledger "$D" r0 k=$K order=lpt batch=0
run r1 "$(nproc)" 1800;                    php $T/flake-ledger.php add $E/ledger $E/r1/junit.xml r1 k=$(nproc) order=lpt batch=0
run r2 $K 1800 --max-batch-size 1;         php $T/flake-ledger.php add $E/ledger $E/r2/junit.xml r2 k=$K order=lpt batch=1
for sd in 101 202 303; do
  run s$sd $K 1800 --order-by=random --random-order-seed=$sd
  php $T/flake-ledger.php add $E/ledger $E/s$sd/junit.xml s$sd k=$K order=random seed=$sd batch=0
done
run r6 2 3600;                             php $T/flake-ledger.php add $E/ledger $E/r6/junit.xml r6 k=2 order=lpt batch=0
php $T/flake-ledger.php report $E/ledger > $E/FLAKES.txt
ln -sf $E/r1/junit.xml $E/kmax.xml; ln -sf "$D" $E/kbase.xml; ln -sf $E/r6/junit.xml $E/k2.xml   # distinct basenames
php $T/contention-sensitivity.php $E/kmax.xml $E/kbase.xml $E/k2.xml > $E/watchlist.txt
echo "$(date +%T) DONE" >> "$E/drive.log"
```

If the session dies mid-protocol, read `drive.log` and re-run only the missing ids.

---

## Appendix C: a sampling profiler with no extension

It sends SIGUSR1 every ~2 ms from a helper shell and records the PHP stack on each tick. Load it with
`-d auto_prepend_file=` and set `SAMPLER_OUT=<file>`. It prints the top self and inclusive frames.

```php
<?php
if (getenv('SAMPLER_OUT') === false || !function_exists('pcntl_signal')) { return; }
$GLOBALS['__samples'] = [];
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, static function (): void {
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60);
    array_shift($bt);
    $frames = [];
    foreach ($bt as $f) { $frames[] = (isset($f['class']) ? $f['class'] . '::' : '') . ($f['function'] ?? '?'); }
    $GLOBALS['__samples'][] = $frames;
});
$pid = getmypid();
$GLOBALS['__sampler_proc'] = proc_open(['bash', '-c', "while kill -USR1 $pid 2>/dev/null; do sleep 0.002; done"],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p);
register_shutdown_function(static function (): void {
    pcntl_signal(SIGUSR1, SIG_IGN);
    @proc_terminate($GLOBALS['__sampler_proc'], 9);
    $self = []; $incl = [];
    foreach ($GLOBALS['__samples'] as $fr) {
        if ($fr === []) continue;
        $self[$fr[0]] = ($self[$fr[0]] ?? 0) + 1;
        foreach (array_unique($fr) as $f) { $incl[$f] = ($incl[$f] ?? 0) + 1; }
    }
    arsort($self); arsort($incl);
    $n = count($GLOBALS['__samples']);
    $out = "samples $n\n== self\n";
    foreach (array_slice($self, 0, 30, true) as $f => $c) $out .= sprintf("%5.1f%% %s\n", 100 * $c / $n, $f);
    $out .= "== inclusive\n";
    foreach (array_slice($incl, 0, 60, true) as $f => $c) $out .= sprintf("%5.1f%% %s\n", 100 * $c / $n, $f);
    file_put_contents(getenv('SAMPLER_OUT'), $out);
});
```

- It changes signal state itself (async signals, a SIGUSR1 handler), so profile a single test with it, never
  a whole suite.
- Its signal interrupts `stream_select`, so select-heavy code shows up as that.
