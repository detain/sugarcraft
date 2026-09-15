<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Mosaic\DiskCache;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Scale;
use SugarCraft\Mosaic\Tests\Support\LoopbackHttpServer;

/**
 * @covers \SugarCraft\Mosaic\Mosaic::poster
 * @covers \SugarCraft\Mosaic\Mosaic::posterAsync
 * @covers \SugarCraft\Mosaic\Mosaic::posterFile
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
        $key = DiskCache::key($path, 8, 4, 'kitty|Fill');

        $this->assertNotSame('', $out);
        $this->assertSame($out, $cache->get($key));

        // Second call serves the stored bytes (hit path) — same content.
        $this->assertSame($out, $mosaic->posterFile($path, 8, 4, $cache));
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

        // The scale-less default must key as Fill (the default poster scale).
        $cache->put(DiskCache::key($path, 8, 4, 'kitty|Fill'), 'FILL-CACHED');
        $this->assertSame('FILL-CACHED', Mosaic::kitty()->posterFile($path, 8, 4, $cache));

        // A Fit mosaic must NOT collide with that Fill entry…
        $fit = Mosaic::kitty()->withScale(Scale::Fit);
        // …and its own key serves its own entry.
        $cache->put(DiskCache::key($path, 8, 4, 'kitty|Fit'), 'FIT-CACHED');
        $this->assertSame('FIT-CACHED', $fit->posterFile($path, 8, 4, $cache));
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * Real `php -S` loopback server serving the fixture PNG at /poster.png —
     * a blocking stream fetch (sync poster()) needs an out-of-process listener.
     */
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
