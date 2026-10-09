<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Ipmi;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\Collect\Ipmi\SensorStatus;
use SugarCraft\Top\Panel\Gfx\Just;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * The ipmi box's drawing primitives, all in theme colours and gradients
 * only, so every theme — and the TTY palette — paints them:
 *  - severity colours come from the `process` gradient (its start / mid /
 *    end are btop's green / yellow / red in Default, mint / lemon / pink
 *    in pastel);
 *  - bars are position gradients over a meter_bg track (btop's meter
 *    law), each family on its own gradient;
 *  - sub-panels are outlined in `div_line`, which the pastel BorderFlow
 *    echoes toward the box's own flow.
 */
final class IpmiPaint
{
    private function __construct()
    {
    }

    /** Foreground for a sensor state: ok / nc / cr+ along `process`, no reading in inactive_fg. */
    public static function status(Ink $ink, SensorStatus $status): string
    {
        return match ($status) {
            SensorStatus::Ok => $ink->gradient('process', 0),
            SensorStatus::NonCritical => $ink->gradient('process', 55),
            SensorStatus::Critical => $ink->gradient('process', 100),
            SensorStatus::NonRecoverable => Symbols::BOLD . $ink->gradient('process', 100),
            default => $ink->fg('inactive_fg'),
        };
    }

    /**
     * A bar `$width` cells wide filled to `$percent` with `$fill` glyphs
     * coloured by position along `$gradient`, the rest `$track` glyphs in
     * meter_bg. Returns an SGR string.
     */
    public static function bar(Ink $ink, int $width, float $percent, string $gradient, string $fill = '■', string $track = '■'): string
    {
        if ($width <= 0) {
            return '';
        }
        $filled = (int) round(max(0.0, min(100.0, $percent)) * $width / 100.0);
        if ($percent > 0 && $filled === 0) {
            $filled = 1;
        }
        $out = '';
        for ($i = 0; $i < $width; $i++) {
            if ($i < $filled) {
                $out .= $ink->gradient($gradient, $width === 1 ? 100 : (int) round($i * 100 / ($width - 1))) . $fill;
            } else {
                $out .= ($i === $filled ? $ink->fg('meter_bg') : '') . $track;
            }
        }

        return $out;
    }

    /**
     * A centred deviation bar: the track spans `$lo`..`$hi`, `$mid` sits
     * on the centre tick, and the reading is a dot reached by a gradient
     * run from the centre — further from nominal = further along
     * `$gradient` (and the dot takes the state colour).
     */
    public static function deviation(Ink $ink, int $width, float $value, float $lo, float $mid, float $hi, string $dot, bool $tty = false): string
    {
        $mark = self::dot($tty);
        if ($width < 3) {
            return $dot . $mark;
        }
        $centre = intdiv($width - 1, 2);
        $pos = $value <= $mid
            ? $centre - ($mid - $lo > 0 ? ($mid - $value) / ($mid - $lo) : 0.0) * $centre
            : $centre + ($hi - $mid > 0 ? ($value - $mid) / ($hi - $mid) : 0.0) * ($width - 1 - $centre);
        $at = (int) round(max(0.0, min($width - 1.0, $pos)));
        $out = '';
        for ($i = 0; $i < $width; $i++) {
            if ($i === $at) {
                $out .= $dot . $mark;
            } elseif (($i > $centre && $i < $at) || ($i < $centre && $i > $at)) {
                $span = max(1, abs($at - $centre));
                $out .= $ink->gradient('free', (int) round(100 * abs($i - $centre) / $span)) . ($tty ? '■' : '━');
            } elseif ($i === $centre) {
                $out .= $ink->fg('graph_text') . '┼';
            } elseif ($i === 0) {
                $out .= $ink->fg('meter_bg') . '├';
            } elseif ($i === $width - 1) {
                $out .= $ink->fg('meter_bg') . '┤';
            } else {
                $out .= $ink->fg('meter_bg') . '─';
            }
        }

        return $out;
    }

    /**
     * A sub-panel outline in div_line with its title embedded at x+2
     * (`┐title┌`, square junctions as btop's sub-boxes) and an optional
     * right-aligned badge on the same edge.
     */
    public static function frame(Region $r, Rect $box, Ink $ink, Border $border, string $title, string $badge = '', int $badgeWidth = 0): void
    {
        if ($box->width < 2 || $box->height < 2) {
            return;
        }
        $line = $ink->fg('div_line');
        $x = $box->x;
        $y = $box->y;
        $right = $x + $box->width - 1;
        $bottom = $y + $box->height - 1;
        $r->put($x, $y, $border->topLeft . str_repeat($border->top, $box->width - 2) . $border->topRight, $line);
        $r->put($x, $bottom, $border->bottomLeft . str_repeat($border->bottom, $box->width - 2) . $border->bottomRight, $line);
        for ($row = $y + 1; $row < $bottom; $row++) {
            $r->put($x, $row, $border->left, $line);
            $r->put($right, $row, $border->right, $line);
        }
        [$open, $close] = $border->embedJunctions(false);
        $room = $box->width - 6;
        $used = 0;
        if ($title !== '' && $room > 0) {
            $text = Just::left($title, min($room, mb_strwidth($title)));
            $r->put($x + 2, $y, $open, $line);
            $r->put($x + 3, $y, $text, Symbols::BOLD . $ink->fg('title'));
            $r->put($x + 3 + mb_strwidth($text), $y, $close, $line);
            $used = mb_strwidth($text) + 4;
        }
        if ($badge !== '' && $badgeWidth > 0 && $badgeWidth + $used + 4 <= $box->width - 2) {
            $bx = $right - 2 - $badgeWidth;
            $r->put($bx - 1, $y, $open, $line);
            $r->ansi($bx, $y, $badge);
            $r->put($bx + $badgeWidth, $y, $close, $line);
        }
    }

    /**
     * The state dot. TTY mode (the Linux console's 512-glyph fonts) keeps
     * to btop's tty glyph set: ■ instead of ●, and no braille anywhere.
     */
    public static function dot(bool $tty): string
    {
        return $tty ? '■' : '●';
    }

    /** Clip a plain string to `$width` display cells (btop ljust with limit). */
    public static function clip(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }

        return mb_strwidth($text) <= $width ? $text : Just::left($text, $width);
    }

    /** Watts as the box prints them: "880 W", "2.06 kW", "10.4 kW". */
    public static function watts(float $w, bool $unit = true): string
    {
        $s = match (true) {
            $w >= 10000 => sprintf('%.1f k', $w / 1000),
            $w >= 1000 => sprintf('%.2f k', $w / 1000),
            default => sprintf('%d ', (int) round($w)),
        };

        return $unit ? $s . 'W' : rtrim($s);
    }

    /** RPM compactly: "980", "16.5k". */
    public static function rpm(float $v): string
    {
        return $v >= 10000 ? sprintf('%.1fk', $v / 1000) : ($v >= 1000 ? sprintf('%.2fk', $v / 1000) : sprintf('%d', (int) round($v)));
    }
}
