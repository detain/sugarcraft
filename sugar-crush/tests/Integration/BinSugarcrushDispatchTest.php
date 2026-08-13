<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;

/**
 * crush_code.md Phase 0 item 3: every unrecognized or incomplete CLI flag
 * used to fall through `bin/sugarcrush`'s dispatch into `Program::run()`,
 * which attaches to the TTY, enters the alt-screen and blocks — so
 * `sugarcrush --version`, a bare `sugarcrush run`, a bare `sugarcrush -p` and
 * `sugarcrush -px "hello"` all hung and printed raw ANSI instead of failing
 * fast. Same bug class as the already-fixed "`--help` opens the TUI".
 *
 * The reason it shipped is that no test drove the real entry point at all
 * (see {@see BinSugarcrushWiringTest}'s docblock, which exercises Bootstrap
 * instead precisely because the bin script ends in a blocking
 * `Program::run()`). This class closes that gap in the only two ways that are
 * safe to do so:
 *
 *  - Argv vectors that MUST terminate before `Program`/the backend are run
 *    against the real binary in a child process ({@see self::runBin()}), with
 *    stdin on /dev/null, a hard deadline, SIGKILL and a detached watchdog. A
 *    regression that reopens the TUI fails loudly instead of hanging the
 *    suite.
 *  - Argv vectors whose correct outcome is "boot the TUI" or "call the
 *    backend" are asserted at the parse/dispatch layer instead — exec'ing
 *    those would be exactly the hang (or the live network call) this file
 *    exists to prevent.
 */
final class BinSugarcrushDispatchTest extends TestCase
{
    /** Usage error, per crush_code.md; distinct from NonInteractive's 1 = ran and failed. */
    private const EXIT_USAGE = 2;

    /** Every case exec'd here provably terminates immediately; this is the "it regressed" tripwire, not a normal wait. */
    private const TIMEOUT_SECONDS = 20;

    /**
     * How long the detached watchdog waits after the in-process SIGKILL
     * before firing its own — i.e. the grace given to `proc_terminate()` +
     * the `proc_close()` wait() to reap a child that ignored SIGKILL.
     */
    private const WATCHDOG_GRACE_SECONDS = 5;

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function unknownFlagInvocations(): array
    {
        return [
            // --version is NOT implemented in this step (that is crush_code.md
            // Phase 4 item 3); failing cleanly as an unknown flag is the
            // correct intermediate state, and this test is what will have to
            // be updated when it lands.
            '--version'            => [['--version']],
            'unrecognized long'    => [['--bogus-flag']],
            'unrecognized short'   => [['-z']],
            // -px is a single unknown token, not "-p" + "x" — the parser does
            // no short-flag clustering, so "hello" is left as a positional
            // that is not path-shaped and is discarded. Before the fix this
            // combination parsed to an empty ParsedArgs and opened the TUI.
            '-px with a value'     => [['-px', 'hello']],
            'unknown before -p'    => [['--bogus', '-p', 'hello']],
            // `--` protects what follows it, never what precedes it, so this
            // must still be a usage error rather than booting the TUI at
            // /tmp.
            'unknown before --'    => [['--bogus', '--', '/tmp']],
        ];
    }

    /**
     * @param list<string> $args
     *
     * @dataProvider unknownFlagInvocations
     */
    public function testUnrecognizedFlagsExitTwoWithoutOpeningTheTui(array $args): void
    {
        $result = $this->runBin($args);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('unrecognized option', $result['stderr']);
        $this->assertStringContainsString($args[0], $result['stderr'], 'the offending flag must be named');
        $this->assertStringNotContainsString("\x1b", $result['stdout'], 'a usage error must never emit terminal escapes');
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function promptlessOneShotInvocations(): array
    {
        return [
            'bare run'      => [['run']],
            'bare -p'       => [['-p']],
            'bare --prompt' => [['--prompt']],
            'empty --prompt=' => [['--prompt=']],
        ];
    }

    /**
     * A one-shot invocation with no prompt VALUE must reach
     * NonInteractive::run()'s existing "no prompt given" error (exit 1), not
     * the TUI. The message is deliberately not duplicated in the binary, so
     * asserting its text here also pins that single ownership.
     *
     * @param list<string> $args
     *
     * @dataProvider promptlessOneShotInvocations
     */
    public function testPromptlessOneShotInvocationsReachTheNoPromptError(array $args): void
    {
        $result = $this->runBin($args);

        $this->assertSame(1, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('no prompt given', $result['stderr']);
        $this->assertStringNotContainsString("\x1b", $result['stdout']);
    }

    /**
     * Positive control: --help still short-circuits ahead of the new
     * unknown-flag guard, so the guard cannot have swallowed it.
     */
    public function testHelpStillPrintsUsageAndExitsZero(): void
    {
        $result = $this->runBin(['--help']);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('Usage:', $result['stdout']);
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function validOneShotInvocations(): array
    {
        return [
            '-p with value'         => [['sugarcrush', '-p', 'hello']],
            '--prompt with value'   => [['sugarcrush', '--prompt', 'hello']],
            '--prompt= with value'  => [['sugarcrush', '--prompt=hello']],
            'run with value'        => [['sugarcrush', 'run', 'hello']],
        ];
    }

    /**
     * Positive control, asserted at the parse layer: a VALID one-shot
     * invocation still carries no unknown flags and still routes to
     * NonInteractive with its prompt intact. Not exec'd — a real
     * `-p "hello"` run would call a live provider.
     *
     * @param list<string> $argv
     *
     * @dataProvider validOneShotInvocations
     */
    public function testValidOneShotInvocationsAreUnaffected(array $argv): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertSame([], $args->unknownFlags);
        $this->assertFalse($args->help);
        $this->assertTrue($args->promptRequested);
        $this->assertSame('hello', $args->prompt);
    }

    /**
     * Positive control, asserted at the parse layer: a bare `sugarcrush` must
     * still fall past all three of the binary's guards (help / unknown flags
     * / one-shot) and open the TUI. Exec'ing this one IS the hang the rest of
     * the file guards against, so the three guard conditions are asserted
     * directly instead.
     */
    public function testBareInvocationStillFallsThroughToTheTui(): void
    {
        $args = ArgvParser::parse(['sugarcrush']);

        $this->assertFalse($args->help);
        $this->assertSame([], $args->unknownFlags);
        $this->assertFalse($args->promptRequested);
    }

    /**
     * Same for `sugarcrush /some/path` — a root-path positional is not a flag
     * and must not be mistaken for one.
     */
    public function testBareRootPathInvocationStillFallsThroughToTheTui(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '/tmp/some/repo']);

        $this->assertFalse($args->help);
        $this->assertSame([], $args->unknownFlags);
        $this->assertFalse($args->promptRequested);
        $this->assertSame('/tmp/some/repo', $args->root);
    }

    /**
     * `sugarcrush -- /tmp/some/repo` is the one shape that used to DO
     * something — set root and open the TUI — and briefly started exiting 2,
     * because `--` had no handling and landed in the unknown-flag recorder.
     * It has to fall through all three of the binary's guards again. Exec'ing
     * it is the hang this file exists to prevent, so the guard conditions are
     * asserted directly, as with the other TUI-bound controls above.
     */
    public function testEndOfOptionsSeparatorStillFallsThroughToTheTui(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '--', '/tmp/some/repo']);

        $this->assertFalse($args->help);
        $this->assertSame([], $args->unknownFlags);
        $this->assertFalse($args->promptRequested);
        $this->assertSame('/tmp/some/repo', $args->root);
    }

    /**
     * Run the real bin/sugarcrush in a child process.
     *
     * stdin is /dev/null (never a TTY, so nothing can block waiting on input)
     * and the child is killed at the deadline; a hang surfaces as an explicit
     * failure. Because this repo's documented gotcha is that a plain
     * `timeout` does not reliably kill a PTY/TUI hang, a detached SIGKILL
     * watchdog on the exact pid is armed as a second layer — but only on the
     * deadline path, see {@see self::armWatchdog()}.
     *
     * @param list<string> $args
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runBin(array $args): array
    {
        $root = \dirname(__DIR__, 2);
        $command = 'exec ' . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($root . '/bin/sugarcrush');
        foreach ($args as $arg) {
            $command .= ' ' . \escapeshellarg($arg);
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = \proc_open($command, $descriptors, $pipes, $root);
        $this->assertIsResource($process, 'failed to spawn bin/sugarcrush');

        // `exec` above makes $pid the php process itself, not an sh wrapper.
        $pid = (int) \proc_get_status($process)['pid'];

        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $deadline = \microtime(true) + self::TIMEOUT_SECONDS;

        while (true) {
            $stdout .= (string) \stream_get_contents($pipes[1]);
            $stderr .= (string) \stream_get_contents($pipes[2]);

            $status = \proc_get_status($process);
            if ($status['running'] === false) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if (\microtime(true) > $deadline) {
                // Armed HERE rather than at spawn time. The normal path exits
                // in milliseconds, so a watchdog armed unconditionally fired
                // `kill -9` at a pid that had been dead for ~25s on every
                // single exec'd case. This host's pid_max is 4194304 so reuse
                // is negligible, but in a container with the classic 32768 on
                // a busy runner that is an unconditional SIGKILL aimed at
                // whatever unrelated process recycled the number. Coverage is
                // not lost: this branch is the only one the watchdog exists
                // for, because `proc_close()` below wait()s, so a child that
                // survived `proc_terminate()` would wedge the suite right
                // here — and the arm happens BEFORE the terminate so it is
                // already ticking if that wait() never returns.
                $this->armWatchdog($pid);
                \proc_terminate($process, 9);
                break;
            }

            \usleep(20000);
        }

        $stdout .= (string) \stream_get_contents($pipes[1]);
        $stderr .= (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($process);

        if ($exitCode === null) {
            $this->fail(
                'bin/sugarcrush ' . \implode(' ', $args) . ' did not exit within '
                . self::TIMEOUT_SECONDS . 's — it almost certainly fell through to Program::run(). '
                . 'stdout: ' . \substr($stdout, 0, 200)
            );
        }

        return ['status' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Arm a detached, identity-checked SIGKILL for $pid, firing
     * self::WATCHDOG_GRACE_SECONDS from now.
     *
     * WHY the descriptor gymnastics rather than the obvious one-liner
     * `shell_exec('(sleep N; kill -9 PID) >/dev/null 2>&1 &')`: that redirect
     * only covers fds 0-2, and PHP marks only its own proc_open pipes
     * FD_CLOEXEC. Every other descriptor the process holds — the running
     * script, whatever the test runner dup'd — is inherited by the detached
     * subshell and pinned open for the whole sleep. Under `phpunit | tail`
     * one of those is a dup of PHPUnit's stdout pipe, so the reader never saw
     * EOF: a 1.6s filtered run took 27s and left 20 orphaned sleeps.
     *
     * Swapping shell_exec for proc_open does NOT fix it on its own — the
     * inherited set above 2 leaks identically (measured: fd 3, the running
     * script, survives both). Hardcoding `3>&- 4>&-` assumes a layout that is
     * not ours to assume, and a shell-side `for` loop over /dev/fd is worse
     * still: dash parks the fds displaced by a compound redirection on high
     * descriptors, so the loop closes the shell's own bookkeeping out from
     * under it. So the actually-inherited set is enumerated from
     * /proc/self/fd and closed by number, before anything else runs.
     * proc_open (not shell_exec) then avoids creating a popen pipe at all.
     */
    private function armWatchdog(int $pid): void
    {
        $closes = '';
        foreach (\glob('/proc/self/fd/*') ?: [] as $entry) {
            $fd = (int) \basename($entry);
            if ($fd > 2) {
                $closes .= ' ' . $fd . '>&-';
            }
        }
        // Closing a descriptor that turns out not to have been inherited (a
        // CLOEXEC one, or glob()'s own transient dirfd) is a silent no-op.
        $closes = $closes === '' ? '' : 'exec' . $closes . '; ';

        // Only ever SIGKILL a pid that is still the child we spawned. `kill
        // -0` alone loses the pid-reuse race, so an identity token captured
        // here — while the child is provably still running — is re-checked
        // inside the watchdog after the sleep. Without /proc there is nothing
        // to compare against, so degrade to the liveness check alone rather
        // than to a watchdog that can never fire.
        $token = $this->startTimeToken($pid);
        $guard = 'kill -0 ' . $pid . ' 2>/dev/null';
        if ($token !== null) {
            $guard .= ' && [ "$(awk \'{sub(/^.*\\) /, ""); print $20}\' /proc/'
                . $pid . '/stat 2>/dev/null)" = ' . \escapeshellarg($token) . ' ]';
        }

        $devNull = static fn(string $mode): array => ['file', '/dev/null', $mode];
        $watchdog = @\proc_open(
            $closes . '(sleep ' . self::WATCHDOG_GRACE_SECONDS . '; ' . $guard
                . ' && kill -9 ' . $pid . ') >/dev/null 2>&1 &',
            [0 => $devNull('r'), 1 => $devNull('w'), 2 => $devNull('w')],
            $ignored,
            \dirname(__DIR__, 2)
        );

        if (\is_resource($watchdog)) {
            // The shell backgrounds the sleeper and exits immediately, so this
            // wait() returns at once instead of blocking out the whole grace.
            \proc_close($watchdog);
        }
    }

    /**
     * Identity token for $pid that survives exec() and cannot be recycled:
     * field 22 of /proc/<pid>/stat, the process start time in clock ticks
     * since boot.
     *
     * WHY not the far more obvious /proc/<pid>/comm: `proc_open()` starts the
     * child as `/bin/sh -c '<command>'`, so comm reads "sh" until the `exec`
     * in the command lands and "php" forever after. A watchdog comparing comm
     * captured at one side of that transition against comm read at the other
     * silently never fires — measured, before this was start-time based.
     * Start time is fixed at fork and is exactly the field the kernel's own
     * pid-reuse disambiguation uses.
     *
     * @return string|null Null when /proc is unavailable or unparseable.
     */
    private function startTimeToken(int $pid): ?string
    {
        $stat = @\file_get_contents('/proc/' . $pid . '/stat');
        if (!\is_string($stat)) {
            return null;
        }

        // comm is the only field allowed to contain spaces or parens, so it is
        // skipped by cutting at the LAST ") " rather than by splitting. What
        // follows starts at field 3 (state), which puts starttime (22) at
        // offset 19.
        $tail = \strrpos($stat, ') ');
        if ($tail === false) {
            return null;
        }

        $fields = \preg_split('/\s+/', \substr($stat, $tail + 2), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return $fields[19] ?? null;
    }
}
