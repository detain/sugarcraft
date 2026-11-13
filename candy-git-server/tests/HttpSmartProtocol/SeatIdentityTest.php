<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\HttpSmartProtocol\HttpListener;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\StatsServer;

/**
 * S13 / finding 112 — seat callbacks must match the CONNECTION, not the
 * number.
 *
 * Both transports index their client seats by `(int) $stream`, and PHP
 * recycles resource ids after a close: a readiness callback registered
 * for a stream that was closed under it looks a seat up by a number a
 * NEWER connection may have inherited. Pre-cure the listener would fread
 * the dead handle, take the EOF branch, and closeClient() — which acts
 * THROUGH the seat — tearing down the innocent socket; the stats face
 * would fclose the dead handle and then unset the newer connection's
 * seat, dropping a live request on the floor. The identity cure — the
 * seat's stream must BE the resource the callback holds — makes every
 * stale callback inert.
 *
 * The pins inject a seat keyed by a stale handle's id while the seat's
 * stream is a DIFFERENT live resource (the exact state genuine id reuse
 * produces, without racing the allocator), and assert the stale
 * callback cannot see, close, or unset it. Normal-lifecycle coverage of
 * the owned paths stays with SmartHttpCloneTest and StatsServerTest.
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\HttpListener
 * @covers \SugarCraft\GitServer\StatsServer
 */
final class SeatIdentityTest extends TestCase
{
    private string $tmpDir = '';

    /** @var list<resource> */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-seat-id-' . \uniqid();
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
        AccessControl::instance()->setAllowAnonymousRead(false);
        foreach (\glob($this->tmpDir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->tmpDir);
    }

    public function testListenerPumpIgnoresAStaleCallbackAndKeepsTheInheritedSeat(): void
    {
        [$stale, $seatKey] = $this->closedHandle();
        $innocent = $this->livePair($peer);
        $listener = $this->makeListener();
        $this->injectSeats($listener, [$seatKey => $this->seat($innocent)]);

        $this->assertFalse(
            $this->pump($listener, $stale),
            'a stale callback must report finished WITHOUT acting — the old shape fread the dead handle, took the EOF branch, and closed through the seat'
        );

        $seats = $this->readSeats($listener);
        $this->assertArrayHasKey($seatKey, $seats, 'the stale callback tore down the connection that inherited its id');
        $this->assertSame($innocent, $seats[$seatKey]['stream']);
        $this->assertTrue(\is_resource($innocent), 'the innocent stream was actually closed under the seat');
        $this->assertSame(5, @\fwrite($peer, 'alive'), 'the innocent connection stopped accepting writes');
        $this->assertSame('', $seats[$seatKey]['buffer'], 'the stale callback must not touch the seat buffer either');
    }

    public function testListenerCloseRefusesToActThroughAMismatchedSeat(): void
    {
        [$stale, $seatKey] = $this->closedHandle();
        $innocent = $this->livePair($peer);
        $listener = $this->makeListener();
        $this->injectSeats($listener, [$seatKey => $this->seat($innocent)]);

        $close = new \ReflectionMethod(HttpListener::class, 'closeClient');
        $close->setAccessible(true);
        $close->invoke($listener, $stale);

        $seats = $this->readSeats($listener);
        $this->assertArrayHasKey($seatKey, $seats, 'closeClient closed a seat its argument does not own');
        $this->assertTrue(\is_resource($innocent), 'the innocent stream was closed through someone else\'s id');

        // Unknown ids stay inert too (the pre-112 isset path):
        $close->invoke($listener, $peer);
        $this->assertArrayHasKey($seatKey, $this->readSeats($listener));
    }

    public function testStatsCallbackIgnoresAStaleStreamAndKeepsTheInheritedSeat(): void
    {
        [$stale, $seatKey] = $this->closedHandle();
        $innocent = $this->livePair($peer);
        $stats = new StatsServer(Config::fromDefaults());
        $this->injectSeats($stats, [$seatKey => $this->seat($innocent)]);

        $readable = new \ReflectionMethod(StatsServer::class, 'onClientReadable');
        $readable->setAccessible(true);
        $readable->invoke($stats, $stale);

        $seats = $this->readSeats($stats);
        $this->assertArrayHasKey($seatKey, $seats, 'the stale stats callback dropped the connection that inherited its id');
        $this->assertSame($innocent, $seats[$seatKey]['stream']);
        $this->assertTrue(\is_resource($innocent), 'the stale stats callback closed the innocent stream');
    }

    // -------------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------------

    /**
     * A closed-but-still-castable handle: PHP keeps (int) on a closed
     * resource returning its original id (verified), which is precisely
     * what a recycled-number callback carries.
     *
     * @return array{0: resource, 1: int}
     */
    private function closedHandle(): array
    {
        [$reader, $writer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsResource($writer);
        $id = (int) $writer;
        @\fclose($reader);
        @\fclose($writer);

        return [$writer, $id];
    }

    /**
     * @param resource|null $peer out-parameter: the other end of the pair
     * @return resource
     */
    private function livePair(&$peer)
    {
        [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsResource($a);
        $this->assertIsResource($b);
        \stream_set_blocking($a, false);
        \stream_set_blocking($b, false);
        $peer = $a;
        $this->sockets[] = $a;

        return $b;
    }

    /** @param resource $stream @return array<string, mixed> */
    private function seat($stream): array
    {
        $now = \microtime(true);

        return [
            'stream'        => $stream,
            'buffer'        => '',
            'connected_at'  => $now,
            'last_activity' => $now,
            'scan'          => ['pos' => 0, 'mode' => 'size', 'want' => 0, 'decoded' => 0, 'done' => false],
        ];
    }

    private function makeListener(): HttpListener
    {
        \file_put_contents($this->tmpDir . '/config.yaml', "name: SeatId\n");
        $router = new Server(Config::load($this->tmpDir . '/config.yaml'));

        return new HttpListener($router, '127.0.0.1', 0);
    }

    /** @param array<int, array<string, mixed>> $seats */
    private function injectSeats(object $target, array $seats): void
    {
        $property = (new \ReflectionClass($target))->getProperty('clients');
        $property->setAccessible(true);
        $property->setValue($target, $seats);
    }

    /** @return array<int, array<string, mixed>> */
    private function readSeats(object $target): array
    {
        $property = (new \ReflectionClass($target))->getProperty('clients');
        $property->setAccessible(true);

        return $property->getValue($target);
    }

    /** @param resource $client */
    private function pump(HttpListener $listener, $client): bool
    {
        $method = new \ReflectionMethod(HttpListener::class, 'pumpClient');
        $method->setAccessible(true);

        return (bool) $method->invoke($listener, $client);
    }
}
