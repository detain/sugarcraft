<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Animation;

use SugarCraft\Bounce\Easing\CubicBezier;

/**
 * Scale animation — foreground "grows" from center outward.
 *
 * Lines appear from the middle outward as progress increases.
 * Uses CubicBezier easing for smooth acceleration/deceleration.
 */
final class Scale
{
    public function __construct(
        private readonly ?CubicBezier $easing = null,
    ) {
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

        $lines = \explode("\n", $foreground);
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
