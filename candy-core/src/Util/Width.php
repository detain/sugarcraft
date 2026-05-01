<?php

declare(strict_types=1);

namespace CandyCore\Core\Util;

/**
 * Display-width measurement for terminal text.
 *
 * Width is counted in monospace cells. ANSI escape sequences are stripped
 * before measurement. Wide East-Asian characters and most emoji count as 2;
 * zero-width and combining marks count as 0.
 */
final class Width
{
    /**
     * Cell width of a string after stripping ANSI sequences.
     */
    public static function string(string $s): int
    {
        $clean = Ansi::strip($s);
        if ($clean === '') {
            return 0;
        }
        $width = 0;
        $clusters = self::graphemes($clean);
        foreach ($clusters as $g) {
            $width += self::graphemeWidth($g);
        }
        return $width;
    }

    /**
     * Truncate $s so its visible width does not exceed $max.
     * ANSI sequences inside $s are dropped.
     */
    public static function truncate(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        $clean = Ansi::strip($s);
        $out = '';
        $w = 0;
        foreach (self::graphemes($clean) as $g) {
            $gw = self::graphemeWidth($g);
            if ($w + $gw > $max) {
                break;
            }
            $out .= $g;
            $w += $gw;
        }
        return $out;
    }

    /** @return list<string> */
    private static function graphemes(string $s): array
    {
        if (function_exists('grapheme_str_split')) {
            $g = grapheme_str_split($s);
            if (is_array($g)) {
                return $g;
            }
        }
        if (function_exists('mb_str_split')) {
            return mb_str_split($s, 1, 'UTF-8');
        }
        return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function graphemeWidth(string $g): int
    {
        if ($g === '') {
            return 0;
        }
        $cp = self::firstCodepoint($g);
        if ($cp === 0) {
            return 0;
        }
        if (self::isZeroWidth($cp)) {
            return 0;
        }
        if (self::isWide($cp)) {
            return 2;
        }
        return 1;
    }

    private static function firstCodepoint(string $g): int
    {
        if (function_exists('mb_ord')) {
            $cp = mb_ord($g, 'UTF-8');
            return $cp === false ? 0 : $cp;
        }
        $b1 = ord($g[0]);
        if ($b1 < 0x80) return $b1;
        if (($b1 & 0xe0) === 0xc0 && strlen($g) >= 2) {
            return (($b1 & 0x1f) << 6) | (ord($g[1]) & 0x3f);
        }
        if (($b1 & 0xf0) === 0xe0 && strlen($g) >= 3) {
            return (($b1 & 0x0f) << 12) | ((ord($g[1]) & 0x3f) << 6) | (ord($g[2]) & 0x3f);
        }
        if (($b1 & 0xf8) === 0xf0 && strlen($g) >= 4) {
            return (($b1 & 0x07) << 18) | ((ord($g[1]) & 0x3f) << 12)
                 | ((ord($g[2]) & 0x3f) << 6) | (ord($g[3]) & 0x3f);
        }
        return 0;
    }

    private static function isZeroWidth(int $cp): bool
    {
        if ($cp < 0x20) {
            return true;
        }
        if ($cp >= 0x7f && $cp < 0xa0) {
            return true;
        }
        if ($cp === 0x200b || $cp === 0x200c || $cp === 0x200d || $cp === 0xfeff) {
            return true;
        }
        if ($cp >= 0x0300 && $cp <= 0x036f) return true;
        if ($cp >= 0x1ab0 && $cp <= 0x1aff) return true;
        if ($cp >= 0x1dc0 && $cp <= 0x1dff) return true;
        if ($cp >= 0x20d0 && $cp <= 0x20ff) return true;
        if ($cp >= 0xfe00 && $cp <= 0xfe0f) return true;
        if ($cp >= 0xfe20 && $cp <= 0xfe2f) return true;
        return false;
    }

    private static function isWide(int $cp): bool
    {
        if ($cp < 0x1100) return false;
        return ($cp >= 0x1100 && $cp <= 0x115f)
            || ($cp >= 0x2e80 && $cp <= 0x303e)
            || ($cp >= 0x3041 && $cp <= 0x33ff)
            || ($cp >= 0x3400 && $cp <= 0x4dbf)
            || ($cp >= 0x4e00 && $cp <= 0x9fff)
            || ($cp >= 0xa000 && $cp <= 0xa4cf)
            || ($cp >= 0xac00 && $cp <= 0xd7a3)
            || ($cp >= 0xf900 && $cp <= 0xfaff)
            || ($cp >= 0xfe30 && $cp <= 0xfe4f)
            || ($cp >= 0xff00 && $cp <= 0xff60)
            || ($cp >= 0xffe0 && $cp <= 0xffe6)
            || ($cp >= 0x1f300 && $cp <= 0x1f64f)
            || ($cp >= 0x1f680 && $cp <= 0x1f6ff)
            || ($cp >= 0x1f900 && $cp <= 0x1f9ff)
            || ($cp >= 0x20000 && $cp <= 0x2fffd)
            || ($cp >= 0x30000 && $cp <= 0x3fffd);
    }
}
