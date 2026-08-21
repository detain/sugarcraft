<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;

/**
 * The bounded collection of a SIGKILLed tool child.
 *
 * {@see Chat::waitForToolChildrenAsync()} used to finish its timeout branch
 * with a bare `pcntl_waitpid($pid, $status)`. The kill above it is guarded by
 * `function_exists('posix_kill')` — ext-posix and ext-pcntl are separately
 * compilable — so in a build with only pcntl NOTHING KILLED THE CHILD and that
 * wait is unbounded on a tool which had already refused to finish.
 *
 * {@see \SugarCraft\Crush\Runtime::reapKilled()} carries the same fix, and this
 * site is worse than that one by one degree: `Runtime::executeConcurrently()`
 * runs inside the forked completion child, on nobody's event loop, while this
 * loop body is an `addPeriodicTimer()` callback in the TUI PROCESS. Blocking
 * here does not stall a turn — it stalls the render and the keyboard, including
 * the Escape-Escape that reaches this same routine through its cancellation
 * token.
 *
 * Both cases run in a CHILD PHP PROCESS under an external clock: the failure is
 * a HANG, and an in-process assertion after a hang is never reached.
 */
final class ToolChildReapTest extends TestCase
{
    /**
     * A child that is STILL RUNNING — the ext-posix-less shape — is given up on
     * rather than waited for.
     *
     * This is the whole regression. Nothing signals the worker here, so a
     * `pcntl_waitpid()` with no `WNOHANG` never returns.
     */
    public function testALiveUnkilledChildIsGivenUpOnRatherThanWaitedFor(): void
    {
        $report = $this->reapBounded(killFirst: false);

        $this->assertFalse($report['reaped'], 'a running child cannot have been reaped');
        $this->assertLessThan(
            2.0,
            $report['elapsed'],
            'the reap blocked on a child nothing had killed',
        );
    }

    /**
     * A child that is already gone is collected immediately — the ordinary
     * case, and the one a budget could easily make expensive.
     */
    public function testAKilledChildIsCollectedWithoutSpendingTheBudget(): void
    {
        $report = $this->reapBounded(killFirst: true);

        $this->assertTrue($report['reaped'], 'the killed child was left a zombie');
        $this->assertLessThan(
            0.5,
            $report['elapsed'],
            'collecting an already-dead child waited on the budget',
        );
    }

    /**
     * Fork a child, optionally SIGKILL it, then reflect into
     * `Chat::reapKilledToolChild()` and report how long it took and whether the
     * child was collected.
     *
     * @return array{elapsed: float, reaped: bool}
     */
    private function reapBounded(bool $killFirst): array
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to fork a tool child');
        }

        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $kill = $killFirst ? 'true' : 'false';

        $script = <<<PHP
            <?php
            declare(strict_types=1);
            require {$this->export($autoload)};

            \$pid = pcntl_fork();
            if (\$pid === 0) {
                while (true) {
                    usleep(50000);
                }
            }

            if ({$kill}) {
                posix_kill(\$pid, 9);
                // Give the kernel a moment to deliver it, so "already dead" is
                // what is being measured rather than a race with the signal.
                usleep(100000);
            }

            \$method = new ReflectionMethod(SugarCraft\Crush\Chat::class, 'reapKilledToolChild');
            \$started = microtime(true);
            \$method->invoke(null, \$pid);
            \$elapsed = microtime(true) - \$started;

            \$status = 0;
            \$reaped = pcntl_waitpid(\$pid, \$status, WNOHANG) !== 0;
            if (!\$reaped) {
                posix_kill(\$pid, 9);
                pcntl_waitpid(\$pid, \$status);
            }

            fwrite(STDOUT, json_encode(['elapsed' => \$elapsed, 'reaped' => \$reaped]));
            PHP;

        $file = tempnam(sys_get_temp_dir(), 'tool_reap_');
        self::assertIsString($file);
        file_put_contents($file, $script);

        try {
            $decoded = json_decode($this->runBounded([PHP_BINARY, $file], 15.0), true);
        } finally {
            @unlink($file);
        }

        self::assertIsArray($decoded, 'the bounded child did not report a reap outcome');
        self::assertIsFloat($decoded['elapsed'] ?? null);
        self::assertIsBool($decoded['reaped'] ?? null);

        return $decoded;
    }

    /**
     * @param list<string> $argv
     */
    private function runBounded(array $argv, float $seconds): string
    {
        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $seconds;
        $out = '';
        $err = '';

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);

            if (proc_get_status($process)['running'] === false) {
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                self::fail("the reap did not finish within {$seconds}s — it wedged");
            }

            usleep(10_000);
        }

        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame('', trim($err), 'the bounded child wrote to stderr');

        return $out;
    }

    /** Chat's budget is a ceiling, not a schedule. */
    public function testTheReapBudgetIsPositiveAndShortEnoughToBeInvisible(): void
    {
        $budget = (new \ReflectionClass(Chat::class))->getConstant('REAP_BUDGET_SECONDS');

        $this->assertIsFloat($budget);
        $this->assertGreaterThan(0.0, $budget);
        $this->assertLessThanOrEqual(
            0.25,
            $budget,
            'a reap budget longer than a frame is a visible stall in the render loop',
        );
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }
}
