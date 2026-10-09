<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Ipmi;

use SugarCraft\Top\View\Ink;

/**
 * The draw over time under the power gauge: a braille area chart, two
 * samples per cell (newest at the right edge), filled up to each sample,
 * each row coloured by its height along a theme gradient (the top rows
 * are the hot end), with a dotted meter_bg baseline where no sample is
 * yet.
 */
final class PowerHistory
{
    private const BITS = [[0x01, 0x02, 0x04, 0x40], [0x08, 0x10, 0x20, 0x80]];

    private function __construct()
    {
    }

    /**
     * A one-row braille sparkline, two samples per cell, newest at the
     * right; each cell coloured along `$gradient` by its higher sample's
     * place between `$low` and `$high`.
     *
     * @param list<float> $values oldest first
     */
    public static function spark(Ink $ink, int $width, array $values, float $low, float $high, string $gradient): string
    {
        if ($width < 1) {
            return '';
        }
        $values = \array_slice($values, -2 * $width);
        $offset = 2 * $width - \count($values);
        $span = max(1e-9, $high - $low);
        $out = '';
        for ($col = 0; $col < $width; $col++) {
            $bits = 0;
            $top = -1.0;
            for ($side = 0; $side < 2; $side++) {
                $i = 2 * $col + $side - $offset;
                if ($i < 0 || !isset($values[$i])) {
                    continue;
                }
                $f = max(0.0, min(1.0, ($values[$i] - $low) / $span));
                $top = max($top, $f);
                $level = max(1, (int) round($f * 4));
                for ($dy = 4 - $level; $dy < 4; $dy++) {
                    $bits |= self::BITS[$side][$dy];
                }
            }
            $out .= $bits === 0
                ? $ink->fg('meter_bg') . '⣀'
                : $ink->gradient($gradient, (int) round($top * 100)) . mb_chr(0x2800 + $bits, 'UTF-8');
        }

        return $out . "\x1b[0m";
    }

    /**
     * `$height` SGR rows of exactly `$width` cells.
     *
     * @param list<float> $values oldest first
     * @return list<string>
     */
    public static function render(Ink $ink, int $width, int $height, array $values, float $scale, string $gradient = 'cpu'): array
    {
        if ($width < 1 || $height < 1) {
            return [];
        }
        $dots = 4 * $height;
        $columns = 2 * $width;
        $values = \array_slice($values, -$columns);
        $offset = $columns - \count($values);
        $levels = [];
        foreach ($values as $i => $v) {
            $levels[$offset + $i] = (int) round(max(0.0, min(1.0, $v / max(1e-9, $scale))) * $dots);
        }
        $rows = [];
        for ($row = 0; $row < $height; $row++) {
            $line = $ink->gradient($gradient, (int) round(100 * ($height - $row - 0.5) / $height));
            for ($col = 0; $col < $width; $col++) {
                $bits = 0;
                $empty = true;
                for ($side = 0; $side < 2; $side++) {
                    $x = 2 * $col + $side;
                    if (!isset($levels[$x])) {
                        continue;
                    }
                    $empty = false;
                    for ($dy = 0; $dy < 4; $dy++) {
                        $fromBottom = $dots - (4 * $row + $dy);
                        if ($fromBottom <= max(1, $levels[$x])) {
                            $bits |= self::BITS[$side][$dy];
                        }
                    }
                }
                if ($empty && $row === $height - 1) {
                    $line .= $ink->fg('meter_bg') . '⣀' . $ink->gradient($gradient, (int) round(100 * 0.5 / $height));
                } else {
                    $line .= $bits === 0 ? ' ' : mb_chr(0x2800 + $bits, 'UTF-8');
                }
            }
            $rows[] = $line . "\x1b[0m";
        }

        return $rows;
    }
}
