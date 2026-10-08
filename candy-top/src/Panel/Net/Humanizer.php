<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Net;

/**
 * btop's full `floating_humanizer`: byte or bit counts with binary (KiB,
 * Kib) or decimal (kB, kb) units, the `shorten` form the graph scale label
 * uses ("10K") and the per-second suffixes ("/s" for bytes, "ps" for bits).
 *
 * The integer law is kept exactly: the value is scaled by 100 (×8 for
 * bits), divided down by 1024 (`>>= 10`) or 1000 while it is at least
 * 102400 / 100000, then two decimal places are carved out of the digit
 * string. `{:.1f}` / `{:.0f}` are reproduced with sprintf, which rounds the
 * same way fmt does (correctly rounded, ties to even on exact halves).
 *
 * Mirrors aristocratos/btop Tools::floating_humanizer (src/btop_tools.cpp:419-517).
 */
final class Humanizer
{
    private const BIN_BIT = ['bit', 'Kib', 'Mib', 'Gib', 'Tib', 'Pib', 'Eib', 'Zib', 'Yib', 'Rib', 'Qib'];
    private const BIN_BYTE = ['Byte', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB', 'RiB', 'QiB'];
    private const DEC_BIT = ['bit', 'kb', 'Mb', 'Gb', 'Tb', 'Pb', 'Eb', 'Zb', 'Yb', 'Rb', 'Qb'];
    private const DEC_BYTE = ['Byte', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB', 'RB', 'QB'];

    /**
     * @param bool   $base10Sizes   btop `base_10_sizes`
     * @param string $base10Bitrate btop `base_10_bitrate`: "Auto" follows base_10_sizes, "True"/"False" force it for bit rates
     */
    private function __construct(
        private readonly bool $base10Sizes,
        private readonly string $base10Bitrate,
    ) {
    }

    public static function new(bool $base10Sizes = false, string $base10Bitrate = 'Auto'): self
    {
        return new self($base10Sizes, $base10Bitrate);
    }

    /**
     * @param int|float $value negative values (UNMEASURED) format as 0, as btop's uint64 never sees them
     */
    public function format(int|float $value, bool $shorten = false, int $start = 0, bool $bit = false, bool $perSecond = false): string
    {
        $mult = $bit ? 8 : 1;
        $mega = $this->base10Sizes;
        if ($bit && $perSecond) {
            if ($this->base10Bitrate === 'True') {
                $mega = true;
            } elseif ($this->base10Bitrate === 'False') {
                $mega = false;
            }
        }
        $units = $bit ? ($mega ? self::DEC_BIT : self::BIN_BIT) : ($mega ? self::DEC_BYTE : self::BIN_BYTE);
        $last = count($units) - 1;

        $v = is_float($value) ? (int) max(0.0, min($value, (float) intdiv(PHP_INT_MAX, 800))) : max(0, min($value, intdiv(PHP_INT_MAX, 800)));
        $v *= 100 * $mult;
        if ($mega) {
            while ($v >= 100000) {
                $v = intdiv($v, 1000);
                $start++;
            }
        } else {
            while ($v >= 102400) {
                $v >>= 10;
                $start++;
            }
        }
        $start = min($start, $last);

        $out = (string) $v;
        if (!$mega && strlen($out) === 4 && $start > 0) {
            $out = substr($out, 0, 2) . '.' . substr($out, 2, 1);
        } elseif (strlen($out) === 3 && $start > 0) {
            $out = substr($out, 0, 1) . '.' . substr($out, 1);
        } elseif (strlen($out) >= 2) {
            $out = substr($out, 0, -2);
        }
        if ($out === '') {
            $out = '0';
        }

        if ($shorten) {
            $hasSep = str_contains($out, '.');
            if ($hasSep) {
                $out = sprintf('%.1f', (float) $out);
            }
            if (strlen($out) > 3) {
                if ($hasSep) {
                    $out = sprintf('%.0f', (float) $out);
                } else {
                    $out = $out[0] . '.0';
                    $start = min($start + 1, $last);
                }
            }
            $out .= $units[$start][0];
        } else {
            $out .= ' ' . $units[$start];
        }

        if ($perSecond) {
            $out .= $bit ? 'ps' : '/s';
        }

        return $out;
    }
}
