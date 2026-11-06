<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\HttpSmartProtocol\HttpListener;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\LFS\LocalStorageBackend;
use SugarCraft\GitServer\Repo;

/**
 * Socket-level hardening pins for the listener: silent and dribbling
 * connections are reaped by the idle and absolute deadlines, seats are
 * bounded, the request-body cap has ONE source (http.max_pack_bytes
 * governs the listener exactly as it governs the router), the incremental
 * chunk scanner agrees with the whole-buffer decoder on every framing
 * edge, an oversize LFS object is refused before its bytes are
 * materialised in memory, duplicate Content-Length claims that DISAGREE
 * and Transfer-Encoding values that merely CONTAIN the chunked token are
 * refused or ignored at the socket, and IPv6 peers are handed downstream
 * bare (never '[::1]').
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\HttpListener
 */
final class HttpListenerHardeningTest extends TestCase
{
    private string $tmpDir = '';

    /** @var list<resource> */
    private array $sockets = [];

    private ?LoopInterface $loop = null;

    private ?Config $lastConfig = null;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-listener-hardening-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (\is_resource($socket)) {
                @\fclose($socket);
            }
        }
        $this->sockets = [];
        $this->loop = null;
        AccessControl::instance()->setAllowAnonymousRead(false);
        $this->removeDirectory($this->tmpDir);
    }

    public function testMaxRequestBodyBytesFollowsTheConfiguredCap(): void
    {
        $default = $this->makeServer(null);
        self::assertSame(268435456, $default->maxRequestBodyBytes(), 'unset max_pack_bytes means the 256 MiB default');

        $tight = $this->makeServer(4096);
        self::assertSame(4096, $tight->maxRequestBodyBytes(), 'the knob must be the same number the socket layer reads');
    }

    public function testIdleDeadlineSweepsASilentSeat(): void
    {
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        $listener = new HttpListener(
            $this->makeServer(null),
            '127.0.0.1',
            0,
            $loop,
            idleTimeoutSeconds: 0.2,
            absoluteDeadlineSeconds: 60.0
        );
        $listener->start();
        try {
            $client = $this->connect($listener);
            // Silence: the seat is held but nothing is ever sent.
            $loop->addTimer(1.5, static fn () => $loop->stop());
            $loop->run();

            self::assertSame(0, $listener->activeConnectionCount(), 'the sweep must have reaped the silent seat');
            self::assertTrue($this->peerClosed($client), 'a reaped client sees EOF on its side');
        } finally {
            $listener->stop();
        }
    }

    public function testAbsoluteDeadlineReapsADribblingClient(): void
    {
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        $listener = new HttpListener(
            $this->makeServer(null),
            '127.0.0.1',
            0,
            $loop,
            idleTimeoutSeconds: 60.0,
            absoluteDeadlineSeconds: 0.4
        );
        $listener->start();
        try {
            $client = $this->connect($listener);
            \stream_set_blocking($client, false);
            $closed = false;
            // Every tick dribbles one byte: idle activity keeps resetting,
            // so ONLY the absolute connection-age deadline can end this.
            $dribble = $loop->addPeriodicTimer(0.1, static function () use ($loop, $client, &$closed): void {
                @\fwrite($client, 'G');
                if (\feof($client)) {
                    $closed = true;
                    $loop->stop();
                }
            });
            $safety = $loop->addTimer(4.0, static fn () => $loop->stop());
            $loop->run();
            $loop->cancelTimer($dribble);
            $loop->cancelTimer($safety);

            self::assertTrue($closed, 'the absolute deadline must close a trickle that never completes');
            self::assertSame(0, $listener->activeConnectionCount());
        } finally {
            $listener->stop();
        }
    }

    public function testCapacityRefusesTheNewcomerAndKeepsEstablishedSeats(): void
    {
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        $listener = new HttpListener(
            $this->makeServer(null),
            '127.0.0.1',
            0,
            $loop,
            idleTimeoutSeconds: 60.0,
            absoluteDeadlineSeconds: 60.0,
            maxClients: 1
        );
        $listener->start();
        try {
            $held = $this->connect($listener);
            $loop->addTimer(0.3, static fn () => $loop->stop());
            $loop->run();
            self::assertSame(1, $listener->activeConnectionCount(), 'the established seat occupies the single slot');

            $newcomer = $this->connect($listener);
            $loop->addTimer(0.3, static fn () => $loop->stop());
            $loop->run();

            self::assertTrue($this->peerClosed($newcomer), 'the newcomer is refused, never queued');
            self::assertSame(1, $listener->activeConnectionCount(), 'refusal frees no established seat and adds none');
            @\fclose($held);
        } finally {
            $listener->stop();
        }
    }

    public function testContentLengthOverTheRouterCapIsRefusedAtTheSocket(): void
    {
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        // cap 16: pre-M-18 the listener policed its own 32 MiB constant and
        // would have waited (then 404'd) instead of refusing this header.
        $listener = new HttpListener(
            $this->makeServer(16),
            '127.0.0.1',
            0,
            $loop,
            idleTimeoutSeconds: 60.0,
            absoluteDeadlineSeconds: 60.0
        );
        $listener->start();
        try {
            $client = $this->connect($listener);
            \fwrite($client, "POST /ghost.git/git-upload-pack HTTP/1.1\r\nHost: t\r\nContent-Length: 64\r\n\r\n");
            $response = $this->readResponse($loop, $client, 1.5);

            self::assertStringStartsWith('HTTP/1.1 413', $response, 'over-cap declarations are refused before a byte of body is awaited');
            self::assertSame(0, $listener->activeConnectionCount());
        } finally {
            $listener->stop();
        }
    }

    public function testTinyChunkPaddingIsRefusedByTheDecodedCap(): void
    {
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        $listener = new HttpListener(
            $this->makeServer(16),
            '127.0.0.1',
            0,
            $loop,
            idleTimeoutSeconds: 60.0,
            absoluteDeadlineSeconds: 60.0
        );
        $listener->start();
        try {
            $body = '';
            for ($i = 0; $i < 20; ++$i) {
                $body .= "1\r\na\r\n"; // 20 decoded bytes against a 16-byte cap
            }
            $body .= "0\r\n\r\n";
            $client = $this->connect($listener);
            \fwrite($client, "POST /ghost.git/git-upload-pack HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n" . $body);
            $response = $this->readResponse($loop, $client, 1.5);

            self::assertStringStartsWith('HTTP/1.1 413', $response);
        } finally {
            $listener->stop();
        }
    }

    public function testChunkScannerServesACompleteRequestExactlyOnce(): void
    {
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        $listener = new HttpListener(
            $this->makeServer(null),
            '127.0.0.1',
            0,
            $loop,
            idleTimeoutSeconds: 60.0,
            absoluteDeadlineSeconds: 60.0
        );
        $listener->start();
        try {
            $body = '';
            for ($i = 0; $i < 20; ++$i) {
                $body .= "1\r\na\r\n";
            }
            $body .= "0\r\n\r\n";
            $client = $this->connect($listener);
            \fwrite($client, "POST /ghost.git/git-upload-pack HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n" . $body);
            $response = $this->readResponse($loop, $client, 1.5);

            self::assertStringStartsWith('HTTP/1.1 404', $response, 'an under-cap chunked body reaches the router once');
            self::assertStringContainsString('Repository not found', $response);
            self::assertSame(1, \substr_count($response, 'HTTP/1.1'), 'no duplicate dispatch on one completed buffer');
        } finally {
            $listener->stop();
        }
    }

    public function testConflictingContentLengthClaimsAreRefusedAtTheSocket(): void
    {
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        $listener = new HttpListener(
            $this->makeServer(null),
            '127.0.0.1',
            0,
            $loop,
            idleTimeoutSeconds: 60.0,
            absoluteDeadlineSeconds: 60.0
        );
        $listener->start();
        try {
            $client = $this->connect($listener);
            \fwrite($client, "POST /ghost.git/git-upload-pack HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\nContent-Length: 6\r\n\r\nhello!");
            $response = $this->readResponse($loop, $client, 1.5);

            self::assertStringStartsWith('HTTP/1.1 400', $response, 'two disagreeing body-length claims cannot be framed');
            self::assertStringNotContainsString('Repository not found', $response, 'the request never reaches the router');
        } finally {
            $listener->stop();
        }
    }

    public function testAgreeingContentLengthDuplicatesStillFrameTheRequest(): void
    {
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        $listener = new HttpListener(
            $this->makeServer(null),
            '127.0.0.1',
            0,
            $loop,
            idleTimeoutSeconds: 60.0,
            absoluteDeadlineSeconds: 60.0
        );
        $listener->start();
        try {
            $client = $this->connect($listener);
            \fwrite($client, "POST /ghost.git/git-upload-pack HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\nContent-Length: 5\r\n\r\nhello");
            $response = $this->readResponse($loop, $client, 1.5);

            self::assertStringStartsWith('HTTP/1.1 404', $response, 'identical duplicates are benign: the request frames and dispatches');
            self::assertStringContainsString('Repository not found', $response);
        } finally {
            $listener->stop();
        }
    }

    public function testChunkedFramingNeedsTheExactTransferEncodingToken(): void
    {
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        $listener = new HttpListener(
            $this->makeServer(null),
            '127.0.0.1',
            0,
            $loop,
            idleTimeoutSeconds: 60.0,
            absoluteDeadlineSeconds: 60.0
        );
        $listener->start();
        try {
            // A substring-matched 'chunkedx' would arm the chunk scanner and
            // this plain Content-Length body would sit unfilled until the
            // deadlines reaped it; exact-token matching frames it normally.
            $client = $this->connect($listener);
            \fwrite($client, "POST /ghost.git/git-upload-pack HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunkedx\r\nContent-Length: 5\r\n\r\nhello");
            $response = $this->readResponse($loop, $client, 1.5);

            self::assertStringStartsWith('HTTP/1.1 404', $response, 'a header VALUE merely containing the token does not enable chunked framing');
            self::assertStringContainsString('Repository not found', $response);
        } finally {
            $listener->stop();
        }
    }

    public function testPeerAddressHandsOutIpv6PeersWithoutBrackets(): void
    {
        $peer = \Closure::bind(static fn ($sock): ?string => HttpListener::peerAddress($sock), null, HttpListener::class);

        $v6Server = @\stream_socket_server('tcp://[::1]:0', $errno, $errstr);
        if ($v6Server === false) {
            self::markTestSkipped('no IPv6 loopback on this host: ' . $errstr);
        }
        $this->sockets[] = $v6Server;
        $bound = (string) \stream_socket_get_name($v6Server, false);
        $v6Client = @\stream_socket_client('tcp://' . $bound, $errno, $errstr, 2.0);
        self::assertIsResource($v6Client, 'v6 client connect: ' . $errstr);
        $this->sockets[] = $v6Client;
        $v6Peer = @\stream_socket_accept($v6Server, 2.0);
        self::assertIsResource($v6Peer, 'no v6 connection arrived at the server');
        $this->sockets[] = $v6Peer;
        // A bracketed '[::1]' never survives inet_pton() downstream, so
        // the accessor must publish the bare literal.
        self::assertSame('::1', $peer($v6Peer), 'the v6 peer is handed out bare, port stripped');

        $v4Server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($v4Server, 'v4 server: ' . $errstr);
        $this->sockets[] = $v4Server;
        $bound = (string) \stream_socket_get_name($v4Server, false);
        $v4Client = @\stream_socket_client('tcp://' . $bound, $errno, $errstr, 2.0);
        self::assertIsResource($v4Client, 'v4 client connect: ' . $errstr);
        $this->sockets[] = $v4Client;
        $v4Peer = @\stream_socket_accept($v4Server, 2.0);
        self::assertIsResource($v4Peer, 'no v4 connection arrived at the server');
        $this->sockets[] = $v4Peer;
        self::assertSame('127.0.0.1', $peer($v4Peer), 'the v4 shape keeps its port-stripping behavior');
    }

    public function testIncrementalScannerAgreesWithTheWholeBufferDecoder(): void
    {
        // Closure bound to the class scope keeps the by-reference $scan
        // cursor alive across the call (ReflectionMethod::invoke drops it).
        $advance = \Closure::bind(static function (string $raw, array &$scan): void {
            HttpListener::advanceChunkScan($raw, $scan);
        }, null, HttpListener::class);
        $decode = \Closure::bind(static function (string $raw): ?string {
            return HttpListener::decodeChunked($raw);
        }, null, HttpListener::class);

        // [raw, expectedDone, expectedDecoded, expectedPayload]
        $fixtures = [
            ["5\r\nhello\r\n0\r\n\r\n", true, 5, 'hello'],
            ["5\r\nhello\r\n5\r\nworld\r\n0\r\n\r\n", true, 10, 'helloworld'],
            // The scan cursor books a chunk's bytes as promised the moment
            // its size line completes, so a truncated body still counts 5.
            ["5\r\nhel", false, 5, null],
            ["5", false, 0, null],
            ["5\r\nhello\r\n0\r\n", false, 5, null],
            // Parity on tolerance: a garbage size line hexdec()s to 0 — the
            // terminator — in BOTH implementations.
            ["xyz\r\n0\r\n\r\n", true, 0, ''],
        ];

        foreach ($fixtures as [$raw, $expectedDone, $expectedDecoded, $expectedPayload]) {
            $scan = ['pos' => 0, 'mode' => 'size', 'want' => 0, 'decoded' => 0, 'done' => false];
            $advance($raw, $scan);
            $decoded = $decode($raw);

            self::assertSame($expectedDone, $scan['done'], "scanner done-state wrong for {$raw}");
            self::assertSame($expectedDecoded, $scan['decoded'], "scanner decoded count wrong for {$raw}");
            self::assertSame(
                $expectedDone,
                $decoded !== null,
                "scanner and whole-buffer decoder disagree on completeness for {$raw}"
            );
            if ($expectedDone) {
                self::assertSame($expectedPayload, $decoded, "decoded payload wrong for {$raw}");
            }
        }
    }

    public function testLfsDownloadRefusesAnOversizeObjectBeforeReadingIt(): void
    {
        $payload = \str_repeat('X', 64);
        $oid = \hash('sha256', $payload);
        $server = $this->makeServer(16);
        (new LocalStorageBackend($this->lastConfig->lfsPath()))->write(
            $oid,
            $this->memoryStream($payload)
        );

        $server->registerRepo(Repo::new('dlrepo', $this->tmpDir . '/dlrepo.git')->withPublic(true));
        AccessControl::instance()->setAllowAnonymousRead(true);

        $response = $server->handleRequest('GET', '/dlrepo/info/lfs/objects/' . $oid, '', [], '');
        self::assertSame(413, $response['status'], 'a stored object past the cap must never be materialised');
        self::assertStringContainsString('Object too large', $response['body']);

        // Positive arm: the same object under a generous cap streams fine —
        // the guard must not break legitimate sizes.
        $roomy = $this->makeServer(1024, dirSuffix: 'roomy');
        (new LocalStorageBackend($this->lastConfig->lfsPath()))->write($oid, $this->memoryStream($payload));
        $roomy->registerRepo(Repo::new('dlrepo', $this->tmpDir . '/roomy.git')->withPublic(true));
        $ok = $roomy->handleRequest('GET', '/dlrepo/info/lfs/objects/' . $oid, '', [], '');
        self::assertSame(200, $ok['status']);
        self::assertSame($payload, $ok['body']);
    }

    /** @return resource */
    private function memoryStream(string $bytes)
    {
        $handle = \fopen('php://temp', 'r+b');
        self::assertIsResource($handle);
        \fwrite($handle, $bytes);
        \rewind($handle);

        return $handle;
    }

    private function makeServer(?int $cap, string $dirSuffix = 'shared'): Server
    {
        $dir = $dirSuffix === 'shared' ? $this->tmpDir : $this->tmpDir . '/' . $dirSuffix;
        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }
        $yaml = "name: Hardening\n";
        if ($cap !== null) {
            $yaml .= "http:\n    max_pack_bytes: {$cap}\n";
        }
        \file_put_contents($dir . '/config.yaml', $yaml);

        $this->lastConfig = Config::load($dir . '/config.yaml');

        return new Server($this->lastConfig);
    }

    /** @return resource */
    private function connect(HttpListener $listener)
    {
        $address = $listener->listenAddress();
        self::assertNotNull($address, 'listener never bound');
        $socket = \stream_socket_client('tcp://' . $address, $errno, $errstr, 2.0);
        self::assertIsResource($socket, "client connect failed: {$errstr}");
        $this->sockets[] = $socket;

        return $socket;
    }

    /** @param resource $client */
    private function readResponse(StreamSelectLoop $loop, $client, float $budget): string
    {
        \stream_set_blocking($client, false);
        $response = '';
        $watch = $loop->addPeriodicTimer(0.02, static function () use (&$response, $client): void {
            $chunk = @\fread($client, 65536);
            if ($chunk !== false && $chunk !== '') {
                $response .= $chunk;
            }
        });
        $loop->addTimer($budget, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($watch);

        return $response;
    }

    /** @param resource $client */
    private function peerClosed($client): bool
    {
        \stream_set_blocking($client, false);
        @\fread($client, 1);

        return \feof($client);
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }
}
