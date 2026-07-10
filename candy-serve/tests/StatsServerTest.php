<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Serve\Config;
use SugarCraft\Serve\Stats;
use SugarCraft\Serve\StatsServer;

/**
 * HTTP stats endpoint on stats.listen_addr — plan item 7.6.
 *
 * Drives real TCP sockets through a StreamSelectLoop with a safety
 * timer, the same pattern as GitDaemonAsyncTest.
 *
 * @covers \SugarCraft\Serve\StatsServer
 */
final class StatsServerTest extends TestCase
{
    private string $tmpDir;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/stats-server-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);

        // Port 0 = kernel-assigned ephemeral port, read back via listenAddress().
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: StatsTest\nstats:\n  listen_addr: \"127.0.0.1:0\"\n"
        );
        $this->config = Config::load($this->tmpDir . '/config.yaml');
    }

    protected function tearDown(): void
    {
        @\unlink($this->tmpDir . '/config.yaml');
        @\rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** Perform one HTTP request against a running StatsServer over the loop. */
    private function httpRequest(StatsServer $server, StreamSelectLoop $loop, string $requestHead): string
    {
        $addr = $server->listenAddress();
        $this->assertNotNull($addr);

        $errno = 0;
        $errstr = '';
        $client = \stream_socket_client("tcp://{$addr}", $errno, $errstr, 2.0);
        $this->assertIsResource($client, "connect failed: {$errstr}");
        \stream_set_blocking($client, false);
        \fwrite($client, $requestHead);

        $response = '';
        $loop->addReadStream($client, static function () use (&$response, $client, $loop): void {
            $chunk = \fread($client, 65536);
            if ($chunk !== false && $chunk !== '') {
                $response .= $chunk;

                return;
            }
            if ($chunk === false || \feof($client)) {
                $loop->removeReadStream($client);
                $loop->stop();
            }
        });

        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($safety);
        \fclose($client);

        return $response;
    }

    // -------------------------------------------------------------------------
    // Serving counters
    // -------------------------------------------------------------------------

    public function testServesCountersAsJson(): void
    {
        $stats = new Stats();
        $stats->recordConnection();
        $stats->recordPackDownload();
        $stats->recordPackDownload();
        $stats->recordLfsBatch();

        $server = new StatsServer($this->config, $stats);
        $loop = new StreamSelectLoop();
        $server->start($loop);
        $this->assertTrue($server->isRunning());

        $response = $this->httpRequest($server, $loop, "GET /stats HTTP/1.1\r\nHost: x\r\n\r\n");

        $server->stop();

        $this->assertStringStartsWith('HTTP/1.1 200 OK', $response);
        $this->assertStringContainsString('Content-Type: application/json', $response);

        $body = \substr($response, \strpos($response, "\r\n\r\n") + 4);
        $decoded = \json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['connections']);
        $this->assertSame(2, $decoded['pack_downloads']);
        $this->assertSame(0, $decoded['pack_uploads']);
        $this->assertSame(1, $decoded['lfs_batch_requests']);
        $this->assertArrayHasKey('uptime_seconds', $decoded);
    }

    public function testRootPathServesSamePayload(): void
    {
        $server = new StatsServer($this->config, new Stats());
        $loop = new StreamSelectLoop();
        $server->start($loop);

        $response = $this->httpRequest($server, $loop, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");

        $server->stop();

        $this->assertStringStartsWith('HTTP/1.1 200 OK', $response);
        $this->assertStringContainsString('"connections":0', $response);
    }

    public function testUnknownPathReturns404(): void
    {
        $server = new StatsServer($this->config, new Stats());
        $loop = new StreamSelectLoop();
        $server->start($loop);

        $response = $this->httpRequest($server, $loop, "GET /nope HTTP/1.1\r\nHost: x\r\n\r\n");

        $server->stop();

        $this->assertStringStartsWith('HTTP/1.1 404 Not Found', $response);
    }

    public function testNonGetReturns405(): void
    {
        $server = new StatsServer($this->config, new Stats());
        $loop = new StreamSelectLoop();
        $server->start($loop);

        $response = $this->httpRequest($server, $loop, "POST /stats HTTP/1.1\r\nHost: x\r\nContent-Length: 0\r\n\r\n");

        $server->stop();

        $this->assertStringStartsWith('HTTP/1.1 405', $response);
    }

    // -------------------------------------------------------------------------
    // Bind-address safety — unauthenticated stats must default to loopback,
    // and a wider exposure must be an explicit, deliberate config.
    // -------------------------------------------------------------------------

    public function testOmittedHostDefaultsToLoopback(): void
    {
        // ":0" mirrors the shipped default ":23233" (host omitted) but on an
        // ephemeral port — it must bind loopback, never the wildcard.
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: LoopbackTest\nstats:\n  listen_addr: \":0\"\n"
        );
        $config = Config::load($this->tmpDir . '/config.yaml');

        $server = new StatsServer($config, new Stats());
        $loop = new StreamSelectLoop();
        $server->start($loop);

        try {
            $addr = $server->listenAddress();
            $this->assertNotNull($addr);
            $this->assertStringStartsWith('127.0.0.1:', $addr);
        } finally {
            $server->stop();
        }
    }

    public function testWildcardBindRequiresExplicitHost(): void
    {
        // A network-wide bind is honored only because the operator wrote
        // "0.0.0.0" explicitly — it is never the default.
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: WildcardTest\nstats:\n  listen_addr: \"0.0.0.0:0\"\n"
        );
        $config = Config::load($this->tmpDir . '/config.yaml');

        $server = new StatsServer($config, new Stats());
        $loop = new StreamSelectLoop();
        $server->start($loop);

        try {
            $addr = $server->listenAddress();
            $this->assertNotNull($addr);
            $this->assertStringStartsWith('0.0.0.0:', $addr);
        } finally {
            $server->stop();
        }
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function testStopDeregistersAllLoopResources(): void
    {
        $server = new StatsServer($this->config, new Stats());
        $loop = new StreamSelectLoop();
        $server->start($loop);
        $this->assertNotNull($server->listenAddress());

        $server->stop();

        $this->assertFalse($server->isRunning());
        $this->assertNull($server->listenAddress());

        $readStreams = (new \ReflectionProperty(StreamSelectLoop::class, 'readStreams'))->getValue($loop);
        $this->assertSame([], $readStreams);
    }

    public function testStopIsIdempotent(): void
    {
        $server = new StatsServer($this->config, new Stats());
        $server->stop();

        $this->assertFalse($server->isRunning());
    }

    public function testStartWhileRunningThrows(): void
    {
        $server = new StatsServer($this->config, new Stats());
        $loop = new StreamSelectLoop();
        $server->start($loop);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('already running');
            $server->start($loop);
        } finally {
            $server->stop();
        }
    }

    public function testStartThrowsWhenPortUnavailable(): void
    {
        $server1 = new StatsServer($this->config, new Stats());
        $loop = new StreamSelectLoop();
        $server1->start($loop);
        $addr = $server1->listenAddress();
        $this->assertNotNull($addr);

        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "stats:\n  listen_addr: \"{$addr}\"\n"
        );
        $server2 = new StatsServer(Config::load($this->tmpDir . '/config.yaml'), new Stats());

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to create stats server socket');
            $server2->start($loop);
        } finally {
            $server1->stop();
        }
    }
}
