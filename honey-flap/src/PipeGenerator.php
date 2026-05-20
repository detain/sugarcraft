<?php

declare(strict_types=1);

namespace SugarCraft\Flap;

/**
 * Generates Pipe objects with gap height that varies based on score.
 *
 * Gap shrinks as score increases to raise difficulty, but a floor
 * prevents it from becoming impossible.
 */
final class PipeGenerator
{
    /** Starting gap height at score 0. */
    public const GAP_DEFAULT = 6;

    /** Smallest gap height (floor). */
    public const GAP_MIN = 3;

    /** Score points between each gap shrink step. */
    public const GAP_SHRINK_INTERVAL = 5;

    /** How many cells the gap shrinks per interval. */
    public const GAP_SHRINK_STEP = 1;

    /**
     * Compute the gap height for a given score.
     *
     * Gap starts at GAP_DEFAULT and shrinks by GAP_SHRINK_STEP
     * every GAP_SHRINK_INTERVAL points, bottoming out at GAP_MIN.
     */
    public static function gapHeightForScore(int $score): int
    {
        $shrinkCount = intdiv($score, self::GAP_SHRINK_INTERVAL);
        $gap = self::GAP_DEFAULT - ($shrinkCount * self::GAP_SHRINK_STEP);
        return max($gap, self::GAP_MIN);
    }

    /**
     * Generate a new Pipe at the right edge with a variable gap height.
     *
     * @param int $score          Current game score (affects gap height)
     * @param \Closure(int): int  $rand  PRNG producing 0..$max
     */
    public static function makePipe(int $score, \Closure $rand): Pipe
    {
        $gapHeight = self::gapHeightForScore($score);
        $halfGap = intdiv($gapHeight, 2);
        $minY = 3 + $halfGap;
        $maxY = Game::HEIGHT - 3 - $halfGap;
        $gapY = $minY + $rand($maxY - $minY);

        return new Pipe(Game::WIDTH - 1, $gapY, $gapHeight);
    }
}
