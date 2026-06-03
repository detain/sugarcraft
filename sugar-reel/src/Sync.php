<?php

declare(strict_types=1);

namespace SugarCraft\Reel;

/**
 * Wall-clock frame pacing and skip/hold decisions for video playback.
 *
 * Sync computes the target frame from wall-clock elapsed time and decides
 * whether the decoder should skip ahead (when behind), hold (when ahead),
 * or advance normally. This prevents frame accumulation lag and ensures
 * smooth playback at the configured speed.
 *
 * The skip limit is hardcoded at 2 frames: if the decoder is behind by
 * more than 2 frames, those frames are discarded to catch up.
 *
 * No single upstream — the wall-clock pacing + frame-skip-resync approach is
 * drawn from maxcurzi/tplay and joelibaceta/video-to-ascii (see video_plan.md
 * lines 94-96).
 */
final class Sync
{
    /**
     * @param float $fps    Frames per second of the source video
     * @param float $speed  Playback speed multiplier (1.0 = normal)
     */
    public function __construct(
        public readonly float $fps,
        public readonly float $speed,
    ) {
    }

    /**
     * Compute the target frame number from wall-clock elapsed time.
     *
     * target = floor(elapsed * fps * speed)
     *
     * @param float $elapsedSeconds Wall-clock seconds since playback started
     * @return int The frame we should be on at this elapsed time
     */
    public static function targetFrame(float $elapsedSeconds, float $fps, float $speed): int
    {
        if ($elapsedSeconds < 0.0) {
            return 0;
        }
        return (int)floor($elapsedSeconds * $fps * $speed);
    }

    /**
     * Determine whether we are too far behind and must skip frames.
     *
     * If target is more than 2 frames ahead of current, we are behind
     * by more than the skip limit and should discard intermediate frames.
     *
     * @param int $currentFrame Frame currently being displayed
     * @param int $targetFrame   Frame we should be on based on elapsed time
     * @return bool True when we should skip ahead to catch up
     */
    public static function shouldSkip(int $currentFrame, int $targetFrame): bool
    {
        return $targetFrame - $currentFrame > 2;
    }

    /**
     * Determine whether we are ahead and must hold/delay frames.
     *
     * If current > target, we are ahead of schedule and should not
     * advance the decoder until wall time catches up.
     *
     * @param int $currentFrame Frame currently being displayed
     * @param int $targetFrame   Frame we should be on based on elapsed time
     * @return bool True when we should hold and wait for wall time
     */
    public static function shouldHold(int $currentFrame, int $targetFrame): bool
    {
        return $currentFrame > $targetFrame;
    }

    /**
     * Reset the sync engine to initial state.
     *
     * Called when seeking or when playback is restarted from the beginning.
     */
    public function reset(): void
    {
        // Sync is stateless for now (fps/speed are immutable).
        // Reset is a no-op but provides API surface for future state.
    }
}
