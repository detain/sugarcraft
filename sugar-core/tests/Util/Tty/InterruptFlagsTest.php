<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Tty\InterruptFlags;

/**
 * sc-02 (finding sugar-core-053): the singleton release contract.
 *
 * destroy() used to leave the cached instance in place with its shm handle
 * false'd out — every later self() handed back that zombie and consume()
 * answered false for the rest of the process, silently deaf to interrupts.
 * These pins hold the release: after destroy()/destroyShared(), self()
 * rebinds to a FRESH instance, and no cleanup call may throw when nothing
 * was ever created.
 */
final class InterruptFlagsTest extends TestCase
{
    protected function tearDown(): void
    {
        // Drop any instance these tests created so the next test (and the
        // suite's shutdown hooks) start from the no-cache state.
        InterruptFlags::destroyShared();
        parent::tearDown();
    }

    public function testDestroyReleasesTheCachedSingleton(): void
    {
        $first = InterruptFlags::self();
        $second = InterruptFlags::self();
        $this->assertSame($first, $second, 'self() caches');

        $first->destroy();

        $this->assertNotSame($first, InterruptFlags::self(), 'a destroyed singleton must not be handed back');
    }

    public function testDestroySharedDestroysTheCacheAndIsNoopWhenEmpty(): void
    {
        $held = InterruptFlags::self();

        InterruptFlags::destroyShared();
        $this->assertNotSame($held, InterruptFlags::self(), 'destroyShared() releases the cache');

        // No instance alive: the second call must not throw nor recreate.
        InterruptFlags::destroyShared();
        $this->assertInstanceOf(InterruptFlags::class, InterruptFlags::self());
    }

    public function testSetConsumeRoundTripOnALiveSegment(): void
    {
        if (!\extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop required for the round-trip pin');
        }

        $flags = InterruptFlags::self();

        $this->assertTrue($flags->set(), 'the segment is writable on this host');
        $this->assertTrue($flags->consume(), 'a set flag reads back once');
        $this->assertFalse($flags->consume(), 'and is cleared after the read');
    }
}
