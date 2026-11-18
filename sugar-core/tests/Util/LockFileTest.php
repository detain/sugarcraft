<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Util\LockFile;
use SugarCraft\Core\Util\LockGuard;

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

    public function testCancellingTheAsyncWaitStopsItsPollTimer(): void
    {
        $loop = new StreamSelectLoop();
        $path = $this->dir . '/l';
        $holder = LockFile::on($path)->tryAcquire();
        self::assertNotNull($holder);

        // The caller gives up after 50ms, as a timeout() wrapper would. With the
        // deadline at 2s, a poll timer that survives the cancel keeps run() busy
        // for the full 2s, which is what this test measures.
        $promise = LockFile::on($path)->tryAcquireAsync($loop, 2.0);
        $value = 'unsettled';
        $promise->then(static function (mixed $v) use (&$value): void {
            $value = $v;
        });
        $loop->addTimer(0.05, static function () use ($promise): void {
            $promise->cancel();
        });

        $started = microtime(true);
        $loop->run();
        $elapsed = microtime(true) - $started;

        self::assertLessThan(1.0, $elapsed, 'a cancelled wait must not keep polling until its deadline');
        self::assertNull($value, 'a cancelled wait resolves null: no lock was taken');

        $holder->release();
        self::assertNotNull(LockFile::on($path)->tryAcquire(), 'the descriptor the cancel closed does not hold the lock');
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

    public function testSweepNeverUnlinksAFreshInodeAnotherWaiterTookSinceItInspectedTheStaleOne(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        $stale = LockFile::on($path)->tryAcquire();
        self::assertNotNull($stale);
        file_put_contents($path, '999999');

        // The second waiter's deadline has passed too: it sweeps the stale inode
        // after the first waiter has already unlinked it and locked a fresh one.
        $fresh = null;
        $probe = static function (int $pid) use ($path, &$fresh): bool {
            @unlink($path);
            $fresh = LockFile::on($path)->tryAcquire();

            return false;
        };

        $late = LockFile::on($path)->withAliveProbe($probe)->tryAcquire(0.0);
        self::assertNotNull($fresh, 'the first waiter locked the fresh inode');
        self::assertNull($late, 'the late sweep must not drop the fresh holder file');
        self::assertNull(LockFile::on($path)->tryAcquire(), 'the fresh holder still excludes everyone');

        $fresh->release();
        $stale->release();
    }

    public function testSweepRunsOnlyWhileItHoldsTheSidecarLock(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        $stale = LockFile::on($path)->tryAcquire();
        self::assertNotNull($stale);
        file_put_contents($path, '999999');

        // Another sweeper is mid-sweep: it holds the sidecar. The dead record is
        // provable, but the waiter must not unlink the inode while that sweeper runs.
        $sidecar = fopen($path . '.sweep', 'cb+');
        self::assertNotFalse($sidecar);
        self::assertTrue(flock($sidecar, LOCK_EX | LOCK_NB));
        clearstatcache();
        $inode = fileinode($path);

        $probe = static fn (int $pid): bool => false;
        $blocked = LockFile::on($path)->withAliveProbe($probe)->tryAcquire(0.0);
        clearstatcache();

        self::assertNull($blocked, 'a waiter that cannot take the sidecar gives up as contended');
        self::assertSame($inode, fileinode($path), 'the stale inode was unlinked without the sidecar');

        // Once the sidecar is free the same waiter may sweep the dead record.
        flock($sidecar, LOCK_UN);
        fclose($sidecar);
        $swept = LockFile::on($path)->withAliveProbe($probe)->tryAcquire(0.0);

        self::assertNotNull($swept, 'the sweep proceeds once the sidecar is free');
        $swept->release();
        $stale->release();
    }

    public function testAsyncSweepNeverUnlinksAFreshInodeAnotherWaiterTook(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        $stale = LockFile::on($path)->tryAcquire();
        self::assertNotNull($stale);
        file_put_contents($path, '999999');

        $fresh = null;
        $probe = static function (int $pid) use ($path, &$fresh): bool {
            @unlink($path);
            $fresh = LockFile::on($path)->tryAcquire();

            return false;
        };

        $loop = new StreamSelectLoop();
        $late = $this->settle(
            $loop,
            LockFile::on($path)->withAliveProbe($probe)->tryAcquireAsync($loop, 0.0),
        );
        self::assertNotNull($fresh, 'the first waiter locked the fresh inode');
        self::assertNull($late, 'the late async sweep must not drop the fresh holder file');
        self::assertNull(LockFile::on($path)->tryAcquire(), 'the fresh holder still excludes everyone');

        $fresh->release();
        $stale->release();
    }

    public function testForkedChildExitDoesNotReleaseTheParentsLock(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('needs ext-pcntl and ext-posix');
        }
        $path = $this->dir . '/l';
        $guard = LockFile::on($path)->tryAcquire();
        self::assertNotNull($guard);

        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            // A normal exit runs __destruct() on every object the fork inherited,
            // including the parent's guard. That run must leave the lock alone.
            exit(0);
        }
        \pcntl_waitpid($pid, $status);
        self::assertTrue(\pcntl_wifexited($status));

        $probe = fopen($path, 'c');
        self::assertIsResource($probe);
        // flock() is per open file description: a fresh fopen() is a second holder.
        self::assertFalse(flock($probe, LOCK_EX | LOCK_NB), 'a forked child must not drop the parent lock');
        fclose($probe);

        $guard->release();
        $again = LockFile::on($path)->tryAcquire();
        self::assertNotNull($again, 'the parent can still release and re-take the lock');
        $again->release();
    }

    /**
     * A dead exclusive holder left its pid note behind and a live shared reader
     * now holds the inode. The reader writes no pid, so the note still reads as
     * dead: an exclusive waiter that times out must not unlink the inode the
     * reader is using, or it would take exclusion beside that reader.
     */
    public function testExclusiveWaiterNeverSweepsOverALiveSharedReader(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        file_put_contents($path, '999999');
        $reader = LockFile::on($path)->shared()->tryAcquire();
        self::assertNotNull($reader);
        clearstatcache();
        $inode = fileinode($path);

        $probed = 0;
        $guard = LockFile::on($path)
            ->withAliveProbe(static function (int $pid) use (&$probed): bool {
                $probed++;

                return false;
            })
            ->tryAcquire(0.05);

        self::assertNull($guard, 'a live shared reader keeps exclusion, even over a dead pid note');
        self::assertSame(1, $probed, 'the dead pid was judged, and the sweep still refused');
        clearstatcache();
        self::assertSame($inode, fileinode($path), 'the reader\'s inode must not be unlinked');
        self::assertTrue($reader->held());
        self::assertNull(LockFile::on($path)->tryAcquire(), 'exclusion still holds beside the reader');

        $reader->release();
    }

    public function testAsyncExclusiveWaiterNeverSweepsOverALiveSharedReader(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        file_put_contents($path, '999999');
        $reader = LockFile::on($path)->shared()->tryAcquire();
        self::assertNotNull($reader);

        $loop = new StreamSelectLoop();
        $guard = $this->settle(
            $loop,
            LockFile::on($path)
                ->withAliveProbe(static fn (int $pid): bool => false)
                ->tryAcquireAsync($loop, 0.05),
        );

        self::assertNull($guard, 'the async sweep refuses a live shared reader too');
        self::assertTrue($reader->held());
        $reader->release();
    }

    /**
     * A new exclusive holder has taken the flock but not yet written its pid
     * note, so the file still names a dead holder. The pid note lands while the
     * contender is judging it. The contender must re-read it after the
     * confirmation poll, see the live note, and leave the file alone.
     */
    public function testSweepIsCancelledWhenAPidNoteLandsDuringTheConfirmationPoll(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        $blocker = LockFile::on($path)->tryAcquire();
        self::assertNotNull($blocker);
        file_put_contents($path, '999999');
        clearstatcache();
        $inode = fileinode($path);

        $probe = static function (int $pid) use ($path): bool {
            file_put_contents($path, (string) getmypid());

            return false;
        };

        $guard = LockFile::on($path)->withAliveProbe($probe)->tryAcquire(0.02);

        self::assertNull($guard, 'a pid note that changed during the confirmation poll must not be swept');
        clearstatcache();
        self::assertSame($inode, fileinode($path), 'the live holder\'s file must not be unlinked');
        self::assertNull(LockFile::on($path)->tryAcquire(), 'the blocker still excludes everyone');
        self::assertNotNull($blocker);
    }

    public function testAsyncSweepIsCancelledWhenAPidNoteLandsDuringTheConfirmationPoll(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        $blocker = LockFile::on($path)->tryAcquire();
        self::assertNotNull($blocker);
        file_put_contents($path, '999999');
        clearstatcache();
        $inode = fileinode($path);

        $probe = static function (int $pid) use ($path): bool {
            file_put_contents($path, (string) getmypid());

            return false;
        };

        $loop = new StreamSelectLoop();
        $guard = $this->settle(
            $loop,
            LockFile::on($path)->withAliveProbe($probe)->tryAcquireAsync($loop, 0.02),
        );

        self::assertNull($guard, 'the async confirmation re-reads the note too');
        clearstatcache();
        self::assertSame($inode, fileinode($path));
        self::assertNotNull($blocker);
    }

    /**
     * The superseded-inode race: the waiter polls inode A, a sweep by another
     * waiter unlinks A and locks a fresh inode B, then the dead holder's
     * inherited lock on A is released. The waiter's next flock() on A succeeds,
     * but A is no longer the path, so it must not return a guard on A.
     */
    public function testWaiterThatWinsASupersededInodeGivesItBackAndDoesNotHoldTheLock(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        $stale = LockFile::on($path)->tryAcquire();
        self::assertNotNull($stale);
        file_put_contents($path, '999999');

        $sweeper = null;
        $hooked = false;
        $probe = static function (int $pid) use ($path, &$stale, &$sweeper, &$hooked): bool {
            if ($hooked) {
                return false;
            }
            $hooked = true;
            // The other waiter's deadline has passed: it confirms the dead pid,
            // sweeps inode A and locks the fresh inode B.
            $sweeper = LockFile::on($path)->withAliveProbe(static fn (int $p): bool => false)->tryAcquire(0.0);
            // The dead holder's inherited descriptor closes, so A is free again.
            $stale->release();

            return false;
        };

        $waiter = LockFile::on($path)->withAliveProbe($probe)->tryAcquire(0.05);

        self::assertNotNull($sweeper, 'the other waiter swept A and locked B');
        self::assertNull($waiter, 'a lock won on the superseded inode A must be given back');
        self::assertTrue($sweeper->held());
        self::assertNull(LockFile::on($path)->tryAcquire(), 'the fresh holder on B still excludes a third contender');

        $sweeper->release();
        self::assertNotNull(LockFile::on($path)->tryAcquire(), 'the path is free once B is released');
    }

    /**
     * The async spelling of {@see testWaiterThatWinsASupersededInodeGivesItBackAndDoesNotHoldTheLock()}:
     * the poll timer must carry the reopened handle, not the superseded one.
     */
    public function testAsyncWaiterThatWinsASupersededInodeGivesItBackAndDoesNotHoldTheLock(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        $stale = LockFile::on($path)->tryAcquire();
        self::assertNotNull($stale);
        file_put_contents($path, '999999');

        $sweeper = null;
        $hooked = false;
        $probe = static function (int $pid) use ($path, &$stale, &$sweeper, &$hooked): bool {
            if ($hooked) {
                return false;
            }
            $hooked = true;
            $sweeper = LockFile::on($path)->withAliveProbe(static fn (int $p): bool => false)->tryAcquire(0.0);
            $stale->release();

            return false;
        };

        $loop = new StreamSelectLoop();
        $waiter = $this->settle(
            $loop,
            LockFile::on($path)->withAliveProbe($probe)->tryAcquireAsync($loop, 0.05),
        );

        self::assertNotNull($sweeper, 'the other waiter swept A and locked B');
        self::assertNull($waiter, 'the async waiter must not resolve a guard on the superseded inode A');
        self::assertTrue($sweeper->held());
        self::assertNull(LockFile::on($path)->tryAcquire(), 'the fresh holder on B still excludes a third contender');

        $sweeper->release();
    }

    /**
     * The deadline has already passed when the stale holder on A is released and
     * the path is swapped to a fresh, free inode B. The sync spelling retries the
     * flock on B before its deadline check and wins it; the async spelling must
     * do the same instead of resolving null on a free lock.
     */
    public function testAsyncWaiterPastItsDeadlineRetriesTheReopenedInodeAndWinsAFreeLock(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows will not unlink a file held open by this process');
        }
        $path = $this->dir . '/l';
        $stale = LockFile::on($path)->tryAcquire();
        self::assertNotNull($stale);
        file_put_contents($path, '999999');

        $hooked = false;
        $probe = static function (int $pid) use ($path, &$stale, &$hooked): bool {
            if ($hooked) {
                return false;
            }
            $hooked = true;
            // The dead holder's inherited lock on A is released, and the path is
            // swapped to a fresh, empty, unlocked inode B during the deadline check.
            $stale->release();
            unlink($path);
            file_put_contents($path, '');

            return false;
        };

        $loop = new StreamSelectLoop();
        $waiter = $this->settle(
            $loop,
            LockFile::on($path)->withAliveProbe($probe)->tryAcquireAsync($loop, 0.0),
        );

        self::assertInstanceOf(LockGuard::class, $waiter, 'a free lock on the reopened inode B must be won');
        self::assertTrue($waiter->held());
        self::assertNull(LockFile::on($path)->tryAcquire(), 'the guard on B excludes a second contender');

        $waiter->release();
        self::assertNotNull(LockFile::on($path)->tryAcquire(), 'the path is free once the guard on B is released');
    }

    /**
     * A deadline read from the wall clock would ignore a monotonic clock that
     * has moved past it, and wait the full 60 s budget instead. The first read
     * sets the deadline, every later read reports the clock already past it.
     */
    public function testSyncWaitDeadlineIsReadFromTheMonotonicClock(): void
    {
        $path = $this->dir . '/l';
        $holder = LockFile::on($path)->tryAcquire();
        self::assertNotNull($holder);

        $reads = 0;
        $clock = static function () use (&$reads): float {
            return ++$reads === 1 ? 100.0 : 200.0;
        };

        $t = microtime(true);
        self::assertNull(LockFile::on($path)->withClock($clock)->tryAcquire(60.0));
        self::assertLessThan(5.0, microtime(true) - $t, 'the 60 s budget must not be waited out on the wall clock');
        self::assertGreaterThanOrEqual(2, $reads, 'the deadline must be read from the injected clock');
    }

    public function testAsyncWaitDeadlineIsReadFromTheMonotonicClock(): void
    {
        $path = $this->dir . '/l';
        $holder = LockFile::on($path)->tryAcquire();
        self::assertNotNull($holder);

        $reads = 0;
        $clock = static function () use (&$reads): float {
            return ++$reads === 1 ? 100.0 : 200.0;
        };

        $loop = new StreamSelectLoop();
        $t = microtime(true);
        $resolved = 'pending';
        LockFile::on($path)->withClock($clock)->tryAcquireAsync($loop, 60.0)
            ->then(static function (mixed $v) use (&$resolved): void {
                $resolved = $v;
            });

        self::assertNull($resolved, 'the deadline passes on the injected clock, so the wait resolves null at once');
        self::assertLessThan(5.0, microtime(true) - $t, 'the 60 s budget must not be waited out on the wall clock');
    }
}
