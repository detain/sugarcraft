<?php

declare(strict_types=1);

namespace SugarCraft\Top;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Top\Collect\ContainerEngine;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Collect\Temp;

/**
 * Facts about the host that are fixed for the run and that the frame
 * layout needs before the first sample arrives: the cpu model shown on
 * the cores sub-box, the logical cpu count that sizes it, and the
 * user/host names for the clock's `/user` `/host` tokens, and the
 * container engine candy-top runs inside ({@see ContainerEngine}, btop
 * `Cpu::container_engine`; '' on a host), shown on the cpu title in place
 * of the `x ctr` button.
 *
 * {@see detect()} does the one-time reads; the launcher calls it so the
 * Model itself never touches the filesystem.
 *
 * Mirrors aristocratos/btop Cpu::get_cpuName / trim_name (src/linux/
 * btop_collect.cpp, src/btop_shared.cpp) and Shared::coreCount.
 */
final class HostInfo
{
    private function __construct(
        public readonly string $cpuName,
        public readonly int $coreCount,
        public readonly string $user,
        public readonly string $host,
        public readonly bool $hasSensors,
        public readonly bool $hasCpuHz,
        public readonly string $containerEngine = '',
    ) {
    }

    /** Explicit facts (tests, fake runs). Names are sanitized for display. */
    public static function new(
        string $cpuName = '',
        int $coreCount = 1,
        string $user = '',
        string $host = '',
        bool $hasSensors = false,
        bool $hasCpuHz = false,
        string $containerEngine = '',
    ): self {
        return new self(
            self::clean($cpuName),
            max(1, $coreCount),
            self::clean($user),
            self::clean($host),
            $hasSensors,
            $hasCpuHz,
            $containerEngine === '' ? '' : ContainerEngine::name($containerEngine),
        );
    }

    /** Read the live host (or a fixture tree via `$paths`). Never throws. */
    public static function detect(?Paths $paths = null): self
    {
        $paths ??= Paths::system();
        $cpuinfo = @file_get_contents($paths->proc('cpuinfo'));
        $cpuinfo = $cpuinfo === false ? '' : $cpuinfo;
        $name = preg_match('/^model name\s*:\s*(.*)$/m', $cpuinfo, $m) === 1 ? self::trimName($m[1]) : '';
        $cores = preg_match_all('/^processor\s*:/m', $cpuinfo);
        if ($cores < 1) {
            $stat = @file_get_contents($paths->proc('stat'));
            $cores = $stat === false ? 1 : (int) preg_match_all('/^cpu\d+\s/m', $stat);
        }
        $user = getenv('USER');
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            $user = is_array($pw) ? $pw['name'] : $user;
        }
        $host = gethostname();

        return self::new(
            $name,
            max(1, $cores),
            is_string($user) ? $user : '',
            is_string($host) ? $host : '',
            self::hasSensors($paths),
            is_file($paths->sys('devices/system/cpu/cpu0/cpufreq/scaling_cur_freq')),
            // A fixture tree stands in for the whole host, so it gets an empty environment too.
            ContainerEngine::detect($paths, $paths->root === '' ? null : []),
        );
    }

    /**
     * btop get_sensors' `got_sensors`: at least one temperature sensor was
     * found — an hwmon chip with a `temp*_input` (directly or under
     * `device/`), the coretemp platform hwmon, or a thermal zone with a
     * `temp` file. An empty or fan-only /sys/class/hwmon is NOT a sensor.
     * The discovery is {@see Temp}'s own port of get_sensors, so the layout's
     * temp columns and the cpu box's readings can never disagree.
     */
    public static function hasSensors(Paths $paths): bool
    {
        [$snapshot] = Temp::new($paths)->sample();

        return $snapshot->sensors !== [];
    }

    /**
     * btop trim_name: reduce a /proc/cpuinfo "model name" to its model —
     * Xeon / Core 2 Duo / Intel names keep the token after "CPU", Ryzen
     * keeps "Ryzen" plus two tokens, anything else drops the vendor
     * boilerplate and everything from "@" on.
     */
    public static function trimName(string $name): string
    {
        $tokens = array_values(array_filter(explode(' ', $name), static fn (string $t): bool => $t !== ''));
        $cpuPos = array_search('CPU', $tokens, true);
        $out = '';
        if ((str_contains($name, 'Xeon') || in_array('Duo', $tokens, true)) && $cpuPos !== false) {
            $out = ($cpuPos < count($tokens) - 1 && !str_ends_with($tokens[$cpuPos + 1], ')')) ? $tokens[$cpuPos + 1] : '';
        } elseif (($ryz = array_search('Ryzen', $tokens, true)) !== false) {
            $out = 'Ryzen';
            $count = 0;
            for ($i = $ryz + 1; $i < count($tokens) && $count < 2; $i++) {
                if (!in_array($tokens[$i], ['AI', 'PRO', 'H', 'HX'], true)) {
                    $count++;
                }
                $out .= ' ' . $tokens[$i];
            }
        } elseif (str_contains($name, 'Intel') && $cpuPos !== false) {
            $next = $tokens[$cpuPos + 1] ?? null;
            $out = ($next !== null && !str_ends_with($next, ')') && $next !== '@') ? $next : '';
        }

        if ($out === '' && $tokens !== []) {
            $kept = [];
            foreach ($tokens as $t) {
                if ($t === '@') {
                    break;
                }
                $kept[] = $t;
            }
            $out = implode(' ', $kept);
            foreach (['Processor', 'CPU', '(R)', '(TM)', 'Intel', 'AMD', 'Apple', 'Core'] as $junk) {
                $out = str_replace([$junk, '  '], ['', ' '], $out);
            }
            $out = trim($out);
        }

        return $out;
    }

    /** Untrusted host strings must never carry escapes into the frame. */
    private static function clean(string $s): string
    {
        return trim(Sanitize::untrustedForDisplay($s));
    }
}
