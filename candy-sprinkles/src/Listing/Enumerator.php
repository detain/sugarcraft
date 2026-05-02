<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Listing;

/**
 * Factory of enumerator closures for {@see ItemList}.
 *
 * Each enumerator is `Closure(int $index, int $total): string` returning the
 * marker shown to the left of an item (e.g. "-", "•", "1.", "A.").
 */
final class Enumerator
{
    public static function dash(): \Closure
    {
        return static fn(int $index, int $total): string => '-';
    }

    public static function bullet(): \Closure
    {
        return static fn(int $index, int $total): string => '•';
    }

    public static function asterisk(): \Closure
    {
        return static fn(int $index, int $total): string => '*';
    }

    public static function arabic(): \Closure
    {
        return static fn(int $index, int $total): string => ($index + 1) . '.';
    }

    public static function alphabet(): \Closure
    {
        return static function (int $index, int $total): string {
            // Spreadsheet-style: A..Z, AA..AZ, BA..ZZ, ...
            $n = $index;
            $s = '';
            do {
                $s = chr(0x41 + ($n % 26)) . $s;
                $n = intdiv($n, 26) - 1;
            } while ($n >= 0);
            return $s . '.';
        };
    }

    public static function none(): \Closure
    {
        return static fn(int $index, int $total): string => '';
    }

    /**
     * Lowercase Roman numerals: i. ii. iii. iv. v. …
     */
    public static function roman(): \Closure
    {
        return static function (int $index, int $total): string {
            return self::toRoman($index + 1) . '.';
        };
    }

    /**
     * Uppercase Roman numerals: I. II. III. IV. V. …
     */
    public static function romanUpper(): \Closure
    {
        return static function (int $index, int $total): string {
            return strtoupper(self::toRoman($index + 1)) . '.';
        };
    }

    /**
     * Decimal-dotted: 1. 2. … 9. 10. (alias for arabic, kept for parity
     * with lipgloss's `Decimal`).
     */
    public static function decimal(): \Closure
    {
        return self::arabic();
    }

    private static function toRoman(int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        static $map = [
            ['m', 1000], ['cm', 900], ['d', 500], ['cd', 400],
            ['c',  100], ['xc',  90], ['l',  50], ['xl',  40],
            ['x',   10], ['ix',   9], ['v',   5], ['iv',   4],
            ['i',    1],
        ];
        $out = '';
        foreach ($map as [$sym, $val]) {
            while ($n >= $val) {
                $out .= $sym;
                $n -= $val;
            }
        }
        return $out;
    }

    private function __construct() {}
}
