<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Support;

use InvalidArgumentException;

use function array_reverse;
use function implode;
use function mb_str_split;
use function mb_strwidth;
use function preg_replace;

/**
 * The one clamp for everything the app writes into a stderr notice.
 *
 * The triplied private copies (candy-tetris-075) byte-sliced the fully
 * composed message at 40 bytes: the wrapper prefix ate the budget, the
 * useful tail of a path diagnostic was the first thing eaten, and a slice
 * landing mid-sequence could split UTF-8 (candy-tetris-014). This helper
 * is applied to the VARIABLE PAYLOAD only — the sentence around it
 * (`keys.ignored`, `scores.ignored`, `score.save.failed`) is short,
 * catalogue-owned and prints in full — and it clamps by display width,
 * never by byte, so multibyte text is only ever cut between characters.
 *
 * An over-long payload loses its MIDDLE, not its tail: the leading words
 * name what failed, the trailing component names where, and both ends of
 * a real path survive the clamp.
 */
final class CliVisible
{
    /** Display-width budget for one clamped payload (candy-tetris-014). */
    public const MAX_PAYLOAD_WIDTH = 120;

    /** The gap marker replacing an elided middle. */
    private const ELLIPSIS = '…';

    /**
     * Control-character-free, width-bounded single-line form of a failure
     * message: every control byte becomes '?' (a filename from the
     * environment must never smuggle escapes into the terminal), and an
     * over-wide payload is elided in the middle.
     *
     * @throws InvalidArgumentException When $width cannot fit head, marker and tail.
     */
    public static function clamp(string $payload, int $width = self::MAX_PAYLOAD_WIDTH): string
    {
        if ($width < 4) {
            throw new InvalidArgumentException("clamp width $width cannot fit head, marker and tail");
        }

        $visible = preg_replace('/[\x00-\x1f\x7f]/', '?', $payload);
        if (mb_strwidth($visible, 'UTF-8') <= $width) {
            return $visible;
        }

        $budget = $width - mb_strwidth(self::ELLIPSIS, 'UTF-8');
        $tail = (int) ceil($budget * 2 / 3);
        $head = $budget - $tail;

        return self::takeFromFront($visible, $head) . self::ELLIPSIS . self::takeFromEnd($visible, $tail);
    }

    /**
     * Leading characters of $text whose combined display width fits $width.
     */
    private static function takeFromFront(string $text, int $width): string
    {
        $taken = '';
        $used = 0;
        foreach (mb_str_split($text) as $character) {
            $weight = mb_strwidth($character, 'UTF-8');
            if ($used + $weight > $width) {
                break;
            }
            $taken .= $character;
            $used += $weight;
        }

        return $taken;
    }

    /**
     * Trailing characters of $text whose combined display width fits $width.
     */
    private static function takeFromEnd(string $text, int $width): string
    {
        $taken = [];
        $used = 0;
        foreach (\array_reverse(mb_str_split($text)) as $character) {
            $weight = mb_strwidth($character, 'UTF-8');
            if ($used + $weight > $width) {
                break;
            }
            \array_unshift($taken, $character);
            $used += $weight;
        }

        return implode('', $taken);
    }
}
