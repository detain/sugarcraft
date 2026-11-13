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
use React\Stream\ThroughStream;
use SugarCraft\Image\ImageSource;

/**
 * Transport-ceiling coverage for the DEFAULT-constructed client of
 * ImageSource::fromUrlAsync() (finding sugar-image-043, plus the loop-resident
 * pointer of 105 which is docblock-only).
 *
 * The class contract refuses bodies over ImageSource::MAX_BYTES, but the
 * refusal used to run strictly AFTER react/http had buffered the entire body
 * — the ceiling bounded what we ACCEPT, never what the hostile socket
 * DELIVERS. The default Browser is now armed with a response buffer of
 * MAX_BYTES + 1 (the surplus discriminator byte mirrors the sync read), so
 * react's Transaction rejects over-size bodies at the Content-Length pre-flight
 * (zero body bytes materialise) or mid-stream at the cap, and the vendor
 * OverflowException is translated back into the typed url_too_large refusal
 * the sync route has always produced. Injected Browsers stay untouched —
 * their buffer choice is the caller's contract, and the translation is armed
 * only for the client we construct.
 *
 * Drives a real, ephemeral loopback HTTP server (allow-listed) exactly like
 * ImageSourceAsyncSsrfTest; everything runs on the shared event loop.
 *
 * @covers \SugarCraft\Image\ImageSource
 */
final class ImageSourceTransportCeilingTest extends TestCase
{
    private string $pngBytes = '';
    private ?SocketServer $socket = null;

    /** Bytes the raw streamer had pushed to the socket when the client hung up. */
    private ?int $rawDeliveredAtClose = null;
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pngBytes            = (string) file_get_contents(__DIR__ . '/fixtures/4x2.png');
        $this->rawDeliveredAtClose = null;
    }

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    public function testTheDefaultAsyncClientIsArmedWithTheTransportCeiling(): void
    {
        // Shape pin (by-construction): the ceiling the default client carries is
        // exactly MAX_BYTES + 1 — the largest payload the contract can accept,
        // plus the surplus byte. Remove the arming (or drift its number) and the
        // behavioural arms below regress to the vendor default; this pin names
        // the law directly, including the +1 that ties it to the sync route's
        // discriminator byte.
        $source = (string) file_get_contents(__DIR__ . '/../src/ImageSource.php');

        $this->assertSame(
            1,
            preg_match('#->withResponseBuffer\(self::MAX_BYTES \+ 1\)#', $source),
            'the default Browser must be armed with a MAX_BYTES + 1 response buffer',
        );
    }

    public function testAdvertisedOversizeBodyIsRefusedWithoutMaterialising(): void
    {
        // Content-Length pre-flight: the server advertises MAX_BYTES + 1000 on
        // the wire but delivers a handful of bytes. With the ceiling armed,
        // react's Transaction rejects the moment the header parses — the body
        // never reaches the buffer at all. The refusal must be the typed
        // url_too_large (our exception class naming OUR limit), not the vendor
        // OverflowException leaking through. Served as RAW wire bytes so no
        // server-side body normalisation can second-guess the lie.
        $advertised = ImageSource::MAX_BYTES + 1000;

        $this->socket = new SocketServer('127.0.0.1:0');
        $this->socket->on('connection', static function ($conn) use ($advertised): void {
            $conn->on('data', static function () use ($conn, $advertised): void {
                $conn->end(
                    "HTTP/1.1 200 OK\r\n" .
                    "Content-Type: image/png\r\n" .
                    'Content-Length: ' . $advertised . "\r\n" .
                    "Connection: close\r\n\r\n" .
                    'tiny'
                );
            });
        });
        $this->port = (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);

        try {
            $this->await(ImageSource::fromUrlAsync(
                "http://127.0.0.1:{$this->port}/advertised-giant",
                null,
                null,
                allowedHosts: ['127.0.0.1'],
            ));
            $this->fail('the advertised over-size body must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('exceeds the maximum of', $e->getMessage());
            $this->assertStringContainsString((string) ImageSource::MAX_BYTES, $e->getMessage());
        }
    }

    public function testSilentStreamPastTheCeilingIsAbortedMidFlight(): void
    {
        // No Content-Length to pre-judge: the server streams chunked bytes that
        // never stop (planned = 3x the ceiling). Without any transport cap the
        // client would buffer all of it before guardFetchedBytes ever ran; with
        // the ceiling armed it must cut the stream at MAX_BYTES + 1 and answer
        // with the same typed refusal. The server-side pin proves the abort is
        // EARLY, not a post-hoc check: the bytes actually written when the
        // client hung up stay well under the planned 3x, so the surplus
        // hundreds of megabytes were never materialised.
        $planned = 3 * ImageSource::MAX_BYTES;
        $this->startRawStreamServer($planned);

        $ceiling = ImageSource::MAX_BYTES;
        try {
            $this->await(ImageSource::fromUrlAsync(
                "http://127.0.0.1:{$this->port}/silent-giant",
                null,
                null,
                allowedHosts: ['127.0.0.1'],
            ), 30.0);
            $this->fail('the silently streamed over-size body must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('exceeds the maximum of', $e->getMessage());
            $this->assertStringContainsString((string) $ceiling, $e->getMessage());
        }

        // Let the loop spin briefly so the server side observes the client's
        // hang-up (the 'close' event cannot fire while await() has stopped it).
        $grace = Loop::addTimer(0.1, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($grace);

        $this->assertNotNull($this->rawDeliveredAtClose, 'the client must close the connection');
        $this->assertLessThan(
            2 * $ceiling,
            $this->rawDeliveredAtClose,
            'the client hung up at the ceiling — the planned 3x stream must never fully materialise',
        );
    }

    public function testAWellBehavedBodyBetweenTheVendorDefaultAndOurCeilingStillLoads(): void
    {
        // Contract coherence (the other edge of the same arming): react's
        // stock buffer is 16 MiB while this class advertises 64 MiB, so bodies
        // in that window were rejected by a limit the contract never
        // mentioned. The armed default client must fetch one end-to-end: a
        // noise PNG just above the vendor default decodes like any other.
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd required to synthesise the mid-size image');
        }

        $midBytes = self::midSizeNoisePng();
        $this->assertGreaterThan(16 * 1024 * 1024, strlen($midBytes), 'fixture must exceed the vendor default buffer');

        $server = new HttpServer(function (ServerRequestInterface $request) use ($midBytes): Response {
            return new Response(200, ['Content-Type' => 'image/png'], $midBytes);
        });
        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);
        $this->port   = (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);

        $img = $this->await(ImageSource::fromUrlAsync(
            "http://127.0.0.1:{$this->port}/mid.png",
            null,
            null,
            allowedHosts: ['127.0.0.1'],
        ), 30.0);

        $this->assertInstanceOf(ImageSource::class, $img);
        $this->assertGreaterThan(2000, $img->width);
    }

    public function testAnInjectedBrowserKeepsItsOwnBufferingContract(): void
    {
        // The "caller must cap" stance stays literally true: only what WE
        // construct is armed. A caller-supplied Browser configured with a
        // buffer one byte under the fixture keeps its raw vendor
        // OverflowException — neither the arming nor the typed translation is
        // force-fed onto someone else's client. (CL pre-flight fires on the
        // header alone, so this arm is deterministic and cheap.)
        $injected = (new Browser())->withResponseBuffer(strlen($this->pngBytes) - 1);

        $this->startServer();

        try {
            $this->await(ImageSource::fromUrlAsync(
                "http://127.0.0.1:{$this->port}/poster.png",
                null,
                $injected,
                allowedHosts: ['127.0.0.1'],
            ));
            $this->fail('the under-sized caller buffer must refuse the poster body');
        } catch (\OverflowException $e) {
            // The vendor's own error, verbatim: not translated, not retyped.
            $this->assertStringContainsString('exceeds maximum of ' . (strlen($this->pngBytes) - 1), $e->getMessage());
        }
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Raw chunked streamer: one 1 MiB chunk per loop tick, written directly to
     * the connection (ConnectionInterface exposes write()'s backpressure flag
     * and 'drain'), so $written mirrors bytes actually handed to the socket.
     * Records how much was written when the client hung up — the abort-observed
     * pin for the transport ceiling.
     */
    private function startRawStreamServer(int $planned): void
    {
        $deliveredAt = &$this->rawDeliveredAtClose;

        $this->socket = new SocketServer('127.0.0.1:0');
        $this->socket->on('connection', static function ($conn) use ($planned, &$deliveredAt): void {
            $conn->on('data', static function () use ($conn, $planned, &$deliveredAt): void {
                $conn->write(
                    "HTTP/1.1 200 OK\r\n" .
                    "Content-Type: image/png\r\n" .
                    "Transfer-Encoding: chunked\r\n" .
                    "Connection: close\r\n\r\n"
                );

                $chunk   = "100000\r\n" . str_repeat('A', 1024 * 1024) . "\r\n"; // 1 MiB hex-framed chunk (0x100000 = 1048576)
                $written = 0;
                $open    = true;

                $conn->on('close', static function () use (&$written, &$deliveredAt, &$open): void {
                    $deliveredAt = $written;
                    $open        = false;
                });

                $pump = static function () use ($conn, $chunk, &$written, &$open, $planned, &$pump): void {
                    if (!$open) {
                        return;
                    }
                    if ($conn->isWritable()) {
                        $conn->write($chunk);
                        $written += 1024 * 1024;
                    }
                    if ($written < $planned && $open) {
                        Loop::addTimer(0.002, $pump);
                    }
                };
                Loop::addTimer(0.0, $pump);
            });
        });
        $this->port = (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }

    /**
     * Loopback server with the routes the ceiling arms need:
     *  - /poster.png    the 4x2 fixture (small, always accepted)
     *  - /silent-giant  chunked stream with NO length, pumping 1 MiB chunks
     *                   until the client hangs up or $planned bytes are
     *                   written; records how much was delivered when the
     *                   server saw the stream close.
     */
    private function startServer(): void
    {
        $png = $this->pngBytes;

        $server = new HttpServer(static function (ServerRequestInterface $request) use ($png): Response {
            $path = $request->getUri()->getPath();

            if ($path === '/poster.png') {
                return new Response(200, ['Content-Type' => 'image/png'], $png);
            }

            return new Response(404, [], 'not found');
        });

        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);
        $this->port = (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }

    /** @var string|null cached mid-size noise PNG (synthesised once per process) */
    private static ?string $midPng = null;

    /**
     * A valid PNG whose COMPRESSED size sits above react's stock 16 MiB buffer
     * but below our 64 MiB contract: white noise compresses badly, so ~2600px
     * of it clears the bar. Built through a 24bpp BMP because raw noise ->
     * imagecreatefromstring -> imagepng is the cheap deterministic path.
     */
    private static function midSizeNoisePng(): string
    {
        if (self::$midPng !== null) {
            return self::$midPng;
        }

        $w = 2600;
        $h = 2600;
        $rowBytes = $w * 3;
        $pad = (4 - ($rowBytes % 4)) % 4;
        // Every row freshly random — repeating one noise row is exactly what
        // PNG's row filters would compress away.
        $noise = random_bytes($rowBytes * $h);
        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $rows[] = substr($noise, $y * $rowBytes, $rowBytes) . ($pad === 0 ? '' : str_repeat("\0", $pad));
        }
        $pixelData = implode('', $rows);

        $bmp = 'BM'
            . pack('V', 54 + strlen($pixelData)) . pack('vv', 0, 0) . pack('V', 54)
            . pack('V', 40) . pack('V', $w) . pack('V', $h) . pack('vv', 1, 24)
            . pack('V', 0) . pack('V', 0) . pack('V', 0) . pack('V', 0) . pack('V', 0) . pack('V', 0)
            . $pixelData;

        $gd = imagecreatefromstring($bmp);
        self::assertSameGd($gd);

        $png = '';
        ob_start();
        imagepng($gd);
        $png = (string) ob_get_clean();
        self::$midPng = $png;

        return $png;
    }

    private static function assertSameGd(\GdImage|false $gd): void
    {
        if (!$gd instanceof \GdImage) {
            throw new \RuntimeException('noise BMP must decode through GD');
        }
    }

    /**
     * Run the loop until $promise settles (or a safety timeout fires). Same
     * shape as ImageSourceAsyncSsrfTest::await().
     */
    private function await(PromiseInterface $promise, float $timeout = 10.0): mixed
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

