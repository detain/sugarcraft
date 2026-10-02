<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\ExchangeLock;

/**
 * The lock file's state contract (phase byte + shared read buffer) in one
 * process; the cross-process exclusion is pinned by StdioMcpServerForkSafetyTest.
 */
final class ExchangeLockTest extends TestCase
{
    private ?ExchangeLock $lock = null;

    protected function tearDown(): void
    {
        $this->lock?->destroy();
    }

    public function testANewLockIsCleanAndEmpty(): void
    {
        $this->lock = ExchangeLock::create('probe');

        self::assertFileExists($this->lock->path);
        self::assertSame([ExchangeLock::PHASE_CLEAN, ''], $this->lock->load());
    }

    public function testStoreRoundTripsPhaseAndBuffer(): void
    {
        $this->lock = ExchangeLock::create('probe');
        self::assertTrue($this->lock->acquire(null, static fn (): bool => true));

        $this->lock->store(ExchangeLock::PHASE_CLEAN, "{\"half\":");
        self::assertSame([ExchangeLock::PHASE_CLEAN, "{\"half\":"], $this->lock->load());

        $this->lock->store(ExchangeLock::PHASE_READING, '');
        self::assertSame([ExchangeLock::PHASE_READING, ''], $this->lock->load());
        $this->lock->release();
    }

    public function testMarkPhaseRewritesOnlyThePhaseByte(): void
    {
        $this->lock = ExchangeLock::create('probe');
        $this->lock->store(ExchangeLock::PHASE_CLEAN, 'kept-bytes');

        $this->lock->markPhase(ExchangeLock::PHASE_WRITING);

        self::assertSame([ExchangeLock::PHASE_WRITING, 'kept-bytes'], $this->lock->load());
    }

    public function testAcquireGivesUpWhenTheServerIsGone(): void
    {
        $this->lock = ExchangeLock::create('probe');
        $other = fopen($this->lock->path, 'r+');
        self::assertIsResource($other);
        // A second description in this process stands in for another process.
        self::assertTrue(flock($other, LOCK_EX));

        try {
            self::assertFalse($this->lock->acquire(null, static fn (): bool => false));
            self::assertFalse($this->lock->acquire(hrtime(true) / 1e9 + 0.05, static fn (): bool => true));
        } finally {
            fclose($other);
        }

        self::assertTrue($this->lock->acquire(null, static fn (): bool => true));
        $this->lock->release();
    }

    public function testDestroyRemovesTheFileInTheOwner(): void
    {
        $lock = ExchangeLock::create('probe');
        $path = $lock->path;

        $lock->destroy();

        self::assertFileDoesNotExist($path);
    }
}
