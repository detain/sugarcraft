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
}
