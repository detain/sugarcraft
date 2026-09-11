<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\TimedFileLock;

/**
 * E679 — the ONE bounded-lock implementation, pinned at its two edges.
 *
 * Uncontended acquire succeeds and actually holds (a second handle's
 * LOCK_NB must be refused); contended acquire THROWS within the bound with a
 * message naming wait, flavour and path — fail-loud per E137, because a
 * timeout that silently fails open lets the next save persist over a live
 * contender's state. Every wall bound here is <= 2 s so a regression that
 * dropped the deadline can only ever make this file slow-fail, never hang.
 */
final class TimedFileLockTest extends TestCase
{
    private string $path;
    private $held;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/crush-timedlock-' . \bin2hex(\random_bytes(6)) . '.lock';
        $this->held = \fopen($this->path, 'c');
        $this->assertIsResource($this->held);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->held)) {
            \flock($this->held, \LOCK_UN);
            \fclose($this->held);
        }
        @\unlink($this->path);
    }

    public function testAnUncontendedAcquireSucceedsAndActuallyHoldsTheLock(): void
    {
        TimedFileLock::acquire($this->held, \LOCK_EX, $this->path, 0.5);

        $contender = \fopen($this->path, 'c');
        $this->assertIsResource($contender);
        try {
            $this->assertFalse(@\flock($contender, \LOCK_EX | \LOCK_NB), 'the acquire was a no-op — nothing is held');

            TimedFileLock::release($this->held);
            $this->assertTrue(@\flock($contender, \LOCK_EX | \LOCK_NB), 'release did not let go');
        } finally {
            \fclose($contender);
        }
    }

    public function testAContendedExclusiveAcquireThrowsWithinTheBoundNamingWaitFlavourAndPath(): void
    {
        TimedFileLock::acquire($this->held, \LOCK_EX, $this->path, 0.5);

        $contender = \fopen($this->path, 'c');
        $start = \microtime(true);
        try {
            TimedFileLock::acquire($contender, \LOCK_EX, $this->path, 0.2);
            $this->fail('a contended lock failed open instead of throwing');
        } catch (\RuntimeException $failure) {
            $elapsed = \microtime(true) - $start;
            $this->assertStringContainsString('waiting for the exclusive lock', $failure->getMessage());
            $this->assertStringContainsString($this->path, $failure->getMessage());
            $this->assertStringContainsString('another process holds it', $failure->getMessage());
            $this->assertGreaterThanOrEqual(0.2, $elapsed, 'gave up before the budget');
            $this->assertLessThan(2.0, $elapsed, 'the bound does not bound — this test can now hang');
        } finally {
            \fclose($contender);
        }
    }

    public function testASharedAcquireBlockedByAnExclusiveHolderNamesTheSharedFlavour(): void
    {
        TimedFileLock::acquire($this->held, \LOCK_EX, $this->path, 0.5);

        $contender = \fopen($this->path, 'c');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('waiting for the shared lock');
            TimedFileLock::acquire($contender, \LOCK_SH, $this->path, 0.2);
        } finally {
            \fclose($contender);
        }
    }

    public function testTheDefaultWaitIsTheDocumentedFiveSeconds(): void
    {
        $this->assertSame(5.0, TimedFileLock::DEFAULT_WAIT_SECONDS, 'the E137 default drifted from the SQLite busyTimeout it mirrors');
    }
}
