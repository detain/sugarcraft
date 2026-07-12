<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use SugarCraft\Mosaic\ImageSource;

/**
 * SSRF regression coverage for the ASYNCHRONOUS fetch path
 * ImageSource::fromUrlAsync().
 *
 * #1360 host-guarded only the synchronous fetchUrlSync(). The async path used
 * React\Http\Browser, which follows 3xx redirects INTERNALLY, so a URL — or a
 * redirect Location — pointing at 169.254.169.254 (cloud metadata) or an
 * RFC-1918 host was still reachable via fromUrlAsync(). The async path now
 * disables the Browser's internal redirect follower and re-runs
 * guardHostNotPrivate() on the initial URL AND every redirect hop by hand,
 * mirroring the sync path.
 *
 * Network-free where possible: the initial-URL cases use the injected
 * host-resolver seam or a literal private IP (guard fires before any connect);
 * the redirect case drives a real, ephemeral loopback HTTP server on
 * 127.0.0.1 (explicitly allow-listed) that 302s to the metadata IP.
 *
 * @covers \SugarCraft\Mosaic\ImageSource
 */
final class ImageSourceAsyncSsrfTest extends TestCase
{
    private string $pngBytes = '';
    private ?SocketServer $socket = null;
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pngBytes = (string) file_get_contents(__DIR__ . '/fixtures/4x2.png');
    }

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        // Never leak the test resolver into another test.
        ImageSource::overrideHostResolver(null);
        parent::tearDown();
    }

    // ---- initial-URL guard (network-free) -------------------------------

    public function testAsyncBlocksInitialUrlResolvingToMetadataIp(): void
    {
        // DNS-rebinding on the async path: a public-looking host that resolves
        // to the metadata IP must reject BEFORE the Browser fetches it. Uses the
        // injected resolver so no DNS/network is touched — revert the guard and
        // the Browser would instead fail with a DNS/transport error (a different
        // message), so this stays revert-proof via the assertion below.
        ImageSource::overrideHostResolver(
            static fn (string $h): array => $h === 'rebind.invalid' ? ['169.254.169.254'] : [],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        $this->await(ImageSource::fromUrlAsync('http://rebind.invalid/latest/meta-data/'));
    }

    public function testAsyncBlocksInitialUrlToRfc1918(): void
    {
        // A literal private IP as the initial async URL rejects before connect.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        $this->await(ImageSource::fromUrlAsync('http://10.0.0.5/x'));
    }

    public function testAsyncBlocksInitialUrlToLoopbackWithoutAllowList(): void
    {
        // Without the opt-in allow-list, even loopback is blocked on the async
        // path — proving the initial URL is guarded, not just redirects.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        $this->await(ImageSource::fromUrlAsync('http://127.0.0.1:9/x'));
    }

    // ---- redirect-hop guard (real loopback server) ----------------------

    public function testAsyncBlocksRedirectHopToMetadataIp(): void
    {
        // The core async hole #1360 left open: React\Http\Browser followed a 3xx
        // Location internally, bypassing the host guard. The loopback origin is
        // allow-listed; its 302 -> http://169.254.169.254/ must be rejected on
        // the redirect hop BEFORE it is followed. Revert the manual follower and
        // the Browser chases the metadata IP with no "blocked" message → fails.
        $this->startServer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/redirect-metadata",
            null,
            null,
            allowedHosts: ['127.0.0.1'],
        ));
    }

    // ---- allow-list bypass + happy path (real loopback server) ----------

    public function testAsyncAllowListBypassFetchesImage(): void
    {
        // The opt-in allow-list bypasses the deny-list for the loopback origin
        // and the image decodes end-to-end — proving the guard does not
        // over-block an explicitly trusted host on the async path.
        $this->startServer();

        $img = $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/poster.png",
            null,
            null,
            allowedHosts: ['127.0.0.1'],
        ));

        $this->assertInstanceOf(ImageSource::class, $img);
        $this->assertSame(4, $img->width);
        $this->assertSame(2, $img->height);
    }

    public function testAsyncFollowsAllowListedRedirectToImage(): void
    {
        // The manual follower must still walk a legitimate same-origin 302 all
        // the way to the image once the origin is allow-listed.
        $this->startServer();

        $img = $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/redirect-ok",
            null,
            null,
            allowedHosts: ['127.0.0.1'],
        ));

        $this->assertInstanceOf(ImageSource::class, $img);
        $this->assertSame(4, $img->width);
        $this->assertSame(2, $img->height);
    }

    // ---- helpers --------------------------------------------------------

    /**
     * Start an ephemeral loopback HTTP server that serves the fixture PNG at
     * /poster.png, a same-origin redirect at /redirect-ok, and a redirect into
     * the cloud-metadata IP at /redirect-metadata.
     */
    private function startServer(): void
    {
        $png = $this->pngBytes;

        $server = new HttpServer(static function (ServerRequestInterface $request) use ($png): Response {
            return match ($request->getUri()->getPath()) {
                '/poster.png'        => new Response(200, ['Content-Type' => 'image/png'], $png),
                '/redirect-ok'       => new Response(302, ['Location' => '/poster.png'], ''),
                '/redirect-metadata' => new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/'], ''),
                default              => new Response(404, [], 'not found'),
            };
        });

        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);
        $this->port = (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }

    /**
     * Run the loop until $promise settles (or a safety timeout fires).
     *
     * Skips Loop::run() entirely when the promise has already settled
     * synchronously (a pre-flight guard rejection settles before the loop
     * runs), so the guard cases return without waiting on the timer.
     */
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

        if (!$settled) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        if (!$settled) {
            throw new \RuntimeException('Promise did not settle within timeout');
        }
        if ($rejected !== null) {
            throw $rejected;
        }

        return $resolved;
    }
}
