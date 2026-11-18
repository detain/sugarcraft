<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Util\LockFile;

final class LockFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sugar-core-lock-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    public function testAcquireRecordsPidAndReleaseClearsIt(): void
    {
        $path = $this->dir . '/l';
        $guard = LockFile::on($path)->tryAcquire();
        self::assertNotNull($guard);
        self::assertTrue($guard->held());
        self::assertTrue($guard->exclusive());
        self::assertSame($path, $guard->path());
        self::assertSame((string) getmypid(), file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);

        $guard->release();
        self::assertFalse($guard->held());
        self::assertSame('', file_get_contents($path));
        self::assertFileExists($path, 'the lock file is never deleted');
        $guard->release();
    }

    public function testSecondExclusiveAcquireTimesOutThenSucceedsAfterRelease(): void
    {
        $path = $this->dir . '/l';
        $a = LockFile::on($path)->tryAcquire();
        self::assertNotNull($a);

        $t = microtime(true);
        self::assertNull(LockFile::on($path)->tryAcquire(0.15));
        self::assertGreaterThanOrEqual(0.14, microtime(true) - $t, 'must wait out the timeout');

        $a->release();
        self::assertNotNull(LockFile::on($path)->tryAcquire());
    }

    public function testZeroTimeoutIsASingleAttempt(): void
    {
        $a = LockFile::on($this->dir . '/l')->tryAcquire();
        $t = microtime(true);
        self::assertNull(LockFile::on($this->dir . '/l')->tryAcquire(0.0));
        self::assertLessThan(0.1, microtime(true) - $t);
        self::assertNotNull($a);
    }

    public function testGuardDestructionReleases(): void
    {
        $path = $this->dir . '/l';
        $guard = LockFile::on($path)->tryAcquire();
        self::assertNull(LockFile::on($path)->tryAcquire());
        $guard = null;
        self::assertNotNull(LockFile::on($path)->tryAcquire());
    }

    public function testSharedLocksCoexistButExcludeExclusive(): void
    {
        $path = $this->dir . '/l';
        $r1 = LockFile::on($path)->shared()->tryAcquire();
        $r2 = LockFile::on($path)->shared()->tryAcquire();
        self::assertNotNull($r1);
        self::assertNotNull($r2);
        self::assertFalse($r1->exclusive());
        self::assertNull(LockFile::on($path)->tryAcquire());
        $r1->release();
        $r2->release();
        self::assertNotNull(LockFile::on($path)->tryAcquire());
    }

    public function testAcquireThrowsNamingWaitFlavourAndPath(): void
    {
        $path = $this->dir . '/l';
        $held = LockFile::on($path)->tryAcquire();
        try {
            LockFile::on($path)->acquire(0.05);
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('0.1s', $e->getMessage());
            self::assertStringContainsString('exclusive', $e->getMessage());
            self::assertStringContainsString($path, $e->getMessage());
        }
        self::assertNotNull($held);
    }

    public function testUnopenablePathIsNull(): void
    {
        self::assertNull(LockFile::on($this->dir . '/no/such/dir/l')->tryAcquire());
    }

    public function testDeadHolderInheritedLockIsSwept(): void
    {
        $path = $this->dir . '/l';
        // The "holder" is this same process holding the flock; its recorded pid
        // is rewritten to one the probe calls dead — the inherited-descriptor shape.
        $holder = LockFile::on($path)->tryAcquire();
        file_put_contents($path, '999999');

        $probed = [];
        $guard = LockFile::on($path)
            ->withAliveProbe(static function (int $pid) use (&$probed): bool {
                $probed[] = $pid;

                return false;
            })
            ->tryAcquire(0.05);

        self::assertNotNull($guard, 'a provably dead recorded pid must be swept');
        self::assertSame([999999], $probed);
        self::assertSame((string) getmypid(), file_get_contents($path));
        self::assertNotNull($holder);
    }

    public function testLiveHolderIsNeverSwept(): void
    {
        $path = $this->dir . '/l';
        $holder = LockFile::on($path)->tryAcquire();
        file_put_contents($path, '999999');

        $guard = LockFile::on($path)->withAliveProbe(static fn (int $pid): bool => true)->tryAcquire(0.05);

        self::assertNull($guard);
        self::assertNotNull($holder);
    }

    public function testOwnPidAndGarbageNeverSweep(): void
    {
        $path = $this->dir . '/l';
        $holder = LockFile::on($path)->tryAcquire();
        $dead = static fn (int $pid): bool => false;

        // Own pid recorded (the normal live-holder case): never swept.
        self::assertNull(LockFile::on($path)->withAliveProbe($dead)->tryAcquire(0.02));

        foreach (['', 'abc', '-5', '1'] as $junk) {
            file_put_contents($path, $junk);
            self::assertNull(LockFile::on($path)->withAliveProbe($dead)->tryAcquire(0.02), "recorded '{$junk}'");
        }
        self::assertNotNull($holder);
    }

    public function testSharedWaiterNeverSweeps(): void
    {
        $path = $this->dir . '/l';
        $holder = LockFile::on($path)->tryAcquire();
        file_put_contents($path, '999999');
        self::assertNull(LockFile::on($path)->shared()->withAliveProbe(static fn (int $p): bool => false)->tryAcquire(0.02));
        self::assertNotNull($holder);
    }

    public function testRealPosixProbeTreatsAnImpossiblePidAsDeadAndSelfAsAlive(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('ext-posix absent');
        }
        $path = $this->dir . '/l';
        $holder = LockFile::on($path)->tryAcquire();
        file_put_contents($path, '4194999');
        self::assertNotNull(LockFile::on($path)->tryAcquire(0.02), 'pid beyond pid_max is ESRCH: swept');
        self::assertNotNull($holder);
    }

    /**
     * Run the loop until $promise settles, bounded so a stuck wait fails by name.
     */
    private function settle(LoopInterface $loop, PromiseInterface $promise): mixed
    {
        $settled = false;
        $value = null;
        $promise->then(static function (mixed $v) use (&$settled, &$value, $loop): void {
            $settled = true;
            $value = $v;
            $loop->stop();
        });

        if (!$settled) {
            $bound = $loop->addTimer(3.0, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($bound);
        }

        self::assertTrue($settled, 'the promise must settle within the bound');

        return $value;
    }

    public function testAsyncAcquireResolvesTheGuardSynchronouslyWhenUncontended(): void
    {
        $loop = new StreamSelectLoop();
        $promise = LockFile::on($this->dir . '/l')->tryAcquireAsync($loop, 0.0);

        $resolved = null;
        $promise->then(static function (mixed $v) use (&$resolved): void {
            $resolved = $v;
        });

        self::assertNotNull($resolved, 'the first attempt is synchronous, so an open lock resolves at once');
    }

    public function testAsyncAcquireWaitsOutAHolderThenResolvesWhenItReleases(): void
    {
        $loop = new StreamSelectLoop();
        $path = $this->dir . '/l';
        $holder = LockFile::on($path)->tryAcquire();
        self::assertNotNull($holder);

        $loop->addTimer(0.05, static function () use ($holder): void {
            $holder->release();
        });

        $guard = $this->settle($loop, LockFile::on($path)->tryAcquireAsync($loop, 2.0));

        self::assertNotNull($guard, 'the waiter takes the lock once the holder lets go');
        self::assertTrue($guard->held());
    }

    public function testAsyncAcquireResolvesNullAtItsDeadlineWhileTheHolderLives(): void
    {
        $loop = new StreamSelectLoop();
        $path = $this->dir . '/l';
        $holder = LockFile::on($path)->tryAcquire();
        self::assertNotNull($holder);

        $guard = $this->settle($loop, LockFile::on($path)->tryAcquireAsync($loop, 0.05));

        self::assertNull($guard);
        self::assertNotNull($holder);
    }

    public function testAsyncAcquireResolvesNullForAnUnopenablePath(): void
    {
        $loop = new StreamSelectLoop();
        $guard = $this->settle(
            $loop,
            LockFile::on($this->dir . '/missing/dir/l')->tryAcquireAsync($loop, 0.0),
        );

        self::assertNull($guard);
    }

    public function testAsyncAcquireSweepsADeadInheritedHolderAtItsDeadline(): void
    {
        $loop = new StreamSelectLoop();
        $path = $this->dir . '/l';
        $holder = LockFile::on($path)->tryAcquire();
        self::assertNotNull($holder);
        file_put_contents($path, '999999');

        $guard = $this->settle(
            $loop,
            LockFile::on($path)
                ->withAliveProbe(static fn (int $pid): bool => false)
                ->tryAcquireAsync($loop, 0.05),
        );

        self::assertNotNull($guard, 'the async path applies the same stale-holder sweep');
        self::assertSame((string) getmypid(), file_get_contents($path));
    }

    /**
     * A dead holder is judged stale, the lock file is unlinked, and the retry
     * then cannot reopen a file (the path is now a directory). Both the sync and
     * the async sweep must resolve null exactly once, without closing their
     * handle a second time.
     *
     * The async leg needs its own contended file: the sync leg leaves a
     * directory at the path, and open() on a directory fails before any probe
     * runs, which would skip the async sweep entirely.
     */
    public function testLostSweepRaceResolvesNullWithoutDoubleClosing(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // The sweep needs to unlink a file this process still holds open, and
            // Windows refuses that without FILE_SHARE_DELETE, so the race cannot be staged.
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        $probes = 0;
        $probe = static function (int $pid) use ($path, &$probes): bool {
            $probes++;
            @unlink($path);
            @mkdir($path);

            return false;
        };

        $syncHolder = LockFile::on($path)->tryAcquire();
        self::assertNotNull($syncHolder);
        file_put_contents($path, '999999');

        $guard = LockFile::on($path)->withAliveProbe($probe)->tryAcquire(0.05);
        self::assertNull($guard);
        self::assertSame(1, $probes, 'the sync sweep reached the probe');
        self::assertDirectoryExists($path, 'the sweep unlinked the file and the retry lost the race');
        rmdir($path);

        $asyncHolder = LockFile::on($path)->tryAcquire();
        self::assertNotNull($asyncHolder);
        file_put_contents($path, '999999');

        $asyncLoop = new StreamSelectLoop();
        $asyncGuard = $this->settle(
            $asyncLoop,
            LockFile::on($path)->withAliveProbe($probe)->tryAcquireAsync($asyncLoop, 0.05),
        );
        self::assertNull($asyncGuard);
        self::assertSame(2, $probes, 'the async sweep reached the probe, not an early open() miss');
        self::assertDirectoryExists($path, 'the async sweep lost the race the same way');
        rmdir($path);

        self::assertNotNull($syncHolder);
        self::assertNotNull($asyncHolder);
    }
}
