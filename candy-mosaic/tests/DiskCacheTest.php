<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\DiskCache;

final class DiskCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/mosaic-diskcache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->dir)) {
            @unlink($this->dir);
        } elseif (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    /** Stamp the entry whose stored content equals $content with mtime $time. */
    private function stamp(string $content, int $time): void
    {
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            if (file_get_contents($file) === $content) {
                touch($file, $time);

                return;
            }
        }
        self::fail("No cache entry found with content '{$content}'");
    }

    public function testKeyIsDeterministic(): void
    {
        $a = DiskCache::key('https://x/p.png', 24, 36, 'sixel');
        $b = DiskCache::key('https://x/p.png', 24, 36, 'sixel');

        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $a);
    }

    public function testKeyVariesByProtocolAndSize(): void
    {
        $base = DiskCache::key('https://x/p.png', 24, 36, 'sixel');

        $this->assertNotSame($base, DiskCache::key('https://x/p.png', 24, 36, 'kitty'));
        $this->assertNotSame($base, DiskCache::key('https://x/p.png', 24, 48, 'sixel'));
        $this->assertNotSame($base, DiskCache::key('https://x/q.png', 24, 36, 'sixel'));
    }

    public function testKeyIsNamespacedByFormatVersion(): void
    {
        // The format version is baked into the key, so an entry hashed under the
        // current version must not collide with the same image under any other
        // version — that namespacing is what retires stale, wrongly-encoded bytes
        // after a renderer fix without a manual cache clear.
        $key = DiskCache::key('https://x/p.png', 24, 36, 'sixel');
        $legacy = sha1('https://x/p.png|24|36|sixel');
        $unversioned = sha1('https://x/p.png|24|36|sixel|v1');

        $this->assertNotSame($legacy, $key);
        $this->assertNotSame($unversioned, $key);
    }

    public function testPutThenGetRoundTrips(): void
    {
        $cache = new DiskCache($this->dir);
        $cache->put('poster', "\e[48;2;0;0;0m  \e[0m");

        $this->assertSame("\e[48;2;0;0;0m  \e[0m", $cache->get('poster'));
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $cache = new DiskCache($this->dir);

        $this->assertNull($cache->get('absent'));
    }

    public function testHasReflectsPresence(): void
    {
        $cache = new DiskCache($this->dir);

        $this->assertFalse($cache->has('k'));
        $cache->put('k', 'v');
        $this->assertTrue($cache->has('k'));
    }

    public function testDeleteRemovesEntry(): void
    {
        $cache = new DiskCache($this->dir);
        $cache->put('k', 'v');

        $cache->delete('k');

        $this->assertFalse($cache->has('k'));
        $cache->delete('k'); // no-op, must not error
    }

    public function testClearEmptiesCache(): void
    {
        $cache = new DiskCache($this->dir);
        $cache->put('a', '1');
        $cache->put('b', '2');

        $cache->clear();

        $this->assertSame(0, $cache->count());
    }

    public function testCountTracksEntries(): void
    {
        $cache = new DiskCache($this->dir);
        $this->assertSame(0, $cache->count());

        $cache->put('a', '1');
        $cache->put('b', '2');

        $this->assertSame(2, $cache->count());
    }

    public function testGetOrComputeStoresOnMissAndServesOnHit(): void
    {
        $cache = new DiskCache($this->dir);
        $calls = 0;
        $compute = function () use (&$calls): string {
            $calls++;

            return 'rendered';
        };

        $this->assertSame('rendered', $cache->getOrCompute('k', $compute));
        $this->assertSame('rendered', $cache->getOrCompute('k', $compute));
        $this->assertSame(1, $calls, 'compute must run only on the miss');
    }

    public function testPutCreatesDirectoryIfMissing(): void
    {
        $nested = $this->dir . '/nested/posters';
        $cache = new DiskCache($nested);

        $cache->put('k', 'v');

        $this->assertDirectoryExists($nested);
        $this->assertSame('v', $cache->get('k'));

        // tidy nested dirs the simple tearDown won't reach
        @unlink($nested . '/' . sha1('k') . '.cache');
        @rmdir($nested);
        @rmdir($this->dir . '/nested');
    }

    public function testConstructorRejectsNonPositiveCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxEntries must be >= 1');

        new DiskCache($this->dir, 0);
    }

    public function testLruEvictionRemovesOldestEntries(): void
    {
        // Populate with a generous cap so nothing is evicted during setup.
        $seed = new DiskCache($this->dir, 10);
        $seed->put('a', 'aaa');
        $seed->put('b', 'bbb');
        $seed->put('c', 'ccc');

        // Explicit mtimes: a oldest, c middle, b newest.
        $this->stamp('aaa', 100);
        $this->stamp('ccc', 200);
        $this->stamp('bbb', 300);

        // A capped instance over the same dir trims to 2 on the next write,
        // evicting the two oldest (a, c) and keeping b plus the new d.
        $cache = new DiskCache($this->dir, 2);
        $cache->put('d', 'ddd');

        $this->assertSame(2, $cache->count());
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('c'));
        $this->assertTrue($cache->has('b'));
        $this->assertTrue($cache->has('d'));
    }

    public function testGetTouchProtectsEntryFromEviction(): void
    {
        $cache = new DiskCache($this->dir, 2);
        $cache->put('a', 'aaa');
        $cache->put('b', 'bbb');

        // a is older than b.
        $this->stamp('aaa', 100);
        $this->stamp('bbb', 200);

        // Touch a via a read — it becomes most-recently-used (mtime now).
        $this->assertSame('aaa', $cache->get('a'));

        // Adding c trims to 2; b is now the oldest and is evicted, a survives.
        $cache->put('c', 'ccc');

        $this->assertTrue($cache->has('a'));
        $this->assertFalse($cache->has('b'));
        $this->assertTrue($cache->has('c'));
    }

    public function testPutThrowsWhenDirectoryCannotBeCreated(): void
    {
        // Occupy the cache path with a regular file so mkdir() can't create
        // the directory there — fails regardless of process privileges.
        file_put_contents($this->dir, 'i am a file, not a dir');
        $cache = new DiskCache($this->dir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create cache directory');

        $cache->put('k', 'v');
    }

    public function testKeyHelperRoundTripsThroughCache(): void
    {
        $cache = new DiskCache($this->dir);
        $key = DiskCache::key('https://cdn/poster.jpg', 24, 36, 'halfblock');

        $cache->put($key, 'ANSIBYTES');

        $this->assertSame('ANSIBYTES', $cache->get($key));
    }

    public function testArbitraryKeyCannotEscapeCacheDir(): void
    {
        $cache = new DiskCache($this->dir);
        $cache->put('../../escape', 'x');

        // Hashed filename keeps everything inside the cache directory.
        $entries = glob($this->dir . '/*.cache') ?: [];
        $this->assertCount(1, $entries);
        $this->assertStringStartsWith($this->dir . '/', $entries[0]);
        $this->assertSame('x', $cache->get('../../escape'));
    }

    public function testEmptyStringValueIsAHitNotAMiss(): void
    {
        $cache = new DiskCache($this->dir);
        $cache->put('k', '');

        // A zero-byte cached value must read back as "" (a hit), never as null,
        // and getOrCompute must not recompute it.
        $this->assertSame('', $cache->get('k'));

        $calls = 0;
        $value = $cache->getOrCompute('k', function () use (&$calls): string {
            $calls++;

            return 'recomputed';
        });

        $this->assertSame('', $value);
        $this->assertSame(0, $calls);
    }

    public function testEvictionHonoursCapEvenWhenMtimesCollide(): void
    {
        // Written in a single burst (same-second mtimes): the cap is still
        // enforced, even though the specific victims aren't strictly LRU.
        $cache = new DiskCache($this->dir, 3);
        foreach (['a', 'b', 'c', 'd', 'e'] as $k) {
            $cache->put($k, $k);
        }

        $this->assertSame(3, $cache->count());
    }

    public function testGetOrComputeDoesNotCacheOnComputeFailure(): void
    {
        $cache = new DiskCache($this->dir);

        try {
            $cache->getOrCompute('k', function (): string {
                throw new \RuntimeException('render blew up');
            });
            $this->fail('expected the compute exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('render blew up', $e->getMessage());
        }

        $this->assertFalse($cache->has('k'));
    }

    public function testPutSweepsStaleTempFiles(): void
    {
        $cache = new DiskCache($this->dir);
        $cache->put('seed', 'v'); // creates the directory

        // Simulate an orphan from a crashed write (old) and a temp from an
        // in-flight concurrent write (fresh).
        $stale = $this->dir . '/mc-stale';
        $fresh = $this->dir . '/mc-fresh';
        file_put_contents($stale, 'orphan');
        file_put_contents($fresh, 'inflight');
        touch($stale, time() - 3600);

        // Any write triggers the sweep.
        $cache->put('again', 'v2');

        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($fresh);
    }
}
