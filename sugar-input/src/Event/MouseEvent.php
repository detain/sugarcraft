<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;
use SugarCraft\Input\KeyModifier;

/**
 * A mouse event (SGR 1006/1016, urxvt 1015, X10 1000).
 *
 * Mirrors charmbracelet/bubbletea's input handling.
 * @readonly
 */
final readonly class MouseEvent implements Event
{
    /**
     * Mouse button identifiers. Buttons 0/1/2 are the press buttons; 3 is the
     * X10 (1000) "no button / release" code — SGR instead signals release with
     * the final 'm' byte while keeping the real button number.
     */
    const BUTTON_LEFT   = 0;
    const BUTTON_MIDDLE = 1;
    const BUTTON_RIGHT  = 2;
    const BUTTON_RELEASE = 3;  // X10 release code only (SGR uses final 'm')

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
     * @param int          $button    Button index (0=left, 1=middle, 2=right;
     *                                3 = X10 release code) or normalized wheel
     *                                code 96-99 (see the scroll factories)
     * @param string       $action    press|release|drag|scroll — one of the ACTION_* constants
     * @param KeyModifier  $modifiers Modifiers held during the event
     */
    public function __construct(
        public int $x,
        public int $y,
        public int $button,
        public string $action,
        public KeyModifier $modifiers,
    ) {
        // Parse-don't-validate at the boundary: an unknown action string means
        // a caller invented vocabulary this class does not model.
        if (!in_array($action, [self::ACTION_PRESS, self::ACTION_RELEASE, self::ACTION_DRAG, self::ACTION_SCROLL], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown mouse action "%s"; expected one of press, release, drag, scroll.',
                $action,
            ));
        }
    }

    public function isScroll(): bool
    {
        return $this->action === self::ACTION_SCROLL;
    }

    /** Scroll up — wire button code 64 (SGR/X10), normalized to stored code 96. */
    public static function scrollUp(int $x, int $y, ?KeyModifier $modifiers = null): self
    {
        return new self($x, $y, 96, self::ACTION_SCROLL, $modifiers ?? KeyModifier::none());
    }

    /** Scroll down — wire button code 65 (SGR/X10), normalized to stored code 97. */
    public static function scrollDown(int $x, int $y, ?KeyModifier $modifiers = null): self
    {
        return new self($x, $y, 97, self::ACTION_SCROLL, $modifiers ?? KeyModifier::none());
    }

    /** Horizontal wheel left — wire button code 66 (SGR/X10), stored as 98. */
    public static function scrollLeft(int $x, int $y, ?KeyModifier $modifiers = null): self
    {
        return new self($x, $y, 98, self::ACTION_SCROLL, $modifiers ?? KeyModifier::none());
    }

    /** Horizontal wheel right — wire button code 67 (SGR/X10), stored as 99. */
    public static function scrollRight(int $x, int $y, ?KeyModifier $modifiers = null): self
    {
        return new self($x, $y, 99, self::ACTION_SCROLL, $modifiers ?? KeyModifier::none());
    }
}
