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
     * Compute the target frame number from video content time.
     *
     * target = floor(videoTime * fps)
     *
     * Speed is applied OUTSIDE this function (in the caller's tick math:
     * newVideoTime = videoTime + delta * speed), so a speed change only
     * affects FUTURE pacing — no retroactive re-scaling of prior time.
     *
     * @param float $videoTimeSeconds Content seconds since playback start
     * @param float $fps              Frames per second
     * @return int The frame we should be on at this video time
     */
    public static function targetFrame(float $videoTimeSeconds, float $fps): int
    {
        if ($videoTimeSeconds < 0.0) {
            return 0;
        }
        return (int)floor($videoTimeSeconds * $fps);
    }

    /**
     * Determine whether we are too far behind and must skip frames.
     *
     * If target is more than 2 frames ahead of current, we are behind
     * by more than the skip limit and should discard intermediate frames.
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
     */
    public static function shouldHold(int $currentFrame, int $targetFrame): bool
    {
        return $currentFrame > $targetFrame;
    }
}
