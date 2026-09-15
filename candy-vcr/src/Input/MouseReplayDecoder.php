<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Input;

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;

/**
 * Decodes recorded raw mouse byte sequences into candy-core mouse Msgs
 * using the encoding the program actually enabled, as tracked by
 * {@see MouseModeTracker}.
 *
 * The default input path ({@see \SugarCraft\Core\InputReader}) only knows
 * the SGR form `CSI < b ; x ; y M/m`, which is self-identifying — so it
 * keeps handling SGR and everything else, and that behaviour is unchanged.
 * This decoder covers the forms that carry no version marker of their own
 * and would otherwise be silently dropped or mis-parsed as keypresses on
 * replay:
 *
 * - X10 `ESC [ M b x y` — valid while mode 1000/1002/1003 is on.
 * - UTF-8 coordinates (mode 1005) — same lead, coordinates as UTF-8
 *   codepoints (`coord = codepoint - 32`).
 * - urxvt (mode 1015) `CSI b ; x ; y M` — numeric, coordinate-bearing,
 *   terminated by `M` without the `<` marker.
 *
 * Returns null (deferring to the caller's fallback) for SGR sequences,
 * for payloads that are not exactly one complete mouse sequence, and for
 * byte shapes whose mode is not currently enabled — decoding X10 bytes
 * when only 1015 was set would be guessing.
 *
 * Documented limitation: mode 1016 pixel reports (`CSI < b ; x ; y ; z M`)
 * are deliberately NOT decoded — {@see MouseMsg} carries 1-based cell
 * coordinates and has no pixel fields, so a faithful decode is impossible;
 * they fall through exactly as they did before this class existed.
 */
final class MouseReplayDecoder
{
    /**
     * @return MouseMsg|null the decoded event, or null when this payload is
     *                       not a mode-enabled, self-contained legacy mouse
     *                       sequence (caller should fall back to InputReader)
     */
    public static function decode(string $bytes, MouseModeTracker $modes): ?MouseMsg
    {
        if (str_starts_with($bytes, "\x1b[M")) {
            return self::decodeLegacy(substr($bytes, 3), $modes);
        }

        return self::decodeUrxvt($bytes, $modes);
    }

    /**
     * X10 3-byte form, optionally with UTF-8 (1005) encoded coordinates.
     */
    private static function decodeLegacy(string $rest, MouseModeTracker $modes): ?MouseMsg
    {
        if (strlen($rest) === 0) {
            return null;
        }

        $button = ord($rest[0]) - 32;
        if ($button < 0) {
            return null; // X10 button byte always carries the +32 bias
        }

        if ($modes->enabled(MouseModeTracker::UTF8_COORDINATES)) {
            $xLength = self::leadByteLength(ord($rest[1] ?? ''));
            $yLength = $xLength === 0 ? 0 : self::leadByteLength(ord($rest[1 + $xLength] ?? ''));
            if ($xLength === 0 || $yLength === 0 || strlen($rest) !== 1 + $xLength + $yLength) {
                return null;
            }
            $x = self::utf8Coordinate($rest, 1);
            $y = self::utf8Coordinate($rest, 1 + $xLength);
            if ($x === null || $y === null) {
                return null;
            }

            return self::eventFromBits($button, $x, $y);
        }

        if (!$modes->legacyReportingActive()) {
            return null;
        }
        if (strlen($rest) !== 3) {
            return null; // not exactly one X10 event — let the fallback parser try
        }
        $x = ord($rest[1]);
        $y = ord($rest[2]);
        if ($x < 32 || $y < 32) {
            return null; // X10 coordinate bytes always carry the +32 bias
        }

        return self::eventFromBits($button, $x - 32, $y - 32);
    }

    /**
     * urxvt `CSI b ; x ; y M` (mode 1015). Unlike SGR there is no `<`
     * marker, so the numeric shape alone is ambiguous with other CSI
     * reports — only decode when the mode that produces it is on.
     */
    private static function decodeUrxvt(string $bytes, MouseModeTracker $modes): ?MouseMsg
    {
        if (!$modes->enabled(MouseModeTracker::URXVT_COORDINATES)) {
            return null;
        }
        if (preg_match('/\A\x1b\[(\d+);(\d+);(\d+)M\z/', $bytes, $m) !== 1) {
            return null;
        }
        $button = (int) $m[1] - 32;
        if ($button < 0) {
            return null; // 1015 button param carries the same +32 bias as X10
        }

        return self::eventFromBits($button, (int) $m[2], (int) $m[3]);
    }

    /**
     * Map a decoded button code + coordinates onto the MouseMsg subclass,
     * mirroring {@see \SugarCraft\Core\InputReader}'s SGR button semantics
     * so every encoding produces identical Msgs for identical events.
     * Legacy encodings signal release with button code 3 (there is no
     * press/release suffix byte as SGR's `m` provides).
     */
    private static function eventFromBits(int $b, int $x, int $y): MouseMsg
    {
        $shift = ($b & 0x04) !== 0;
        $alt   = ($b & 0x08) !== 0;
        $ctrl  = ($b & 0x10) !== 0;

        $isMotion = ($b & 0x20) !== 0;
        $isWheel  = ($b & 0x40) !== 0;
        $isExtra  = ($b & 0x80) !== 0;
        $btnBits  = $b & 0x03;

        $isRelease = $btnBits === 3 && !$isWheel && !$isExtra;

        if ($isWheel) {
            $button = $btnBits === 0 ? MouseButton::WheelUp : MouseButton::WheelDown;
            $action = MouseAction::Press;
        } elseif ($isExtra) {
            $button = $btnBits === 0 ? MouseButton::Backward : MouseButton::Forward;
            $action = $isMotion ? MouseAction::Motion : ($isRelease ? MouseAction::Release : MouseAction::Press);
        } else {
            $button = match ($btnBits) {
                0       => MouseButton::Left,
                1       => MouseButton::Middle,
                2       => MouseButton::Right,
                default => MouseButton::None,
            };
            $action = $isMotion
                ? MouseAction::Motion
                : ($isRelease ? MouseAction::Release : MouseAction::Press);
        }

        $class = match (true) {
            $isWheel                          => MouseWheelMsg::class,
            $action === MouseAction::Motion   => MouseMotionMsg::class,
            $action === MouseAction::Release  => MouseReleaseMsg::class,
            default                           => MouseClickMsg::class,
        };

        return new $class($x, $y, $button, $action, $shift, $alt, $ctrl);
    }

    /**
     * Decode one UTF-8 codepoint at $offset and apply the X10 +32 bias,
     * as mode 1005 does per the xterm specification.
     */
    private static function utf8Coordinate(string $bytes, int $offset): ?int
    {
        $first = ord($bytes[$offset] ?? '');
        $length = self::leadByteLength($first);
        if ($length === 0 || $offset + $length > strlen($bytes)) {
            return null;
        }

        $codepoint = $length === 1 ? $first : ($first & (0xFF >> ($length + 1)));
        for ($i = 1; $i < $length; $i++) {
            $continuation = ord($bytes[$offset + $i]);
            if (($continuation & 0xC0) !== 0x80) {
                return null;
            }
            $codepoint = ($codepoint << 6) | ($continuation & 0x3F);
        }

        // Reject overlong forms and surrogates: a real 1005 terminal only
        // emits canonical encodings, and decoding the rest would invent
        // coordinates no encoder can produce (e.g. 0, violating 1-based x/y).
        $canonicalMinimum = match ($length) {
            1 => 0x00,
            2 => 0x80,
            3 => 0x800,
            default => 0x10000,
        };
        if (
            $codepoint < $canonicalMinimum
            || $codepoint > 0x10FFFF
            || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)
        ) {
            return null;
        }

        // 1005 coordinates carry the same +32 bias as X10; anything below
        // the bias is a control byte, not a coordinate.
        return $codepoint < 32 ? null : $codepoint - 32;
    }

    /** Expected byte length of a UTF-8 sequence from its lead byte; 0 = invalid lead. */
    private static function leadByteLength(int $lead): int
    {
        return match (true) {
            $lead < 0x80 => 1,
            $lead >= 0xC2 && $lead <= 0xDF => 2,
            $lead >= 0xE0 && $lead <= 0xEF => 3,
            $lead >= 0xF0 && $lead <= 0xF4 => 4,
            default => 0,
        };
    }
}
