<?php

declare(strict_types=1);

use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Support\ToolIpcFiles;

require __DIR__ . '/../vendor/autoload.php';

/*
 * The suite's skip roster, armed before the first test event.
 *
 * "Exactly one test skipped" has been this plan's proof that a run was WHOLE --
 * and therefore that the assertion total printed beside it is comparable to the
 * last one -- for fifty-four rounds, checked by eye every time and asserted
 * nowhere. `SuiteSkipRoster` turns it into a set compared by NAME, so a second
 * skip appearing anywhere in `tests/` fails the run instead of quietly re-basing
 * every figure that follows it.
 *
 * Installed here rather than in a test because skips happen throughout the run
 * in discovery order: a test can only see the ones that ran before it, and
 * roughly half the tree runs after any given file. The class's doc-block carries
 * the mechanism, the three checks, the pid guard, and -- stated rather than left
 * to be discovered -- what it does on a non-Linux runner, which is report and
 * not fail.
 *
 * Registration happens here because PHPUnit loads this file BEFORE it seals its
 * event facade. It is a no-op in the several test files that `require` this
 * bootstrap in a plain child PHP process: no facade, nothing registered, nothing
 * armed.
 */
\SugarCraft\Crush\Tests\Support\SuiteSkipRoster::install();

/*
 * Pin the suite's shared event loop to StreamSelectLoop.
 *
 * DO NOT "clean this up" — it fixes a measured ~33% flake (2 failures in 6
 * runs, on this change AND on the untouched baseline) in the three tests that
 * bound their wait with a Loop safety timer: BinSugarcrushWiringTest,
 * StreamingWiringTest and SystemPromptWiringTest.
 *
 * Loop::get() autodetects, and where ext-uv is installed it hands back
 * ExtUvLoop. libuv computes a timer's deadline against the loop's CACHED
 * clock (`loop->time`), which is only refreshed from the OS inside uv_run().
 * A PHPUnit process runs the loop in short bursts with long stretches of
 * ordinary synchronous test code in between, so by the time a test calls
 * run() the cached clock can be many seconds stale. A timer armed for 10s
 * against a clock that is 10s behind is already overdue, so it fires on the
 * FIRST tick and run() returns immediately. Those three tests arm exactly
 * such a safety timeout to bound their wait, so the safety net fires instead
 * of the work: effective delay is `delay - idle_since_last_run`. Measured on
 * this suite: 8s idle => run() returned after 2.0013s; 10.5s idle => 0.0002s;
 * 12s idle => 0.0002s. The tests then fail having consumed no wall time.
 *
 * StreamSelectLoop recomputes microtime(true) every iteration, so it has no
 * stale-clock window at all: the same probe at 4s and 11s of idle returns
 * 10.0003s and 10.0010s. Production is unaffected either way — there
 * candy-core's Program::run() drives one continuous uv_run(), which keeps
 * libuv's cached clock fresh, so this is a test-harness concern only and the
 * pin deliberately does not reach into src/.
 *
 * The one global side effect to know about: StreamSelectLoop's constructor
 * calls pcntl_async_signals(true) process-wide. That is what candy-core's
 * Program::run() does in production anyway, nothing in this repo uses
 * Loop::addSignal(), and 30 runs of tests/Agents with and 30 without the pin
 * were identical, so it is recorded rather than worked around.
 */
Loop::set(new StreamSelectLoop());

/*
 * The suite is hermetic against the terminal it happens to be run from.
 *
 * `SUGARCRUSH_BACKGROUND` is a supported escape hatch that outranks every other
 * source of the background colour, and `COLORFGBG` is the pre-OSC-11 inference
 * below it — so a developer whose shell exports either was running a DIFFERENT
 * suite from CI's. MEASURED on this tree at the commit that added
 * tests/Tui/ShellContrastTest.php: `SUGARCRUSH_BACKGROUND=dark` turned 10 of its
 * 12 tests red and `=light` another 10, plus 2 in TerminalBackgroundTest, and
 * every message blamed the shell's colours rather than the environment.
 *
 * Unset here rather than defended per-test: the tests that exercise the two
 * variables set them explicitly and restore them (including back to unset, which
 * `putenv()` only does when handed a bare name), so clearing the inherited value
 * is the one thing that makes both the pinned and the ambient case reachable.
 * ShellContrastTest asserts they are absent, so deleting these two lines fails
 * loudly instead of quietly re-tiering every background in the suite.
 */
putenv('SUGARCRUSH_BACKGROUND');
putenv('COLORFGBG');

/*
 * A temp directory for the suite's own throwaway files, and the two things that
 * keep `vendor/bin/phpunit` from garbage-collecting the developer's real /tmp.
 *
 * ToolIpcFiles::sweepOnce() is wired into Bootstrap::backend()/backendFor(),
 * and a dozen test files reach those — so whichever one PHPUnit happened to run
 * first spent the process's one sweep on the REAL sys_get_temp_dir(), unlinking
 * every sc_chat_tool_* / sc_runtime_tool_* / crush-hook-payload-* file over an
 * hour old that this uid owns. Harmless for the suite, hostile to the machine:
 * running the tests is not a request to reap another sugar-crush's files.
 *
 * 1. The latch is per-process and idempotent, so tripping it here leaves no
 *    sweep for any test to spend on the real directory. ToolIpcFilesTest resets
 *    it by reflection where it needs to exercise the sweep itself.
 *
 * 2. TMPDIR covers the real `bin/sugarcrush` processes this suite spawns,
 *    which get a genuine startup sweep of their own that no in-process latch
 *    can reach. (WHAT THIS SAID: "eighteen test files". WHAT IS TRUE: nobody
 *    can re-derive that. Three generators over `tests/` at 85a34cc1 answer
 *    41 files mentioning the path at all, 15 of those also containing a spawn
 *    primitive, and 13 naming it outside a comment — none of them eighteen.
 *    WHY THE SENTENCE STILL EARNS ITS PLACE: what it is FOR is that the set
 *    is not empty and the children are real processes, and that is why TMPDIR
 *    has to be exported rather than merely resolved in here.) It works on a CHILD and only on a child: `putenv()` does
 *    not move the temp directory of the process that calls it (PHP has already
 *    resolved sys_get_temp_dir(), so this suite keeps building its sandboxes
 *    under the real one and every test that does so keeps working), but a child
 *    is a fresh process that resolves it after inheriting this. Tests that hand
 *    their child a whitelist environment forward it explicitly.
 *
 * What that leaves is one test: ToolIpcFilesTest's wiring proof deliberately
 * resets the latch so a real Bootstrap::backend() sweep runs, in-process, on
 * the real temp directory. That is the production contract executing as
 * written — abandoned payloads of ours, older than an hour, owned by this uid —
 * and it is the only place the suite reaches the machine's /tmp at all.
 *
 * The directory is stable rather than per-run, and is never torn down: it has
 * to outlive the run (PHP silently falls back to /tmp when TMPDIR names
 * anything that is not a writable directory, which would put the children right
 * back on the real one), a shutdown hook would be inherited by every forked
 * child and run at the wrong time, and the sweep above prunes it on the next
 * run anyway. Per-uid because /tmp is shared.
 *
 * PER-UID AND NOT PER-CHECKOUT, which E242 proposed and which was measured
 * before it was declined (round 49). Three findings, all on PHP 8.3.6 and all
 * pinned by `tests/SuiteTempSandboxContractTest.php` so 8.4 answers for itself:
 *
 *  - THE KEY CANNOT HAVE CAUSED WHAT IT WAS BLAMED FOR. E242's one observed
 *    failure was two processes opening one `tasklist_test_<id>.sqlite3`, and
 *    that path is built from `sys_get_temp_dir()`. By the paragraph above,
 *    `putenv()` never moves THIS process's answer to that — measured in both
 *    orderings, including putenv before the first call — so it was the
 *    machine's real temp directory, not this sandbox, at any key. The same is
 *    true of every in-process `ToolIpcFiles::reserve()`, whose names are
 *    `sys_get_temp_dir()`-based too. Re-keying moves none of them.
 *  - THERE IS ALMOST NOTHING IN HERE TO COLLIDE ON. Sampled every 0.5s across
 *    a full 9,508-test run, the only entries this directory ever held were 20
 *    `crush-hook-payload-*` files, every one named by `tempnam()` — which is
 *    atomic and collision-free by construction, not by luck.
 *  - AND A PER-CHECKOUT KEY WOULD ADD A LEAK THIS ONE DOES NOT HAVE. The
 *    sandbox is pruned only by the next run's own bootstrap sweep. Keyed by
 *    uid there is exactly one per user and every run of any checkout prunes
 *    it; keyed by uid plus checkout path there is one per checkout, and a
 *    deleted lane worktree leaves a directory no bootstrap will ever sweep
 *    again — five of those a round, forever.
 *
 * WHAT THE RE-KEY WOULD HAVE DONE TO `ToolIpcFiles::sweepOnce()`, since E242's
 * Step asked and the answer is "less than it looks": nothing. The sweep above
 * is spent HERE to trip the per-process latch, and by the first finding the
 * payloads this suite reserves in-process are not in the directory it is
 * pointed at anyway. Its `$dir` argument only has to be somewhere harmless.
 * The uid filter inside `ToolIpcFiles::sweep()` keeps its stated reason too —
 * courtesy on a shared `/tmp` — because the one place the suite genuinely
 * sweeps the real `/tmp` is `ToolIpcFilesTest`'s wiring proof, which resets
 * the latch itself and never consults this key.
 *
 * WHAT E242 ACTUALLY SAW is the descriptor-0 block recorded at the bottom of
 * this file, which reproduces with one process on an idle box.
 */
$sandbox = sys_get_temp_dir() . '/sc_suite_tmp_' . (function_exists('posix_geteuid') ? posix_geteuid() : 'x');
@mkdir($sandbox, 0o700, true);

ToolIpcFiles::sweepOnce($sandbox);

putenv('TMPDIR=' . $sandbox);

/*
 * ...and the built-in audit hook writes into the sandbox too (E351).
 *
 * `HookManager::registerBuiltIns()` constructs `new BuiltIn\AuditHook()` with
 * no argument, and several suites reach it, so a plain `vendor/bin/phpunit`
 * CREATED AND POPULATED THE PRODUCTION AUDIT DIRECTORY on the developer's own
 * box — observed at round 49 as `/tmp/sugar-crush-audit-<uid>/` with an
 * `audit.log` grown to 29165 bytes by a suite run and by no real `sugarcrush`.
 * `AuditHookTest` was rewritten at E298 to stop driving writes at that path
 * and no longer does; the leak was every OTHER suite.
 *
 * THE `putenv()` ABOVE CANNOT MOVE IT, which is why this needs a seam of its
 * own. MEASURED, PHP 8.3.6: `sys_get_temp_dir()` still answers `/tmp` after
 * `putenv('TMPDIR=…')`, because PHP resolves and caches it once per process —
 * the same fact `ToolIpcFiles::sweep()`'s doc-block records for its `$dir`
 * parameter.
 *
 * THE GUARDS ARE NOT SWITCHED OFF BY THIS, only pointed elsewhere:
 * `AuditHook::append()` still refuses a directory that is a symlink, is not a
 * directory, is not this euid's, or is reachable by anybody else, and the
 * suite's own tests of those arms pass their own paths in. What moves is where
 * an UNCONFIGURED hook writes.
 *
 * `tests/Hooks/AuditHookProductionPathIsolationTest.php` asserts this line did
 * its job, because deleting it fails as a file quietly appearing under /tmp
 * rather than as a red.
 */
AuditHook::pinDefaultLogDirectory($sandbox . '/audit');

/*
 * The suite never reads the runner's own descriptor 0 (E212).
 *
 * `NonInteractive::run()` — the `-p "<prompt>"` one-shot path — calls
 * `readStdinIfPiped()`, whose default was the `\STDIN` constant. Roughly
 * thirty direct `run()` calls across tests/Cli therefore read whatever
 * descriptor 0 the process running PHPUnit happened to inherit, and the three
 * shapes that takes are not equally harmless: a terminal returns null, a
 * `/dev/null` or already-closed pipe returns `''` (also null), and a pipe that
 * is OPEN AND NEVER WRITTEN blocks inside `stream_get_contents()` with no
 * timeout. That last one hangs the ENTIRE run — no failure, no output, no
 * verdict — and it is the ordinary shape when a CI job or a supervising
 * process holds the child's stdin open. An ambient pipe that DOES carry bytes
 * is the quieter version of the same defect: those bytes are prepended to the
 * prompt of every `-p` test as stdin context.
 *
 * An empty `php://memory` stream is the pin: `stream_isatty()` is false for it
 * (so the tty short-circuit is NOT what answers, and the read path stays the
 * one production takes), and the read returns `''` immediately, so
 * `readStdinIfPiped()` answers null in bounded time whatever the runner's
 * stdin is doing.
 *
 * THIS LINE IS NOT THE REDIRECT OF FD 0 — but it is no longer true that there
 * ISN'T one, and this paragraph used to say there could not be.
 *
 * WHAT THIS SAID: "Not a redirect of fd 0: `\STDIN` is a constant bound at
 * startup and PHP has no `dup2`, so the descriptor itself cannot be replaced
 * from here."
 *
 * WHAT IS TRUE NOW: the second half is false, and the block at the FOOT of
 * this file does exactly the thing it forbids. `fclose(\STDIN)` frees
 * descriptor 0 and the next `fopen()` lands on the lowest free descriptor,
 * which is 0 — no `dup2` required. The half that survives is the premise, not
 * the conclusion: `\STDIN` really is bound at startup, which is why the repair
 * has to close the CONSTANT rather than re-point it, and why
 * `is_resource(\STDIN)` is false afterwards while `defined('STDIN')` stays
 * true. See "THE REPAIR IS THE DESCRIPTOR ITSELF" below, which carries the
 * measurements and the five attempts.
 *
 * WHY THIS PARAGRAPH STILL EARNS ITS PLACE: the two repairs are independent,
 * both are needed, and the descriptor replacement did NOT make this line
 * redundant — which is the first thing a reader who has just met the block
 * below will wonder. Two reasons, neither of them about spawned children:
 *
 *  - THE REPLACEMENT IS SKIPPED ON A TERMINAL, on purpose — it is guarded by
 *    `!stream_isatty(\STDIN)` so a developer running the suite from a shell
 *    keeps their own stdin. On that run fd 0 is the terminal, and without this
 *    pin `readStdinIfPiped()` would answer through the TTY SHORT-CIRCUIT
 *    instead of the read path production takes, which is precisely what the
 *    `php://memory` stream above is chosen to avoid.
 *  - AND THE PIN IS ON THE DEFAULT ONLY. A test that passes its own stream
 *    still gets it, and `src/`/`bin/` never call `pinStdinDefault()`, so
 *    production reads `\STDIN` exactly as before. The descriptor replacement
 *    has no such seam: it is process-wide.
 *
 * Deleting either repair leaves a real hole, and they fail differently — this
 * one as a test that quietly stops exercising the production path, the other
 * as a hang.
 *
 * `tests/Cli/NonInteractiveStdinPinTest.php` asserts this line did its job,
 * because deleting it fails as a HANG rather than as a red.
 */
NonInteractive::pinStdinDefault(fopen('php://memory', 'r+'));

/*
 * ...and neither does anything the suite SPAWNS (E212's other half).
 *
 * The pin above is on `NonInteractive`'s in-process default only, and says so:
 * "`src/`/`bin/` never call `pinStdinDefault()`, so production reads `\STDIN`
 * exactly as before". Many test files spawn a real `bin/sugarcrush`, and
 * `exec()`/`proc_open()` hand a child THIS process's descriptor 0. So the
 * hazard the pin removed from the runner was still live one process down.
 *
 * MEASURED on PHP 8.3.6, `bin/sugarcrush -p "hi"` with a sandbox HOME, timing
 * only the descriptor-0 shape:
 *
 *   stdin = /dev/null                 0.110s
 *   stdin = closed                    0.105s
 *   stdin = open, never-written pipe  blocks; SIGKILLed at 25s
 *
 * and, with a writer that sends one line after four seconds and then closes,
 * the run finishes at 4.1s with those bytes PREPENDED TO THE PROMPT
 * ("You said: > LATE-STDIN-CONTEXT ... > hi"). The block is
 * `stream_get_contents()` inside
 * {@see NonInteractive::readStdinIfPiped()} — bounded above by nothing —
 * and the child's own output places it there: the two `Bootstrap` notices
 * that immediately precede that call are printed, and nothing after it is.
 *
 * WHY THIS IS NOT A THEORETICAL SHAPE. `phpunit.xml` sets
 * `enforceTimeLimit="true" defaultTimeLimit="60"`, so each such test costs a
 * full minute and is reported as RISKY ("aborted after 60 seconds"), not as a
 * failure naming stdin. Observed in this tree while measuring E242:
 * `BootstrapSkillSkipsTest`'s two `-p` cases — 0.4s for the whole file when
 * run from a terminal — were both aborted at 60s in a full run whose runner
 * had been started with its stdin held open by a supervising process. That is
 * the "crawling, not CPU-bound, wall-clock waits" symptom E242 recorded and
 * attributed to concurrency; it reproduces with ONE process on an idle box and
 * has nothing to do with how many suites are running.
 *
 * THE REPAIR IS THE DESCRIPTOR ITSELF, AND IT TOOK FIVE ATTEMPTS OVER THREE
 * ROUNDS TO GET BACK TO THE ONE THAT WAS TRIED FIRST. Every attempt is kept,
 * because what killed each of them is the useful part.
 *
 * ATTEMPT 1 — `fclose(\STDIN)` then `fopen('/dev/null', 'r')`, which lands on
 * the lowest free descriptor, i.e. 0. PHP has no `dup2`, so this is the only
 * way to REPLACE the descriptor from in here. MEASURED, PHP 8.3.6, reading
 * `/proc/self/fd/0` either side, with fd 0 a held-open pipe and again with fd 0
 * `/dev/null`: `pipe:[…]` → (nothing, the descriptor is gone) → `/dev/null`,
 * both times. `is_resource(\STDIN)` is then false while `defined('STDIN')`
 * stays true, which is the trap for anyone testing for the constant's presence
 * rather than the handle's liveness.
 *
 * WHAT ATTEMPT 1 WAS REFUTED BY, AND WHY THAT REFUTATION NO LONGER HOLDS —
 * this is the whole story of E296, and it is a lesson about measurement rather
 * than about descriptors.
 *
 * WHAT WAS SAID: attempt 1 is unaffordable. A full suite with it applied
 * produced `9500 tests, 107 errors, rc 2`, every error
 * `Error: Undefined constant SugarCraft\Palette\Probe\Capability::Iterm2Image`.
 * The census behind the attempt had looked in `sugar-crush/src`, `bin` and
 * `tests` for `\STDIN`, and the reader that mattered was in a SIBLING LIBRARY:
 * {@see \SugarCraft\Mosaic\Detect} resolved its probe stream as
 * `self::$probeStdin ?? STDIN` and handed it on unguarded, `Mosaic::auto()`
 * caught the resulting throw, and the fallback it landed in named a
 * `Capability` case that does not exist. 107 became the price of option (a)
 * and it stalled the repair for two rounds.
 *
 * WHAT IS TRUE NOW: it was never 107 costs. It was ONE defect multiplied by
 * every test that reached it. The enum name was fixed in candy-mosaic (E302);
 * the unguarded read underneath it was fixed in the same class (E318), whose
 * `stdinFd()` now answers null for a dead handle instead of passing it to
 * `stream_select()`. RE-MEASURED here, PHP 8.3.6, full suite, this exact
 * change alone against a green 9661/142165/1-skipped baseline at the same
 * head: `9661 tests, 1 error, 2 failures, 1 skipped` — and not one
 * candy-mosaic error of any kind. All three are in tests whose entire subject
 * is this repair, and all three are rewritten alongside it. Nobody re-ran the
 * experiment for a round because 107 is a number that ends a conversation; a
 * blocking measurement has to be re-taken after anything in its causal chain
 * changes, and the chain here was one enum case name in another repository.
 *
 * ATTEMPT 2 — `stream_set_blocking(\STDIN, false)`, which SETS `O_NONBLOCK`
 * (the polarity is written out because two rounds of prose here had it
 * backwards: MEASURED from `/proc/self/fdinfo/0`, the flag is clear at
 * startup, SET by `stream_set_blocking($s, false)`, and clear again after
 * `stream_set_blocking($s, true)`). The flag lives on the open file
 * DESCRIPTION, which is what `fork(2)` and `exec(2)` share, so it reached every
 * inherited child without touching the descriptor or the constant. MEASURED,
 * PHP 8.3.6, three takes each, a parent whose own fd 0 is an open never-written
 * pipe `exec()`ing a child that calls `stream_get_contents(\STDIN)`:
 *
 *   inherited as-is                     child SIGKILLed at 8s, rc 137   3/3
 *   stream_set_blocking(\STDIN, false)  child read 0 bytes in 0.000s    3/3
 *   close fd 0 + reopen /dev/null       child read 0 bytes in 0.000s    3/3
 *
 * — indistinguishable to the child, and that last row is attempt 1. It shipped
 * for a round and it is what this change replaces.
 *
 * WHY THE FLAG WAS NEVER ENOUGH, which is the reason to pay attempt 1's price
 * rather than a matter of taste. `O_NONBLOCK` changes when a read RETURNS; it
 * does not change what the read returns. Bytes already sitting in the runner's
 * pipe are available, so they are read, and `NonInteractive::historyFrom()`
 * prepends them to the prompt of every spawned `-p` child. That is not
 * untidiness: it is an unbounded read of whatever handed this process
 * descriptor 0, concatenated into a prompt and sent to whatever provider is
 * configured — a build log piped into `phpunit` on a CI runner would go to a
 * model. Reproduced directly, PHP 8.3.6:
 * `printf 'MARKER\n' | php bin/sugarcrush -p "hi"` echoes `> MARKER` back
 * inside the turn. `/dev/null` reads empty, so replacing the descriptor closes
 * the blocking half and the prepend half at once.
 *
 * WHAT REFUTED ATTEMPT 2 SEPARATELY, kept because the guard it produced is
 * still load-bearing for other reasons. WHAT IT SAID: a flag can be cleared by
 * anything holding the same description, and this suite had such sites —
 * `new Tty(null, $injectedTermios)`, where `Tty::__construct()` is
 * `self::backend($stream ?? STDIN, $termios)` and the injected-Termios branch
 * of `PosixBackend::enableRawMode()` skips its own `isTty()` guard, so its
 * trailing `@stream_set_blocking($this->stream, false)` and `restore()`'s
 * matching `(…, true)` both landed on descriptor 0. MEASURED, PHP 8.3.6, three
 * takes each, that seam driven directly in a child whose fd 0 is a pipe: with
 * `null`, clear once the flag is set, still clear after `enableRawMode()`,
 * BLOCKED AGAIN after `restore()` — 3/3; with an explicit stream, fd 0's flag
 * never moves — 3/3; with NO `Termios` at all, also never moves — 3/3.
 *
 * WHAT IS TRUE NOW: this repair holds no flag, so no `new Tty(null, …)` can
 * erase it — the descriptor those sites would reach for is `/dev/null`, and
 * setting or clearing `O_NONBLOCK` on `/dev/null` changes nothing a child can
 * observe. WHY THAT CENSUS STILL EARNS ITS PLACE:
 * {@see \SugarCraft\Crush\Tests\TtyStreamArgumentCensusTest} was never only
 * about this line. A `new Tty(null, …)` still wraps THIS process's fd 0 and
 * still puts a test in raw mode on a descriptor it does not own, and the way
 * that census was found matters more than what it guards — THREE SITES WERE
 * FOUND BY A GREP AND A FOURTH WAS NOT, because `grep -rn 'new Tty('` cannot
 * express `new \SugarCraft\Core\Util\Tty(null, …)`, which is how
 * `tests/ChatTest.php` spells it. It walks the token stream, it fails on an
 * argument list it cannot read to its close rather than skipping it, and it
 * carries known-answer fixtures for both spellings.
 *
 * WHAT THIS COSTS, stated because it is the half that has to be paid
 * deliberately:
 *
 *  - THE `\STDIN` CONSTANT IS A CLOSED RESOURCE for the rest of the run.
 *    `defined('STDIN')` stays true, so any code testing for the constant's
 *    presence rather than the handle's liveness is handed a dead resource.
 *    {@see \SugarCraft\Crush\Tests\StdinConstantReaderCensusTest} is the roster
 *    of every reachable place that names descriptor 0 — this package's `src`
 *    and `bin` plus each sibling's `src` — and it is a test, so a new reader
 *    arrives red rather than arriving as a surprise. Its doc-block also
 *    carries the four THIRD-PARTY packages that name fd 0, one of which is
 *    inside PHPUnit's own dependency tree: `sebastian/environment`'s
 *    `Console::getNumberOfColumns()` is handed the closed handle and degrades
 *    to 80 columns, because `isInteractive()` opens with `is_resource()`.
 *  - IT IS DELIBERATELY NOT A `dup2`. The replacement handle is parked in
 *    `$GLOBALS` rather than a local, and that is load-bearing rather than
 *    belt-and-braces: PHPUnit `include_once`s this file from inside a private
 *    METHOD of `Application`, so a bare local here is a function-scoped
 *    variable that is freed on return — which closes fd 0 again. MEASURED,
 *    PHP 8.3.6 / PHPUnit 10.5.64, three takes each, two three-line bootstraps
 *    identical but for `$GLOBALS['__keep']` versus a bare `$keep`, each
 *    included from inside such a method, then `readlink('/proc/self/fd/0')`:
 *    `$GLOBALS` → `/dev/null` 3/3; the bare local → `false`, i.e. fd 0 closed,
 *    3/3.
 *
 * ONLY WHEN FD 0 IS NOT A TERMINAL, for two reasons rather than one. A
 * developer running the suite from a shell keeps their terminal, and a tty is
 * harmless anyway (`stream_isatty()` is the first thing `readStdinIfPiped()`
 * checks, and it returns null on it). It is also where descriptors 0, 1 and 2
 * most often ARE one description, and closing fd 0 there would be closing the
 * developer's own terminal out from under an interactive run.
 *
 * WHAT IT DOES TO E243: nothing, because E243 is CLOSED.
 * WHAT THIS SAID: "E243 stays NARROWED, not closed — a developer running the
 * suite from a real terminal skips this whole block, `\STDIN` stays open,
 * blocking and interactive, and the block E243 describes is still live there."
 * WHAT IS TRUE NOW: that sentence was written when
 * `HeadlessPermissionPrompt::__construct()` was `$this->in = $in ?? \STDIN;`.
 * It is `$this->in = $in ?? NonInteractive::stdinDefault();` — verified by
 * symbol — so the class shares the pin installed above rather than reading the
 * constant, and `tests/Cli/HeadlessPermissionPromptStdinDefaultTest.php` pins
 * that. The terminal case the sentence describes cannot arise any more: the
 * default is the suite's `php://memory` stream whether or not fd 0 is a tty.
 * WHY THE PARAGRAPH STILL EARNS ITS PLACE: the two `?? \STDIN` defaults were
 * one hazard family with one seam, and a reader who finds only the
 * `NonInteractive` half will not know the second one was folded into it rather
 * than left alone.
 *
 * Pinned by `tests/SuiteChildStdinIsolationTest.php`, which spawns the real
 * binary from a runner whose own stdin is an open, never-written pipe — and
 * runs the un-bootstrapped control through the same harness first, because
 * "it did not hang" is also what a harness that never started the child says —
 * and by `tests/SuiteChildStdinPrependResidualTest.php`, which does the same
 * with BYTES on that pipe and asserts they do not reach the child's prompt.
 * Both run the bootstrap in a child of their own, so no in-process seam can
 * reach them.
 */
if (!stream_isatty(\STDIN)) {
    fclose(\STDIN);
    // $GLOBALS, not a local: see the cost list above. This handle is what
    // occupies descriptor 0 for the rest of the run, and it has to outlive the
    // method PHPUnit includes this file from.
    $GLOBALS['__sugarcrushSuiteStdin'] = fopen('/dev/null', 'r');
}
