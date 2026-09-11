<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Crush\Agents\ProcessExecutor;

/**
 * E688 — `getExitCode()` must never fabricate 0 for an un-reaped child.
 *
 * THE DEFECT THIS PINS. `proc_get_status()` keeps reporting
 * `running => true` for a moment after the child's pipes have EOF — under CPU
 * pressure that moment is scheduled, not bounded — and the old body answered
 * that state with a literal `0`. Both tail callers in `ProcessExecutor` gate
 * on `!== 0`, so a worker that had actually crashed with code 5 was reported
 * as a clean "ended without complete message" (measured in the aa lane:
 * `testAWorkerThatDiesWhileHoldingALeaseIsReapedImmediately` expects 5, got
 * the generic branch). The fix is a bounded reap followed by an honest
 * `?int`: null means UNKNOWN and rides the generic branch; only a measured
 * non-zero attributes a code.
 *
 * WHY REFLECTION. `getExitCode()` is private and its contract is the whole
 * point — driving it through `execute()` would make the fixture a full
 * worker-protocol server whose pipe-EOF-while-alive shape cannot be forced on
 * schedule. Reflection-call the instrument, hand it the exact state the
 * defect lives in. `newInstanceWithoutConstructor()` is honest here: the
 * method body touches no instance state (verified: it reads only its
 * argument and the class constant budget).
 */
final class WorkerExitCodeAttributionTest extends TestCase
{
    /**
     * MUTATION PIN: a live child whose pipes are closed must answer null,
     * never 0. RED ON BASE (the old `return 0`), green on the fix. The child
     * outlives the bounded-reap budget, so this also proves the budget
     * EXPIRES HONESTLY rather than the test merely winning a race.
     */
    public function testAChildStillRunningAtPipeEofIsUnknownAndNeverZero(): void
    {
        $process = proc_open(
            [\PHP_BINARY, '-r', 'usleep(8000000);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'could not spawn the fixture child');
        $budget = (new ReflectionMethod(ProcessExecutor::class, 'getExitCode'))
            ->getDeclaringClass()
            ->getConstant('EXIT_REAP_BUDGET_SECONDS');

        try {
            foreach ($pipes as $pipe) {
                \fclose($pipe);
            }

            $exitCode = self::readExitCode($process);

            self::assertNull(
                $exitCode,
                'a child still running past the bounded reap budget answered '
                . var_export($exitCode, true) . ' — an exit code nobody measured; 0 here '
                . 'is the E688 laundering: callers read it as "exited cleanly"'
            );
            $status = proc_get_status($process);
            self::assertTrue(
                $status['running'],
                'the fixture child died inside the ' . $budget . 's budget — the pin did not '
                . 'actually exercise the un-reaped state it exists to pin'
            );
        } finally {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }

    /**
     * THE OTHER DIRECTION OF THE SAME MEASUREMENT: a child that exits inside
     * the budget reports its REAL code — the crash-5 case the fabrication
     * used to launder. RED ON BASE too (0 instead of 5).
     */
    public function testAChildThatDiesJustAfterPipeEofReportsItsRealCode(): void
    {
        $process = proc_open(
            [\PHP_BINARY, '-r', 'usleep(200000); exit(5);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'could not spawn the fixture child');

        try {
            foreach ($pipes as $pipe) {
                \fclose($pipe);
            }

            self::assertSame(5, self::readExitCode($process));
        } finally {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }

    /**
     * @param resource $process
     */
    private static function readExitCode($process): ?int
    {
        $executor = (new \ReflectionClass(ProcessExecutor::class))->newInstanceWithoutConstructor();
        $read = new ReflectionMethod(ProcessExecutor::class, 'getExitCode');
        $read->setAccessible(true);

        return $read->invoke($executor, $process);
    }
}
