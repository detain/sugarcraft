<?php

declare(strict_types=1);

use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Support\ToolIpcFiles;

require __DIR__ . '/../vendor/autoload.php';

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
 * Not a redirect of fd 0: `\STDIN` is a constant bound at startup and PHP has
 * no `dup2`, so the descriptor itself cannot be replaced from here. The pin is
 * on the DEFAULT only — a test that passes its own stream still gets it, and
 * `src/`/`bin/` never call `pinStdinDefault()`, so production reads `\STDIN`
 * exactly as before.
 *
 * `tests/Cli/NonInteractiveStdinPinTest.php` asserts this line did its job,
 * because deleting it fails as a HANG rather than as a red.
 */
NonInteractive::pinStdinDefault(fopen('php://memory', 'r+'));

/*
 * ...and neither does anything the suite SPAWNS (E212's other half, measured
 * in round 49).
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
 * THE REPAIR IS A FLAG ON THE OPEN FILE DESCRIPTION, and round 49 spent TWO
 * attempts at a real descriptor before settling there. Both attempts are kept
 * because each was refuted by a measurement, and the second refutation is the
 * one nobody would guess.
 *
 * ATTEMPT 1 — `fclose(\STDIN)` then `fopen('/dev/null', 'r')`, which lands on
 * the lowest free descriptor, i.e. 0. Hermetic, and PHP has no `dup2` so this
 * is the only way to REPLACE the descriptor from in here. WHAT WAS SAID FOR
 * IT: "the `\STDIN` constant is now a closed resource for the rest of the run
 * … Nothing in the suite reaches it today, and the two things that could are
 * both already safe."
 *
 * WHAT IS TRUE: the census behind that sentence looked in `sugar-crush/src`,
 * `bin` and `tests` for `\STDIN`, and the reader that matters is in a SIBLING
 * LIBRARY. {@see \SugarCraft\Mosaic\Detect} resolves its probe stream as
 * `self::$probeStdin ?? STDIN` and hands it to `drainStdin()` with no
 * `is_resource()` guard; `Mosaic::auto()` catches the resulting throw and
 * falls back to `autoFromPalette()`, which references a `Capability` case that
 * DOES NOT EXIST on candy-palette's enum (`Iterm2Image`; the enum spells
 * `ITerm2`). MEASURED — full suite, PHP 8.3.6, this lane: attempt 1 produced
 * `9500 tests, 107 errors, rc 2`, every error
 * `Error: Undefined constant SugarCraft\Palette\Probe\Capability::Iterm2Image`.
 * A closed `\STDIN` does not merely inconvenience one control assertion; it
 * moves a whole library onto a code path that has never run. Recorded as its
 * own finding, because that fallback is broken independently of this file.
 *
 * ATTEMPT 2 — `stream_set_blocking(\STDIN, false)`, which is what ships.
 * `O_NONBLOCK` lives on the open file DESCRIPTION, and a description is
 * exactly what `fork(2)` and `exec(2)` share, so setting it here reaches every
 * child that inherits fd 0 without touching the descriptor or the constant.
 * MEASURED on PHP 8.3.6, three takes each, a parent whose own fd 0 is an open
 * never-written pipe `exec()`ing a child that calls
 * `stream_get_contents(\STDIN)`:
 *
 *   inherited as-is                     child SIGKILLed at 8s, rc 137   3/3
 *   stream_set_blocking(\STDIN, false)  child read 0 bytes in 0.000s    3/3
 *   close fd 0 + reopen /dev/null       child read 0 bytes in 0.000s    3/3
 *
 * — indistinguishable to the child, and after the flag `is_resource(\STDIN)`
 * is still true where after the close it is false. That is the whole
 * difference and, given attempt 1's 107 errors, the whole reason.
 *
 * WHAT REFUTED ATTEMPT 2 THE FIRST TIME, and why it does not any more. A flag
 * can be cleared by anything holding the same description, and this suite had
 * such sites: `new Tty(null, $injectedTermios)`. `Tty::__construct()` is
 * `self::backend($stream ?? STDIN, $termios)`, so a null stream wraps THIS
 * process's fd 0, and the injected-Termios branch of
 * `PosixBackend::enableRawMode()` skips its own `isTty()` guard — so its
 * trailing `@stream_set_blocking($this->stream, false)` and `restore()`'s
 * matching `(…, true)` both landed on descriptor 0. MEASURED, PHP 8.3.6, three
 * takes each, that seam driven directly in a child whose fd 0 is a pipe: with
 * `null`, clear once the flag is set, still clear after `enableRawMode()`,
 * BLOCKED AGAIN after `restore()` — 3/3; with an explicit stream, fd 0's flag
 * never moves — 3/3; with NO `Termios` at all, also never moves — 3/3, because
 * `enableRawMode()` returns at `!isTty()` before it reaches the flag. That
 * third row is why the shape is null-stream AND injected-Termios rather than
 * either alone.
 *
 * Every such site was given an explicit stream, and each now asserts the flag
 * moves on the stream it PASSED rather than on the runner's, so a revert is red
 * where it happens. THREE WERE FOUND BY A GREP AND A FOURTH WAS NOT, which is
 * the part worth carrying: `grep -rn 'new Tty(' src/ tests/ bin/` cannot
 * express `new \SugarCraft\Core\Util\Tty(null, …)`, which is how
 * `tests/ChatTest.php` spells it — and the full run went red at the guard
 * below with the other three already repaired. So the census is no longer
 * prose. {@see \SugarCraft\Crush\Tests\TtyStreamArgumentCensusTest} walks the
 * token stream, where the spelling cannot hide a site; it FAILS on an argument
 * list it cannot read to its close rather than skipping it; and it carries
 * known-answer fixtures for both spellings, so an empty result is evidence
 * rather than the silence of a dead scanner. No count is quoted here, because
 * a count over `tests/` is stale the next time one is added.
 *
 * WHAT THIS COSTS, stated because it is invisible until something steps on it:
 *
 *  - The flag is on the description, so it is shared with whatever else holds
 *    it — this process's children, and the process that handed fd 0 down. A
 *    reader elsewhere gets `EAGAIN` where it used to block.
 *  - It is NOT hermetic the way `/dev/null` was. Bytes already sitting in the
 *    pipe stay readable, so a runner started with data on stdin can still leak
 *    them into a spawned `-p` child's prompt. That is the PREPEND half of the
 *    hazard above, still open one process down; the BLOCKING half is what this
 *    line closes. Closing the prepend half needs the descriptor itself gone,
 *    and attempt 1 is what that costs.
 *
 * ONLY WHEN FD 0 IS NOT A TERMINAL, for two reasons rather than one. A
 * developer running the suite from a shell keeps their terminal, and a tty is
 * harmless anyway (`stream_isatty()` is the first thing `readStdinIfPiped()`
 * checks, and it returns null on it). It is also where descriptors 0, 1 and 2
 * most often ARE one description, and `O_NONBLOCK` set through fd 0 would
 * then reach this suite's stdout.
 *
 * WHAT IT DOES TO E243: nothing changes in the outcome.
 * {@see \SugarCraft\Crush\Cli\HeadlessPermissionPrompt::isInteractive()} is
 * `is_resource($this->in) && stream_isatty($this->in)`, so its `?? \STDIN`
 * default takes the no-tty refusal arm via the SECOND clause, and a
 * non-blocking `fgets()` cannot park. E243 stays NARROWED, not closed: a
 * developer running the suite from a real terminal skips this whole block,
 * `\STDIN` stays open, blocking and interactive, and the block E243 describes
 * is still live there.
 *
 * Pinned by `tests/SuiteChildStdinIsolationTest.php`, which spawns the real
 * binary from a runner whose own stdin is an open, never-written pipe — and
 * runs the un-bootstrapped control through the same harness first, because
 * "it did not hang" is also what a harness that never started the child says.
 * That spawn test is the order-INDEPENDENT guard: it runs the bootstrap in a
 * child of its own, so no in-process seam can reach it. The flag assertion
 * beside it is the order-DEPENDENT one, and is kept precisely because a future
 * `new Tty(null, …)` should turn it red.
 */
if (!stream_isatty(\STDIN)) {
    stream_set_blocking(\STDIN, false);
}
