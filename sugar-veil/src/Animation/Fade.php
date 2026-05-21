<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Animation;

use SugarCraft\Bounce\Easing\CubicBezier;

/**
 * Fade animation — foreground opacity increases from 0 to 1.
 *
 * Note: True per-character alpha blending is not widely supported in
 * terminal emulators. This implementation is a best-effort that degrades
 * gracefully. The animation state is tracked but the visual effect may
 * not be visible in all terminals.
 *
 * To use the fade effect, the composite() call would need to be wrapped
 * by the caller to render at different progress values.
 */
final class Fade
{
    public function __construct(
        private readonly ?CubicBezier $easing = null,
    ) {
    }

    private function easing(): CubicBezier
    {
        return $this->easing ?? CubicBezier::easeInOut();
    }

    /**
     * Apply fade animation to the foreground at the given progress.
     *
     * Since terminals don't support true alpha blending, this returns
     * the foreground unchanged. The easing progress is still calculated
     * for use by the animation system.
     *
     * @param string $foreground The overlay content
     * @param float  $progress   Animation progress 0.0–1.0
     * @return string The foreground (unchanged due to terminal limitations)
     */
    public function apply(string $foreground, float $progress): string
    {
        // True alpha blending is not reliably supported in terminals.
        // The foreground is returned unchanged; the animation progress
        // is tracked by the Veil and can be used for external rendering.
        return $foreground;
    }

    /**
     * Get the opacity value for this progress (0-100).
     *
     * @return int 0-100 representing the visible opacity
     */
    public function opacity(float $progress): int
    {
        if ($progress <= 0.0) {
            return 0;
        }
        if ($progress >= 1.0) {
            return 100;
        }
        $eased = $this->easing()->evaluate($progress);
        return (int) \max(0, \min(100, \round($eased * 100)));
    }
}
