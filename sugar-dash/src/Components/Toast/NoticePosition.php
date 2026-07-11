<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Toast;

use SugarCraft\Toast\Position as ToastPosition;

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
    case Anchor = 'anchor';

    /**
     * Get the vertical alignment for this position.
     */
    public function vertical(): string
    {
        return match ($this) {
            self::TopLeft, self::TopCenter, self::TopRight => 'top',
            self::BottomLeft, self::BottomCenter, self::BottomRight => 'bottom',
            self::CenterLeft, self::CenterRight, self::Center => 'center',
            self::Anchor => 'anchor',
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
            self::Anchor => 'anchor',
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

    /**
     * Check if this is an anchor position (positioned relative to an element).
     */
    public function isAnchor(): bool
    {
        return $this === self::Anchor;
    }

    /**
     * Map onto the sugar-toast {@see ToastPosition} used by the shared engine.
     *
     * The six corner/edge positions map 1:1; the vertically-centred edges fold
     * onto the engine's Middle* variants. `Anchor` (positioned relative to an
     * element) has no sugar-toast equivalent, so it falls back to TopRight —
     * the conventional floating-toast corner.
     */
    public function toToastPosition(): ToastPosition
    {
        return match ($this) {
            self::TopLeft      => ToastPosition::TopLeft,
            self::TopCenter    => ToastPosition::TopCenter,
            self::TopRight     => ToastPosition::TopRight,
            self::BottomLeft   => ToastPosition::BottomLeft,
            self::BottomCenter => ToastPosition::BottomCenter,
            self::BottomRight  => ToastPosition::BottomRight,
            self::CenterLeft   => ToastPosition::MiddleLeft,
            self::CenterRight  => ToastPosition::MiddleRight,
            self::Center       => ToastPosition::MiddleCenter,
            // No engine equivalent for an element-anchored toast.
            self::Anchor       => ToastPosition::TopRight,
        };
    }
}
