<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

/**
 * btop's `clock_format`: a strftime pattern plus the custom tokens
 * `/user`, `/host` and `/uptime`.
 *
 * PHP's strftime() is deprecated (8.1) and locale-global, so the
 * conversions are ported here against the C/POSIX locale — `%X` is
 * `%H:%M:%S`, `%c` is `%a %b %e %H:%M:%S %Y`, as btop prints under
 * LC_TIME=C. An unknown conversion is kept literally (glibc does the same).
 *
 * Mirrors aristocratos/btop Tools::strf_time + Draw::update_clock
 * (src/btop_tools.cpp, src/btop_draw.cpp).
 */
final class ClockFormat
{
    private function __construct()
    {
    }

    /**
     * @param int    $time   unix seconds
     * @param float  $uptime system uptime in seconds (for `/uptime`)
     */
    public static function format(
        string $format,
        int $time,
        string $user = '',
        string $host = '',
        float $uptime = 0.0,
        ?\DateTimeZone $zone = null,
    ): string {
        if ($format === '') {
            return '';
        }
        $out = self::strftime($format, $time, $zone);
        if (str_contains($out, '/uptime')) {
            $up = self::dhms((int) max(0, $uptime));
            // btop drops the seconds once days push it past 8 characters.
            if (strlen($up) > 8) {
                $up = substr($up, 0, -3);
            }
            $out = str_replace('/uptime', $up, $out);
        }

        return str_replace(['/user', '/host'], [$user, $host], $out);
    }

    /** btop sec_to_dhms: `[Nd ]HH:MM:SS`. */
    public static function dhms(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;

        return ($days > 0 ? $days . 'd ' : '')
            . sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** strftime(3) in the C locale. */
    public static function strftime(string $format, int $time, ?\DateTimeZone $zone = null): string
    {
        $dt = (new \DateTimeImmutable('@' . $time))->setTimezone($zone ?? new \DateTimeZone(date_default_timezone_get()));
        $out = '';
        $len = strlen($format);
        for ($i = 0; $i < $len; $i++) {
            $c = $format[$i];
            if ($c !== '%' || $i + 1 >= $len) {
                $out .= $c;
                continue;
            }
            $spec = $format[++$i];
            $out .= match ($spec) {
                'a' => $dt->format('D'),
                'A' => $dt->format('l'),
                'b', 'h' => $dt->format('M'),
                'B' => $dt->format('F'),
                'c' => $dt->format('D M ') . sprintf('%2d', (int) $dt->format('j')) . $dt->format(' H:i:s Y'),
                'C' => sprintf('%02d', intdiv((int) $dt->format('Y'), 100)),
                'd' => $dt->format('d'),
                'D', 'x' => $dt->format('m/d/y'),
                'e' => sprintf('%2d', (int) $dt->format('j')),
                'F' => $dt->format('Y-m-d'),
                'g' => substr($dt->format('o'), -2),
                'G' => $dt->format('o'),
                'H' => $dt->format('H'),
                'I' => $dt->format('h'),
                'j' => sprintf('%03d', (int) $dt->format('z') + 1),
                'k' => sprintf('%2d', (int) $dt->format('G')),
                'l' => sprintf('%2d', (int) $dt->format('g')),
                'm' => $dt->format('m'),
                'M' => $dt->format('i'),
                'n' => "\n",
                'p' => $dt->format('A'),
                'P' => $dt->format('a'),
                'r' => $dt->format('h:i:s A'),
                'R' => $dt->format('H:i'),
                's' => (string) $time,
                'S' => $dt->format('s'),
                't' => "\t",
                'T', 'X' => $dt->format('H:i:s'),
                'u' => $dt->format('N'),
                'V' => $dt->format('W'),
                'w' => $dt->format('w'),
                'y' => $dt->format('y'),
                'Y' => $dt->format('Y'),
                'z' => $dt->format('O'),
                'Z' => $dt->format('T'),
                '%' => '%',
                default => '%' . $spec,
            };
        }

        return $out;
    }
}
