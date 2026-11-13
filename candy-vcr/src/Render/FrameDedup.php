<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Render;

use SugarCraft\Vterm\Snapshot;

/**
 * Collapse identical adjacent frames in a FrameStream.
 *
 * Critical for GIF size — typical terminal recordings have 80–95% of frames
 * identical between captures (cursor blink and idle time produce many
 * consecutive identical frames). By not emitting duplicates, downstream
 * encoders receive fewer frames and can allocate appropriate display time
 * per unique frame.
 *
 * When a frame is identical to the previous, it is not emitted. This
 * effectively extends the display duration of the previous unique frame.
 * The `holdMax` parameter caps how many consecutive identical frames can
 * be collapsed to prevent pathological cases (e.g., infinite identical
 * frames from a frozen terminal state).
 *
 * @see FrameStream
 * @see \SugarCraft\Vcr\Render\Renderer
 */
final class FrameDedup
{
    /**
     * Wrap a FrameStream, collapsing duplicate adjacent frames.
     *
     * When a frame is identical to the previous, it is not emitted. This
     * extends the hold duration of the previous unique frame. The `holdMax`
     * parameter prevents infinite holds by still emitting frames after
     * `holdMax` consecutive duplicates.
     *
     * @param \Traversable<int, Snapshot> $stream The frame stream to dedup
     * @param int $holdMax Maximum consecutive identical frames to collapse
     *                     (default 300, which at 30fps means ~10 seconds of hold)
     * @return \Iterator<int, Snapshot> Iterator of unique snapshots
     */
    public static function dedup(\Traversable $stream, int $holdMax = 300): \Iterator
    {
        $prev = null;
        $prevHold = 0;
        $index = 0;

        foreach ($stream as $snapshot) {
            if ($prev !== null && self::isDuplicate($snapshot, $prev)) {
                $prevHold++;
                if ($prevHold <= $holdMax) {
                    continue;
                }
            }

            yield $index => $snapshot;
            $index++;
            $prev = $snapshot;
            $prevHold = 0;
        }
    }

    /**
     * Duplicate test with a revision fast path.
     *
     * {@see \SugarCraft\Vterm\Terminal::revision()} is monotonic per feed:
     * two snapshots from one terminal lineage with equal non-zero revisions
     * have provably untouched grids, so the cursor check completes the
     * decision and the full grid equals() — a deep cell-by-cell walk — is
     * skipped. This collapses the idle-hold re-emissions FrameStream emits
     * on its hold cadence to two integer compares each (finding 051).
     *
     * Revision 0 is the constructor default for hand-built snapshots with
     * no captured lineage, so it is never trusted as proof; those fall
     * through to the content compare exactly as before.
     */
    private static function isDuplicate(Snapshot $snapshot, Snapshot $prev): bool
    {
        if ($snapshot->revision > 0 && $snapshot->revision === $prev->revision) {
            return $snapshot->cursor->equals($prev->cursor);
        }

        return $snapshot->equals($prev);
    }
}
