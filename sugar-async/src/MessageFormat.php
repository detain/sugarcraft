<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Formatting primitives for user-facing duration messages.
 *
 * Raw floats interpolate badly ('0.05' vs '0.05000000000000000279'), and the
 * old 'second(s)' compromise reads incorrectly for every value it prints. This is
 * the single home for both fixes so exception messages stay consistent.
 *
 * @internal  Library plumbing — not part of the public API.
 */
final class MessageFormat
{
    /**
     * A float rendered with the trailing noise trimmed: 0.05 → '0.05',
     * 2.0 → '2', 0.125 → '0.125'. At most six decimal places (microsecond
     * resolution) — enough for any real deadline, short enough to read.
     */
    public static function seconds(float $seconds): string
    {
        $trimmed = \rtrim(\rtrim(\sprintf('%.6F', $seconds), '0'), '.');

        // '0' and '-0' would read odd next to a unit; a true zero is never a
        // legal duration here (guards reject it), but a sub-microsecond remnant
        // rounds to it — print '0.000001' rather than '0'.
        return $trimmed === '0' || $trimmed === '-0' ? '0.000001' : $trimmed;
    }

    /**
     * The English unit for a duration: singular only at exactly one second.
     * Fractional seconds are plural ('0.5 seconds'), matching prose style.
     */
    public static function unit(float $seconds): string
    {
        return $seconds === 1.0 ? 'second' : 'seconds';
    }
}
