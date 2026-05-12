<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

/**
 * Mouse event.
 */
final class MouseEvent extends Event
{
    public const BUTTON_LEFT = 0;
    public const BUTTON_MIDDLE = 1;
    public const BUTTON_RIGHT = 2;
    public const BUTTON_RELEASE = 3;
    public const WHEEL_UP = 4;
    public const WHEEL_DOWN = 5;

    public function __construct(
        int $timestamp,
        public readonly int $x,
        public readonly int $y,
        public readonly int $button,
        public readonly bool $ctrl = false,
        public readonly bool $alt = false,
        public readonly bool $shift = false,
    ) {
        parent::__construct($timestamp);
    }

    public function getType(): string
    {
        return 'mouse';
    }

    /**
     * Check if this is a click event.
     */
    public function isClick(): bool
    {
        return $this->button >= 0 && $this->button <= 2;
    }

    /**
     * Check if this is a scroll event.
     */
    public function isScroll(): bool
    {
        return $this->button === self::WHEEL_UP || $this->button === self::WHEEL_DOWN;
    }

    /**
     * Check if this is a drag event.
     */
    public function isDrag(): bool
    {
        return $this->button === self::BUTTON_RELEASE;
    }
}
