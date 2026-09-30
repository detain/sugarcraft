<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

/**
 * Parses the `--fps` option at the CLI boundary into a render cadence.
 *
 * F2 (round 90): before this door existed, any `is_numeric` string reached
 * the render pipeline, and `--fps 0` produced a float division-by-zero in
 * {@see \SugarCraft\Vcr\Render\FrameStream} (or, past the int-casting
 * encoder paths, a `1000 % 0` ArithmeticError / hang). The command layer
 * now parses fps into a trusted positive finite float — internals get a
 * typed value or the run never starts.
 */
final class FpsOption
{
    /**
     * @param mixed $raw the raw option value (string from argv, int/float
     *                   from programmatic callers, or null when absent)
     *
     * @return float|null null means "not provided — use the caller's
     *                    default"; a provided value must be a positive
     *                    finite number or this throws
     *
     * @throws \InvalidArgumentException when the value is not numeric, is
     *         not finite, or is not strictly greater than zero
     */
    public static function parse(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException(
                "candy-vcr: --fps must be a positive number, got " . var_export($raw, true) . "."
            );
        }
        $fps = (float) $raw;
        if (!is_finite($fps) || $fps <= 0.0) {
            throw new \InvalidArgumentException(
                "candy-vcr: --fps must be a positive finite number of frames per second, got " . var_export($raw, true) . "."
            );
        }
        return $fps;
    }

    private function __construct()
    {
    }
}
