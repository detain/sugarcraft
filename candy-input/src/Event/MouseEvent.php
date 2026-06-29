<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;
use SugarCraft\Input\KeyModifier;

/**
 * A mouse event (SGR 1006).
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 * @readonly
 */
final readonly class MouseEvent implements Event
{
    /**
     * Mouse button identifiers.
     */
    const BUTTON_LEFT   = 0;
    const BUTTON_MIDDLE = 1;
    const BUTTON_RIGHT  = 2;
    const BUTTON_RELEASE = 3;  // used in SGR for button release encoding

    /**
     * Mouse action types.
     */
    const ACTION_PRESS   = 'press';
    const ACTION_RELEASE = 'release';
    const ACTION_DRAG    = 'drag';
    const ACTION_SCROLL  = 'scroll';

    /**
     * @param int          $x         Column (1-based, matches terminal coordinates)
     * @param int          $y         Row (1-based)
     * @param int          $button    Button index (0=left, 1=middle, 2=right) or 3=release
     * @param string       $action   press|release|drag|scroll
     * @param KeyModifier  $modifiers
     */
    public function __construct(
        public int $x,
        public int $y,
        public int $button,
        public string $action,
        public KeyModifier $modifiers,
    ) {}

    public function isScroll(): bool
    {
        return $this->action === self::ACTION_SCROLL;
    }

    /** Scroll up (button 96 encoding in SGR) */
    public static function scrollUp(int $x, int $y, ?KeyModifier $modifiers = null): self
    {
        return new self($x, $y, 96, self::ACTION_SCROLL, $modifiers ?? KeyModifier::none());
    }

    /** Scroll down (button 97 encoding in SGR) */
    public static function scrollDown(int $x, int $y, ?KeyModifier $modifiers = null): self
    {
        return new self($x, $y, 97, self::ACTION_SCROLL, $modifiers ?? KeyModifier::none());
    }
}
