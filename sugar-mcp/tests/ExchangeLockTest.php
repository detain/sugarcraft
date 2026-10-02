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

    private ?string $dir = null;

    protected function tearDown(): void
    {
        $this->lock?->destroy();

        if ($this->dir !== null) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
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

    // =========================================================================
    // Audit R14: a killed owner's file is swept by the next create()
    // =========================================================================

    public function testTheFileNameRecordsTheOwnerAndTheFileIsPrivate(): void
    {
        $this->lock = ExchangeLock::create('probe', $this->privateDir());

        [, $owner] = $this->nameParts($this->lock->path);
        self::assertSame((int) getmypid(), $owner);
        self::assertSame(0600, fileperms($this->lock->path) & 0777);
    }

    public function testCreateSweepsTheFileOfAnOwnerThatDiedWithoutDestroy(): void
    {
        $dir = $this->privateDir();
        $orphan = $this->fileOwnedBy($this->deadPid(), $dir);

        $this->lock = ExchangeLock::create('probe', $dir);

        self::assertFileDoesNotExist($orphan, 'the dead owner\'s lock file is reclaimed at the next connection start');
        self::assertFileExists($this->lock->path);
    }

    public function testSweepKeepsEveryFileItCannotProveIsAbandoned(): void
    {
        $dir = $this->privateDir();
        $namespace = $this->namespace($dir);

        $live = proc_open(['sleep', '30'], [], $pipes);
        self::assertIsResource($live);
        $livePid = (int) proc_get_status($live)['pid'];

        try {
            $ownedByLive = $this->fileOwnedBy($livePid, $dir);
            $ownedBySelf = $this->fileOwnedBy((int) getmypid(), $dir);
            $legacy = $dir . '/' . ExchangeLock::FILE_PREFIX . 'Ab12Cd';
            file_put_contents($legacy, ExchangeLock::PHASE_CLEAN);
            $otherNamespace = $dir . '/' . ExchangeLock::FILE_PREFIX . ($namespace === '1' ? '2' : '1') . '-' . $this->deadPid() . '-00ff';
            file_put_contents($otherNamespace, ExchangeLock::PHASE_CLEAN);

            // A dead owner's file that a surviving fork is mid-exchange on.
            $held = $this->fileOwnedBy($this->deadPid(), $dir);
            $holder = fopen($held, 'r+');
            self::assertIsResource($holder);
            self::assertTrue(flock($holder, LOCK_EX));

            try {
                self::assertSame(0, ExchangeLock::sweepStale($dir));
            } finally {
                fclose($holder);
            }

            self::assertFileExists($ownedByLive, 'a live owner keeps its lock');
            self::assertFileExists($ownedBySelf, 'the sweeping process never removes its own');
            self::assertFileExists($legacy, 'a pre-R14 name says nothing about its owner and is left');
            self::assertFileExists($otherNamespace, 'a pid from another pid namespace names some other process');
            self::assertFileExists($held, 'a held flock means somebody still uses it');

            self::assertSame(1, ExchangeLock::sweepStale($dir), 'released, the held file is reclaimed on the next sweep');
            self::assertFileDoesNotExist($held);
        } finally {
            proc_terminate($live, 9);
            proc_close($live);
        }
    }

    private function privateDir(): string
    {
        $this->dir = sys_get_temp_dir() . '/exchange-lock-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);

        return $this->dir;
    }

    /** This process' pid-namespace tag, read off the name create() gives a file. */
    private function namespace(string $dir): string
    {
        $probe = ExchangeLock::create('ns-probe', $dir);
        [$namespace] = $this->nameParts($probe->path);
        $probe->destroy();

        return $namespace;
    }

    /** @return array{0: string, 1: int} [pid namespace, owner pid] */
    private function nameParts(string $path): array
    {
        self::assertMatchesRegularExpression('/^' . ExchangeLock::FILE_PREFIX . '(\d+)-(\d+)-[0-9a-f]+$/', basename($path));
        preg_match('/^' . ExchangeLock::FILE_PREFIX . '(\d+)-(\d+)-/', basename($path), $m);

        return [$m[1], (int) $m[2]];
    }

    /** A file shaped exactly as create() would have made it in $owner. */
    private function fileOwnedBy(int $owner, string $dir): string
    {
        $path = $dir . '/' . ExchangeLock::FILE_PREFIX . $this->namespace($dir) . '-' . $owner . '-' . bin2hex(random_bytes(6));
        file_put_contents($path, ExchangeLock::PHASE_READING . 'stranded-bytes');

        return $path;
    }

    /** The pid of a process that has exited and been reaped. */
    private function deadPid(): int
    {
        $proc = proc_open(['true'], [], $pipes);
        self::assertIsResource($proc);
        $pid = (int) proc_get_status($proc)['pid'];
        proc_close($proc);

        return $pid;
    }
}
