<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Support\HttpWire;

/**
 * Pins for the shared transport helper (S13, findings 033/143): the write
 * loop that HttpListener used to own privately — hardened against the
 * one-shot fwrite truncation and later against unbounded trickle holds —
 * now lives here and serves BOTH socket faces, StatsServer included (it
 * had shipped the original defective shape the whole time).
 *
 * The behavioral legs moved verbatim from HttpListenerWriteTest: byte
 * fidelity on a peer that keeps up, multi-slice fidelity, zero-progress
 * stall bounds, and the absolute ceiling that trickle progress may not
 * extend.
 *
 * @covers \SugarCraft\GitServer\Support\HttpWire
 */
final class HttpWireTest extends TestCase
{
    public function testTheSharedWireCarriesOneHeaderCapAndOneWriteQuantum(): void
    {
        // The two transports' private duplicates are gone; the cap on an
        // inbound head and the outbound feed quantum are single-sourced
        // numbers that agree BY CONVENTION (64 KiB).
        self::assertSame(65536, HttpWire::MAX_HEADER_BYTES);
        self::assertSame(65536, HttpWire::CHUNK_BYTES);
        self::assertSame(10.0, HttpWire::STALL_SECONDS);
        self::assertSame(300.0, HttpWire::ABSOLUTE_SECONDS);

        $listenerSource = (string) \file_get_contents(__DIR__ . '/../../src/HttpSmartProtocol/HttpListener.php');
        $statsSource = (string) \file_get_contents(__DIR__ . '/../../src/StatsServer.php');
        self::assertStringContainsString('private const MAX_HEADER_BYTES = HttpWire::MAX_HEADER_BYTES;', $listenerSource, 'listener re-forked its own header cap');
        self::assertStringContainsString('private const MAX_HEADER_BYTES = HttpWire::MAX_HEADER_BYTES;', $statsSource, 'stats server re-forked its own header cap');
    }

    public function testWriteAllDeliversTheWholePayloadWhenThePeerKeepsUp(): void
    {
        [$reader, $writer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsResource($reader);
        self::assertIsResource($writer);
        try {
            \stream_set_blocking($writer, false);
            $payload = \str_repeat('abcdefgh', 512); // 4 KiB — fits one window

            $written = HttpWire::writeAll($writer, $payload, 5.0);

            self::assertSame(\strlen($payload), $written);
            @\fclose($writer); // half-close so the blocking drain below sees EOF
            self::assertSame($payload, (string) \stream_get_contents($reader));
        } finally {
            @\fclose($reader);
            if (\is_resource($writer)) {
                @\fclose($writer);
            }
        }
    }

    public function testWriteAllDeliversLargePayloadsSliceBySliceWithByteFidelity(): void
    {
        // S02: the write loop feeds one 64 KiB chunk per fwrite instead of
        // re-slicing the whole remainder per attempt. The remainder must
        // still land verbatim — no truncation, no reordering, no
        // duplication across slice boundaries.
        [$reader, $writer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsResource($reader);
        self::assertIsResource($writer);
        try {
            \stream_set_blocking($writer, false);
            $payload = \random_bytes(5 * 65536 + 1234); // 4+ slices, non-aligned tail

            $written = HttpWire::writeAll($writer, $payload, 5.0);

            self::assertSame(\strlen($payload), $written);
            @\fclose($writer); // half-close so the blocking drain below sees EOF
            self::assertSame($payload, (string) \stream_get_contents($reader));
        } finally {
            @\fclose($reader);
            if (\is_resource($writer)) {
                @\fclose($writer);
            }
        }
    }

    public function testWriteAllStallsOnANonConsumingPeerAndReportsPartialProgress(): void
    {
        // Deterministic wedge without touching kernel knobs: the reader end
        // of this pipe lives in a sleeping child that never drains, and the
        // kernel caps a pipe at 64 KiB — a 1 MiB body cannot be handed off.
        // writeAll must spin in writability ticks until the stall grace
        // expires and report exactly how far it got — never return
        // "delivered" on a body truncated to one window the way the old
        // one-shot @fwrite did.
        $proc = \proc_open(['sleep', '30'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $writer = $pipes[0];
        \stream_set_blocking($writer, false);

        try {
            $payload = \random_bytes(1024 * 1024);
            $started = \microtime(true);
            $written = HttpWire::writeAll($writer, $payload, 0.2);
            $elapsed = \microtime(true) - $started;

            self::assertGreaterThan(0, $written, 'write loop must have handed the pipe its first windows');
            self::assertLessThan(\strlen($payload), $written, 'a never-read pipe cannot absorb the whole payload');
            self::assertGreaterThanOrEqual(0.2, $elapsed, 'stall grace is the deadline — the loop had to wait through it');
        } finally {
            @\fclose($writer);
            @\fclose($pipes[1]);
            @\fclose($pipes[2]);
            @\proc_terminate($proc);
            @\proc_close($proc);
        }
    }

    public function testWriteAllStopsATricklingPeerAtTheAbsoluteCeiling(): void
    {
        // S01: the progress re-arm bounds the ZERO-progress grace only — a
        // peer that keeps the buffers fed used to extend the write forever.
        // The 64 KiB pipe absorbs the first window (progress), then refuses
        // more while a sleeping child holds the read end: the 10 s stall
        // grace is re-armed by that first window, so ONLY the absolute
        // ceiling may end the call — at its bound, not before, and not
        // unboundedly after.
        $proc = \proc_open(['sleep', '30'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $writer = $pipes[0];
        \stream_set_blocking($writer, false);

        $payload = \random_bytes(1024 * 1024);
        $started = \microtime(true);
        try {
            $written = HttpWire::writeAll($writer, $payload, 10.0, 0.5);
        } finally {
            $elapsed = \microtime(true) - $started;
            @\fclose($writer);
            @\fclose($pipes[1]);
            @\fclose($pipes[2]);
            @\proc_terminate($proc);
            @\proc_close($proc);
        }

        self::assertGreaterThan(0, $written, 'the write must hand the pipe its first windows');
        self::assertLessThan(\strlen($payload), $written, 'a never-read pipe cannot absorb the whole payload');
        self::assertGreaterThanOrEqual(0.5 - 0.05, $elapsed, 'the ceiling fired early — progress re-arm is not supposed to end the call');
        self::assertLessThan(2.0, $elapsed, 'the absolute ceiling never fired — trickle progress extended the write past its bound');
    }

    public function testTheWriteCeilingIsBakedIntoTheSharedLoop(): void
    {
        // Structural pin for S01+S13: the one home of the loop must carry
        // BOTH a wall grace and a monotonic absolute ceiling, checked
        // ahead of every feed — and the transports may not re-grow private
        // copies of the loop.
        $source = (string) \file_get_contents(__DIR__ . '/../../src/Support/HttpWire.php');
        $writeAll = self::sliceBetween($source, 'public static function writeAll', 'private function __construct');

        self::assertMatchesRegularExpression('/hrtime\(true\)\s*>=\s*\$absoluteNs/', $writeAll, 'writeAll lost its absolute ceiling check');
        self::assertStringContainsString('$absoluteNs = \\hrtime(true) +', $writeAll, 'the ceiling must start at the call, not advance with progress');
        self::assertMatchesRegularExpression('/microtime\(true\)\s*>=\s*\$giveUp/', $writeAll, 'writeAll lost the zero-progress grace');
        self::assertStringContainsString('self::CHUNK_BYTES', $writeAll, 'the feed quantum must be the shared constant, not a re-sliced remainder');

        $listenerSource = (string) \file_get_contents(__DIR__ . '/../../src/HttpSmartProtocol/HttpListener.php');
        self::assertStringNotContainsString('function writeAll', $listenerSource, 'HttpListener grew a private copy of the drain loop (143)');
        self::assertStringNotContainsString('function writeAll', (string) \file_get_contents(__DIR__ . '/../../src/StatsServer.php'), 'StatsServer grew a private copy of the drain loop (143)');
    }

    public function testBothTransportsRouteEveryClientWriteThroughTheHelper(): void
    {
        // Per-site structural pins (143, 033): the defect SHAPE — a bare
        // @fwrite($client, ...) on a response path — must stay gone in
        // BOTH files; every answer goes through HttpWire::writeAll.
        $listener = (string) \file_get_contents(__DIR__ . '/../../src/HttpSmartProtocol/HttpListener.php');
        self::assertStringNotContainsString('@\\fwrite($client', $listener, 'one-shot client write reintroduced in the listener');
        self::assertGreaterThanOrEqual(2, \substr_count($listener, 'HttpWire::writeAll($client'), 'both listener response writers must drain via the shared loop');

        $dispatch = self::sliceBetween($listener, 'private function dispatch', 'private static function peerAddress');
        // 049 split the answer into two drains (head, then body) so a
        // max-size response never materializes a concat copy; the guard
        // re-aims to the new shape — both legs must still ride writeAll.
        self::assertStringContainsString('HttpWire::writeAll($client, $headWire);', $dispatch);
        self::assertStringContainsString("HttpWire::writeAll(\$client, (string) \$result['body']);", $dispatch);

        $writeStatus = self::sliceBetween($listener, 'private function writeStatus', 'private function closeClient');
        self::assertStringContainsString('HttpWire::writeAll($client,', $writeStatus);

        $stats = (string) \file_get_contents(__DIR__ . '/../../src/StatsServer.php');
        self::assertStringNotContainsString('@\\fwrite($client', $stats, 'the stats transport re-grew its one-shot write (033)');
        $writeResponse = self::sliceBetween($stats, 'private function writeResponse', 'private function closeClient');
        self::assertStringContainsString('HttpWire::writeAll($client, $headers . $body);', $writeResponse, 'the stats answer must drain through the shared loop');
    }

    private static function sliceBetween(string $haystack, string $startNeedle, string $endNeedle): string
    {
        $from = \strpos($haystack, $startNeedle);
        $to = \strpos($haystack, $endNeedle, (int) $from);
        self::assertIsInt($from, 'HttpWire source lost the ' . $startNeedle . ' anchor');
        self::assertIsInt($to, 'HttpWire source lost the ' . $endNeedle . ' anchor');

        return \substr($haystack, $from, $to - $from);
    }
}
