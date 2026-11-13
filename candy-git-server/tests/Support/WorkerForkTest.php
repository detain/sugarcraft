<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Support\WorkerFork;

/**
 * Owner ruling 8b: the fork-per-request pool behind the HTTP listener.
 *
 * The pool's load-bearing contracts, each pinned here: the handler runs
 * in a separate process and its output reaches the client; capacity
 * refuses NEWCOMERS (established workers are never cut); terminateAll()
 * ends even a SIGTERM-ignoring worker inside the KILL ladder; and — the
 * one no in-process test can fake — a worker whose parent was SIGKILLed
 * exits on its own via the parent-death pipe (driver script below, the
 * daemon-e2e-child pattern).
 */
final class WorkerForkTest extends TestCase
{
    protected function setUp(): void
    {
        if (!WorkerFork::supported()) {
            $this->markTestSkipped('pcntl fork pool needs POSIX with pcntl_*');
        }
    }

    public function testWorkerRunsTheHandlerAndItsBytesReachTheClient(): void
    {
        $pool = new WorkerFork();
        [$near, $far] = $this->pair();

        $spawned = $pool->fork(static function ($client): bool {
            \fwrite($client, 'served-by-worker');

            return false; // request finished
        }, $far);
        \fclose($far);
        $this->assertTrue($spawned, 'a free pool must spawn');

        $got = '';
        \stream_set_blocking($near, false);
        $until = \microtime(true) + 5.0;
        while (\microtime(true) < $until && !\str_contains($got, 'served-by-worker')) {
            $chunk = \fread($near, 4096);
            if ($chunk === false || ($chunk === '' && \feof($near))) {
                break;
            }
            $got .= $chunk;
            \usleep(10_000);
        }
        \fclose($near);

        $this->assertSame('served-by-worker', $got);

        // And the worker must EXIT (not linger) once its handler is done.
        $until = \microtime(true) + 5.0;
        while (\microtime(true) < $until && $pool->live() > 0) {
            \usleep(10_000);
        }
        $this->assertSame(0, $pool->live(), 'a finished worker must reap out of the pool');
        $this->assertSame(1, $pool->totalSpawned());
    }

    public function testCapacityRefusesTheNewcomerNotAnEstablishedWorker(): void
    {
        $pool = new WorkerFork(maxWorkers: 1);
        [$near1, $far1] = $this->pair();
        [$near2, $far2] = $this->pair();

        $first = $pool->fork(static fn (): bool => true, $far1); // forever worker
        $this->assertTrue($first);
        $second = $pool->fork(static fn (): bool => true, $far2);
        $this->assertFalse($second, 'a full pool refuses the newcomer');
        $this->assertSame(1, $pool->live(), 'the established worker kept its seat');

        $pool->terminateAll();
        $this->assertSame(0, $pool->live(), 'terminateAll ends every worker, TERM ladder first');

        \fclose($far1);
        \fclose($far2);
        \fclose($near1);
        \fclose($near2);
    }

    public function testTerminateAllEndsEvenATermIgnoringWorker(): void
    {
        $pool = new WorkerFork();
        [$near, $far] = $this->pair();

        // A worker that re-arms SIGTERM to ignore: only the KILL rung can
        // end it, exactly the case the ladder exists for.
        $spawned = $pool->fork(static function ($client): bool {
            \pcntl_signal(\SIGTERM, \SIG_IGN);

            return true; // never finishes on its own
        }, $far);
        $this->assertTrue($spawned);

        $started = \microtime(true);
        $pool->terminateAll();
        $elapsed = \microtime(true) - $started;

        $this->assertSame(0, $pool->live(), 'the KILL rung must clear a TERM-immune worker');
        $this->assertLessThan(6.0, $elapsed, 'TERM grace + KILL wait stay bounded');

        \fclose($far);
        \fclose($near);
    }

    /**
     * Foreign-descriptor release: everything the parent passes in $foreign
     * is closed inside the worker at entry — a wedged worker must never
     * keep the LISTEN port or a neighbour's stream alive. Pinned with a
     * captured resource the handler inspects from inside the child.
     */
    public function testForeignDescriptorsAreClosedInsideTheWorker(): void
    {
        $pool = new WorkerFork();
        [$near, $far] = $this->pair();
        [$foreignNear, $foreignFar] = $this->pair();

        $spawned = $pool->fork(static function ($client) use ($foreignFar): bool {
            // The child reports what its own copy of the foreign stream
            // became: runWorker() closes $foreign BEFORE the first tick,
            // so is_resource must read false in the child.
            \fwrite($client, \is_resource($foreignFar) ? 'still-open' : 'foreign-closed');

            return false;
        }, $far, [$foreignFar]);
        $this->assertTrue($spawned);
        \fclose($far);
        $this->assertTrue(\is_resource($foreignFar), 'the parent copy is untouched');

        $got = '';
        \stream_set_blocking($near, false);
        $until = \microtime(true) + 5.0;
        while (\microtime(true) < $until && !\str_contains($got, 'closed')) {
            $chunk = \fread($near, 4096);
            if ($chunk === false || ($chunk === '' && \feof($near))) {
                break;
            }
            $got .= $chunk;
            \usleep(10_000);
        }

        $this->assertStringContainsString('foreign-closed', $got);
        \fclose($near);
        \fclose($foreignNear);
        @\fclose($foreignFar); // parent end: closed here, not by the child
    }

    /**
     * THE orphan gate (brief-mandated, run synchronously in this test's
     * turn): a pool host process is SIGKILLed while one of its workers
     * owns a connection; the worker must exit within the bounded teardown
     * window via the parent-death pipe — no orphans survive the pool host.
     *
     * SIGKILL is the ONLY realistic way to test this (a clean exit always
     * runs stop/terminateAll first), and it is also the worst case:
     * inherited SIG_IGN dispositions, un-runnable loops, no shutdown
     * handlers.
     */
    public function testKilledPoolHostLeavesNoOrphanWorkers(): void
    {
        $driver = __DIR__ . '/worker-fork-death-driver.php';
        $this->assertFileExists($driver);

        $proc = \proc_open(
            [\PHP_BINARY, $driver],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc);
        \stream_set_blocking($pipes[1], false);

        // Wait for "WORKER <pid>" on the driver's stdout (5 s boot budget).
        $workerPid = 0;
        $line = '';
        $until = \microtime(true) + 5.0;
        while (\microtime(true) < $until) {
            $chunk = \fread($pipes[1], 1024);
            if ($chunk === false) {
                break;
            }
            $line .= $chunk;
            if (\preg_match('/WORKER (\d+)/', $line, $m)) {
                $workerPid = (int) $m[1];

                break;
            }
            \usleep(20_000);
        }
        $this->assertGreaterThan(0, $workerPid, 'driver must report its worker pid: ' . $line);
        $this->assertFileExists('/proc/' . $workerPid, 'the worker is alive while its pool host runs');

        // SIGKILL the host: no handler runs, no terminateAll happens.
        \proc_terminate($proc, 9);
        $this->assertGreaterThanOrEqual(0, \proc_close($proc));

        // Bounded teardown window (census ceiling): the death-pipe EOF is
        // the worker's cue. Poll until /proc forgets it.
        $deadline = \microtime(true) + 8.0;
        $orphanSeen = true;
        while (\microtime(true) < $deadline) {
            if (!\file_exists('/proc/' . $workerPid)) {
                $orphanSeen = false;

                break;
            }
            // A zombie is already dead (exit_releases getResources first —
            // the death pipe closed at the instant of death); only a live
            // process counts as an orphan.
            $state = $this->processState($workerPid);
            if ($state === 'Z') {
                $orphanSeen = false;

                break;
            }
            \usleep(20_000);
        }
        $this->assertFalse($orphanSeen, 'worker ' . $workerPid . ' outlived its SIGKILLed pool host — the parent-death pipe is broken');
    }

    /** @return array{0: resource, 1: resource} */
    private function pair(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);

        return $pair;
    }

    private function processState(int $pid): ?string
    {
        $stat = @\file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            return null;
        }
        $close = \strrpos($stat, ')');

        return $close === false ? null : \trim(\substr($stat, $close + 1))[0] ?? null;
    }
}
