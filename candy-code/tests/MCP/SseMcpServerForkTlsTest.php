<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\MCP\SseMcpServer;

/**
 * C-MCP-6.a: a forked child re-establishing its SSE GET must RETAIN the
 * socket it inherited from the parent, never close it.
 *
 * The fork is simulated through the class's own `pidProvider` test seam —
 * the same convention SseMcpServerTest's fork tests use, and the reason the
 * seam exists: the defect is about the ownership stamp carried beside the
 * descriptor, which a pid flip reproduces exactly. The streams are plain
 * socket-pair fixtures (a 200 `text/event-stream` head is pre-buffered so
 * the GET gate passes), not TLS. The harm the retention law prevents —
 * a stream destructor running SSL_shutdown, putting `close_notify` on the
 * parent's still-live TLS session — is PLAUSIBLE over this fixture, not
 * observable: an `fclose` on a plain TCP dup only decrements the kernel
 * refcount. What IS observable, and what these tests pin, is the proximate
 * cause: whether `fclose` runs on the inherited handle at all. The parent's
 * fixture-side end of the pair reports EOF the instant the transport closes
 * its client end — retained handles never get there.
 */
final class SseMcpServerForkTlsTest extends TestCase
{
    /** @var list<resource> fixture-side ends, one per opened stream, in order */
    private array $fixtureEnds = [];

    private int $pid = 100;

    protected function tearDown(): void
    {
        foreach ($this->fixtureEnds as $end) {
            if (\is_resource($end)) {
                fclose($end);
            }
        }
        $this->fixtureEnds = [];
    }

    public function testAForkedChildReplacesTheInheritedStreamWithoutClosingIt(): void
    {
        $server = $this->server();
        $open = $this->openStream();

        // The parent opens its persistent GET.
        $open->invoke($server);
        $parentStream = $this->fixtureEnds[0];
        self::assertFalse($this->sawEof($parentStream), 'a fresh stream must not read as closed');

        // Fork: this process is now the CHILD, still holding the parent's
        // handle. Its first establish replaces $this->socket.
        $this->pid = 200;
        $open->invoke($server);

        self::assertCount(2, $this->fixtureEnds, 'the child opened its own stream');
        self::assertFalse(
            $this->sawEof($parentStream),
            'the child CLOSED the socket it inherited instead of retaining it — over TLS that'
            . ' destructor is SSL_shutdown, a close_notify fired onto the parent\'s still-live'
            . ' session (C-MCP-6.a)',
        );
    }

    public function testTheChildStillClosesAStreamItOpenedItself(): void
    {
        // Retention is scoped to inherited handles; it must not degrade into
        // a blanket leak. A stream the child opened (opener pid == this pid)
        // is still closed when its own session is re-established.
        $server = $this->server();
        $open = $this->openStream();

        $open->invoke($server);            // parent's stream (pid 100)
        $this->pid = 200;
        $open->invoke($server);            // child's OWN stream — parent's is retained
        $childStream = $this->fixtureEnds[1];
        $open->invoke($server);            // child replaces its own again

        self::assertTrue(
            $this->sawEof($childStream),
            'the child\'s own replaced stream was retained instead of closed — the retain rule'
            . ' must apply ONLY to handles this process did not open',
        );
    }

    public function testCloseStreamOnAnInheritedHandleRetainsItToo(): void
    {
        // Defense in depth: the same ownership question gates closeStream,
        // so a child that stops before ever opening its own stream cannot
        // close_notify the parent's session either.
        $server = $this->server();
        $open = $this->openStream();
        $close = new ReflectionMethod(SseMcpServer::class, 'closeStream');
        $close->setAccessible(true);

        $open->invoke($server);
        $parentStream = $this->fixtureEnds[0];

        $this->pid = 200;                  // forked child, no stream of its own yet
        $close->invoke($server);

        self::assertFalse(
            $this->sawEof($parentStream),
            'closeStream in a child that never opened the held socket closed it (C-MCP-6.a)',
        );
    }

    // ------------------------------------------------------------------ harness

    private function openStream(): ReflectionMethod
    {
        $open = new ReflectionMethod(SseMcpServer::class, 'openStream');
        $open->setAccessible(true);

        return $open;
    }

    /**
     * Drain whatever the GET request bytes left buffered, then report whether
     * the transport's end of this pair has been closed.
     */
    private function sawEof($end): bool
    {
        self::assertIsResource($end);
        stream_set_blocking($end, false);
        while (true) {
            $chunk = fread($end, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
        }

        return feof($end);
    }

    private function server(): SseMcpServer
    {
        return new SseMcpServer(
            name: 'sse-fork-tls',
            url: 'http://127.0.0.1:9/sse',
            headers: [],
            httpClient: new Client(),
            pidProvider: fn (): int => $this->pid,
            // Plain socket-pair stub: the client end goes to the transport
            // with the response head pre-buffered, the fixture end stays
            // here as the EOF witness. The transport is the sole reference
            // to the client end, so replacing it WITHOUT retaining runs the
            // stream destructor immediately — that is the whole pin.
            connector: function (string $scheme, string $host, int $port, float $timeoutSeconds) {
                unset($scheme, $host, $port, $timeoutSeconds);
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_SOCK_STREAM);
                self::assertIsArray($pair);
                [$client, $fixture] = $pair;
                stream_set_read_buffer($client, 0);
                fwrite($fixture, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n\r\n");
                $this->fixtureEnds[] = $fixture;

                return $client;
            },
            sleeper: static function (float $seconds): void {
                // openStream alone never sleeps; nothing to catch.
            },
        );
    }
}
