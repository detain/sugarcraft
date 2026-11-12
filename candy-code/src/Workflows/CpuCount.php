<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

/**
 * The host's CPU count, for the default workflow width (P0-3,
 * {@see Workflow::defaultConcurrency()}).
 *
 * Read from files and the environment only — no child process — so asking
 * never forks (`tools/check-child-lifetimes.php` accounts for every
 * `proc_open`, and a width probe is not worth a roster entry). The answer
 * is memoised: CPUs do not come and go during a session.
 */
final class CpuCount
{
    /**
     * What an undetectable host is assumed to have. Four keeps the computed
     * default width at 2 — narrow enough that a misdetected small box is not
     * flooded, wide enough that a parallel stage is still parallel.
     */
    public const FALLBACK = 4;

    private static ?int $detected = null;

    /** The detected count (at least 1), or {@see FALLBACK} when nothing answers. */
    public static function detect(): int
    {
        return self::$detected ??= self::probe() ?? self::FALLBACK;
    }

    private static function probe(): ?int
    {
        // Windows publishes the count in the environment.
        $env = getenv('NUMBER_OF_PROCESSORS');
        if (is_string($env) && ctype_digit(trim($env)) && (int) trim($env) > 0) {
            return (int) trim($env);
        }

        // Linux: the cgroup-agnostic CPU list the kernel says is online.
        $online = @file_get_contents('/sys/devices/system/cpu/online');
        if (is_string($online)) {
            $count = self::countCpuList(trim($online));
            if ($count !== null) {
                return $count;
            }
        }

        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo)) {
            $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
            if (is_int($count) && $count > 0) {
                return $count;
            }
        }

        return null;
    }

    /**
     * Count a kernel CPU list such as `0-3,6,8-9` (7). Null for anything
     * that is not one — an unparsable answer is no answer.
     */
    public static function countCpuList(string $list): ?int
    {
        if ($list === '') {
            return null;
        }

        $count = 0;
        foreach (explode(',', $list) as $range) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', $range, $m) !== 1) {
                return null;
            }
            $low = (int) $m[1];
            $high = isset($m[2]) ? (int) $m[2] : $low;
            if ($high < $low) {
                return null;
            }
            $count += $high - $low + 1;
        }

        return $count > 0 ? $count : null;
    }
}
