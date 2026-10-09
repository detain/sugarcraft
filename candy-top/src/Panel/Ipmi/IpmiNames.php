<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Ipmi;

use SugarCraft\Top\Collect\Ipmi\IpmiSensor;

/**
 * Display names for BMC sensors. Vendors decorate the same thing very
 * differently — HP numbers every zone (`01-Inlet Ambient`), AMI suffixes
 * it (`CPU1 Temperature`, `CPU1_DIMMA1_Temp`) — so the box strips the
 * decoration the column heading already says and turns `_` into spaces.
 */
final class IpmiNames
{
    private function __construct()
    {
    }

    public static function label(IpmiSensor $sensor, bool $stripNumber = true): string
    {
        $name = trim($sensor->name);
        $name = $stripNumber ? preg_replace('/^\d{1,3}-/', '', $name) ?? $name : $name;
        $name = preg_replace('/[ _]?(temperature|temp)$/i', '', $name) ?? $name;
        $name = preg_replace('/[ _]power$/i', '', $name) ?? $name;
        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = trim($name);

        return $name === '' ? trim($sensor->name) : $name;
    }

    /**
     * Display labels for one sensor group, made unique: where stripping
     * the decoration would make two sensors look the same (HP's
     * `15-VR P1 Mem` / `16-VR P1 Mem`), those keep their number prefix;
     * any that still collide get `#2`, `#3`.
     *
     * @param list<IpmiSensor> $sensors
     * @return array<string, string> sensor name => label
     */
    public static function unique(array $sensors): array
    {
        $labels = [];
        $count = [];
        foreach ($sensors as $s) {
            $labels[$s->name] = self::label($s);
            $count[$labels[$s->name]] = ($count[$labels[$s->name]] ?? 0) + 1;
        }
        $seen = [];
        foreach ($sensors as $s) {
            $label = $labels[$s->name];
            if ($count[$label] > 1) {
                $label = self::label($s, false);
            }
            $seen[$label] = ($seen[$label] ?? 0) + 1;
            $labels[$s->name] = $seen[$label] > 1 ? $label . ' #' . $seen[$label] : $label;
        }

        return $labels;
    }

    /** Intake air: the reading every other temperature is judged against. */
    public static function inlet(IpmiSensor $sensor): bool
    {
        return preg_match('/inlet|ambient|intake/i', $sensor->name) === 1
            && preg_match('/\b(PS|PSU|P\/S)\b/i', $sensor->name) !== 1;
    }

    /**
     * The upper limit a temperature meter fills toward: the sensor's own
     * critical (else non-critical) threshold, else a class default —
     * intake air 45 °C, DIMMs 85, CPUs 95, voltage regulators 110, the
     * rest 90.
     */
    public static function tempLimit(IpmiSensor $sensor): float
    {
        $own = $sensor->upper();
        if ($own !== null && $own > 0) {
            return $own;
        }
        $n = $sensor->name;

        return match (true) {
            self::inlet($sensor) => 45.0,
            preg_match('/dimm|mem/i', $n) === 1 => 85.0,
            preg_match('/\bVR\b|vrm|regulator/i', $n) === 1 => 110.0,
            preg_match('/cpu|proc|soc|\bP\d\b/i', $n) === 1 => 95.0,
            default => 90.0,
        };
    }

    /**
     * The nominal a voltage rail aims at, from its name (`+12V`, `+3.3VSB`,
     * `P5V`, `VBAT` = 3.0), when that sits inside its thresholds; else
     * null (the bar centres on the threshold midpoint instead).
     */
    public static function nominal(IpmiSensor $sensor): ?float
    {
        $n = $sensor->name;
        $v = null;
        if (preg_match('/(\d+(?:[.p_]\d+)?)\s*V(?![A-Z]*CORE)/i', $n, $m) === 1) {
            $v = (float) str_replace(['p', 'P', '_'], '.', $m[1]);
        } elseif (preg_match('/vbat|battery/i', $n) === 1) {
            $v = 3.0;
        }
        if ($v === null || $v <= 0) {
            return null;
        }
        $lo = $sensor->lower();
        $hi = $sensor->upper();
        if (($lo !== null && $v <= $lo) || ($hi !== null && $v >= $hi)) {
            return null;
        }

        return $v;
    }
}
