<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Msg;

use SugarCraft\Core\Msg;

/**
 * Per-frame tick driving the video player.
 *
 * TickMsg is dispatched on a timer to drive frame advancement when
 * the player is not paused. Each tick computes wall-clock elapsed time,
 * determines the target frame, and decides whether to advance, hold,
 * or skip frames to maintain sync with the playback speed.
 *
 * Mirrors candy-flip/src/TickMsg but scoped to video playback timing.
 */
final class TickMsg implements Msg
{
    /** Singleton instance to avoid allocation on every tick. */
    private static ?TickMsg $instance = null;

    /**
     * Return the singleton TickMsg instance.
     *
     * All ticks use the same immutable instance since TickMsg carries no state.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
