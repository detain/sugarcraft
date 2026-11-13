<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The one append-a-line rung: parent directory ensured owner-private, a
 * symlinked directory or file refused, `flock(LOCK_EX)` held across the write,
 * and the byte count VERIFIED against the line's length (a short write reads
 * as failure, not success).
 *
 * Extracted from {@see \SugarCraft\Code\Agents\Live\SubAgentTranscriptLog},
 * which donated the rung, so the transcript log and the
 * {@see \SugarCraft\Code\Agents\Mailbox} legs share one discipline instead of
 * three drifting copies.
 *
 * Failure is a `false` return, never an exception: callers that must not
 * break a turn over a lost line (the transcript log) and callers that choose
 * their own reaction both read the same bool.
 *
 * A sugar-core `LineAppender` (rider R1c) is deliberately deferred until a
 * second app family proves the API; this stays app-local until then.
 */
final class LineAppends
{
    private function __construct() {}

    /** Append `$line` (caller supplies the trailing newline) to `$path`. */
    public static function append(string $path, string $line): bool
    {
        $previous = umask(0o077);
        try {
            $dir = \dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                return false;
            }
            if (is_link($dir) || is_link($path)) {
                return false;
            }

            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                return false;
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    return false;
                }
                $written = fwrite($handle, $line);
                fflush($handle);
                flock($handle, LOCK_UN);

                return $written === \strlen($line);
            } finally {
                fclose($handle);
            }
        } finally {
            umask($previous);
        }
    }
}
