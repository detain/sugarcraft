<?php

declare(strict_types=1);

namespace SugarCraft\Input;

/**
 * Modifier key flags for keyboard and mouse events.
 *
 * These are internal STORAGE bits in bubbletea's order (META=8, SUPER=16,
 * HYPER=32) — not wire values. The Kitty keyboard protocol and the SGR 1006
 * mouse modifier field use a different wire map (Super=8, Hyper=16, Meta=32;
 * in the legacy Kitty form bit 32 doubles as the release flag), and the
 * translation happens exclusively in `fromKittyInt()`, `fromXtermParam()`
 * and `fromSgrMouse()` — never cast a raw wire field onto these constants.
 *
 * Mirrors charmbracelet/bubbletea's `input.Mod` bitmask — the modifier
 * state carried with a key, translated from each protocol's wire bits.
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

    /** Union of every defined storage bit — the range guard for fromMask(). */
    private const DEFINED_BITS = self::SHIFT | self::ALT | self::CTRL | self::META
        | self::SUPER | self::HYPER | self::CAPS_LOCK | self::NUM_LOCK;

    /**
     * Pre-defined instances for the common combinations; the comparison API
     * is equals(). Every factory routes through cached(), so equal masks are
     * identical objects — fromKittyInt(0) === fromSgrMouse(0) ===
     * fromXtermParam(1) === none() holds by the memoization law, and that
     * law must not be broken by future factories.
     */
    private static array $cache = [];

    private function __construct(private readonly int $mask)
    {
    }

    /** Return the canonical instance for a storage mask (memoization law). */
    private static function cached(int $mask): self
    {
        return self::$cache[$mask] ??= new self($mask);
    }

    /** No modifiers held. */
    public static function none(): self
    {
        return self::cached(self::NONE);
    }

    /** Alt modifier. */
    public static function alt(): self
    {
        return self::cached(self::ALT);
    }

    /** Ctrl modifier. */
    public static function ctrl(): self
    {
        return self::cached(self::CTRL);
    }

    /** Shift modifier. */
    public static function shift(): self
    {
        return self::cached(self::SHIFT);
    }

    /** Alt+Shift modifiers. */
    public static function altShift(): self
    {
        return self::cached(self::ALT | self::SHIFT);
    }

    /** Ctrl+Shift modifiers. */
    public static function ctrlShift(): self
    {
        return self::cached(self::CTRL | self::SHIFT);
    }

    /** Alt+Ctrl modifiers. */
    public static function altCtrl(): self
    {
        return self::cached(self::ALT | self::CTRL);
    }

    /**
     * Shift+Alt+Ctrl — the bits the legacy CSI wire forms can express.
     *
     * Extended storage bits (META/SUPER/HYPER/locks) can never arrive on the
     * legacy `& 0x1f`-masked forms, so no "everything set" variant exists;
     * the former name `all()` overstated the coverage.
     */
    public static function shiftAltCtrl(): self
    {
        return self::cached(self::SHIFT | self::ALT | self::CTRL);
    }

    /**
     * @deprecated Renamed to {@see shiftAltCtrl()} — the old name promised
     * every modifier while only the legacy 0x1f trio was ever covered.
     * Alias retained for one release.
     */
    public static function all(): self
    {
        return self::shiftAltCtrl();
    }

    /**
     * Rebuild an instance from a stored mask value (the IPC/bridge seam for
     * hosts that persisted `value()`). Refuses anything outside the defined
     * bit union — a phantom bit fails fast at the boundary instead of
     * travelling silently through the event stream.
     */
    public static function fromMask(int $mask): self
    {
        if ($mask < 0 || ($mask & ~self::DEFINED_BITS) !== 0) {
            throw new \InvalidArgumentException(
                "modifier mask $mask carries bits outside the defined KeyModifier union (0x00-0x"
                . dechex(self::DEFINED_BITS) . ')'
            );
        }

        return self::cached($mask);
    }

    /**
     * Build a modifier mask from a raw Kitty modifier bitmask (bits already
     * de-based by the caller: the wire field is 1 + mask). Bit layout per the
     * Kitty keyboard protocol: bit 0=Shift, bit 1=Alt, bit 2=Control,
     * bit 3=Super, bit 4=Hyper, bit 5=Meta, bit 6=CapsLock, bit 7=NumLock.
     *
     * The lock-state bits arrive only on the explicit-event-type form (legacy
     * event flags mask the field to 0x1f before this method sees it).
     *
     * @see https://sw.kovidgoyal.net/kitty/keyboard-protocol/ - modifier keys
     */
    public static function fromKittyInt(int $raw): self
    {
        $mask = 0;
        if ($raw & 1)   { $mask |= self::SHIFT; }
        if ($raw & 2)   { $mask |= self::ALT; }
        if ($raw & 4)   { $mask |= self::CTRL; }
        if ($raw & 8)   { $mask |= self::SUPER; }
        if ($raw & 16)  { $mask |= self::HYPER; }
        if ($raw & 32)  { $mask |= self::META; }
        if ($raw & 64)  { $mask |= self::CAPS_LOCK; }
        if ($raw & 128) { $mask |= self::NUM_LOCK; }

        return self::cached($mask);
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

        return self::cached($mask);
    }

    /**
     * Build a modifier mask from an xterm modified-key parameter.
     *
     * Xterm encodes modifiers as 1 + bitmask where bit 0=Shift,
     * bit 1=Alt, bit 2=Ctrl, bit 3=Meta. A field of 1 (or less) carries no
     * modifier at all: the public factory refuses to de-base into -1 and
     * fabricate every bit (the decoder's own callers keep their mods<1
     * pre-filters; this guard closes the public-entry bypass).
     *
     * Mirrors the xterm modifier key sequence format.
     */
    public static function fromXtermParam(int $xtermParam): self
    {
        if ($xtermParam <= 0) {
            return self::none();
        }

        $mask = 0;
        if (($xtermParam - 1) & 1) { $mask |= self::SHIFT; }
        if (($xtermParam - 1) & 2) { $mask |= self::ALT; }
        if (($xtermParam - 1) & 4) { $mask |= self::CTRL; }
        if (($xtermParam - 1) & 8) { $mask |= self::META; }

        return self::cached($mask);
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
