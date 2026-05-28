<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * Immutable mouse event — x/y are 1-based terminal cell coordinates,
 * matching the coordinate space used by {@see Scanner::hit()}.
 *
 * Mirrors bubblezone's MouseEvent struct.
 */
final readonly class MouseEvent
{
    public function __construct(
        public int $x,
        public int $y,
        public int $button,
        public MouseAction $action,
    ) {}

    /**
     * Convenience factory for a press event.
     */
    public static function press(int $x, int $y, int $button = 0): self
    {
        return new self($x, $y, $button, MouseAction::Press);
    }

    /**
     * Convenience factory for a release event.
     */
    public static function release(int $x, int $y, int $button = 0): self
    {
        return new self($x, $y, $button, MouseAction::Release);
    }

    /**
     * Convenience factory for a drag event.
     */
    public static function drag(int $x, int $y, int $button = 0): self
    {
        return new self($x, $y, $button, MouseAction::Drag);
    }

    /**
     * Convenience factory for a scroll event.
     */
    public static function scroll(int $x, int $y, int $button = 0): self
    {
        return new self($x, $y, $button, MouseAction::Scroll);
    }
}
