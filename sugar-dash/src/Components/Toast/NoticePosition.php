<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Toast;

/**
 * Positioning options for toast notifications on screen.
 */
enum NoticePosition: string
{
    case TopLeft = 'top-left';
    case TopCenter = 'top-center';
    case TopRight = 'top-right';
    case BottomLeft = 'bottom-left';
    case BottomCenter = 'bottom-center';
    case BottomRight = 'bottom-right';
    case CenterLeft = 'center-left';
    case CenterRight = 'center-right';
    case Center = 'center';

    /**
     * Get the vertical alignment for this position.
     */
    public function vertical(): string
    {
        return match ($this) {
            self::TopLeft, self::TopCenter, self::TopRight => 'top',
            self::BottomLeft, self::BottomCenter, self::BottomRight => 'bottom',
            self::CenterLeft, self::CenterRight, self::Center => 'center',
        };
    }

    /**
     * Get the horizontal alignment for this position.
     */
    public function horizontal(): string
    {
        return match ($this) {
            self::TopLeft, self::BottomLeft, self::CenterLeft => 'left',
            self::TopRight, self::BottomRight, self::CenterRight => 'right',
            self::TopCenter, self::BottomCenter, self::Center => 'center',
        };
    }

    /**
     * Check if this is a corner position.
     */
    public function isCorner(): bool
    {
        return in_array($this, [
            self::TopLeft,
            self::TopRight,
            self::BottomLeft,
            self::BottomRight,
        ], true);
    }

    /**
     * Check if this is a center position.
     */
    public function isCenter(): bool
    {
        return $this === self::Center;
    }
}
