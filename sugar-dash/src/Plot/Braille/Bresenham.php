<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Braille;

use SugarCraft\Dash\Plot\Canvas\CanvasPoint;

/**
 * Bresenham line algorithm implementation for precise line drawing.
 *
 * Provides integer-only line drawing for high-resolution plotting.
 */
final class Bresenham
{
    /**
     * Generate all points on a line from (x1,y1) to (x2,y2).
     *
     * @return list<CanvasPoint>
     */
    public static function line(int $x1, int $y1, int $x2, int $y2): array
    {
        $points = [];

        $dx = abs($x2 - $x1);
        $dy = -abs($y2 - $y1);
        $sx = $x1 < $x2 ? 1 : -1;
        $sy = $y1 < $y2 ? 1 : -1;
        $err = $dx + $dy;

        $x = $x1;
        $y = $y1;

        while (true) {
            $points[] = new CanvasPoint($x, $y);

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

        return $points;
    }

    /**
     * Generate all points on a circle arc (octant).
     *
     * @return list<CanvasPoint>
     */
    public static function circleArc(int $cx, int $cy, int $radius, int $octant = 0): array
    {
        if ($radius <= 0) {
            return [new CanvasPoint($cx, $cy)];
        }

        $points = [];
        $x = $radius;
        $y = 0;
        $dx = 1 - ($radius << 1);
        $dy = 1;
        $err = 0;

        while ($x >= $y) {
            $points = array_merge($points, self::getOctantPoints($cx, $cy, $x, $y, $octant));

            $y++;
            $err += $dy;
            $dy += 2;

            if ((2 * $err) + $dx > 0) {
                $x--;
                $err += $dx;
                $dx += 2;
            }
        }

        return $points;
    }

    /**
     * Get points for a specific octant of a circle.
     *
     * @return list<CanvasPoint>
     */
    private static function getOctantPoints(int $cx, int $cy, int $x, int $y, int $octant): array
    {
        $allPoints = [
            new CanvasPoint($cx + $x, $cy + $y),
            new CanvasPoint($cx + $y, $cy + $x),
            new CanvasPoint($cx - $y, $cy + $x),
            new CanvasPoint($cx - $x, $cy + $y),
            new CanvasPoint($cx - $x, $cy - $y),
            new CanvasPoint($cx - $y, $cy - $x),
            new CanvasPoint($cx + $y, $cy - $x),
            new CanvasPoint($cx + $x, $cy - $y),
        ];

        if ($octant === 0) {
            return $allPoints;
        }

        return [$allPoints[$octant - 1]];
    }

    /**
     * Check if a point is on the line segment.
     */
    public static function isOnLine(int $x, int $y, int $x1, int $y1, int $x2, int $y2): bool
    {
        $minX = min($x1, $x2);
        $maxX = max($x1, $x2);
        $minY = min($y1, $y2);
        $maxY = max($y1, $y2);

        if ($x < $minX || $x > $maxX || $y < $minY || $y > $maxY) {
            return false;
        }

        // Check using the line equation: (y - y1) / (y2 - y1) = (x - x1) / (x2 - x1)
        if ($x1 === $x2) {
            return $x === $x1 && $y >= $minY && $y <= $maxY;
        }

        if ($y1 === $y2) {
            return $y === $y1 && $x >= $minX && $x <= $maxX;
        }

        // Check collinearity using cross product
        return (($x - $x1) * ($y2 - $y1)) === (($y - $y1) * ($x2 - $x1));
    }
}
