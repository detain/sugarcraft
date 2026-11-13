<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\DiskCache;
use SugarCraft\Image\ImageSource;

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
        DiskCache::overrideClock(null); // the touch clock is process-static; never leak the seam
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

    /** The on-disk path a key hashes to (the sweep's glob shape). */
    private function entryFor(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.cache';
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
        $this->assertSame(0o700, fileperms($nested) & 0o777, 'a created cache directory is owner-only');
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

    // --- SI-19 (findings 013+014): byte budget, single-entry ceiling, coarse
    // touch, debounced sweep, deterministic same-second victims ---

    public function testConstructorRejectsNonPositiveByteBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxBytes must be >= 1, got 0');

        new DiskCache($this->dir, 512, 0);
    }

    public function testByteBudgetDefaultsToTheLibsOwnMaxBytesLaw(): void
    {
        // The store must never hold (or slurp) a payload the pipeline itself
        // refuses to fetch, so the default budget is not an independent
        // number — it IS ImageSource::MAX_BYTES, and a constructor left at
        // defaults carries it into the instance.
        $this->assertSame(ImageSource::MAX_BYTES, DiskCache::DEFAULT_MAX_BYTES);

        $property = new \ReflectionProperty(DiskCache::class, 'maxBytes');
        $this->assertSame(ImageSource::MAX_BYTES, $property->getValue(new DiskCache($this->dir)));
    }

    public function testGetRefusesEntryOverTheByteCeilingWithoutTouchingIt(): void
    {
        // Seeded through a wide door, read through a narrow one — the shape a
        // re-tuned cache takes. An over-ceiling entry is a MISS, never a
        // slurp (finding 013)...
        $seed = new DiskCache($this->dir);
        $seed->put('big', str_repeat('x', 64));
        $file = $this->entryFor('big');
        touch($file, 1000);

        $narrow = new DiskCache($this->dir, 512, 32);

        $this->assertTrue($narrow->has('big'), 'presence is presence — the ceiling is a read law');
        $this->assertNull($narrow->get('big'), 'a single entry above maxBytes must not be slurped');
        clearstatcache();
        $this->assertSame(1000, filemtime($file), 'the refused entry is not touched, so it carries the oldest mtime in the house');
    }

    public function testOverCeilingEntryAgesOutUnderTheByteSweepSelfHealing(): void
    {
        // The second half of the 013 story: a refused read is self-healing,
        // not a permanent orphan. It counts toward the directory bytes, so
        // the next sweep wakes on it and the untouched (oldest) entry pays.
        $seed = new DiskCache($this->dir);
        $seed->put('big', str_repeat('x', 64));
        touch($this->entryFor('big'), 1000);

        $narrow = new DiskCache($this->dir, 512, 32);
        $narrow->put('small', str_repeat('s', 10)); // 74 bytes > 125 % of 32 — wakes the sweep

        $this->assertFalse($narrow->has('big'), 'the over-ceiling orphan aged out first');
        $this->assertSame(str_repeat('s', 10), $narrow->get('small'));
    }

    public function testByteSweepWakesOnlyPastTheDebounceBand(): void
    {
        // Finding 014's churn law, pinned at the strict boundary: four 30-byte
        // entries put the directory at 120 of a 100-byte budget — over the
        // budget but inside the 125 % band, so writes there must cost ZERO
        // eviction. Only crossing 125 % wakes the sweep, and it trims back to
        // budget (not merely under the band).
        $seed = new DiskCache($this->dir, 1000);
        foreach (['a', 'b', 'c', 'd'] as $i => $k) {
            $seed->put($k, str_repeat('x', 30));
            touch($this->entryFor($k), 100 + $i * 100);
        }

        $cache = new DiskCache($this->dir, 1000, 100);
        for ($i = 1; $i <= 5; $i++) {
            $cache->put("w{$i}", 'w'); // totals 121..125 — 125 is NOT > 125
        }
        $this->assertSame(9, $cache->count(), 'at exactly 125 % of budget nothing is swept');

        $cache->put('w6', 'w'); // 126 — the band is crossed

        $this->assertFalse($cache->has('a'), 'the oldest entry is the sweep victim');
        foreach (['b', 'c', 'd'] as $k) {
            $this->assertTrue($cache->has($k));
        }
        $bytes = 0;
        foreach (glob($this->dir . '/*.cache') ?: [] as $f) {
            $bytes += (int) filesize($f);
        }
        $this->assertLessThanOrEqual(100, $bytes, 'the sweep trims back to the budget itself');
    }

    public function testGetOnFreshEntrySkipsTheLruTouch(): void
    {
        // Write-amplification law (finding 014): a redraw loop hitting the
        // same poster every frame used to pay one touch() syscall per hit for
        // an mtime change that 1-second filesystem resolution cannot even
        // observe between frames. A hit on an entry inside the skip window
        // must cost zero writes.
        $cache = new DiskCache($this->dir);
        $cache->put('k', 'v');
        $file = $this->entryFor('k');
        clearstatcache();
        $before = filemtime($file);

        $this->assertSame('v', $cache->get('k'));

        clearstatcache();
        $this->assertSame($before, filemtime($file), 'a fresh hit is a zero-write read');
    }

    public function testGetOnAgedEntryTouchesThroughTheClockSeam(): void
    {
        // The other side of the coarse touch, pinned at the exact interval
        // boundary via the virtual clock: 59 s still skips, 60 s re-arms the
        // LRU promotion (and the test never wall-waits sixty of them).
        $cache = new DiskCache($this->dir);
        $cache->put('k', 'v');
        $file = $this->entryFor('k');
        touch($file, 1000);

        DiskCache::overrideClock(static fn (): int => 1059);
        $this->assertSame('v', $cache->get('k'));
        clearstatcache();
        $this->assertSame(1000, filemtime($file), 'inside the skip window the touch is withheld');

        DiskCache::overrideClock(static fn (): int => 1060);
        $this->assertSame('v', $cache->get('k'));
        clearstatcache();
        $this->assertGreaterThan(1000, (int) filemtime($file), 'at the interval the touch fires again');
    }

    public function testSameSecondVictimOrderIsDeterministic(): void
    {
        // The class docblock moved the same-second story from "orders
        // arbitrarily among themselves" to a law: oldest first, then lowest
        // hashed path. Three entries stamped to one mtime under a cap of 2
        // must therefore lose exactly one of the tie — and WHICH one dies is
        // the strcmp leg of the comparator, not the accident of a directory
        // listing order.
        $seed = new DiskCache($this->dir, 10);
        foreach (['a', 'b', 'c'] as $k) {
            $seed->put($k, $k);
            touch($this->entryFor($k), 500);
        }

        $cache = new DiskCache($this->dir, 2);
        $cache->put('d', 'd'); // newest mtime; excess 2 takes both victims from the tie

        $expected = 'a';
        foreach (['b', 'c'] as $k) {
            if (sha1($k) > sha1($expected)) {
                $expected = $k;
            }
        }
        foreach (['a', 'b', 'c'] as $k) {
            $this->assertSame($k === $expected, $cache->has($k), "only the highest-path tie entry survives");
        }
        $this->assertTrue($cache->has('d'));
        $this->assertSame(2, $cache->count());
    }

    public function testCountCapIsTrimmedImmediatelyBurstsNeverWin(): void
    {
        // Acceptance from the finding: the count cap keeps its pre-finding
        // semantics and is NOT debounced like the byte budget — a cap that
        // lags a burst is no cap. Which tied-second entries survive is the
        // strcmp leg's business (pinned separately); this arm pins that the
        // directory never sits above the cap between writes.
        $cache = new DiskCache($this->dir, 3);
        foreach (range('a', 'g') as $i => $k) {
            $cache->put($k, $k);
            $this->assertSame(min(3, $i + 1), $cache->count(), 'trim is immediate after every write past the cap');
        }
    }

    // --- SI-20 (findings 033+110+111): same-dir exclusive create, durable
    // rename, umask-proof entry mode, sweep covers the real temp naming ---

    public function testPutOnUnwritableDirectoryRefusesWithoutSystemTmpLitter(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory write permissions — the refusal cannot be simulated');
        }

        // Finding 110's law: a refused write must be refused INSIDE the
        // cache dir. The pre-row tempnam() silently retreated to the system
        // temp when the wanted dir was unwritable, so a death mid-write
        // stranded an mc-* orphan in /tmp outside every sweep this class
        // owns. Exclusive create there simply fails, cleanly.
        mkdir($this->dir, 0o700);
        chmod($this->dir, 0o500);
        $litterBefore = count(glob(sys_get_temp_dir() . '/mc-*') ?: []);

        $cache = new DiskCache($this->dir);
        $threw = null;
        try {
            $cache->put('k', 'v');
        } catch (\Throwable $e) {
            $threw = $e;
        }
        chmod($this->dir, 0o700); // restore before assertions so tearDown can reach the dir

        $this->assertInstanceOf(\RuntimeException::class, $threw, 'the write must be refused, not redirected');
        $this->assertStringContainsString('Failed to write cache entry', $threw->getMessage());
        $this->assertSame($litterBefore, count(glob(sys_get_temp_dir() . '/mc-*') ?: []), 'a refused put must not touch the system temp');
        $this->assertSame([], glob($this->dir . '/mc-*') ?: [], 'nor leave an orphan beside the target');
    }

    public function testPutEntryModeIsSixHundredUnderPermissiveUmask(): void
    {
        // Finding 111's truth: entry privacy must not ride the umask. The
        // exclusive create starts at 0666 & ~umask — world-readable under a
        // permissive one — and the row chmods the temp to 0600 BEFORE bytes
        // land; the rename carries that mode to the entry.
        $umask = umask(0o000);
        try {
            $cache = new DiskCache($this->dir);
            $cache->put('k', 'v');
        } finally {
            umask($umask);
        }

        $this->assertSame(0o600, fileperms($this->entryFor('k')) & 0o777, 'entries are born owner-only, umask cannot widen them');
    }

    public function testWrittenEntrySurvivesReReadDigestCheck(): void
    {
        $value = random_bytes(4096);
        $cache = new DiskCache($this->dir);

        $cache->put('poster', $value);

        $this->assertSame($value, $cache->get('poster'));
        $this->assertSame(hash('xxh3', $value), hash('xxh3', (string) file_get_contents($this->entryFor('poster'))), 'the bytes on disk digest-match what put() was handed');
    }

    public function testPutSweepsStaleTempsUnderTheRealExclusiveCreateName(): void
    {
        // The sweep glob and the name put() actually produces must agree —
        // tempnam() minted extensionless mc-* while the row writes
        // mc-<hex>.tmp; a glob that drifted to '*.tmp' or the create that
        // drifted to bare 'mc-' would silently un-reap every orphan.
        $cache = new DiskCache($this->dir);
        $cache->put('seed', 'v');

        $orphan = $this->dir . '/mc-' . bin2hex(random_bytes(12)) . '.tmp';
        file_put_contents($orphan, 'crashed-write');
        touch($orphan, time() - 3600);

        $cache->put('again', 'v2');

        $this->assertFileDoesNotExist($orphan);
    }

    public function testPutShapePinIsExclusiveCreateWithFsyncAndNoTempnam(): void
    {
        // The crash-window laws (no system-temp retreat, rename never
        // outpaces data) are structural — no in-process test can simulate
        // the power cut — so the shape itself is pinned, house idiom for
        // guard-by-grep invariants (cf. the banded-sampler pin in SI-09).
        $file = (new \ReflectionClass(DiskCache::class))->getFileName();
        $src  = (string) file_get_contents($file);
        $start = (int) strpos($src, 'public function put(');
        $body  = substr($src, $start, (int) strpos(substr($src, $start), "\n    }"));

        $this->assertNotFalse($start);
        $this->assertStringNotContainsString('@tempnam(', $body, 'the system-temp fallback is dead on this door (call form; prose may name it)');
        $this->assertStringContainsString("'xb'", $body, 'the temp must be an exclusive same-dir create');
        $this->assertStringContainsString('fsync(', $body, 'the rename must not outpace the data');
        $this->assertStringContainsString('chmod($tmp, 0o600)', $body, 'entry mode lands before bytes');
    }
}
