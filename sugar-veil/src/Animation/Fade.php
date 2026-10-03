<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Animation;

use SugarCraft\Bounce\Easing\CubicBezier;

/**
 * Fade animation — the foreground brightens in from black as progress grows.
 *
 * Terminals cannot alpha-blend glyphs, so the fade is rendered the same way
 * Veil's backdrop dim is: a truecolor foreground pen blended toward black by
 * the eased opacity (see opacity()). While the fade runs (opacity strictly
 * between 0 and 100) every glyph of the overlay is drawn in that neutral
 * gray — the pen is re-applied after each SGR sequence inside a line, so the
 * content's own foreground colours and SGR resets cannot punch through it.
 * Background colours and attributes (bold, underline, …) are left alone.
 *
 * At opacity 0 the overlay is fully transparent and apply() returns '' (the
 * compositor then paints the backdrop only, as Scale does at progress 0); at
 * opacity 100 the foreground is returned byte-for-byte, original colours
 * included.
 */
final class Fade
{
    /** CSI SGR sequence: parameter bytes 0x30–0x3F, intermediates 0x20–0x2F, final 'm'. */
    private const SGR = '/\e\[[\x30-\x3F]*[\x20-\x2F]*m/';

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
     * @param string $foreground The overlay content
     * @param float  $progress   Animation progress 0.0–1.0
     * @return string '' at opacity 0, the foreground unchanged at opacity
     *                100, otherwise every non-empty line drawn in the faded
     *                gray pen and closed with SGR 39
     */
    public function apply(string $foreground, float $progress): string
    {
        $opacity = $this->opacity($progress);
        if ($opacity >= 100) {
            return $foreground;
        }
        if ($opacity <= 0) {
            return '';
        }

        $level = (int) \round(255 * $opacity / 100);
        $pen = "\e[38;2;{$level};{$level};{$level}m";

        $lines = \explode("\n", $foreground);
        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }
            $repinned = \preg_replace(self::SGR, '$0' . $pen, $line);
            if ($repinned === null) {
                throw new \RuntimeException('Fade::apply() could not scan the foreground for SGR sequences.');
            }
            $lines[$i] = $pen . $repinned . "\e[39m";
        }

        return \implode("\n", $lines);
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
