<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Support;

/**
 * One diagnostic line for a throwable, safe to put in a log or a refusal row.
 *
 * Mirrors candy-code's ErrorLog::line() byte for byte, so a plugin fault reads
 * the same whichever host logged it. The message is flattened and capped
 * because a plugin's exception text is plugin-controlled data: it must not be
 * able to forge a second log line.
 */
final class ErrorLine
{
    public const MAX_MESSAGE_BYTES = 200;

    public static function of(\Throwable $e, string $prefix = 'internal error'): string
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

        // mb_strcut backs up to a character boundary, so a multibyte character
        // straddling the cap is dropped whole, never split.
        return \mb_strcut($flat, 0, self::MAX_MESSAGE_BYTES, 'UTF-8');
    }
}
