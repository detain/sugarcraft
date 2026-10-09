<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Ipmi;

use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Symbols;

/**
 * A semicircular "radio" gauge drawn in braille (2×4 dots per cell, so
 * the arc is smooth at any size): a thick outer arc filled from the left
 * to the reading, coloured by position along a theme gradient, the rest
 * a thin meter_bg rail; a thin inner arc spanning the BMC window's min..max
 * with the average marked; and a needle at the reading.
 *
 * One cell has one colour, so where layers meet in a cell the
 * higher-priority layer draws its own dots alone: needle > fill > average
 * mark > window > track.
 */
final class PowerGauge
{
    /** Braille dot bits by [column][row] inside a cell. */
    private const BITS = [[0x01, 0x02, 0x04, 0x40], [0x08, 0x10, 0x20, 0x80]];

    private const NEEDLE = 0;
    private const FILL = 1;
    private const AVG = 2;
    private const WINDOW = 3;
    private const TRACK = 4;

    private function __construct()
    {
    }

    /**
     * Rows that fit a gauge `$width` cells wide: its dot radius is
     * min(width, 4·height) / 1 — a round arc needs height ≈ width / 4.
     */
    public static function height(int $width): int
    {
        return max(2, (int) ceil($width / 4));
    }

    /**
     * `$height` SGR rows of exactly `$width` cells.
     *
     * @param float  $value  0..1 of full scale
     * @param ?float $min    window minimum, 0..1 (null = no window arc)
     * @param ?float $avg    window average, 0..1
     * @param ?float $max    window maximum, 0..1
     * @return list<string>
     */
    public static function render(Ink $ink, int $width, int $height, float $value, ?float $min, ?float $avg, ?float $max, string $gradient = 'cpu'): array
    {
        if ($width < 4 || $height < 2) {
            return array_fill(0, max(0, $height), str_repeat(' ', max(0, $width)));
        }
        $dw = 2 * $width;
        $dh = 4 * $height;
        $cx = ($dw - 1) / 2;
        $cy = $dh - 0.5;
        $radius = min($dw / 2, $dh) - 0.5;
        $thick = max(2.2, $radius * 0.3);
        $band = $radius - $thick - 1.6;
        $value = max(0.0, min(1.0, $value));
        $theta = M_PI * (1 - $value);
        $needleFrom = $radius * 0.28;
        $needleTo = $radius - $thick + 0.6;

        /** @var array<int, array<int, int>> $cells cell index → layer → bits */
        $cells = [];
        $fsum = [];
        $fcnt = [];
        $set = static function (int $x, int $y, int $layer) use (&$cells, $width): void {
            if ($x < 0 || $y < 0) {
                return;
            }
            $cell = intdiv($y, 4) * $width + intdiv($x, 2);
            $cells[$cell][$layer] = ($cells[$cell][$layer] ?? 0) | self::BITS[$x % 2][$y % 4];
        };
        for ($y = 0; $y < $dh; $y++) {
            for ($x = 0; $x < $dw; $x++) {
                $dx = $x - $cx;
                $dy = $cy - $y;
                $r = sqrt($dx * $dx + $dy * $dy);
                $f = 1.0 - atan2(max(0.0, $dy), $dx) / M_PI;
                if ($r <= $radius && $r > $radius - $thick) {
                    $layer = $f <= $value + 1e-9 && $value > 0 ? self::FILL : self::TRACK;
                    if ($layer === self::TRACK && abs($r - ($radius - $thick / 2)) > 0.6) {
                        continue; // the unfilled arc is a thin rail along the band's middle, so the fill reads at a glance
                    }
                    $set($x, $y, $layer);
                    if ($layer === self::FILL) {
                        $cell = intdiv($y, 4) * $width + intdiv($x, 2);
                        $fsum[$cell] = ($fsum[$cell] ?? 0.0) + $f;
                        $fcnt[$cell] = ($fcnt[$cell] ?? 0) + 1;
                    }
                } elseif ($min !== null && $max !== null && $band > 3 && $r <= $band && $r > $band - 1.15) {
                    if ($avg !== null && abs($f - $avg) <= 0.022) {
                        $set($x, $y, self::AVG);
                    } elseif ($f >= $min - 1e-9 && $f <= $max + 1e-9) {
                        $set($x, $y, self::WINDOW);
                    }
                }
            }
        }
        for ($t = $needleFrom; $t <= $needleTo; $t += 0.35) {
            $set((int) round($cx + cos($theta) * $t), (int) round($cy - sin($theta) * $t), self::NEEDLE);
        }

        $styles = [
            self::NEEDLE => Symbols::BOLD . $ink->fg('title'),
            self::AVG => $ink->fg('hi_fg'),
            self::WINDOW => $ink->fg('graph_text'),
            self::TRACK => $ink->fg('meter_bg'),
        ];
        $rows = [];
        for ($row = 0; $row < $height; $row++) {
            $line = '';
            $current = null;
            for ($col = 0; $col < $width; $col++) {
                $cell = $row * $width + $col;
                $layers = $cells[$cell] ?? [];
                if ($layers === []) {
                    $line .= ($current !== '' ? "\x1b[0m" : '') . ' ';
                    $current = '';
                    continue;
                }
                ksort($layers);
                $layer = array_key_first($layers);
                $bits = $layers[$layer];
                if ($layer === self::AVG && isset($layers[self::WINDOW])) {
                    $bits |= $layers[self::WINDOW];
                }
                $style = $layer === self::FILL
                    ? $ink->gradient($gradient, (int) round(100 * $fsum[$cell] / max(1, $fcnt[$cell])))
                    : $styles[$layer];
                if ($style !== $current) {
                    $line .= "\x1b[0m" . $style;
                    $current = $style;
                }
                $line .= mb_chr(0x2800 + $bits, 'UTF-8');
            }
            $rows[] = $line . "\x1b[0m";
        }

        return $rows;
    }
}
