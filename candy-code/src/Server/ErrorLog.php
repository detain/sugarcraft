<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server;

/**
 * One-line rendering of a swallowed internal failure (ISS-057, Appendix O
 * §6.2): SERVER.md promises "the cause is in the server's log, never in the
 * answer", so every catch that hides the truth from the client renders it
 * here for the log. The class name, a flattened and clipped message, and the
 * throw site — enough to find the bug, and the raw message may still name a
 * path or quote a prompt, which is precisely why it goes to the 0600
 * `server.log` and never onto the wire.
 */
final class ErrorLog
{
    /** How much of the throwable's message a log line carries. */
    public const MAX_MESSAGE_BYTES = 200;

    private function __construct()
    {
    }

    /** A single line: `{prefix}: {class}: {message} at {file}:{line}`. */
    public static function line(\Throwable $e, string $prefix = 'internal error'): string
    {
        return \sprintf(
            '%s: %s: %s at %s:%d',
            $prefix,
            $e::class,
            self::flatten($e->getMessage()),
            $e->getFile(),
            $e->getLine(),
        );
    }

    /** Control bytes out, length capped: one line stays one line. */
    public static function flatten(string $text): string
    {
        $flat = \preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text) ?? '';

        return \substr($flat, 0, self::MAX_MESSAGE_BYTES);
    }
}
