<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
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
}
