<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Smoke-runs the shipped examples as real child processes (finding 045).
 *
 * WHY A CHILD AT ALL: examples/cli-page.php and examples/logo.php are the
 * only byte-verified consumers of the full presenter surface from outside
 * the test namespace — call-site drift (the SC-ST1 signature rework, for
 * one) stays green in the suite while the examples rot. Running them under
 * PHP_BINARY catches that.
 *
 * Child discipline mirrors this lib's own DefaultThemeTest probe: the
 * process is wrapped in `timeout -s KILL` so a wedged example dies inside
 * the budget instead of hanging the runner, stdout goes to /dev/null (a
 * closed pipe could SIGPIPE a chatty example; the bytes are irrelevant
 * here), stderr is drained to EOF BEFORE proc_close (an undrained stderr
 * pipe is a deadlock, and proc_close is the wait), and the exit code is
 * asserted — no orphan outlives either path.
 *
 * No TTY assumption: both examples resolve width through the probe's 80-cell
 * fallback on a piped STDOUT, so they run identically under CI and interactively.
 */
final class ExamplesTest extends TestCase
{
    /** Wall-clock budget per example; the examples finish in well under a second. */
    private const WALL_CLOCK_BUDGET_SECONDS = 20;

    public function testCliPageExampleRunsClean(): void
    {
        $this->runExample(__DIR__ . '/../examples/cli-page.php');
    }

    public function testLogoExampleRunsClean(): void
    {
        $this->runExample(__DIR__ . '/../examples/logo.php');
    }

    private function runExample(string $script): void
    {
        self::assertFileExists($script);

        $process = proc_open(
            [
                'timeout', '-s', 'KILL', (string) self::WALL_CLOCK_BUDGET_SECONDS,
                \PHP_BINARY, $script,
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            \dirname($script),
        );
        self::assertIsResource($process, 'could not start ' . $script);

        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(
            0,
            $exit,
            $script . ' exited ' . $exit
                . ($exit === 137 ? ' (SIGKILL by the wall-clock budget — the example wedged)' : '')
                . "\nstderr: " . $stderr,
        );
        self::assertSame('', $stderr, $script . ' must run without stderr output');
    }
}
