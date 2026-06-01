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
}
