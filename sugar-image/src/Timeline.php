<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Maps a playback position to the sprite tile that covers it: a clip of
 * $durationSeconds split into $tiles equal slices, tile i covering
 * [i * step, (i + 1) * step) where step = duration / tiles.
 *
 * Positions before the start clamp to tile 0 and positions at or past the end
 * clamp to the last tile, so a scrubber that overshoots still lands on a
 * real thumbnail.
 */
final class Timeline
{
    private function __construct(
        public readonly int $tiles,
        public readonly float $durationSeconds,
    ) {}

    /**
     * @throws \InvalidArgumentException when $tiles is below 1 or the duration
     *                                   is not a positive finite number
     */
    public static function new(int $tiles, float $durationSeconds): self
    {
        if ($tiles < 1) {
            throw new \InvalidArgumentException("timeline needs at least one tile, got {$tiles}");
        }
        if (!is_finite($durationSeconds) || $durationSeconds <= 0.0) {
            throw new \InvalidArgumentException('timeline duration must be a positive finite number of seconds');
        }

        return new self($tiles, $durationSeconds);
    }

    /**
     * The tile index covering playback position $sec.
     *
     * @throws \InvalidArgumentException when $sec is NaN or infinite
     */
    public function tileAt(float $sec): int
    {
        if (is_nan($sec) || is_infinite($sec)) {
            throw new \InvalidArgumentException('timeline position must be a finite number of seconds');
        }
        if ($sec <= 0.0) {
            return 0;
        }

        // Compare in float before the int cast: a huge overshoot would wrap
        // under (int) and land on a tile in the middle of the sheet.
        $guess = floor($sec * $this->tiles / $this->durationSeconds);
        if ($guess >= $this->tiles) {
            return $this->tiles - 1;
        }
        $index = (int) $guess;

        // The boundary is judged on the product, not the rounded step: dividing
        // by duration/tiles put 0.3 s over 10 one-second tiles into tile 2
        // (0.3 / 0.1 is 2.9999999999999996). One nudge each way is enough.
        if ($index + 1 < $this->tiles && $sec * $this->tiles >= ($index + 1) * $this->durationSeconds) {
            $index++;
        } elseif ($index > 0 && $sec * $this->tiles < $index * $this->durationSeconds) {
            $index--;
        }

        return $index;
    }
}
