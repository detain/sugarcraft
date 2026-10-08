<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\Read;
use SugarCraft\Top\Collect\Sentinel;

/**
 * hwmon attribute helpers shared by the sysfs GPU backends
 * (Documentation/hwmon/sysfs-interface: temperatures in millidegrees,
 * power in microwatts, energy in microjoules, frequencies in Hz).
 */
final class Hwmon
{
    private function __construct()
    {
    }

    /**
     * The `tempN_input` whose `tempN_label` is one of `$labels` (first
     * match in label order), else — when `$fallback` — the lowest-numbered
     * `tempN_input`; null when none.
     *
     * @param list<string> $labels
     */
    public static function temp(?string $hwmon, array $labels, bool $fallback = true): ?string
    {
        if ($hwmon === null) {
            return null;
        }
        $inputs = [];
        $byLabel = [];
        foreach (Read::entries($hwmon) as $entry) {
            if (preg_match('/^temp(\d+)_input$/', $entry, $m) === 1) {
                $inputs[] = "$hwmon/$entry";
                $label = Read::line("$hwmon/temp{$m[1]}_label");
                if ($label !== null) {
                    $byLabel[strtolower($label)] ??= "$hwmon/$entry";
                }
            }
        }
        foreach ($labels as $label) {
            if (isset($byLabel[$label])) {
                return $byLabel[$label];
            }
        }

        return $fallback ? ($inputs[0] ?? null) : null;
    }

    /** The first of `$names` that exists under the hwmon dir. */
    public static function first(?string $hwmon, string ...$names): ?string
    {
        if ($hwmon === null) {
            return null;
        }
        foreach ($names as $name) {
            if (is_file("$hwmon/$name")) {
                return "$hwmon/$name";
            }
        }

        return null;
    }

    /** A millidegree reading in °C; UNMEASURED when missing. */
    public static function celsius(?string $input): float
    {
        $v = $input === null ? null : Read::int($input);

        return $v === null ? Sentinel::UNMEASURED : $v / 1000.0;
    }

    /** A microunit reading (µW, µJ) scaled to the base unit; UNMEASURED when missing or negative. */
    public static function micro(?string $input): float
    {
        $v = $input === null ? null : Read::int($input);

        return $v === null || $v < 0 ? Sentinel::UNMEASURED : $v / 1e6;
    }
}
