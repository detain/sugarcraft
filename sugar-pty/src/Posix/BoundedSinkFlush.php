<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

/**
 * Deliver bytes to a sink stream, but stop at a wall-clock deadline and hand
 * back what did not fit.
 *
 * The pumps' drain used to end in one plain `fwrite()` against the caller's
 * own stream mode. On a blocking sink whose reader stalls, that write never
 * returns, so the "final flush" had no bound at all; on a non-blocking sink it
 * was a single attempt whose refused tail vanished without a count. This
 * helper is the bounded form: the sink is borrowed in non-blocking mode for
 * the call (its original mode is handed back before returning), each refused
 * write waits for writability only as long as the deadline allows, and the
 * undelivered remainder is returned so the caller can count it as dropped.
 *
 * At least one write is always attempted, even with a deadline already in the
 * past, so a zero flush window still delivers whatever the sink takes at once.
 *
 * Original work: no upstream counterpart (creack/pty and portable-pty leave
 * the copy loop to the caller).
 *
 * @internal shared by {@see PosixPump}; not part of the public API.
 */
final class BoundedSinkFlush
{
    /** Longest single writability wait, so a deadline is noticed promptly. */
    private const WAIT_SLICE_US = 10_000;

    /**
     * @param resource $sink
     * @param float    $deadlineAt absolute `microtime(true)` deadline
     * @return string the bytes the sink did not take ('' when all delivered);
     *                a write error (EPIPE, closed reader) returns the whole
     *                rest at once, since no later attempt can succeed
     */
    public static function flush($sink, string $bytes, float $deadlineAt): string
    {
        if ($bytes === '') {
            return '';
        }
        if (!\is_resource($sink)) {
            return $bytes;
        }

        $wasBlocking = (bool) (\stream_get_meta_data($sink)['blocked'] ?? true);
        if ($wasBlocking) {
            @\stream_set_blocking($sink, false);
        }

        try {
            while (true) {
                $written = @\fwrite($sink, $bytes);
                if ($written === false) {
                    return $bytes;
                }
                if ($written > 0) {
                    $bytes = (string) \substr($bytes, $written);
                    if ($bytes === '') {
                        return '';
                    }
                }

                $leftUs = (int) (($deadlineAt - \microtime(true)) * 1_000_000);
                if ($leftUs <= 0) {
                    return $bytes;
                }
                $waitUs = \min($leftUs, self::WAIT_SLICE_US);
                $read = [];
                $write = [$sink];
                $except = null;
                // A sink select cannot watch (or an EINTR) falls back to a
                // short sleep; either way the next pass re-checks the deadline.
                if (@\stream_select($read, $write, $except, 0, $waitUs) === false) {
                    \usleep(\min($waitUs, 1_000));
                }
            }
        } finally {
            if ($wasBlocking && \is_resource($sink)) {
                @\stream_set_blocking($sink, true);
            }
        }
    }
}
