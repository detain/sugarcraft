<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use SugarCraft\Image\ImageSource;

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
 * @covers \SugarCraft\Image\ImageSource
 */
final class ImageSourceAsyncSsrfTest extends TestCase
{
    private string $pngBytes = '';
    private ?SocketServer $socket = null;
    private ?SocketServer $socket2 = null;
    private int $port = 0;
    private int $port2 = 0;

    /** Header sightings per watched path, recorded by the loopback handlers. */
    private array $headerLog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pngBytes  = (string) file_get_contents(__DIR__ . '/fixtures/4x2.png');
        $this->headerLog = [];
    }

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket  = null;
        $this->socket2?->close();
        $this->socket2 = null;
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

    // ---- allow-list bypass + happy path (real loopback server) ----------

    public function testAsyncSameAuthorityRedirectKeepsCredentialsAndStrangers(): void
    {
        // finding sugar-image-101 (async twin): the caller's Authorization /
        // Cookie / X-Keep ride a relative (same-authority) redirect whole —
        // replay to the same origin is the contract, and shedding legitimate
        // bearer tokens on internal CDN hops would break real deployments.
        $this->startServer();

        $img = $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/auth-orig",
            ['Authorization' => 'Bearer token123', 'Cookie' => 'session=abc', 'X-Keep' => 'k'],
            null,
            allowedHosts: ['127.0.0.1'],
        ));

        $this->assertSame(4, $img->width);
        $rec = $this->headerLog['/poster-auth.png'] ?? [];
        $this->assertSame('Bearer token123', $rec['authorization'] ?? null, 'same-authority replay vanished');
        $this->assertSame('session=abc', $rec['cookie'] ?? null);
        $this->assertSame('k', $rec['x-keep'] ?? null);
    }

    public function testAsyncCrossAuthorityRedirectShedsCredentialsKeepsOthers(): void
    {
        // The attacker-chosen hop: an absolute Location naming a DIFFERENT
        // authority (here: the second loopback port — same host, foreign
        // port, so it is purely the port component moving) must arrive
        // WITHOUT Authorization / Proxy-Authorization / Cookie, while every
        // other header carries on untouched.
        $this->startServer();

        $img = $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/auth-cross",
            ['Authorization' => 'Bearer token123', 'Cookie' => 'session=abc', 'X-Keep' => 'k'],
            null,
            allowedHosts: ['127.0.0.1'],
        ));

        $this->assertSame(4, $img->width);
        $rec = $this->headerLog['/poster-auth.png'] ?? [];
        $this->assertSame('', $rec['authorization'] ?? '', 'cross-authority hop leaked a bearer token');
        $this->assertSame('', $rec['cookie'] ?? '', 'cross-authority hop leaked a session cookie');
        $this->assertSame('k', $rec['x-keep'] ?? null, 'non-credential headers must ride on');
    }

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
     * Start ephemeral loopback HTTP servers: the primary serves the fixture PNG
     * at /poster.png, a same-origin redirect at /redirect-ok, a redirect into
     * the cloud-metadata IP at /redirect-metadata, and the credential-leg pair
     * (/auth-orig -> relative same-authority, /auth-cross -> absolute SECOND
     * PORT — a different authority). The watched landing route
     * /poster-auth.png records the headers each chain delivered.
     */
    private function startServer(): void
    {
        $png = $this->pngBytes;

        $handler = function (ServerRequestInterface $request) use ($png): Response {
            $path = $request->getUri()->getPath();

            if ($path === '/poster-auth.png') {
                $seen = [];
                foreach (['authorization', 'cookie', 'proxy-authorization', 'x-keep'] as $name) {
                    $seen[$name] = $request->getHeaderLine($name);
                }
                $this->headerLog[$path] = $seen;

                return new Response(200, ['Content-Type' => 'image/png'], $png);
            }

            return match ($path) {
                '/poster.png'         => new Response(200, ['Content-Type' => 'image/png'], $png),
                '/redirect-ok'        => new Response(302, ['Location' => '/poster.png'], ''),
                '/redirect-metadata'  => new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/'], ''),
                '/auth-orig'          => new Response(302, ['Location' => '/poster-auth.png'], ''),
                // Absolute target on the SECOND ephemeral port: same host,
                // same path, foreign authority — purely the port moves, so a
                // leak can only be the port component failing to shed.
                '/auth-cross'         => new Response(302, ['Location' => "http://127.0.0.1:{$this->port2}/poster-auth.png"], ''),
                default               => new Response(404, [], 'not found'),
            };
        };

        $server  = new HttpServer($handler);
        $server2 = new HttpServer($handler);

        $this->socket  = new SocketServer('127.0.0.1:0');
        $this->socket2 = new SocketServer('127.0.0.1:0');
        $this->port2   = (int) parse_url((string) $this->socket2->getAddress(), PHP_URL_PORT);
        $server->listen($this->socket);
        $server2->listen($this->socket2);
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
