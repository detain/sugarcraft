<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

/**
 * Reads a piped data channel under the same wall-clock deadline
 * {@see TimeoutGuard} arms around a prompt.
 *
 * Every piped command used to slurp STDIN with a blocking
 * `stream_get_contents` BEFORE its guard could arm, so
 * `sleep 999999 | candy-scriptui filter --timeout 5` never reached the
 * deadline the flag promises (no event loop runs while blocked). Reading via
 * `stream_select` keeps the wait bounded without a loop: the deadline is
 * measured from the first call, silence past it raises
 * {@see IntakeTimedOut}, and the prompt's own guard (armed afterwards) still
 * counts from prompt open — the two bounds are independent.
 *
 * A deadline of 0 (or less) is gum's "no limit": a plain blocking read.
 */
final class IntakeReader
{
    private const CHUNK = 65536;

    /**
     * @param resource $stream  a readable stream (STDIN in production)
     * @param float    $seconds deadline for the WHOLE read; <= 0 = unbounded
     *
     * @throws IntakeTimedOut when the stream is not at EOF at the deadline
     */
    public static function read($stream, float $seconds): string
    {
        if ($seconds <= 0.0) {
            return (string) stream_get_contents($stream);
        }

        $deadline = microtime(true) + $seconds;
        $data = '';
        $failures = 0;

        if (\PHP_OS_FAMILY === 'Windows') {
            return self::readPolled($stream, $deadline);
        }

        while (!feof($stream)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                throw new IntakeTimedOut();
            }
            $read = [$stream];
            $write = null;
            $except = null;
            $whole = (int) floor($remaining);
            $ready = @stream_select($read, $write, $except, $whole, (int) (($remaining - $whole) * 1_000_000));
            if ($ready === false) {
                // EINTR from a handled signal retries; a persistently broken
                // stream must not spin, so give up and keep what arrived.
                if (++$failures > 3) {
                    break;
                }
                continue;
            }
            if ($ready === 0) {
                throw new IntakeTimedOut();
            }
            $chunk = fread($stream, self::CHUNK);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    /** Silence after the last byte that Windows reads as the writer's EOF. */
    private const WINDOWS_QUIET_SECONDS = 0.3;

    /**
     * Windows: stream_select() calls an empty pipe ready, so the blocking fread
     * after it parks past any deadline. Poll fstat for what is waiting instead.
     * A Windows pipe shows no EOF without a read that would park, so once bytes
     * have arrived a quiet gap of {@see WINDOWS_QUIET_SECONDS} stands for the
     * writer closing; a pipe still streaming at the deadline times out as on POSIX.
     *
     * @param resource $stream
     */
    private static function readPolled($stream, float $deadline): string
    {
        $data = '';
        $lastByteAt = null;
        while (true) {
            $chunk = \SugarCraft\Core\Util\Proc\WindowsPipes::readAvailable($stream, self::CHUNK);
            if ($chunk !== '') {
                $data .= $chunk;
                $lastByteAt = microtime(true);

                continue;
            }
            $now = microtime(true);
            if ($lastByteAt !== null && $now - $lastByteAt >= self::WINDOWS_QUIET_SECONDS) {
                return $data;
            }
            if ($now >= $deadline) {
                throw new IntakeTimedOut();
            }
            // A regular file reports its whole size at once and then ''; the
            // quiet gap ends that read too.
            usleep(10_000);
        }
    }
}
