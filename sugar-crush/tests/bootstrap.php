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
 * 2. TMPDIR covers the real `bin/sugarcrush` processes eighteen test files
 *    spawn, which get a genuine startup sweep of their own that no in-process
 *    latch can reach. It works on a CHILD and only on a child: `putenv()` does
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
