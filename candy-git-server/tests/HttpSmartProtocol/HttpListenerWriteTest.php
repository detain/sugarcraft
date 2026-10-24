<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\HttpSmartProtocol\HttpListener;

/**
 * Pins for the r1024/githttp2 response-write cure (review MAJOR-1): the
 * listener used to hand the whole response to a non-blocking client socket
 * with ONE @fwrite, so anything past the kernel send window was silently
 * lost on close. writeAll() keeps feeding until drained or the client
 * stalls; these tests pin the loop's byte fidelity, its stall behavior,
 * and the structural absence of the old one-shot write on the response
 * path (the byte-exact proof lives in SmartHttpCloneTest's 8 MiB leg).
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\HttpListener
 */
final class HttpListenerWriteTest extends TestCase
{
    public function testWriteAllDeliversTheWholePayloadWhenThePeerKeepsUp(): void
    {
        [$reader, $writer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsResource($reader);
        self::assertIsResource($writer);
        try {
            \stream_set_blocking($writer, false);
            $payload = \str_repeat('abcdefgh', 512); // 4 KiB — fits one window

            $written = self::invokeWriteAll($writer, $payload, 5.0);

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
        // (A socketpair auto-tunes its buffers past 1 MiB on this kernel,
        // which is why the first cut of this test used to pass through the
        // whole payload.) writeAll must spin in writability ticks until the
        // stall grace expires and report exactly how far it got — never
        // return "delivered" on a body truncated to one window the way the
        // old one-shot @fwrite did. Byte fidelity of the advances is pinned
        // by the keeps-up leg above plus the 8 MiB clone leg of
        // SmartHttpCloneTest.
        $proc = \proc_open(['sleep', '30'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $writer = $pipes[0];
        \stream_set_blocking($writer, false);

        try {
            $payload = \random_bytes(1024 * 1024);
            $started = \microtime(true);
            $written = self::invokeWriteAll($writer, $payload, 0.2);
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

    public function testNoOneShotClientWriteRemainsOnTheResponsePath(): void
    {
        // Structural pin: the defect SHAPE — a bare @fwrite($client, ...) in
        // the response writers — must stay gone; both dispatch() and
        // writeStatus() answer exclusively through self::writeAll().
        $source = (string) \file_get_contents(__DIR__ . '/../../src/HttpSmartProtocol/HttpListener.php');

        self::assertStringNotContainsString('@\\fwrite($client', $source, 'one-shot client write reintroduced');
        self::assertGreaterThanOrEqual(2, \substr_count($source, 'self::writeAll($client'), 'both response writers must drain via writeAll');

        $dispatch = self::sliceBetween($source, 'private function dispatch', 'private static function peerAddress');
        self::assertStringContainsString('self::writeAll($client, $wire)', $dispatch);
        self::assertStringNotContainsString('\fwrite($client', $dispatch);

        $writeStatus = self::sliceBetween($source, 'private function writeStatus', 'private function closeClient');
        self::assertStringContainsString('self::writeAll($client,', $writeStatus);
        self::assertStringNotContainsString('@\fwrite($client', $writeStatus);
    }

    /** @param resource $writer */
    private static function invokeWriteAll($writer, string $bytes, float $stallSeconds): int
    {
        $method = new \ReflectionMethod(HttpListener::class, 'writeAll');

        return (int) $method->invoke(null, $writer, $bytes, $stallSeconds);
    }

    private static function sliceBetween(string $haystack, string $startNeedle, string $endNeedle): string
    {
        $from = \strpos($haystack, $startNeedle);
        $to   = \strpos($haystack, $endNeedle, (int) $from);
        self::assertIsInt($from, 'listener source lost the ' . $startNeedle . ' anchor');
        self::assertIsInt($to, 'listener source lost the ' . $endNeedle . ' anchor');

        return \substr($haystack, $from, $to - $from);
    }
}
