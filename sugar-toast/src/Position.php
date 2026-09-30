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
     * @param int $alertHeight  Number of lines this alert takes
     * @param int $viewportHeight  Total viewport height
     * @param int $stackedHeightBefore  Cumulative height of the alerts placed
     *                                  BEFORE this one: top families stack
     *                                  downward from it, while bottom and
     *                                  middle families stack UPWARD against
     *                                  it (each further alert rides one box
     *                                  higher, clamped to row 0 on short
     *                                  viewports).
     */
    public function yOffset(int $alertHeight, int $viewportHeight, int $stackedHeightBefore = 0): int
    {
        return match ($this) {
            self::TopLeft, self::TopCenter, self::TopRight
                => $stackedHeightBefore,
            // WHY: candy-buffer clamps negative y but the silent disappearance
            // (toast rendering at negative y = clipped) is the real defect.
            // Pinning to top edge (y=0) makes the toast visible rather than
            // vanishing when the viewport is too short for the requested stack.
            self::BottomLeft, self::BottomCenter, self::BottomRight
                => \max(0, $viewportHeight - $alertHeight - $stackedHeightBefore),
            self::MiddleLeft, self::MiddleCenter, self::MiddleRight
                => \max(0, (int) \floor(($viewportHeight - $alertHeight) / 2) - $stackedHeightBefore),
        };
    }
}
