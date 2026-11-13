<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Animation;

use SugarCraft\Spring\Easing\CubicBezier;

/**
 * Fade animation — the foreground brightens in from black as progress grows.
 *
 * Terminals cannot alpha-blend glyphs, so the fade is rendered the same way
 * Veil's backdrop dim is: a truecolor foreground pen blended toward black by
 * the eased opacity (see opacity()). While the fade runs (opacity strictly
 * between 0 and 100) every glyph of the overlay is drawn in that neutral
 * gray — the pen is re-applied after each SGR sequence inside a line, so the
 * content's own foreground colors and SGR resets cannot punch through it.
 * Background colors and attributes (bold, underline, …) are left alone.
 *
 * At opacity 0 the overlay is fully transparent and apply() returns '' (the
 * compositor then paints the backdrop only, as Scale does at progress 0); at
 * opacity 100 the foreground is returned byte-for-byte, original colors
 * included.
 *
 * Native to sugar-overlay (upstream Bubble Tea carries no animation layer —
 * sugar-overlay-028). The default curve is {@see CubicBezier::easeInOut()};
 * Slide and Scale default to easeOut instead, so per-kind defaults are part
 * of the contract, not a silent swap (sugar-overlay-058).
 */
final class Fade
{
    /** CSI SGR sequence: parameter bytes 0x30–0x3F, intermediates 0x20–0x2F, final 'm'. */
    private const SGR = '/\e\[[\x30-\x3F]*[\x20-\x2F]*m/';

    public function __construct(
        private readonly ?CubicBezier $easing = null,
    ) {
    }

    public static function new(?CubicBezier $easing = null): self
    {
        return new self($easing);
    }

    /**
     * The eased scalar progress for this curve (0.0–1.0, saturating at the
     * ends). Veil::animate() drives the scrim ramp from the SAME value the
     * content glides on (sugar-overlay-066).
     */
    public function easedProgress(float $progress): float
    {
        if ($progress <= 0.0) {
            return 0.0;
        }
        if ($progress >= 1.0) {
            return 1.0;
        }

        return \max(0.0, \min(1.0, $this->easing()->ease($progress)));
    }

    /** Memoized default curve (sugar-overlay-077): animation ticks news a
     *  driver per frame and each driver used to news this cubic afresh. */
    private static ?CubicBezier $defaultEasing = null;

    private function easing(): CubicBezier
    {
        return $this->easing ?? (self::$defaultEasing ??= CubicBezier::easeInOut());
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

        // Raw explode/implode is load-bearing (sugar-overlay-060): apply()
        // must round-trip the foreground's bytes — a trailing "\n" stays.
        // Lines::split would drop that tail, silently changing output for
        // every host that reads apply() directly instead of through the
        // compositor (which splits for itself anyway).
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
        // Overshoot curves (back/elastic) can ease past the interval —
        // opacity saturates at the ends exactly as the old guards did.
        return (int) \max(0, \min(100, \round($this->easedProgress($progress) * 100)));
    }
}
