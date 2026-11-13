<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Git;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\GitServer\Git\StatelessRpc;

/**
 * Pins for the the smart-HTTP hardening round stdin-write cure (review finding): the
 * negotiate() docblock claimed the deadline covered write + drain, but the
 * body called a bare blocking-context fwrite on the child's stdin pipe
 * first — a child that stopped consuming could freeze the single-loop
 * server inside the write, off any timer. writeBounded() now shares the
 * ONE NEGOTIATE_DEADLINE_SECONDS budget with the drain and raises on
 * deadline, so the existing finally escalates TERM→KILL instead.
 *
 * @covers \SugarCraft\GitServer\Git\StatelessRpc
 */
final class StatelessRpcWriteBoundedTest extends TestCase
{
    public function testWriteBoundedHandsTheWholeBodyToAConsumingPeer(): void
    {
        [$reader, $writer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsResource($reader);
        self::assertIsResource($writer);
        try {
            $body = 'want ' . \str_repeat('a', 40) . "\n";
            self::invokeWriteBounded($writer, $body, \hrtime(true) + 2_000_000_000);

            @\fclose($writer); // half-close so the blocking drain below sees EOF
            self::assertSame($body, (string) \stream_get_contents($reader));
        } finally {
            @\fclose($reader);
            if (\is_resource($writer)) {
                @\fclose($writer);
            }
        }
    }

    public function testWriteBoundedHandsAMultiChunkBodyWithByteFidelity(): void
    {
        // S02: writeBounded feeds one 64 KiB chunk per attempt; a body many
        // chunks wide must still reach the child verbatim.
        [$reader, $writer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsResource($reader);
        self::assertIsResource($writer);
        try {
            $body = \random_bytes(3 * 65536 + 77);

            self::invokeWriteBounded($writer, $body, \hrtime(true) + 5_000_000_000);

            @\fclose($writer); // half-close so the blocking drain below sees EOF
            self::assertSame($body, (string) \stream_get_contents($reader));
        } finally {
            @\fclose($reader);
            if (\is_resource($writer)) {
                @\fclose($writer);
            }
        }
    }

    public function testWriteBoundedRaisesWhenTheChildStopsConsuming(): void
    {
        // The production wedge shape: writeBounded's stream IS a proc_open
        // stdin pipe, so the honest fixture is one too — a child that never
        // reads (sleep) fills the kernel's 64 KiB pipe and the 1 MiB body
        // can no longer be handed off. A socketpair auto-tunes past 1 MiB
        // on this kernel and never stalls, which is why the first cut of
        // this test needed the rewrite.
        $proc = \proc_open(['sleep', '30'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $writer = $pipes[0];
        \stream_set_blocking($writer, false);

        $started = \hrtime(true);
        $deadline = $started + 200_000_000; // ns — writeBounded takes the same absolute hrtime deadline negotiate computes
        $raised = null;
        try {
            // fail() lives OUTSIDE this catch on purpose: PHPUnit's
            // AssertionFailedError extends RuntimeException and would be
            // swallowed by a catch (RuntimeException) wrapped around it.
            try {
                self::invokeWriteBounded($writer, \random_bytes(1024 * 1024), $deadline);
            } catch (RuntimeException $e) {
                $raised = $e->getMessage();
            }
        } finally {
            @\fclose($writer);
            @\fclose($pipes[1]);
            @\fclose($pipes[2]);
            @\proc_terminate($proc);
            @\proc_close($proc);
        }

        self::assertNotNull($raised, 'a non-consuming stdin must trip the deadline');
        self::assertStringContainsString('stdin write stalled past the deadline', $raised);
        self::assertGreaterThanOrEqual(0.2, (\hrtime(true) - $started) / 1e9, 'the deadline is honored, not skipped');
    }

    public function testNegotiateFeedsStdinThroughTheBoundedWriter(): void
    {
        // Wiring pin: the negotiate() body must not carry a bare fwrite on
        // the stdin pipe — the one shared deadline claim is only true while
        // writeBounded is the sole writer.
        $source = (string) \file_get_contents(__DIR__ . '/../../src/Git/StatelessRpc.php');
        $from = \strpos($source, 'public static function negotiate');
        $to = \strpos($source, 'private static function stripServiceHeader', (int) $from);
        self::assertIsInt($from);
        self::assertIsInt($to);
        $body = \substr($source, $from, $to - $from);

        self::assertStringContainsString('self::writeBounded($pipes[0]', $body);
        self::assertStringNotContainsString('\fwrite($pipes[0]', $body, 'unguarded stdin write reintroduced');
        self::assertStringContainsString('self::drainBounded($pipes[1], $pipes[2], $deadline', $body, 'drain must inherit the write deadline budget');
    }

    /** @param resource $writer */
    private static function invokeWriteBounded($writer, string $bytes, int $deadlineNs): void
    {
        (new \ReflectionMethod(StatelessRpc::class, 'writeBounded'))->invoke(null, $writer, $bytes, $deadlineNs);
    }
}
