<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Animation;

use SugarCraft\Bounce\Easing\CubicBezier;
use SugarCraft\Veil\Position;

/**
 * Slide animation — foreground enters from the anchor direction.
 *
 * At progress 0, the foreground is offset in the slide direction.
 * At progress 1, the foreground is in its final position.
 * The offset is applied via the returned vertical/horizontal offsets
 * which should be added to the composite() call's xOffset/yOffset.
 */
final class Slide
{
    public function __construct(
        private readonly ?CubicBezier $easing = null,
    ) {
    }

    private function easing(): CubicBezier
    {
        return $this->easing ?? CubicBezier::easeOut();
    }

    /**
     * Apply slide animation to the foreground at the given progress.
     *
     * @param string   $foreground  The overlay content
     * @param float    $progress    Animation progress 0.0–1.0
     * @param Position $vertical    Vertical anchor
     * @param Position $horizontal  Horizontal anchor
     * @return array{foreground: string, verticalOffset: int, horizontalOffset: int}
     */
    public function apply(
        string $foreground,
        float $progress,
        Position $vertical,
        Position $horizontal,
    ): array {
        $eased = $this->easing()->evaluate($progress);

        $lines = \explode("\n", $foreground);
        $fgHeight = \count($lines);
        $fgWidth = $this->maxWidth($lines);

        $factor = 1.0 - $eased;

        $vOffset = 0;
        $hOffset = 0;

        if ($this->isLeftAnchor($vertical, $horizontal)) {
            $hOffset = (int) \round($factor * $fgWidth);
        } elseif ($this->isRightAnchor($vertical, $horizontal)) {
            $hOffset = -(int) \round($factor * $fgWidth);
        }

        if ($this->isTopAnchor($vertical, $horizontal)) {
            $vOffset = (int) \round($factor * $fgHeight);
        } elseif ($this->isBottomAnchor($vertical, $horizontal)) {
            $vOffset = -(int) \round($factor * $fgHeight);
        }

        return [
            'foreground' => $foreground,
            'verticalOffset' => $vOffset,
            'horizontalOffset' => $hOffset,
        ];
    }

    private function isTopAnchor(Position $v, Position $h): bool
    {
        return match ($v) {
            Position::TOP, Position::TOP_LEFT, Position::TOP_RIGHT => true,
            default => false,
        };
    }

    private function isBottomAnchor(Position $v, Position $h): bool
    {
        return match ($v) {
            Position::BOTTOM, Position::BOTTOM_LEFT, Position::BOTTOM_RIGHT => true,
            default => false,
        };
    }

    private function isLeftAnchor(Position $v, Position $h): bool
    {
        return match ($h) {
            Position::LEFT, Position::TOP_LEFT, Position::BOTTOM_LEFT => true,
            default => false,
        };
    }

    private function isRightAnchor(Position $v, Position $h): bool
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
            if ($w > $max) $max = $w;
        }
        return $max;
    }

    private function strWidth(string $str): int
    {
        $clean = \preg_replace('/\x1b\[[0-9;]*m/', '', $str);
        return \mb_strwidth($clean ?? '');
    }
}
