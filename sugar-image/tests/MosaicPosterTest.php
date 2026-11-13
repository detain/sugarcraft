<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use ReflectionMethod;
use SugarCraft\Image\DiskCache;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Scale;
use SugarCraft\Image\Tests\Support\LoopbackHttpServer;

/**
 * @covers \SugarCraft\Image\Mosaic::poster
 * @covers \SugarCraft\Image\Mosaic::posterAsync
 * @covers \SugarCraft\Image\Mosaic::posterFile
 */
final class MosaicPosterTest extends TestCase
{
    private string $cacheDir;
    private ?LoopbackHttpServer $server = null;
    private string $serverDir = '';
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/mosaic-poster-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->cacheDir = $dir;
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        if ($this->serverDir !== '') {
            LoopbackHttpServer::removeTempDir($this->serverDir);
            $this->serverDir = '';
        }

        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            if (is_dir($file)) {
                // DiskCache roots created INSIDE the sandbox (the 034
                // arms keep their entries one level down so the naive
                // sweep below never confuses them with poster copies).
                foreach ((array) glob($file . '/*') as $inner) {
                    @unlink((string) $inner);
                }
                @rmdir($file);
                continue;
            }
            unlink($file);
        }
        @rmdir($this->cacheDir);

        parent::tearDown();
    }

    // ---- poster(): cache hit -------------------------------------------

    public function testPosterReturnsCachedBytesWithoutFetching(): void
    {
        $mosaic = Mosaic::halfBlock();
        $cache  = new DiskCache($this->cacheDir);
        $key    = DiskCache::key('http://127.0.0.1:1/poster.png', 8, 4, 'halfblock|Fill');
        $cache->put($key, "CACHED\x1b[0m");

        // The URL points at a closed port: only a cache hit can return.
        $out = $mosaic->poster('http://127.0.0.1:1/poster.png', 8, 4, $cache);

        $this->assertSame("CACHED\x1b[0m", $out);
    }

    public function testPosterCacheHitUsesAutoHeightSentinelForNullHeight(): void
    {
        $mosaic = Mosaic::halfBlock();
        $cache  = new DiskCache($this->cacheDir);
        // $cellH null must key on the sentinel, not on a derived height —
        // pre-populate under the sentinel and prove the hit.
        $key = DiskCache::key('http://127.0.0.1:1/poster.png', 8, -1, 'halfblock|Fill');
        $cache->put($key, 'SENTINEL');

        $this->assertSame('SENTINEL', $mosaic->poster('http://127.0.0.1:1/poster.png', 8, null, $cache));
    }

    // ---- poster(): fetch → render → populate ----------------------------

    public function testPosterFetchesRendersAndPopulatesCache(): void
    {
        $this->startServer();
        $mosaic = Mosaic::halfBlock();
        $cache  = new DiskCache($this->cacheDir);
        $url    = "http://127.0.0.1:{$this->port}/poster.png";
        $key    = DiskCache::key($url, 8, 4, 'halfblock|Fill');

        $this->assertFalse($cache->has($key));
        $out = $mosaic->poster($url, 8, 4, $cache, ['127.0.0.1']);

        $this->assertNotSame('', $out);
        $this->assertTrue($cache->has($key), 'miss must populate the cache');
        $this->assertSame($out, $cache->get($key));
    }

    public function testPosterDitherConfigMissesTheOtherDitherPosterEntry(): void
    {
        $this->startServer();
        $cache = new DiskCache($this->cacheDir);
        $url   = "http://127.0.0.1:{$this->port}/poster.png";

        $fs     = Mosaic::sixel(Dither::FloydSteinberg);
        $stucki = Mosaic::sixel(Dither::Stucki);
        $fsKey  = $this->posterKey($fs, $url);
        $stKey  = $this->posterKey($stucki, $url);

        $this->assertNotSame($fsKey, $stKey, 'D-04: same url+size+protocol, different dither, different key');
        $cache->put($fsKey, 'FS-CACHED');

        // The warmed arm hits without touching the network…
        $this->assertSame('FS-CACHED', $fs->poster($url, 8, 4, $cache, ['127.0.0.1']));

        // …and the cold arm must render fresh instead of being served the
        // FS entry (pre-D-04 both names hashed to 'sixel|Fill' and did).
        $out = $stucki->poster($url, 8, 4, $cache, ['127.0.0.1']);
        $this->assertNotSame('FS-CACHED', $out);
        $this->assertSame($out, $cache->get($stKey));
        $this->assertSame('FS-CACHED', $cache->get($fsKey), 'the FS entry must survive untouched');
    }

    public function testPosterWithoutCacheStillRenders(): void
    {
        $this->startServer();

        $out = Mosaic::halfBlock()->poster(
            "http://127.0.0.1:{$this->port}/poster.png",
            6,
            3,
            null,
            ['127.0.0.1'],
        );

        $this->assertNotSame('', $out);
    }

    public function testPosterBlocksPrivateHostWithoutAllowedHosts(): void
    {
        $this->startServer();

        // SSRF deny-list stays wired through the convenience: no allowedHosts
        // seam passed → loopback fetch is refused before it goes out.
        $this->expectException(\InvalidArgumentException::class);

        Mosaic::halfBlock()->poster("http://127.0.0.1:{$this->port}/poster.png", 8, 4);
    }

    // ---- posterAsync() ----------------------------------------------------

    public function testPosterAsyncResolvesFromCacheWithoutTouchingTheLoop(): void
    {
        $mosaic = Mosaic::halfBlock();
        $cache  = new DiskCache($this->cacheDir);
        $key    = DiskCache::key('http://127.0.0.1:1/poster.png', 8, 4, 'halfblock|Fill');
        $cache->put($key, 'ASYNC-CACHED');

        $resolved = null;
        $mosaic->posterAsync('http://127.0.0.1:1/poster.png', 8, 4, $cache)
            ->then(static function (string $value) use (&$resolved): void {
                $resolved = $value;
            });

        // A cache hit resolves synchronously — no Loop::run() needed.
        $this->assertSame('ASYNC-CACHED', $resolved);
    }

    public function testPosterAsyncFetchesRendersAndPopulatesCache(): void
    {
        $this->startServer();
        $mosaic = Mosaic::kitty();
        $cache  = new DiskCache($this->cacheDir);
        $url    = "http://127.0.0.1:{$this->port}/poster.png";
        $key    = DiskCache::key($url, 8, 4, 'kitty|Fill');

        $out = $this->await($mosaic->posterAsync($url, 8, 4, $cache, ['127.0.0.1']));

        $this->assertIsString($out);
        $this->assertNotSame('', $out);
        $this->assertSame($out, $cache->get($key), 'fulfilment must store under the poster key');

        // Second call is a pure cache hit — resolves without the loop running.
        $again = null;
        $mosaic->posterAsync($url, 8, 4, $cache, ['127.0.0.1'])
            ->then(static function (string $value) use (&$again): void {
                $again = $value;
            });
        $this->assertSame($out, $again);
    }

    public function testPosterAsyncRejectsOnBlockedPrivateHost(): void
    {
        $this->startServer();

        $rejected = null;
        Mosaic::halfBlock()
            ->posterAsync("http://127.0.0.1:{$this->port}/poster.png", 8, 4)
            ->then(null, static function (\Throwable $e) use (&$rejected): void {
                $rejected = $e;
            });
        $this->awaitIdle();

        $this->assertInstanceOf(\InvalidArgumentException::class, $rejected);
    }

    public function testPosterAsyncThrowsSynchronouslyOnDisallowedScheme(): void
    {
        // The documented error split: scheme refusals throw at call time
        // (misuse, not I/O), host/SSRF refusals come back rejected. No loop
        // run here — a rejected promise would leave this test unresolved.
        $this->expectException(\InvalidArgumentException::class);

        Mosaic::halfBlock()->posterAsync('file:///etc/passwd', 8, 4);
    }

    public function testPosterFileMissPathRendersWithFillScale(): void
    {
        // Fill-by-default is requirement 4's anti-squash guarantee — pin it
        // on the RENDER BYTES, not just the cache key: a cache-less miss
        // must equal the explicit Fill render of the same source.
        $path     = __DIR__ . '/fixtures/4x2.png';
        $mosaic   = Mosaic::kitty();
        $expected = $mosaic->withScale(Scale::Fill)
            ->render(ImageSource::fromFile($path), 8, 4);

        $this->assertSame($expected, $mosaic->posterFile($path, 8, 4));
    }

    // ---- posterFile() -----------------------------------------------------

    public function testPosterFileRendersAndRoundTripsThroughCache(): void
    {
        $path   = __DIR__ . '/fixtures/4x2.png';
        $mosaic = Mosaic::kitty();
        $cache  = new DiskCache($this->cacheDir);

        $out = $mosaic->posterFile($path, 8, 4, $cache);

        // Post-034 the file-key leg embeds the file's mtime+size, so the
        // naive public DiskCache::key() shape can no longer derive the
        // path-keyed entry — that is the finding: the key is mosaic-
        // internal by construction until G3 ships a public poster-key
        // door. The round-trip is pinned behaviourally instead: exactly
        // one stored entry, and the second call serves stored bytes
        // without re-encoding (same content, count still 1).
        $this->assertNotSame('', $out);
        $this->assertSame(1, $cache->count());

        // Second call serves the stored bytes (hit path) — same content,
        // no second entry written.
        $this->assertSame($out, $mosaic->posterFile($path, 8, 4, $cache));
        $this->assertSame(1, $cache->count());
    }

    public function testAnEditedPosterFileSelfInvalidatesItsCachedEncode(): void
    {
        // Finding 034: a path-keyed entry used to be an immortal memo of
        // whatever bytes the path held at first render — replace the file
        // and the old encode served forever. Now the key carries the
        // file's identity, so an edit is a miss.
        $path   = $this->cacheDir . '/poster-edit-me.png';
        $cache  = new DiskCache($this->cacheDir . '/c');
        $mosaic = Mosaic::kitty();

        copy(__DIR__ . '/fixtures/4x2.png', $path);
        $first = $mosaic->posterFile($path, 8, 4, $cache);
        $this->assertSame(1, $cache->count());

        copy(__DIR__ . '/fixtures/8x4_red.png', $path); // different bytes AND size
        $second = $mosaic->posterFile($path, 8, 4, $cache);

        $this->assertNotSame($first, $second, 'the edited file must render its own bytes, not the cached generation');
        $this->assertSame(2, $cache->count(), 'a changed identity is a new key — the old generation ages out through eviction');

        $fresh = Mosaic::kitty()->posterFile($path, 8, 4);
        $this->assertSame($fresh, $second, 'the miss re-encodes exactly what a cache-less render produces');
    }

    public function testASizePreservingRewriteWithFreshMtimeStillInvalidates(): void
    {
        // The subtle half of 034: a rewrite that happens to land the same
        // byte count (in-place palette swap, re-compressed same-size
        // PNG) must not slip through on size alone — mtime (ns-aware)
        // carries the edit.
        $path  = $this->cacheDir . '/poster-same-size.png';
        $cache = new DiskCache($this->cacheDir . '/c');

        copy(__DIR__ . '/fixtures/4x2.png', $path);
        $mosaic = Mosaic::kitty();
        $first  = $mosaic->posterFile($path, 8, 4, $cache);

        // Same bytes (so any size-gated logic stays honest) but a mtime
        // five seconds later: a re-save tool would land here too.
        touch($path, time() + 5);

        $mosaic->posterFile($path, 8, 4, $cache);
        $this->assertSame(2, $cache->count(), 'a bumped mtime is a new key even at identical size');
    }

    public function testPosterFileWithoutCache(): void
    {
        $out = Mosaic::halfBlock()->posterFile(__DIR__ . '/fixtures/4x2.png', 4);

        $this->assertNotSame('', $out);
    }

    public function testPosterFileThrowsOnMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Mosaic::halfBlock()->posterFile('/nonexistent/poster.png', 8, 4);
    }

    // ---- poster(): scale rides in the cache key ---------------------------

    public function testPosterCacheKeyFoldsScaleWithoutDisturbingTheDefault(): void
    {
        $path  = __DIR__ . '/fixtures/4x2.png';
        $cache = new DiskCache($this->cacheDir);

        // Post-034 the path legs fold the file's identity into the key,
        // so the pre-seed must derive the stamped key through the same
        // (private) door rather than the naive public DiskCache::key()
        // shape — that mismatch IS the G3 finding, pinned from the other
        // side here.
        // The scale-less default must key as Fill (the default poster scale).
        $cache->put($this->posterFileKey(Mosaic::kitty(), $path), 'FILL-CACHED');
        $this->assertSame('FILL-CACHED', Mosaic::kitty()->posterFile($path, 8, 4, $cache));

        // A Fit mosaic must NOT collide with that Fill entry…
        $fit = Mosaic::kitty()->withScale(Scale::Fit);
        // …and its own key serves its own entry.
        $cache->put($this->posterFileKey($fit, $path), 'FIT-CACHED');
        $this->assertSame('FIT-CACHED', $fit->posterFile($path, 8, 4, $cache));
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * Real `php -S` loopback server serving the fixture PNG at /poster.png —
     * a blocking stream fetch (sync poster()) needs an out-of-process listener.
     */
    /** Derive the renderer's poster key from Mosaic itself (private). */
    private function posterKey(Mosaic $mosaic, string $url): string
    {
        $method = new ReflectionMethod(Mosaic::class, 'posterCacheKey');
        $method->setAccessible(true);

        return $method->invoke($mosaic, $url, 8, 4);
    }

    /**
     * Same, for the FILE legs: chains the private identity stamp so the
     * pre-seed matches what posterFile() computes post-034.
     */
    private function posterFileKey(Mosaic $mosaic, string $path): string
    {
        $stamp = new ReflectionMethod(Mosaic::class, 'fileIdentityKeySource');
        $stamp->setAccessible(true);
        $method = new ReflectionMethod(Mosaic::class, 'posterCacheKey');
        $method->setAccessible(true);

        return $method->invoke($mosaic, $stamp->invoke(null, $path, @stat($path)), 8, 4);
    }

    private function startServer(): void
    {
        $this->serverDir = LoopbackHttpServer::makeTempDir();
        $router = $this->serverDir . '/' . LoopbackHttpServer::ROUTER_FILE;
        $png = var_export(__DIR__ . '/fixtures/4x2.png', true);
        file_put_contents($router, <<<PHP
            <?php
            \$p = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (\$p === '/health') {
                echo 'ok';
                return true;
            }
            if (\$p === '/poster.png') {
                header('Content-Type: image/png');
                readfile({$png});
                return true;
            }
            http_response_code(404);
            echo 'not found';
            return true;
            PHP);

        $this->server = LoopbackHttpServer::start($router);
        if ($this->server === null) {
            $this->markTestSkipped('Could not start a local php -S server.');
        }
        $this->port = $this->server->port();
    }

    /** Run the loop until $promise settles (or a safety timeout fires). */
    private function await(PromiseInterface $promise, float $timeout = 5.0): mixed
    {
        $resolved = null;
        $rejected = null;
        $settled  = false;

        $promise->then(
            function ($value) use (&$resolved, &$settled): void {
                $resolved = $value;
                $settled  = true;
                Loop::stop();
            },
            function ($reason) use (&$rejected, &$settled): void {
                $rejected = $reason;
                $settled  = true;
                Loop::stop();
            },
        );

        $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        if (!$settled) {
            throw new \RuntimeException('Promise did not settle within timeout');
        }
        if ($rejected !== null) {
            throw $rejected;
        }

        return $resolved;
    }

    /** Let already-queued rejections settle without expecting a resolution. */
    private function awaitIdle(float $timeout = 1.0): void
    {
        $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }
}
