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

    private function __construct() {}
}
