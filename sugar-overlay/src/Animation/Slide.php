<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Animation;

use SugarCraft\Spring\Easing\CubicBezier;
use SugarCraft\Overlay\AnimationFrame;
use SugarCraft\Overlay\Lines;
use SugarCraft\Overlay\MarkedFrame;
use SugarCraft\Overlay\Position;

/**
 * Slide animation — foreground enters from the anchor direction.
 *
 * At progress 0, the foreground sits fully OFF-SCREEN on the anchor side
 * (a LEFT anchor starts at columns −fgWidth, a TOP anchor starts at rows
 * −fgHeight, …) and travels toward its final position as progress grows;
 * at progress 1 the offsets are zero. Negative-x/y placement is clipped
 * away by Veil::composite(), so progress 0 shows nothing rather than a
 * sliver.
 *
 * `CENTER` on an axis contributes no displacement on THAT axis — the
 * other axis still travels; a slide anchored `CENTER` on both axes is the
 * deliberate no-op (content parked at its final cells, scrim still
 * ramps). Anchor at an edge (TOP/LEFT/RIGHT/BOTTOM or a corner) to get
 * movement on that axis.
 *
 * The offset is applied via the returned vertical/horizontal offsets
 * which should be added to the composite() call's xOffset/yOffset.
 *
 * Native to sugar-overlay (upstream Bubble Tea carries no animation layer —
 * the old "matches upstream's horizontal slide semantics" claim, retired by
 * sugar-overlay-028). The default curve is {@see CubicBezier::easeOut()};
 * Fade defaults to easeInOut instead, so per-kind defaults are part of the
 * contract, not a silent swap (sugar-overlay-058).
 */
final class Slide
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
     * glides on (sugar-overlay-066). The travel offsets themselves keep
     * using the raw curve — an overshoot that slides a hair past its
     * landing is visible in the motion and stays.
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

    /**
     * Get the easing function, falling back to easeOut if not set.
     */
    /** Memoized default curve (sugar-overlay-077): animation ticks news a
     *  driver per frame and each driver used to news this cubic afresh. */
    private static ?CubicBezier $defaultEasing = null;

    private function easing(): CubicBezier
    {
        return $this->easing ?? (self::$defaultEasing ??= CubicBezier::easeOut());
    }

    /**
     * The typed per-frame result: the foreground plus the anchor-relative
     * travel offsets to add to the composite() call (sugar-overlay-013 —
     * the shape the untyped array form used to hide).
     *
     * @param string   $foreground  The overlay content
     * @param float    $progress    Animation progress 0.0–1.0
     * @param Position $vertical    Vertical anchor
     * @param Position $horizontal  Horizontal anchor
     */
    public function applyFrame(
        string $foreground,
        float $progress,
        Position $vertical,
        Position $horizontal,
    ): AnimationFrame {
        $eased = $this->easing()->ease($progress);

        // Measure exactly as Veil::composite() does, so a trailing "\n"
        // does not add a phantom row to the travel distance.
        $lines = Lines::split($foreground);
        $fgHeight = \count($lines);
        $fgWidth = $this->maxWidth($lines);

        $factor = 1.0 - $eased;

        $vOffset = 0;
        $hOffset = 0;

        // A LEFT-anchored overlay's final column is the left edge, so it must
        // START left of there (negative = off-screen left) and travel right.
        // A RIGHT anchor mirrors it. TOP/BOTTOM do the same on the row axis.
        if ($this->isLeftAnchor($horizontal)) {
            $hOffset = -(int) \round($factor * $fgWidth);
        } elseif ($this->isRightAnchor($horizontal)) {
            $hOffset = (int) \round($factor * $fgWidth);
        }

        if ($this->isTopAnchor($vertical)) {
            $vOffset = -(int) \round($factor * $fgHeight);
        } elseif ($this->isBottomAnchor($vertical)) {
            $vOffset = (int) \round($factor * $fgHeight);
        }

        return new AnimationFrame(
            foreground: $foreground,
            verticalOffset: $vOffset,
            horizontalOffset: $hOffset,
        );
    }

    /**
     * The legacy array shape (public since 1.x), delegating to
     * {@see applyFrame()} — source-compatible until the 2.0 flip. New
     * consumers take the typed frame; the magic-key array is the compat
     * reading, not the contract.
     *
     * @return array{foreground: string, verticalOffset: int, horizontalOffset: int}
     */
    public function apply(
        string $foreground,
        float $progress,
        Position $vertical,
        Position $horizontal,
    ): array {
        $frame = $this->applyFrame($foreground, $progress, $vertical, $horizontal);

        return [
            'foreground'       => $frame->foreground,
            'verticalOffset'   => $frame->verticalOffset,
            'horizontalOffset' => $frame->horizontalOffset,
        ];
    }

    private function isTopAnchor(Position $v): bool
    {
        return match ($v) {
            Position::TOP, Position::TOP_LEFT, Position::TOP_RIGHT => true,
            default => false,
        };
    }

    private function isBottomAnchor(Position $v): bool
    {
        return match ($v) {
            Position::BOTTOM, Position::BOTTOM_LEFT, Position::BOTTOM_RIGHT => true,
            default => false,
        };
    }

    private function isLeftAnchor(Position $h): bool
    {
        return match ($h) {
            Position::LEFT, Position::TOP_LEFT, Position::BOTTOM_LEFT => true,
            default => false,
        };
    }

    private function isRightAnchor(Position $h): bool
    {
        return match ($h) {
            Position::RIGHT, Position::TOP_RIGHT, Position::BOTTOM_RIGHT => true,
            default => false,
        };
    }

    /** @param list<string> $lines */
    private function maxWidth(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $w = $this->strWidth($line);
            if ($w > $max) {
                $max = $w;
            }
        }
        return $max;
    }

    private function strWidth(string $str): int
    {
        // Travel distance must equal the columns composite() actually
        // paints: zone envelopes/links cost zero cells there, so Width::string
        // (which charges their bytes) would slide a marked modal too far.
        return MarkedFrame::measure($str);
    }
}
