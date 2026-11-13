<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Animation;

use SugarCraft\Spring\Easing\CubicBezier;
use SugarCraft\Overlay\Lines;

/**
 * Scale animation — foreground "grows" from center outward.
 *
 * Lines appear from the middle outward as progress increases.
 * Uses CubicBezier easing for smooth acceleration/deceleration.
 *
 * Native to sugar-overlay (upstream Bubble Tea carries no animation layer —
 * sugar-overlay-028). The default curve is {@see CubicBezier::easeOut()};
 * Fade defaults to easeInOut instead, so per-kind defaults are part of the
 * contract, not a silent swap (sugar-overlay-058).
 */
final class Scale
{
    public function __construct(
        private readonly ?CubicBezier $easing = null,
    ) {
    }

    public static function new(?CubicBezier $easing = null): self
    {
        return new self($easing);
    }

    /**
     * The eased scalar progress for this curve, saturating at the ends.
     * Veil::animate() drives the scrim ramp from the SAME value the content
     * glides on (sugar-overlay-066).
     */
    public function easedProgress(float $progress): float
    {
        if ($progress <= 0.0) {
            return 0.0;
        }
        if ($progress >= 1.0) {
            return 1.0;
        }

        return \max(0.0, \min(1.0, $this->easing()->evaluate($progress)));
    }

    /**
     * Get the easing function, falling back to easeOut if not set.
     */
    private function easing(): CubicBezier
    {
        return $this->easing ?? CubicBezier::easeOut();
    }

    /**
     * Apply scale animation to the foreground at the given progress.
     *
     * @param string $foreground The overlay content
     * @param float  $progress   Animation progress 0.0–1.0
     * @return string The scaled foreground (subset of lines from center)
     */
    public function apply(string $foreground, float $progress): string
    {
        if ($progress <= 0.0) {
            return '';
        }

        if ($progress >= 1.0) {
            return $foreground;
        }

        $eased = $this->easing()->evaluate($progress);

        // Count lines exactly as Veil::composite() does, so a trailing "\n"
        // does not add a phantom row to the reveal budget.
        $lines = Lines::split($foreground);
        $totalLines = \count($lines);

        if ($totalLines === 0) {
            return '';
        }

        // At eased progress, show a portion of lines from center
        // Scale 0 = 0 lines, Scale 1 = all lines
        $visibleCount = (int) \max(1, \round($eased * $totalLines));
        $visibleCount = \min($totalLines, $visibleCount);

        // Calculate the center line index
        $center = $totalLines / 2;

        // Start from center and expand outward
        $startLine = (int) \floor($center - $visibleCount / 2);
        $startLine = \max(0, $startLine);

        $scaledLines = \array_slice($lines, $startLine, $visibleCount);

        return \implode("\n", $scaledLines);
    }
}
