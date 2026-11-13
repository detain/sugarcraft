<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use SugarCraft\Image\ImageSource;

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
            ImageSource::fromUrlAsync(
                "http://127.0.0.1:{$this->port}/poster.png",
                allowedHosts: ['127.0.0.1'],
            ),
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
            allowedHosts: ['127.0.0.1'],
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
            allowedHosts: ['127.0.0.1'],
        ));

        $this->assertSame(4, $img->width);
    }

    public function testFromUrlAsyncFollowsRedirect(): void
    {
        $this->startServer();

        $img = $this->await(
            ImageSource::fromUrlAsync(
                "http://127.0.0.1:{$this->port}/redirect",
                allowedHosts: ['127.0.0.1'],
            ),
        );

        $this->assertInstanceOf(ImageSource::class, $img);
        $this->assertSame(4, $img->width);
    }

    public function testFromUrlAsyncRejectsOnHttpError(): void
    {
        $this->startServer();

        $this->expectException(\Throwable::class);

        $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/missing",
            allowedHosts: ['127.0.0.1'],
        ));
    }

    public function testFromUrlAsyncRejectsOnConnectionRefused(): void
    {
        // Grab a free port, then release it so nothing is listening.
        $probe = new SocketServer('127.0.0.1:0');
        $port  = (int) parse_url((string) $probe->getAddress(), PHP_URL_PORT);
        $probe->close();

        $this->expectException(\Throwable::class);

        $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$port}/poster.png",
            allowedHosts: ['127.0.0.1'],
        ));
    }

    public function testSyncAndAsyncTwinsShareThePixelCeiling(): void
    {
        // finding sugar-image-092 parity law: fromUrlAsync() once lacked the
        // $maxPixels parameter its synchronous twin honours — an async caller
        // could not cap the decompression bomb budget at all. The twins must
        // refuse the SAME over-budget image with the SAME typed message and
        // accept it with the SAME generous ceiling.
        $this->startServer();

        $asyncRefused = null;
        try {
            $this->await(ImageSource::fromUrlAsync(
                "http://127.0.0.1:{$this->port}/poster.png",
                allowedHosts: ['127.0.0.1'],
                maxPixels: 4,
            ));
        } catch (\Throwable $e) {
            $asyncRefused = $e;
        }

        $this->assertInstanceOf(\InvalidArgumentException::class, $asyncRefused);
        $this->assertStringContainsString('exceed the maximum of 4 pixels', $asyncRefused->getMessage());

        $syncRefused = null;
        try {
            ImageSource::fromUrl('file://' . $this->fixture, null, ['file'], 4);
        } catch (\Throwable $e) {
            $syncRefused = $e;
        }

        $this->assertInstanceOf(\InvalidArgumentException::class, $syncRefused);
        $this->assertSame(
            $asyncRefused->getMessage(),
            $syncRefused->getMessage(),
            'the twins must refuse with byte-identical messages, not dialects',
        );

        $asyncOk = $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/poster.png",
            allowedHosts: ['127.0.0.1'],
            maxPixels: 8,
        ));
        $this->assertSame(8, $asyncOk->maxPixels, 'the granted ceiling must ride the constructed source');

        $syncOk = ImageSource::fromUrl('file://' . $this->fixture, null, ['file'], 8);
        $this->assertSame(8, $syncOk->maxPixels);
    }

    public function testTheAsyncTwinCarriesThePixelCeilingParameterShape(): void
    {
        // Shape pin for the same finding: the parameter must exist with the
        // contract default, and it must sit where the signature advertises it
        // (after allowedSchemes, before the allowedHosts seam) — so a caller
        // migrating from the sync route passes it the same way.
        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(ImageSource::class, 'fromUrlAsync'))->getParameters(),
        );

        $this->assertContains('maxPixels', $params);
        $this->assertSame(
            ['url', 'headers', 'browser', 'allowedSchemes', 'maxPixels', 'allowedHosts'],
            $params,
        );

        $rm = new \ReflectionMethod(ImageSource::class, 'fromUrlAsync');
        $idx = (int) array_search('maxPixels', $params, true);
        $this->assertSame(
            ImageSource::MAX_PIXELS,
            $rm->getParameters()[$idx]->getDefaultValue(),
            'the async default must draw the same MAX_PIXELS constant as the contract',
        );
    }

    public function testFromUrlAsyncRejectsOnNonImagePayload(): void
    {        $this->startServer();

        $this->expectException(\InvalidArgumentException::class);

        $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/text",
            allowedHosts: ['127.0.0.1'],
        ));
    }

    // ---- default fetch-timeout parity (round-90 audit INFO) -------------

    public function testAsyncAndSyncShareOneDefaultFetchTimeout(): void
    {
        // Both fetch paths must honour ONE shared ceiling, not hand-typed
        // numerals: sync carries it in the stream context, the async default
        // Browser in withTimeout(). An injected Browser stays caller-owned.
        $ref       = new \ReflectionClass(ImageSource::class);
        $constants = $ref->getConstants(\ReflectionClassConstant::IS_PRIVATE);
        $this->assertSame(30.0, $constants['FETCH_TIMEOUT_SECONDS'] ?? null);

        $source = (string) file_get_contents(__DIR__ . '/../src/ImageSource.php');
        $this->assertStringContainsString(
            "'timeout'         => self::FETCH_TIMEOUT_SECONDS,",
            $source,
            'sync stream context must draw the shared timeout constant',
        );
        $this->assertStringContainsString(
            '(new Browser())->withTimeout(self::FETCH_TIMEOUT_SECONDS)',
            $source,
            'default-constructed async Browser must draw the same constant',
        );
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
