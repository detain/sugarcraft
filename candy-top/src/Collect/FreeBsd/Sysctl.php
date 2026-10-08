<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

/**
 * Parsers for sysctl(8) text values.
 *
 * The live probe runs `sysctl -i -e <names>` ("name=value", unknown OIDs
 * skipped); captured reference output uses the default "name: value"
 * form. {@see parse()} reads both, so fixtures can be pasted verbatim.
 *
 * @internal FreeBSD collectors only.
 */
final class Sysctl
{
    private function __construct()
    {
    }

    /**
     * @return array<string, string> OID => value, in output order
     */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^([A-Za-z0-9_.%-]+)(?:=|:[ \t]?)(.*)$/', rtrim($line, "\r"), $m) === 1) {
                $out[$m[1]] = trim($m[2]);
            }
        }

        return $out;
    }

    /** An integer value; null when absent or not an integer. */
    public static function int(array $values, string $name): ?int
    {
        $v = $values[$name] ?? null;

        return $v !== null && preg_match('/^-?\d+$/', $v) === 1 ? (int) $v : null;
    }

    /**
     * A whitespace-separated list of integers (kern.cp_times).
     *
     * @return list<int>
     */
    public static function ints(array $values, string $name): array
    {
        $v = $values[$name] ?? '';
        $out = [];
        foreach (preg_split('/\s+/', trim($v), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^-?\d+$/', $token) !== 1) {
                return [];
            }
            $out[] = (int) $token;
        }

        return $out;
    }

    /**
     * A temperature as sysctl(8) prints the IK type: "40.1C" (or "313.2K"
     * with -T-less raw kelvin). -1 is ACPI's "not set". Null when absent,
     * unset or unparsable.
     */
    public static function celsius(array $values, string $name): ?float
    {
        $v = $values[$name] ?? null;
        if ($v === null || preg_match('/^(-?\d+(?:\.\d+)?)([CK]?)$/', $v, $m) !== 1) {
            return null;
        }
        $n = (float) $m[1];
        if ($m[2] === '') {
            return null; // "-1": the trip point is not set
        }
        $c = $m[2] === 'K' ? $n - 273.15 : $n;

        return $c < -100.0 || $c > 250.0 ? null : $c;
    }

    /**
     * vm.loadavg "{ 0.32 0.33 0.25 }".
     *
     * @return list<float>|null
     */
    public static function loadavg(array $values): ?array
    {
        $v = $values['vm.loadavg'] ?? '';
        if (preg_match('/(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)/', $v, $m) !== 1) {
            return null;
        }

        return [(float) $m[1], (float) $m[2], (float) $m[3]];
    }

    /** kern.boottime "{ sec = 1790798684, usec = 725700 } Wed Sep 30 ..." → UNIX seconds. */
    public static function boottime(array $values): ?float
    {
        $v = $values['kern.boottime'] ?? '';
        if (preg_match('/sec\s*=\s*(\d+)(?:,\s*usec\s*=\s*(\d+))?/', $v, $m) !== 1) {
            return null;
        }

        return (int) $m[1] + (isset($m[2]) ? (int) $m[2] / 1e6 : 0.0);
    }
}
