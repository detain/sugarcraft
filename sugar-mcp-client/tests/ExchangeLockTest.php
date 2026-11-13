<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\ExchangeLock;

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
        $this->lock = ExchangeLock::new('probe');

        self::assertFileExists($this->lock->path);
        self::assertSame([ExchangeLock::PHASE_CLEAN, ''], $this->lock->load());
    }

    public function testStoreRoundTripsPhaseAndBuffer(): void
    {
        $this->lock = ExchangeLock::new('probe');
        self::assertTrue($this->lock->acquire(null, static fn (): bool => true));

        $this->lock->store(ExchangeLock::PHASE_CLEAN, "{\"half\":");
        self::assertSame([ExchangeLock::PHASE_CLEAN, "{\"half\":"], $this->lock->load());

        $this->lock->store(ExchangeLock::PHASE_READING, '');
        self::assertSame([ExchangeLock::PHASE_READING, ''], $this->lock->load());
        $this->lock->release();
    }

    public function testMarkPhaseRewritesOnlyThePhaseByte(): void
    {
        $this->lock = ExchangeLock::new('probe');
        $this->lock->store(ExchangeLock::PHASE_CLEAN, 'kept-bytes');

        $this->lock->markPhase(ExchangeLock::PHASE_WRITING);

        self::assertSame([ExchangeLock::PHASE_WRITING, 'kept-bytes'], $this->lock->load());
    }

    public function testStateWritesReportSuccess(): void
    {
        $this->lock = ExchangeLock::new('probe');

        self::assertTrue($this->lock->store(ExchangeLock::PHASE_CLEAN, 'buffered'));
        self::assertTrue($this->lock->markPhase(ExchangeLock::PHASE_WRITING));
        self::assertSame([ExchangeLock::PHASE_WRITING, 'buffered'], $this->lock->load());
    }

    public function testStateWritesThatFailAreReportedNotAssumed(): void
    {
        $this->lock = ExchangeLock::new('probe');
        self::assertTrue($this->lock->store(ExchangeLock::PHASE_CLEAN, 'kept'));

        // A read-only handle: every write fails the way a full or read-only
        // temp filesystem fails it.
        $readOnly = fopen($this->lock->path, 'r');
        self::assertIsResource($readOnly);
        (new \ReflectionProperty(ExchangeLock::class, 'handle'))->setValue($this->lock, $readOnly);
        (new \ReflectionProperty(ExchangeLock::class, 'handlePid'))->setValue($this->lock, (int) getmypid());

        self::assertFalse($this->lock->store(ExchangeLock::PHASE_CLEAN, 'lost'));
        self::assertFalse($this->lock->markPhase(ExchangeLock::PHASE_WRITING));
    }

    public function testAnEmptyStateFileReadsAsDirtyNotClean(): void
    {
        // new() always writes a phase byte, so an empty file is a store that
        // failed after its truncate: the stream state is unknown, and only a
        // dirty reading makes the next holder resynchronise.
        $this->lock = ExchangeLock::new('probe');
        file_put_contents($this->lock->path, '');

        self::assertSame([ExchangeLock::PHASE_READING, ''], $this->lock->load());
    }

    public function testTheDeprecatedCreateNameStillBuildsAWorkingLock(): void
    {
        $this->lock = ExchangeLock::create('probe');

        self::assertStringStartsWith(ExchangeLock::FILE_PREFIX, basename($this->lock->path));
        self::assertSame([ExchangeLock::PHASE_CLEAN, ''], $this->lock->load());
    }

    public function testAcquireGivesUpWhenTheServerIsGone(): void
    {
        $this->lock = ExchangeLock::new('probe');
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
        $lock = ExchangeLock::new('probe');
        $path = $lock->path;

        $lock->destroy();

        self::assertFileDoesNotExist($path);
    }

    // =========================================================================
    // The boot-sweep law: a killed owner's file is swept by the next create()
    // =========================================================================

    public function testTheFileNameRecordsTheOwnerAndTheFileIsPrivate(): void
    {
        $this->lock = ExchangeLock::new('probe', $this->privateDir());

        [, $owner] = $this->nameParts($this->lock->path);
        self::assertSame((int) getmypid(), $owner);
        self::assertSame(0600, fileperms($this->lock->path) & 0777);
    }

    public function testCreateSweepsTheFileOfAnOwnerThatDiedWithoutDestroy(): void
    {
        $dir = $this->privateDir();
        $orphan = $this->fileOwnedBy($this->deadPid(), $dir);

        $this->lock = ExchangeLock::new('probe', $dir);

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
            self::assertFileExists($legacy, 'a legacy tempnam-style name says nothing about its owner and is left');
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
        $probe = ExchangeLock::new('ns-probe', $dir);
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

    /** A file shaped exactly as new() would have made it in $owner. */
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

    /**
     * Review M12 (fix wave): the lock file carries server output, so the
     * mode is SET AND VERIFIED before any payload byte lands — a chmod that
     * cannot stick refuses the boot loudly. Structural slice: re-spreading
     * the old `@chmod`-after-write-and-forget order reddens this.
     */
    public function testTheLockFileModeIsSetAndVerifiedBeforeThePayloadLands(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/ExchangeLock.php');
        $start = strpos($src, 'public static function new');
        self::assertIsInt($start);
        $end = strpos($src, 'readable by every user on the host', $start);
        self::assertIsInt($end, 'the loud refusal exists');
        $slice = substr($src, $start, $end - $start);

        $chmod = strpos($slice, '@chmod');
        $fwrite = strpos($slice, '@fwrite');
        self::assertIsInt($chmod, 'the factory sets the mode');
        self::assertIsInt($fwrite, 'the factory writes the phase payload');
        self::assertLessThan($fwrite, $chmod, 'the mode lands before the payload, never after');
        self::assertStringContainsString('modeOf(', $slice, 'the mode is verified, not assumed');
        self::assertStringContainsString('@unlink', $slice, 'a refused boot removes the half-made file');
        self::assertStringContainsString('could not be set to mode', $slice, 'the refusal is loud');
    }

    public function testOwnerRecreationOfADeletedLockSettlesAtMode0600(): void
    {
        // The reopen law: 'c+' created the replacement under the AMBIENT UMASK
        // — a lock deleted mid-session (a janitor sweeping /tmp) came back
        // world-readable, the exact state the class docblock names as
        // expected. The owner arm now settles the mode before trusting it.
        $this->dir = sys_get_temp_dir() . '/mclock-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $lock = ExchangeLock::new('probe', $this->dir);
        $this->lock = $lock;

        $previousUmask = umask(0o022);

        try {
            self::assertTrue($lock->store(ExchangeLock::PHASE_CLEAN, 'first'));
            $lock->close();
            unlink($lock->path); // the janitor got it while the owner served on

            self::assertTrue($lock->store(ExchangeLock::PHASE_CLEAN, 'second'));
            $mode = fileperms($lock->path) & 0o777;
            self::assertSame(0o600, $mode, sprintf('the recreated lock landed at %o', $mode));
            self::assertSame([ExchangeLock::PHASE_CLEAN, 'second'], $lock->load());
        } finally {
            umask($previousUmask);
        }
    }

    public function testLockDescriptorIsNotInheritedBySpawnedChildren(): void
    {
        // The CLOEXEC law: without 'e' on every mode, every MCP child spawned
        // after a lock opened later held a descriptor on another session's
        // exchange state. The probe walks its OWN descriptor table; the lock
        // path must not be in it.
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('/proc/self/fd is a Linux view of the descriptor table');
        }

        $this->dir = sys_get_temp_dir() . '/mclock-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $lock = ExchangeLock::new('probe', $this->dir);
        $this->lock = $lock;
        self::assertTrue($lock->store(ExchangeLock::PHASE_CLEAN, 'busy')); // handle open in OUR pid

        $walk = 'foreach (glob("/proc/self/fd/*") ?: [] as $fd) { $t = @readlink($fd); if ($t !== false) { echo $t, "\n"; } }';
        $child = proc_open(
            [PHP_BINARY, '-r', $walk],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($child);

        $seen = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($child);

        self::assertSame(0, $status, "fd walker failed: {$err}");
        self::assertStringNotContainsString(basename($lock->path), $seen, 'the lock descriptor rode into the child');
    }
}
