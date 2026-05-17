<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Exception\PoolExhaustedException;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\PtyPool;

/**
 * Structural + behavioural tests for {@see PtyPool}. The exhaustion /
 * validation paths run unconditionally with a fake PtySystem; the
 * real-PTY round-trip skips cleanly on FFI-less / sandboxed CI.
 */
final class PtyPoolTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testConstructorRejectsNonPositiveMaxSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PtyPool(maxSize: 0);
    }

    public function testConstructorRejectsNegativeMaxSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PtyPool(maxSize: -1);
    }

    public function testDefaultsToPosixPtySystemWhenSystemOmitted(): void
    {
        $pool = new PtyPool();
        $this->assertSame(PtyPool::DEFAULT_MAX_SIZE, $pool->maxSize);
        $this->assertSame(0, $pool->inFlight());
        $this->assertSame(PtyPool::DEFAULT_MAX_SIZE, $pool->available());
        $this->assertSame(0, $pool->totalAcquired());
    }

    public function testAcquireThrowsAtCapacityWithFakeSystem(): void
    {
        $system = $this->fakeSystem(opens: 2);
        $pool = new PtyPool(system: $system, maxSize: 2);

        $a = $pool->acquire();
        $b = $pool->acquire();

        $this->assertSame(2, $pool->inFlight());
        $this->assertSame(0, $pool->available());

        try {
            $pool->acquire();
            $this->fail('third acquire should have thrown');
        } catch (PoolExhaustedException $e) {
            $this->assertStringContainsString('PtyPool exhausted', $e->getMessage());
            $this->assertStringContainsString('2 sessions', $e->getMessage());
        }

        // After releasing one, the next acquire should succeed.
        $system->reload(1);
        $pool->release($a);
        $this->assertSame(1, $pool->inFlight());
        $this->assertSame(1, $pool->available());

        $c = $pool->acquire();
        $this->assertNotSame($a, $c);

        $pool->release($b);
        $pool->release($c);
    }

    public function testReleaseIsIdempotent(): void
    {
        $system = $this->fakeSystem(opens: 1);
        $pool = new PtyPool(system: $system, maxSize: 4);

        $pair = $pool->acquire();
        $pool->release($pair);
        $pool->release($pair); // second release is a no-op

        $this->assertSame(0, $pool->inFlight());
    }

    public function testReleaseOfUnknownPairIsNoOp(): void
    {
        $system = $this->fakeSystem(opens: 1);
        $pool = new PtyPool(system: $system, maxSize: 4);

        $stranger = $system->open();
        $pool->release($stranger);
        $this->assertSame(0, $pool->inFlight());
    }

    public function testTotalAcquiredMonotonicallyIncreases(): void
    {
        $system = $this->fakeSystem(opens: 5);
        $pool = new PtyPool(system: $system, maxSize: 3);

        for ($i = 0; $i < 5; $i++) {
            $p = $pool->acquire();
            $pool->release($p);
            $system->reload(1);
        }

        $this->assertSame(5, $pool->totalAcquired());
        $this->assertSame(0, $pool->inFlight());
    }

    public function testDrainClosesEveryInFlightPair(): void
    {
        $system = $this->fakeSystem(opens: 3);
        $pool = new PtyPool(system: $system, maxSize: 4);

        $a = $pool->acquire();
        $b = $pool->acquire();
        $c = $pool->acquire();

        $this->assertSame(3, $pool->inFlight());
        $pool->drain();
        $this->assertSame(0, $pool->inFlight());

        foreach ([$a, $b, $c] as $pair) {
            $this->assertTrue($pair->master()->isClosed(), 'drain must close every in-flight master');
        }
    }

    public function testRealPtyAcquireAndReleaseRoundTrip(): void
    {
        $this->requirePtySyscalls();

        $pool = new PtyPool(system: new PosixPtySystem(), maxSize: 4);
        $pair = $pool->acquire(80, 24);

        try {
            $this->assertInstanceOf(PtyPair::class, $pair);
            $this->assertSame(1, $pool->inFlight());
        } finally {
            $pool->release($pair);
        }

        $this->assertSame(0, $pool->inFlight());
        $this->assertTrue($pair->master()->isClosed(), 'release must close the underlying master');
    }

    public function testRealPtyExhaustionSurfacesAsPoolException(): void
    {
        $this->requirePtySyscalls();

        $pool = new PtyPool(system: new PosixPtySystem(), maxSize: 2);
        $a = $pool->acquire();
        $b = $pool->acquire();

        try {
            $this->expectException(PoolExhaustedException::class);
            $pool->acquire();
        } finally {
            $pool->release($a);
            $pool->release($b);
        }
    }

    /**
     * Stress check: 200 acquire/release cycles on a 4-slot pool.
     * Catches fd leaks because each iteration releases before the next
     * acquire — if `release()` failed to close, `acquire()` would hit
     * the ptmx limit by ~iteration 1000.
     */
    public function testRealPtyStressAcquireReleaseCycles(): void
    {
        $this->requirePtySyscalls();

        $pool = new PtyPool(system: new PosixPtySystem(), maxSize: 4);

        for ($i = 0; $i < 200; $i++) {
            $pair = $pool->acquire();
            $pool->release($pair);
        }

        $this->assertSame(0, $pool->inFlight());
        $this->assertSame(200, $pool->totalAcquired());
    }

    /**
     * Build a fake {@see PtySystem} that hands back lightweight stub
     * pairs without touching libc. Lets the validation / accounting
     * tests run on FFI-less hosts.
     */
    private function fakeSystem(int $opens): FakePtySystem
    {
        return new FakePtySystem(opens: $opens);
    }
}

/**
 * Minimal fake PtySystem for pool bookkeeping tests. Holds a fixed
 * budget of "opens" — each `open()` decrements it, throwing once
 * exhausted so a misbehaving pool can't silently allocate forever.
 * `reload()` lets a test extend the budget mid-run after a release.
 */
final class FakePtySystem implements PtySystem
{
    public function __construct(private int $opens) {}

    public function open(int $cols = 80, int $rows = 24): PtyPair
    {
        if ($this->opens <= 0) {
            throw new PtyException('FakePtySystem out of opens');
        }
        $this->opens--;
        return new FakePtyPair(new FakeMasterPty());
    }

    public function capabilities(): array
    {
        return ['pty' => true, 'termios' => true, 'signal' => true];
    }

    public function reload(int $additional): void
    {
        $this->opens += $additional;
    }
}

final class FakePtyPair implements PtyPair
{
    public function __construct(private readonly \SugarCraft\Pty\Contract\MasterPty $master) {}

    public function master(): \SugarCraft\Pty\Contract\MasterPty
    {
        return $this->master;
    }

    public function slave(): \SugarCraft\Pty\Contract\SlavePty
    {
        throw new \LogicException('FakePtyPair does not expose a slave; pool tests do not spawn');
    }
}

final class FakeMasterPty implements \SugarCraft\Pty\Contract\MasterPty
{
    private bool $closed = false;

    public function read(int $len = 8192, ?float $timeout = null): ?string { return null; }
    public function write(string $bytes): int { return \strlen($bytes); }
    public function resize(int $cols, int $rows): void {}
    public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }
    public function stream(): mixed { throw new \LogicException('FakeMasterPty has no real stream'); }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
