<?php

declare(strict_types=1);

namespace SugarCraft\Input;

/**
 * Modifier key flags for keyboard and mouse events.
 *
 * These are bitmask flags compatible with the Kitty keyboard protocol
 * modifier encoding and the SGR 1006 mouse modifier field.
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 * @api
 */
final class KeyModifier
{
    public const NONE = 0;
    public const SHIFT = 1 << 0;
    public const ALT = 1 << 1;
    public const CTRL = 1 << 2;
    public const META = 1 << 3;
    public const SUPER = 1 << 4;
    public const HYPER = 1 << 5;
    public const CAPS_LOCK = 1 << 6;
    public const NUM_LOCK = 1 << 7;

    /** Singleton instances for common modifier combinations used in type hints. */
    private static ?KeyModifier $none = null;
    private static ?KeyModifier $alt = null;
    private static ?KeyModifier $ctrl = null;
    private static ?KeyModifier $shift = null;
    private static ?KeyModifier $altShift = null;
    private static ?KeyModifier $ctrlShift = null;
    private static ?KeyModifier $altCtrl = null;
    private static ?KeyModifier $all = null;

    private int $mask;

    private function __construct(int $mask)
    {
        $this->mask = $mask;
    }

    /** No modifiers held. */
    public static function none(): self
    {
        return self::$none ??= new self(self::NONE);
    }

    /** Alt modifier. */
    public static function alt(): self
    {
        return self::$alt ??= new self(self::ALT);
    }

    /** Ctrl modifier. */
    public static function ctrl(): self
    {
        return self::$ctrl ??= new self(self::CTRL);
    }

    /** Shift modifier. */
    public static function shift(): self
    {
        return self::$shift ??= new self(self::SHIFT);
    }

    /** Alt+Shift modifiers. */
    public static function altShift(): self
    {
        return self::$altShift ??= new self(self::ALT | self::SHIFT);
    }

    /** Ctrl+Shift modifiers. */
    public static function ctrlShift(): self
    {
        return self::$ctrlShift ??= new self(self::CTRL | self::SHIFT);
    }

    /** Alt+Ctrl modifiers. */
    public static function altCtrl(): self
    {
        return self::$altCtrl ??= new self(self::ALT | self::CTRL);
    }

    /** All modifiers (Shift+Alt+Ctrl). */
    public static function all(): self
    {
        return self::$all ??= new self(self::SHIFT | self::ALT | self::CTRL);
    }

    /**
     * Build a modifier mask from a raw integer (Kitty format: bit 0=Shift,
     * bit 1=Alt, bit 2=Control, bit 3=Meta, bit 4=Super, bit 5=Hyper).
     */
    public static function fromKittyInt(int $raw): self
    {
        $mask = 0;
        if ($raw & 1)  { $mask |= self::SHIFT; }
        if ($raw & 2)  { $mask |= self::ALT; }
        if ($raw & 4)  { $mask |= self::CTRL; }
        if ($raw & 8)  { $mask |= self::META; }
        if ($raw & 16) { $mask |= self::SUPER; }
        if ($raw & 32) { $mask |= self::HYPER; }

        return new self($mask);
    }

    /**
     * Build a modifier mask from an SGR mouse modifier field.
     * SGR uses: bit 0=Shift, bit 1=Alt, bit 2=Ctrl.
     */
    public static function fromSgrMouse(int $raw): self
    {
        $mask = 0;
        if ($raw & 1) { $mask |= self::SHIFT; }
        if ($raw & 2) { $mask |= self::ALT; }
        if ($raw & 4) { $mask |= self::CTRL; }

        return new self($mask);
    }

    /**
     * Check if a specific modifier flag is set.
     *
     * @param int $flag One of the KeyModifier::SHIFT, ::ALT, ::CTRL, etc. constants
     */
    public function includes(int $flag): bool
    {
        return (bool) ($this->mask & $flag);
    }

    /** Get the raw bitmask value. */
    public function value(): int
    {
        return $this->mask;
    }

    public function equals(self $other): bool
    {
        return $this->mask === $other->mask;
    }
}
