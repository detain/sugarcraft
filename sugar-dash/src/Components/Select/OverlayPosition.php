<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Select;

/**
 * Positioning options for overlay components like dropdowns, tooltips, and modals.
 */
enum OverlayPosition: string
{
    case Top = 'top';
    case Bottom = 'bottom';
    case Left = 'left';
    case Right = 'right';
    case TopStart = 'top-start';
    case TopEnd = 'top-end';
    case BottomStart = 'bottom-start';
    case BottomEnd = 'bottom-end';
    case LeftStart = 'left-start';
    case LeftEnd = 'left-end';
    case RightStart = 'right-start';
    case RightEnd = 'right-end';
    case Center = 'center';

    /**
     * Get the opposite position.
     */
    public function opposite(): self
    {
        return match ($this) {
            self::Top => self::Bottom,
            self::Bottom => self::Top,
            self::Left => self::Right,
            self::Right => self::Left,
            self::TopStart => self::BottomStart,
            self::TopEnd => self::BottomEnd,
            self::BottomStart => self::TopStart,
            self::BottomEnd => self::TopEnd,
            self::LeftStart => self::RightStart,
            self::LeftEnd => self::RightEnd,
            self::RightStart => self::LeftStart,
            self::RightEnd => self::LeftEnd,
            self::Center => self::Center,
        };
    }

    /**
     * Check if this is a horizontal position.
     */
    public function isHorizontal(): bool
    {
        return in_array($this, [self::Left, self::Right, self::LeftStart, self::LeftEnd, self::RightStart, self::RightEnd], true);
    }

    /**
     * Check if this is a vertical position.
     */
    public function isVertical(): bool
    {
        return in_array($this, [self::Top, self::Bottom, self::TopStart, self::TopEnd, self::BottomStart, self::BottomEnd], true);
    }
}
