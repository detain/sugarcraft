<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixMasterPty;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PtyPool;

/**
 * PTY-38: close-on-sweep for fully abandoned pool-owned POSIX pairs.
 *
 * The WeakReference sweep always freed the SLOT; these pins prove it
 * now also releases the master FD — while the ownership law holds:
 * a caller who keeps the master keeps the obligation, and a recycled
 * descriptor number is never reaped.
 */
final class PtyPoolOrphanCloseTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    /**
     * Sorted list of currently-open /dev/ptmx descriptors.
     *
     * @return list<int>
     */
    private static function openPtmxFds(): array
    {
        $found = [];
        foreach ((array) @\scandir('/proc/self/fd') as $entry) {
            if (!\is_string($entry) || !\ctype_digit($entry)) {
                continue;
            }
            if (@\readlink('/proc/self/fd/' . $entry) === '/dev/ptmx') {
                $found[] = (int) $entry;
            }
        }
        \sort($found);

        return $found;
    }

    public function testAbandonedPairsReleaseTheirMasterFds(): void
    {
        $this->requirePtySyscalls();
        $this->requireGlibc();

        $before = self::openPtmxFds();
        $pool = new PtyPool(new PosixPtySystem(), maxSize: 12);

        for ($i = 0; $i < 10; $i++) {
            $pair = $pool->acquire();
            // Deliberate: no release(), no close — drop on the floor.
            unset($pair);
        }

        // A bookkeeping call runs the sweep.
        $this->assertSame(0, $pool->inFlight(), 'collected pairs must not pin capacity');

        $after = self::openPtmxFds();
        $this->assertSame(
            $before,
            $after,
            'fully abandoned pool pairs leaked ' . \count(\array_diff($after, $before)) . ' master fds',
        );
    }

    public function testHeldMasterIsNotOrphanClosed(): void
    {
        $this->requirePtySyscalls();
        $this->requireGlibc();

        $pool = new PtyPool(new PosixPtySystem(), maxSize: 4);
        $pair = $pool->acquire();
        $master = $pair->master();
        $fd = $master->fd();
        unset($pair);

        $this->assertSame(0, $pool->inFlight());

        // The caller kept the master — the sweep must leave its fd alive.
        $this->assertFalse($master->isClosed());
        $this->assertSame('/dev/ptmx', @\readlink('/proc/self/fd/' . $fd), 'kept master must stay usable');
        $master->close();
    }

    public function testGuardNeverReapsARecycledDescriptor(): void
    {
        $this->requirePtySyscalls();
        $this->requireGlibc();

        $ffi = \FFI::cdef('int open(const char *path, int flags); int close(int fd);');
        $readlinkAt = static fn (int $fd): string => (string) @\readlink('/proc/self/fd/' . $fd);

        $pool = new PtyPool(new PosixPtySystem(), maxSize: 4);
        $pair = $pool->acquire();
        $fd = $pair->master()->fd();

        // Normal close WITHOUT pool release(): the slot stays, the
        // descriptor number becomes free, and the pair then dies.
        $pair->master()->close();
        unset($pair);

        // Claim the freed number with a raw open (glibc picks the
        // lowest free fd; PHP's stream layer would NOT reuse it).
        $claimed = $ffi->open('/dev/null', 2);
        try {
            if ($claimed !== $fd) {
                $this->fail("recycling did not reuse master fd {$fd} (raw open got {$claimed})");
            }

            $this->assertSame(0, $pool->inFlight(), 'sweep runs');
            $this->assertSame('/dev/null', $readlinkAt($fd), 'guard reaped a recycled descriptor!');
        } finally {
            $ffi->close($claimed);
        }
    }

    public function testPoolDestructSweepsOrphans(): void
    {
        $this->requirePtySyscalls();
        $this->requireGlibc();

        $before = self::openPtmxFds();

        $pool = new PtyPool(new PosixPtySystem(), maxSize: 4);
        $pair = $pool->acquire();
        unset($pair);
        $pool = null; // destruct alone must sweep and release the fd

        $this->assertSame($before, self::openPtmxFds(), 'pool destruct must not leave orphaned master fds');
    }

    public function testCloseRawFdIsTheGuardedOrphanDoor(): void
    {
        $this->requirePtySyscalls();
        $this->requireGlibc();

        $system = new PosixPtySystem();
        $pair = $system->open();
        $fd = $pair->master()->fd();
        // Open the master the sweep would orphan: bypass close().
        $this->assertSame(0, PosixMasterPty::closeRawFd($fd));
        $this->assertFalse(@\readlink('/proc/self/fd/' . $fd) === '/dev/ptmx');
    }

    private function requireGlibc(): void
    {
        if (!\is_dir('/proc/self/fd')) {
            $this->markTestSkipped('the orphan-close guard reads /proc/self/fd (Linux only)');
        }
    }
}
