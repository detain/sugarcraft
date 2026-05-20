<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

/**
 * Screen position for toast alerts.
 *
 * @see https://github.com/daltonsw/bubbleup
 */
enum Position
{
    case TopLeft;
    case TopCenter;
    case TopRight;
    case BottomLeft;
    case BottomCenter;
    case BottomRight;
    case MiddleLeft;
    case MiddleCenter;
    case MiddleRight;

    /**
     * Compute the X offset in a viewport of given dimensions.
     */
    public function xOffset(int $alertWidth, int $viewportWidth): int
    {
        return match ($this) {
            self::TopLeft, self::BottomLeft, self::MiddleLeft        => 0,
            self::TopCenter, self::BottomCenter, self::MiddleCenter  => (int) \floor(($viewportWidth - $alertWidth) / 2),
            self::TopRight, self::BottomRight, self::MiddleRight     => $viewportWidth - $alertWidth,
        };
    }

    /**
     * Compute the Y offset in a viewport of given dimensions.
     *
     * @param int $alertHeight  Number of lines the alert takes
     * @param int $viewportHeight  Total viewport height
     * @param int $totalAlertLines  Total height of all stacked alerts at this position
     */
    public function yOffset(int $alertHeight, int $viewportHeight, int $totalAlertLines = 0): int
    {
        return match ($this) {
            self::TopLeft, self::TopCenter, self::TopRight
                => 0,
            self::BottomLeft, self::BottomCenter, self::BottomRight
                => $viewportHeight - $alertHeight - $totalAlertLines,
            self::MiddleLeft, self::MiddleCenter, self::MiddleRight
                => (int) \floor(($viewportHeight - $alertHeight) / 2) - $totalAlertLines,
        };
    }
}
