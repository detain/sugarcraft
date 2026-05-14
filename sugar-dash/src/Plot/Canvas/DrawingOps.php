<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Canvas;

use SugarCraft\Core\Util\Color;

/**
 * Drawing operations helper class with static methods for common drawing operations.
 *
 * Mirrors charmbracelet/bubbletea drawing math patterns.
 */
final class DrawingOps
{
    /**
     * Draw a horizontal line.
     */
    public static function drawHLine(
        int $x1,
        int $y,
        int $x2,
        string $char,
        ?Color $fg,
        ?Color $bg,
        Canvas $canvas,
        int $widthConstraint,
        int $heightConstraint,
    ): Canvas {
        $minX = min($x1, $x2);
        $maxX = max($x1, $x2);

        for ($x = $minX; $x <= $maxX; $x++) {
            if ($x < 0 || $x >= $widthConstraint || $y < 0 || $y >= $heightConstraint) {
                continue;
            }
            $canvas = $canvas->setPixel($x, $y, $char, $fg, $bg);
        }

        return $canvas;
    }

    /**
     * Draw a vertical line.
     */
    public static function drawVLine(
        int $x,
        int $y1,
        int $y2,
        string $char,
        ?Color $fg,
        ?Color $bg,
        Canvas $canvas,
        int $widthConstraint,
        int $heightConstraint,
    ): Canvas {
        $minY = min($y1, $y2);
        $maxY = max($y1, $y2);

        for ($y = $minY; $y <= $maxY; $y++) {
            if ($x < 0 || $x >= $widthConstraint || $y < 0 || $y >= $heightConstraint) {
                continue;
            }
            $canvas = $canvas->setPixel($x, $y, $char, $fg, $bg);
        }

        return $canvas;
    }

    /**
     * Draw a rectangle outline.
     */
    public static function drawRect(
        int $x,
        int $y,
        int $w,
        int $h,
        string $char,
        ?Color $fg,
        ?Color $bg,
        Canvas $canvas,
        int $widthConstraint,
        int $heightConstraint,
    ): Canvas {
        // Top and bottom
        $canvas = self::drawHLine($x, $y, $x + $w - 1, $char, $fg, $bg, $canvas, $widthConstraint, $heightConstraint);
        $canvas = self::drawHLine($x, $y + $h - 1, $x + $w - 1, $char, $fg, $bg, $canvas, $widthConstraint, $heightConstraint);

        // Left and right (avoiding corners)
        $canvas = self::drawVLine($x, $y + 1, $y + $h - 2, $char, $fg, $bg, $canvas, $widthConstraint, $heightConstraint);
        $canvas = self::drawVLine($x + $w - 1, $y + 1, $y + $h - 2, $char, $fg, $bg, $canvas, $widthConstraint, $heightConstraint);

        return $canvas;
    }

    /**
     * Fill a rectangle.
     *
     * @param callable $setPixel fn(int $x, int $y, string $char, ?Color $fg, ?Color $bg): Canvas
     */
    public static function fillRect(
        int $x,
        int $y,
        int $w,
        int $h,
        string $char,
        ?Color $fg,
        ?Color $bg,
        Canvas $canvas,
        int $widthConstraint,
        int $heightConstraint,
    ): Canvas {
        for ($dy = 0; $dy < $h; $dy++) {
            for ($dx = 0; $dx < $w; $dx++) {
                if ($x + $dx < 0 || $x + $dx >= $widthConstraint || $y + $dy < 0 || $y + $dy >= $heightConstraint) {
                    continue;
                }
                $canvas = $canvas->setPixel($x + $dx, $y + $dy, $char, $fg, $bg);
            }
        }

        return $canvas;
    }

    /**
     * Draw a circle outline using the midpoint circle algorithm.
     */
    public static function drawCircle(
        int $cx,
        int $cy,
        int $radius,
        string $char,
        ?Color $fg,
        ?Color $bg,
        Canvas $canvas,
        int $widthConstraint,
        int $heightConstraint,
    ): Canvas {
        if ($radius <= 0) {
            return $canvas;
        }

        $x = $radius;
        $y = 0;
        $dx = 1 - ($radius << 1);
        $dy = 1;
        $err = 0;

        while ($x >= $y) {
            // 8 octants
            $canvas = self::plotCirclePoints($cx, $cy, $x, $y, $char, $fg, $bg, $canvas, $widthConstraint, $heightConstraint);

            $y++;
            $err += $dy;
            $dy += 2;

            if ((2 * $err) + $dx > 0) {
                $x--;
                $err += $dx;
                $dx += 2;
            }
        }

        return $canvas;
    }

    /**
     * Fill a circle.
     */
    public static function fillCircle(
        int $cx,
        int $cy,
        int $radius,
        string $char,
        ?Color $fg,
        ?Color $bg,
        Canvas $canvas,
        int $widthConstraint,
        int $heightConstraint,
    ): Canvas {
        if ($radius <= 0) {
            return $canvas;
        }

        for ($dy = -$radius; $dy <= $radius; $dy++) {
            for ($dx = -$radius; $dx <= $radius; $dx++) {
                if (($dx * $dx) + ($dy * $dy) <= ($radius * $radius)) {
                    if ($cx + $dx < 0 || $cx + $dx >= $widthConstraint || $cy + $dy < 0 || $cy + $dy >= $heightConstraint) {
                        continue;
                    }
                    $canvas = $canvas->setPixel($cx + $dx, $cy + $dy, $char, $fg, $bg);
                }
            }
        }

        return $canvas;
    }

    /**
     * Plot the 8 points of a circle octant.
     *
     * @param callable $setPixel fn(int $x, int $y, string $char, ?Color $fg, ?Color $bg): Canvas
     */
    private static function plotCirclePoints(
        int $cx,
        int $cy,
        int $x,
        int $y,
        string $char,
        ?Color $fg,
        ?Color $bg,
        Canvas $canvas,
        int $widthConstraint,
        int $heightConstraint,
    ): Canvas {
        $points = [
            [$cx + $x, $cy + $y],
            [$cx + $y, $cy + $x],
            [$cx - $y, $cy + $x],
            [$cx - $x, $cy + $y],
            [$cx - $x, $cy - $y],
            [$cx - $y, $cy - $x],
            [$cx + $y, $cy - $x],
            [$cx + $x, $cy - $y],
        ];

        foreach ($points as [$px, $py]) {
            if ($px >= 0 && $px < $widthConstraint && $py >= 0 && $py < $heightConstraint) {
                $canvas = $canvas->setPixel($px, $py, $char, $fg, $bg);
            }
        }

        return $canvas;
    }

    /**
     * Draw ASCII text at the given position.
     */
    public static function drawText(
        int $x,
        int $y,
        string $text,
        ?Color $fg,
        ?Color $bg,
        Canvas $canvas,
        int $widthConstraint,
        int $heightConstraint,
    ): Canvas {
        $chars = mb_str_split($text);

        foreach ($chars as $i => $char) {
            if ($x + $i < 0 || $x + $i >= $widthConstraint || $y < 0 || $y >= $heightConstraint) {
                continue;
            }
            $canvas = $canvas->setPixel($x + $i, $y, $char, $fg, $bg);
        }

        return $canvas;
    }

    /**
     * Draw a line using Bresenham's algorithm.
     *
     * @param callable $setPixel fn(int $x, int $y, string $char, ?Color $fg, ?Color $bg): Canvas
     */
    public static function drawLine(
        int $x1,
        int $y1,
        int $x2,
        int $y2,
        string $char,
        ?Color $fg,
        ?Color $bg,
        callable $setPixel,
        int $widthConstraint,
        int $heightConstraint,
    ): Canvas {
        $canvas = $setPixel;

        $dx = abs($x2 - $x1);
        $dy = -abs($y2 - $y1);
        $sx = $x1 < $x2 ? 1 : -1;
        $sy = $y1 < $y2 ? 1 : -1;
        $err = $dx + $dy;

        $x = $x1;
        $y = $y1;

        while (true) {
            if ($x >= 0 && $x < $widthConstraint && $y >= 0 && $y < $heightConstraint) {
                $canvas = $setPixel($x, $y, $char, $fg, $bg);
            }

            if ($x === $x2 && $y === $y2) {
                break;
            }

            $e2 = 2 * $err;

            if ($e2 >= $dy) {
                $err += $dy;
                $x += $sx;
            }

            if ($e2 <= $dx) {
                $err += $dx;
                $y += $sy;
            }
        }

        return $canvas;
    }
}
