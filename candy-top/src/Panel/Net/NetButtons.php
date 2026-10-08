<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Net;

/**
 * Where btop's net title-row buttons sit, as box-local 0-based columns —
 * shared by the painter and the mouse hit-test so the two can never drift.
 *
 * btop draws with `Mv::to(y, x + width - i_size - N)` (1-based `x` = the
 * box's left border), which is local column `width - i_size - N`; the
 * mouse_mappings entries `{y, x + width - i_size - M, 1, w}` become the
 * `[column, cells]` pairs below. `i_size` is the selected interface name's
 * width capped at {@see MAX_IFNAMSIZ}.
 *
 * Mirrors aristocratos/btop Net::draw (src/btop_draw.cpp:1534-1556, 1560-1562).
 */
final class NetButtons
{
    /** btop `Net::MAX_IFNAMSIZ`. */
    public const MAX_IFNAMSIZ = 15;

    private function __construct()
    {
    }

    /**
     * Mouse targets: key => [first local column, cells] on the title row.
     * `a` exists only when `width - i_size - 20 > 6`, `y` when `> 13`.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function targets(int $width, int $iSize): array
    {
        $t = [
            'b' => [$width - $iSize - 8, 3],
            'n' => [$width - 6, 3],
            'z' => [$width - $iSize - 14, 4],
        ];
        if (self::hasAuto($width, $iSize)) {
            $t['a'] = [$width - $iSize - 20, 4];
        }
        if (self::hasSync($width, $iSize)) {
            $t['y'] = [$width - $iSize - 26, 4];
        }

        return $t;
    }

    /** Column of the `┐←b iface n→┌` embed's opening junction. */
    public static function ifaceAt(int $width, int $iSize): int
    {
        return $width - $iSize - 9;
    }

    public static function zeroAt(int $width, int $iSize): int
    {
        return $width - $iSize - 15;
    }

    public static function autoAt(int $width, int $iSize): int
    {
        return $width - $iSize - 21;
    }

    public static function syncAt(int $width, int $iSize): int
    {
        return $width - $iSize - 27;
    }

    public static function hasAuto(int $width, int $iSize): bool
    {
        return $width - $iSize - 20 > 6;
    }

    public static function hasSync(int $width, int $iSize): bool
    {
        return $width - $iSize - 20 > 13;
    }

    /** btop draws the address at x + 8 only while `width - i_size - 36 > ip.size()`. */
    public static function ipFits(int $width, int $iSize, int $ipLength): bool
    {
        return $ipLength > 0 && $width - $iSize - 36 > $ipLength;
    }

    /** The column of the IP embed's opening junction. */
    public const IP_AT = 8;
}
