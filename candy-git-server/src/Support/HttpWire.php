<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Support;

/**
 * Shared HTTP transport primitives for the lib's two socket faces.
 *
 * The listener (smart-HTTP git traffic) and the stats endpoint speak the
 * same wire and had drifted into two hand-rolled transports: the write
 * loop — hardened once against the one-shot fwrite truncation (kernel
 * send windows on non-blocking sockets swallow all but the first buffer)
 * and later against unbounded trickle holds — lived private in
 * HttpListener, while StatsServer shipped exactly the defective
 * one-shot shape the listener had already been cured of (finding 033):
 * a bare `@fwrite($client, $headers.$body)` that silently loses every
 * byte past the first window. One implementation, both transports (143).
 *
 * The 64 KiB request-head ceiling belongs here too: HttpListener and
 * StatsServer each carried their own private copy of the same number
 * with no shared source of truth (finding 112's audit trail); a cap on
 * INBOUND bytes and a quantum on OUTBOUND feeds agree on size by
 * convention, and this file is that convention.
 */
final class HttpWire
{
    /**
     * Cap on a request head before the transport refuses it (431).
     * Shared by both listeners — the number is a protocol posture, not
     * a per-transport tuning knob.
     */
    public const MAX_HEADER_BYTES = 65536;

    /** Bytes handed to one fwrite feed by writeAll() (the lib's write quantum). */
    public const CHUNK_BYTES = 65536;

    /** How long a response write may make ZERO progress on a client socket. */
    public const STALL_SECONDS = 10.0;

    /**
     * Absolute ceiling for one response write regardless of trickle
     * progress — mirrors StdioPipe::HARD_SECONDS, the per-request bound
     * the daemon pump already carries. A seat that keeps the house's
     * kernel buffers fed forever is still only a seat for this long.
     */
    public const ABSOLUTE_SECONDS = 300.0;

    /**
     * Push $bytes through a non-blocking stream, retrying every partial
     * accept until the whole payload is handed to the kernel — or until
     * the client makes zero progress for $stallSeconds, or the write
     * outlives the absolute ceiling, or the socket errors. Returns the
     * byte count actually written (== strlen($bytes) on success); the
     * caller's close path is identical either way because a
     * half-delivered response is already protocol-dead to the client.
     *
     * The progress re-arm bounds only the ZERO-progress grace: a client
     * that accepts one byte every few seconds used to hold the seat —
     * and, in the serial-loop build, every other client with it — for as
     * long as it liked. The absolute ceiling (start + $absoluteSeconds,
     * monotonic) is the same shape GitDaemon::drainWrite and StdioPipe's
     * hard bound already enforce, so no response write is unbounded
     * anywhere in the lib. Sweeping deadlines cannot preempt a write
     * already in flight (single-threaded dispatch), which is exactly why
     * the bound lives here.
     *
     * @param resource $stream
     */
    public static function writeAll($stream, string $bytes, ?float $stallSeconds = null, ?float $absoluteSeconds = null): int
    {
        $stall    = $stallSeconds ?? self::STALL_SECONDS;
        $absolute = $absoluteSeconds ?? self::ABSOLUTE_SECONDS;
        $total    = \strlen($bytes);
        $offset   = 0;
        $absoluteNs = \hrtime(true) + (int) ($absolute * 1e9);
        $giveUp   = \microtime(true) + $stall;

        while ($offset < $total) {
            if (\microtime(true) >= $giveUp || \hrtime(true) >= $absoluteNs) {
                return $offset; // wedged (zero progress past the grace) or dribbling (past the ceiling)
            }
            // Hand the kernel ONE chunk per feed: substr($bytes, $offset)
            // re-sliced the whole remainder on every partial accept, so a
            // max-size response on a slow peer did O(n²/chunk) byte
            // copying inside the write loop (the same quadratic-writer
            // family as the fleet's streaming holds). The slice is also
            // what keeps a huge $bytes from being copied twice per
            // attempt.
            $written = @\fwrite($stream, \substr($bytes, $offset, self::CHUNK_BYTES));
            if ($written === false) {
                return $offset; // socket error / reset by peer
            }
            if ($written > 0) {
                $offset += $written;
                $giveUp = \microtime(true) + $stall; // progress re-arms the window, never past the ceiling
                continue;
            }
            // 0-byte accept: kernel buffer full (or EINTR-ish moment). Wait
            // for writability in short ticks so the stall deadline stays
            // authoritative even if the socket never turns writable.
            $read = $except = null;
            $write = [$stream];
            @\stream_select($read, $write, $except, 0, 100_000); // 100 ms tick
        }

        return $offset;
    }

    /** Not instantiable — namespace for shared wire primitives. */
    private function __construct()
    {
    }
}
