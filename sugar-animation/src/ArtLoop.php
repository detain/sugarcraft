<?php

declare(strict_types=1);

namespace SugarCraft\Animation;

use InvalidArgumentException;

/**
 * Pure frame-pacing math for animated ANSI art. The `.ansi` corpus carries
 * no per-frame delays (see {@see AnsiArt}), so playback speed is a caller
 * policy: each frame shows for `$frameSeconds`, and the LAST frame is then
 * held an extra `$pauseSeconds` before the loop restarts at frame 0 — the
 * beat where the animation "breathes" on screen.
 *
 * Kept free of any clock or timer: `frameAt(elapsedSeconds)` is a total
 * function of wall-clock offset, which makes it directly testable and lets
 * hosts drive it from whatever tick source they already own.
 *
 * Deliberate exception to the fleet `Concerns\Mutable` law: `withPause()`
 * re-mints through {@see self::new()} instead of a blind copy, so every
 * variant re-passes the full construction door (negative durations, the
 * never-advancing cycle, the product-overflow guard). For a total value
 * object like this one, re-validation on every derivative is the cheaper
 * contract to keep than a second set of guards inside the mutate path.
 */
final class ArtLoop
{
    private function __construct(
        public readonly int $frameCount,
        public readonly float $frameSeconds,
        public readonly float $pauseSeconds,
    ) {}

    /**
     * @throws InvalidArgumentException on a frame-less loop, negative or
     *         non-finite durations, or a cycle that could never advance
     *         (both durations zero)
     */
    public static function new(int $frameCount, float $frameSeconds = 0.1, float $pauseSeconds = 2.5): self
    {
        if ($frameCount < 1) {
            throw new InvalidArgumentException("ArtLoop: frameCount must be >= 1, got {$frameCount}");
        }
        self::guardDuration($frameSeconds, 'frameSeconds');
        self::guardDuration($pauseSeconds, 'pauseSeconds');
        // Per-value guards pass while the PRODUCT overflows to INF (a near
        // PHP_FLOAT_MAX frameSeconds times any frameCount >= 2), so the cycle
        // is checked as composed — same refuse tone as the non-finite gate
        // in frameAt().
        $cycle = $frameCount * $frameSeconds + $pauseSeconds;
        if (!is_finite($cycle)) {
            throw new InvalidArgumentException("ArtLoop: cycle overflows to a non-finite duration (frameCount × frameSeconds + pauseSeconds = {$cycle})");
        }
        if ($cycle <= 0.0) {
            throw new InvalidArgumentException('ArtLoop: cycle would never advance (frameSeconds and pauseSeconds both zero)');
        }

        return new self($frameCount, $frameSeconds, $pauseSeconds);
    }

    /** Fluent pause variant — the dwell on the last frame is the usual tuning knob. */
    public function withPause(float $pauseSeconds): self
    {
        return self::new($this->frameCount, $this->frameSeconds, $pauseSeconds);
    }

    /** One full lap: every frame once, plus the closing hold on the last. */
    public function cycleSeconds(): float
    {
        return $this->frameCount * $this->frameSeconds + $this->pauseSeconds;
    }

    /**
     * Which frame is on screen at an elapsed offset. Boundaries land on the
     * later frame (a frame owns [start, start+duration) ).
     *
     * @throws InvalidArgumentException on negative or non-finite elapsed time
     *         (INF/NAN would otherwise fmod to NAN and silently read as frame 0)
     */
    public function frameAt(float $elapsedSeconds): int
    {
        if (!is_finite($elapsedSeconds)) {
            throw new InvalidArgumentException("ArtLoop: elapsedSeconds must be finite, got {$elapsedSeconds}");
        }
        if ($elapsedSeconds < 0.0) {
            throw new InvalidArgumentException("ArtLoop: elapsedSeconds must be >= 0, got {$elapsedSeconds}");
        }

        $position = fmod($elapsedSeconds, $this->cycleSeconds());
        $lastFrameStart = ($this->frameCount - 1) * $this->frameSeconds;
        if ($position >= $lastFrameStart) {
            return $this->frameCount - 1; // includes the pause hold
        }

        // frameSeconds > 0 is guaranteed here: a zero frameSeconds with a
        // positive cycle puts every position in the last-frame window above.
        return (int) floor($position / $this->frameSeconds);
    }

    private static function guardDuration(float $seconds, string $name): void
    {
        if (!is_finite($seconds)) {
            throw new InvalidArgumentException("ArtLoop: {$name} must be finite, got {$seconds}");
        }
        if ($seconds < 0.0) {
            throw new InvalidArgumentException("ArtLoop: {$name} must be >= 0, got {$seconds}");
        }
    }
}
