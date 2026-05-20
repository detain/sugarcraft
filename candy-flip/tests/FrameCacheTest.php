<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Cache\FrameCache;
use SugarCraft\Flip\Frame;
use PHPUnit\Framework\TestCase;

final class FrameCacheTest extends TestCase
{
    public function testGetReturnsNullForUncachedFrame(): void
    {
        $cache = new FrameCache();
        $f = new Frame([[[255, 0, 0]]]);
        $this->assertNull($cache->get($f));
    }

    public function testHasReturnsFalseForUncachedFrame(): void
    {
        $cache = new FrameCache();
        $f = new Frame([[[255, 0, 0]]]);
        $this->assertFalse($cache->has($f));
    }

    public function testSetAndGetRoundTrip(): void
    {
        $cache = new FrameCache();
        $f = new Frame([[[255, 0, 0], [0, 255, 0]]]);
        $rendered = "\033[48;2;255;0;0m \033[48;2;0;255;0m \033[0m";

        $cache->set($f, $rendered);

        $this->assertTrue($cache->has($f));
        $this->assertSame($rendered, $cache->get($f));
    }

    public function testDeleteRemovesCacheEntry(): void
    {
        $cache = new FrameCache();
        $f = new Frame([[[128, 128, 128]]]);
        $cache->set($f, 'cached-output');

        $cache->delete($f);

        $this->assertFalse($cache->has($f));
        $this->assertNull($cache->get($f));
    }

    public function testClearRemovesAllEntries(): void
    {
        $cache = new FrameCache();
        $f1 = new Frame([[[255, 0, 0]]]);
        $f2 = new Frame([[[0, 255, 0]]]);
        $cache->set($f1, 'output1');
        $cache->set($f2, 'output2');

        $cache->clear();

        $this->assertFalse($cache->has($f1));
        $this->assertFalse($cache->has($f2));
        $this->assertNull($cache->get($f1));
        $this->assertNull($cache->get($f2));
    }

    public function testSeparateCacheInstancesAreIndependent(): void
    {
        $cacheA = new FrameCache();
        $cacheB = new FrameCache();
        $f = new Frame([[[0, 0, 255]]]);
        $cacheA->set($f, 'from-a');

        $this->assertNull($cacheB->get($f));
        $this->assertSame('from-a', $cacheA->get($f));
    }

    public function testSameFrameContentDifferentObjectsAreDistinctEntries(): void
    {
        $cache = new FrameCache();
        $f1 = new Frame([[[255, 255, 255]]]);
        $f2 = new Frame([[[255, 255, 255]]]);

        $cache->set($f1, 'from-f1');

        // Different objects — different cache entries (WeakMap uses identity).
        $this->assertTrue($cache->has($f1));
        $this->assertFalse($cache->has($f2));
        $this->assertSame('from-f1', $cache->get($f1));
        $this->assertNull($cache->get($f2));
    }
}
