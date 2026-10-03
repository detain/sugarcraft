<?php

declare(strict_types=1);

namespace SugarCraft\Veil;

/**
 * The one line-splitting rule every sugar-veil measurement shares.
 *
 * Veil::composite() and the Slide / Scale animations must agree on how many
 * rows a foreground has, or an animation's travel distance and line reveal
 * drift a row away from what the compositor paints. A trailing "\n" closes
 * the last line rather than opening an empty one.
 *
 * @internal
 */
final class Lines
{
    private function __construct()
    {
    }

    /**
     * Split text into lines, dropping the empty tail a final "\n" leaves.
     *
     * The empty string has no lines at all.
     *
     * @return list<string>
     */
    public static function split(string $text): array
    {
        $lines = \explode("\n", $text);
        if (\end($lines) === '') {
            \array_pop($lines);
        }

        return $lines;
    }
}
