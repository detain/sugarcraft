<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use SugarCraft\Mosaic\ImageSource;

/**
 * Covers ImageSource::fromUrl() (synchronous stream-wrapper fetch) and
 * ImageSource::fromUrlAsync() (non-blocking fetch via a real, ephemeral
 * ReactPHP HTTP server bound to 127.0.0.1 — no external network needed).
 */
final class ImageSourceUrlTest extends TestCase
{
    private string $fixture;
    private string $pngBytes;
    private ?SocketServer $socket = null;
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture  = __DIR__ . '/fixtures/4x2.png';
        $this->pngBytes = (string) file_get_contents($this->fixture);
    }

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    // ---- synchronous fromUrl -------------------------------------------

    public function testFromUrlLoadsFileScheme(): void
    {
        $img = ImageSource::fromUrl('file://' . $this->fixture, null, ['file']);

        $this->assertSame('image/png', $img->format);
        $this->assertSame(4, $img->width);
        $this->assertSame(2, $img->height);
    }

    public function testFromUrlLoadsDataUri(): void
    {
        $uri = 'data://image/png;base64,' . base64_encode($this->pngBytes);

        $img = ImageSource::fromUrl($uri, null, ['data']);

        $this->assertSame(4, $img->width);
        $this->assertSame(2, $img->height);
    }

    public function testFromUrlAppliesAssociativeHeaders(): void
    {
        // Headers are ignored by the file:// wrapper but the
        // associative-array formatting path must still run cleanly.
        $img = ImageSource::fromUrl(
            'file://' . $this->fixture,
            ['Authorization' => 'Bearer token123', 'Accept' => 'image/png'],
            ['file'],
        );

        $this->assertSame(4, $img->width);
    }

    public function testFromUrlAppliesPreformattedHeaderList(): void
    {
        $img = ImageSource::fromUrl(
            'file://' . $this->fixture,
            ['Authorization: Bearer token123'],
            ['file'],
        );

        $this->assertSame(4, $img->width);
    }

    public function testFromUrlThrowsOnMissingResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to fetch image from URL');

        ImageSource::fromUrl('file:///nonexistent/path/to/poster.png', null, ['file']);
    }

    public function testFromUrlThrowsOnNonImagePayload(): void
    {
        $uri = 'data://text/plain;base64,' . base64_encode('this is not an image');

        $this->expectException(\InvalidArgumentException::class);

        ImageSource::fromUrl($uri, null, ['data']);
    }

    public function testFromUrlRejectsCrlfInHeaderValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CR or LF');

        ImageSource::fromUrl(
            'file://' . $this->fixture,
            ['X-Evil' => "value\r\nX-Injected: 1"],
            ['file'],
        );
    }

    public function testFromUrlRejectsFileSchemeByDefault(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URL scheme file is not in the allowed list');

        ImageSource::fromUrl('file:///etc/passwd');
    }

    public function testFromUrlWithExplicitFileSchemeAllowed(): void
    {
        // Security: opt-in to file:// only when needed and the source is trusted.
        $img = ImageSource::fromUrl('file://' . $this->fixture, null, ['file']);

        $this->assertSame(4, $img->width);
        $this->assertSame(2, $img->height);
    }

    // ---- asynchronous fromUrlAsync -------------------------------------

    public function testFromUrlAsyncResolvesWithDecodedImage(): void
    {
        $this->startServer();

        $img = $this->await(
            ImageSource::fromUrlAsync("http://127.0.0.1:{$this->port}/poster.png"),
        );

        $this->assertInstanceOf(ImageSource::class, $img);
        $this->assertSame(4, $img->width);
        $this->assertSame(2, $img->height);
    }

    public function testFromUrlAsyncForwardsHeaders(): void
    {
        $seen = [];
        $this->startServer($seen);

        $img = $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/poster.png",
            ['X-Phlix-Test' => 'hello'],
        ));

        $this->assertInstanceOf(ImageSource::class, $img);
        $this->assertSame('hello', $seen['x-phlix-test'] ?? null);
    }

    public function testFromUrlAsyncAcceptsCustomBrowser(): void
    {
        $this->startServer();

        $img = $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/poster.png",
            null,
            new Browser(),
        ));

        $this->assertSame(4, $img->width);
    }

    public function testFromUrlAsyncFollowsRedirect(): void
    {
        $this->startServer();

        $img = $this->await(
            ImageSource::fromUrlAsync("http://127.0.0.1:{$this->port}/redirect"),
        );

        $this->assertInstanceOf(ImageSource::class, $img);
        $this->assertSame(4, $img->width);
    }

    public function testFromUrlAsyncRejectsOnHttpError(): void
    {
        $this->startServer();

        $this->expectException(\Throwable::class);

        $this->await(ImageSource::fromUrlAsync("http://127.0.0.1:{$this->port}/missing"));
    }

    public function testFromUrlAsyncRejectsOnConnectionRefused(): void
    {
        // Grab a free port, then release it so nothing is listening.
        $probe = new SocketServer('127.0.0.1:0');
        $port  = (int) parse_url((string) $probe->getAddress(), PHP_URL_PORT);
        $probe->close();

        $this->expectException(\Throwable::class);

        $this->await(ImageSource::fromUrlAsync("http://127.0.0.1:{$port}/poster.png"));
    }

    public function testFromUrlAsyncRejectsOnNonImagePayload(): void
    {
        $this->startServer();

        $this->expectException(\InvalidArgumentException::class);

        $this->await(ImageSource::fromUrlAsync("http://127.0.0.1:{$this->port}/text"));
    }

    // ---- helpers --------------------------------------------------------

    /**
     * Start an ephemeral HTTP server on 127.0.0.1 that serves the fixture
     * PNG at /poster.png, plain text at /text, and 404s everything else.
     *
     * @param array<string,string> $seen  Populated with the request headers
     *                                     of the most recent request (by ref).
     */
    private function startServer(array &$seen = []): void
    {
        $png = $this->pngBytes;

        $server = new HttpServer(static function (ServerRequestInterface $request) use ($png, &$seen): Response {
            foreach ($request->getHeaders() as $name => $values) {
                $seen[strtolower($name)] = implode(',', $values);
            }

            $path = $request->getUri()->getPath();

            return match ($path) {
                '/poster.png' => new Response(200, ['Content-Type' => 'image/png'], $png),
                '/text'       => new Response(200, ['Content-Type' => 'text/plain'], 'not an image'),
                '/redirect'   => new Response(302, ['Location' => '/poster.png'], ''),
                default       => new Response(404, [], 'not found'),
            };
        });

        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);

        $port = parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
        $this->port = (int) $port;
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
}
