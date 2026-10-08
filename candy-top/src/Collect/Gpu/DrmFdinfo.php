<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Collect\Read;
use SugarCraft\Top\Collect\Sentinel;

/**
 * DRM fdinfo scanner — the PHP-feasible source for Intel GPU utilization
 * (btop #1888 vendors intel_gpu_top's PMU reader, which needs
 * perf_event_open) and for per-process GPU use on amdgpu / i915 / xe
 * (btop #1552's fallback). Kernel ABI: Documentation/gpu/drm-usage-stats.rst.
 *
 * Every `/proc/<pid>/fdinfo/<fd>` of an fd open on `/dev/dri/*` carries
 * `drm-pdev` (PCI slot), `drm-client-id`, `drm-driver` and per engine
 * class either `drm-engine-<class>: <ns> ns` (i915, amdgpu) or the xe pair
 * `drm-cycles-<class>` / `drm-total-cycles-<class>`, optionally divided by
 * `drm-engine-capacity-<class>`; memory as `drm-resident-<region>` (or the
 * legacy amdgpu `drm-memory-<region>`) with a KiB/MiB unit.
 *
 * Rates: busy-ns deltas over the scanner's own wall-clock delta (or
 * cycles over total-cycles), per client and engine class, clamped 0..100.
 * A client is keyed by (pdev, client id) and counted ONCE however many
 * fds or processes share it (dup(), fd passing) — the first pid in scan
 * order owns it. A client seen for the first time has no rate yet; one
 * that vanished simply drops out (summing raw totals per device instead
 * would turn every exiting client into a negative delta).
 *
 * Cost bound (the #1552 reviewer measured ~10 ms per full scan of ~490
 * pids; a non-root walk of ~1190 pids measured 42-58 ms): discovery —
 * readdir of every /proc/<pid>/fd plus one readlink per fd — is
 * AMORTIZED. A pass walks the pid list behind a persistent cursor, at
 * most `$budget` units per sample (DISCOVERY_BUDGET; one unit per fd dir
 * listed and per readlink), resuming mid-pid where it stopped, so no pid
 * starves however long the list. A new pass starts once the previous one
 * finished AND `$rescanInterval` seconds passed since it started. Every
 * sample also reads the remembered DRM fds' fdinfo files — a handful of
 * small reads. Pids not yet reached keep the previous pass's fds.
 *
 * Visibility (documented choice, see {@see DrmScan::utilization()}): a
 * client opened after its pid was walked stays invisible until the next
 * pass reaches that pid again (up to `$rescanInterval` plus the pass
 * length). Another uid's fd dir is unreadable to a non-root monitor: it
 * is counted, not silently skipped — the scan is then `partial`, and a
 * device with no visible client reads UNMEASURED rather than a
 * fabricated idle 0 %. Until the first pass completes the scan is not
 * `complete`, with the same effect.
 *
 * The scanner only exists when an amdgpu / i915 / xe device is present —
 * an NVIDIA-only host never pays for it ({@see Backend::drmClients()}).
 */
final class DrmFdinfo
{
    public const float RESCAN_INTERVAL = 10.0;

    /** Discovery units (fd-dir listings + readlinks) per sample. */
    public const int DISCOVERY_BUDGET = 2048;

    /**
     * @param \Closure(): float              $clock    monotonic seconds
     * @param \Closure(string): ?string      $readlink link target, null when not a link
     * @param array<int, list<string>>       $fds      pid → DRM fd numbers
     * @param list<int>|null                 $pass     pids of the pass in progress; null = none
     * @param array{0: int, 1: list<string>, 2: list<string>}|null $partialPid [pid, fds left, DRM fds found]
     * @param array<string, array{engines: array<string, array{0: int, 1: int}>, at: float}> $prev
     *        (pdev|client) → engine → [busy (ns or cycles), total cycles (0 for ns engines)]
     */
    private function __construct(
        private readonly Paths $paths,
        private readonly \Closure $clock,
        private readonly \Closure $readlink,
        private readonly float $rescanInterval,
        private readonly int $budget,
        private readonly array $fds,
        private readonly ?array $pass,
        private readonly int $cursor,
        private readonly ?array $partialPid,
        private readonly float $passStartedAt,
        private readonly int $passes,
        private readonly int $hidden,
        private readonly int $lastHidden,
        private readonly array $prev,
        private readonly bool $scanned,
    ) {
    }

    /**
     * @param (\Closure(): float)|null         $clock    defaults to hrtime monotonic seconds
     * @param (\Closure(string): ?string)|null $readlink defaults to readlink(2)
     */
    public static function new(
        ?Paths $paths = null,
        ?\Closure $clock = null,
        ?\Closure $readlink = null,
        float $rescanInterval = self::RESCAN_INTERVAL,
        int $budget = self::DISCOVERY_BUDGET,
    ): self {
        return new self(
            $paths ?? Paths::system(),
            $clock ?? static fn (): float => hrtime(true) / 1e9,
            $readlink ?? static function (string $path): ?string {
                $target = @readlink($path);

                return $target === false ? null : $target;
            },
            max(0.0, $rescanInterval),
            max(1, $budget),
            [],
            null,
            0,
            null,
            -INF,
            0,
            0,
            0,
            [],
            false,
        );
    }

    /** Completed discovery passes. */
    public function passes(): int
    {
        return $this->passes;
    }

    /**
     * @return array{0: DrmScan, 1: self}
     */
    public function sample(): array
    {
        $now = ($this->clock)();
        $d = [
            'fds' => $this->fds,
            'pass' => $this->pass,
            'cursor' => $this->cursor,
            'partialPid' => $this->partialPid,
            'startedAt' => $this->passStartedAt,
            'passes' => $this->passes,
            'hidden' => $this->hidden,
            'lastHidden' => $this->lastHidden,
        ];
        if ($d['pass'] === null && ($this->passes === 0 || $now - $this->passStartedAt >= $this->rescanInterval)) {
            $d['pass'] = $this->pids();
            $d['cursor'] = 0;
            $d['partialPid'] = null;
            $d['startedAt'] = $now;
            $d['hidden'] = 0;
        }
        if ($d['pass'] !== null) {
            $d = $this->walk($d);
        }

        ksort($d['fds']); // scan order = pid order: the lowest pid owns a shared client
        $clients = [];
        $seen = [];
        $prev = [];
        $alive = [];
        foreach ($d['fds'] as $pid => $list) {
            $kept = [];
            foreach ($list as $fd) {
                $info = Read::file($this->paths->proc("$pid/fdinfo/$fd"));
                if ($info === null) {
                    continue; // pid exited or fd closed
                }
                $parsed = self::parse($info);
                if ($parsed === null) {
                    continue; // fd number reused by a non-DRM file
                }
                $kept[] = $fd;
                $key = $parsed['pdev'] . '|' . $parsed['client'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $prev[$key] = ['engines' => $parsed['engines'], 'at' => $now];
                $old = $this->prev[$key] ?? null;
                $rates = $old === null ? null : self::rates($old['engines'], $parsed['engines'], $parsed['capacity'], $now - $old['at']);
                $clients[] = new DrmClient(
                    (int) $pid,
                    $parsed['pdev'],
                    $parsed['client'],
                    $parsed['driver'],
                    $rates ?? [],
                    $parsed['memory'],
                    $rates !== null,
                );
            }
            if ($kept !== []) {
                $alive[$pid] = $kept;
            }
        }

        return [
            new DrmScan($clients, $this->scanned, true, $d['passes'] > 0, $d['hidden'] > 0 || $d['lastHidden'] > 0),
            new self(
                $this->paths,
                $this->clock,
                $this->readlink,
                $this->rescanInterval,
                $this->budget,
                $alive,
                $d['pass'],
                $d['cursor'],
                $d['partialPid'],
                $d['startedAt'],
                $d['passes'],
                $d['hidden'],
                $d['lastHidden'],
                $prev,
                true,
            ),
        ];
    }

    /** @return list<int> */
    private function pids(): array
    {
        $pids = [];
        foreach (@scandir($this->paths->proc()) ?: [] as $entry) {
            if (ctype_digit($entry)) {
                $pids[] = (int) $entry;
            }
        }
        sort($pids);

        return $pids;
    }

    /**
     * One budgeted slice of the pass in progress.
     *
     * @param array{fds: array<int, list<string>>, pass: list<int>, cursor: int,
     *              partialPid: array{0: int, 1: list<string>, 2: list<string>}|null,
     *              startedAt: float, passes: int, hidden: int, lastHidden: int} $d
     * @return array{fds: array<int, list<string>>, pass: list<int>|null, cursor: int,
     *              partialPid: array{0: int, 1: list<string>, 2: list<string>}|null,
     *              startedAt: float, passes: int, hidden: int, lastHidden: int}
     */
    private function walk(array $d): array
    {
        $proc = $this->paths->proc();
        $budget = $this->budget;
        while ($budget > 0) {
            if ($d['partialPid'] === null) {
                if ($d['cursor'] >= count($d['pass'])) {
                    $d['pass'] = null;
                    $d['passes']++;
                    $d['lastHidden'] = $d['hidden'];

                    return $d;
                }
                $pid = $d['pass'][$d['cursor']++];
                $budget--;
                $list = @scandir("$proc/$pid/fd");
                if ($list === false) {
                    unset($d['fds'][$pid]);
                    if (is_dir("$proc/$pid")) {
                        $d['hidden']++; // another uid's process: unreadable, not absent
                    }

                    continue;
                }
                $d['partialPid'] = [$pid, array_values(array_filter($list, 'ctype_digit')), []];
            }
            [$pid, $left, $found] = $d['partialPid'];
            while ($left !== [] && $budget > 0) {
                $fd = array_shift($left);
                $budget--;
                $target = ($this->readlink)("$proc/$pid/fd/$fd");
                if ($target !== null && str_starts_with($target, '/dev/dri/')) {
                    $found[] = $fd;
                }
            }
            if ($left !== []) {
                $d['partialPid'] = [$pid, $left, $found];

                return $d; // budget spent mid-pid: resume here next sample
            }
            $d['partialPid'] = null;
            if ($found === []) {
                unset($d['fds'][$pid]);
            } else {
                $d['fds'][$pid] = $found;
            }
        }
        if ($d['partialPid'] === null && $d['cursor'] >= count($d['pass'])) {
            $d['pass'] = null;
            $d['passes']++;
            $d['lastHidden'] = $d['hidden'];
        }

        return $d;
    }

    /**
     * @return array{pdev: string, client: string, driver: string,
     *               engines: array<string, array{0: int, 1: int}>,
     *               capacity: array<string, int>, memory: int}|null
     */
    private static function parse(string $info): ?array
    {
        $kv = [];
        foreach (explode("\n", $info) as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false && str_starts_with($line, 'drm-')) {
                $kv[substr($line, 0, $colon)] = trim(substr($line, $colon + 1));
            }
        }
        $pdev = $kv['drm-pdev'] ?? '';
        $client = $kv['drm-client-id'] ?? '';
        if ($pdev === '' || $client === '') {
            return null;
        }
        $engines = [];
        $capacity = [];
        $resident = [];
        $legacy = [];
        foreach ($kv as $key => $value) {
            if (preg_match('/^drm-engine-capacity-(.+)$/', $key, $m) === 1) {
                $capacity[$m[1]] = max(1, (int) $value);
            } elseif (preg_match('/^drm-engine-(.+)$/', $key, $m) === 1 && preg_match('/^(\d+)/', $value, $v) === 1) {
                $engines[$m[1]] = [(int) $v[1], 0];
            } elseif (preg_match('/^drm-cycles-(.+)$/', $key, $m) === 1 && ctype_digit($value)) {
                $total = $kv['drm-total-cycles-' . $m[1]] ?? '';
                if (ctype_digit($total)) {
                    $engines[$m[1]] = [(int) $value, (int) $total];
                }
            } elseif (preg_match('/^drm-resident-((?:vram|local)\d*)$/', $key, $m) === 1) {
                $resident[$m[1]] = self::bytes($value);
            } elseif (preg_match('/^drm-memory-((?:vram|local)\d*)$/', $key, $m) === 1) {
                $legacy[$m[1]] = self::bytes($value);
            }
        }
        $regions = array_filter($resident !== [] ? $resident : $legacy, static fn (int $b): bool => $b >= 0);

        return [
            'pdev' => $pdev,
            'client' => $client,
            'driver' => $kv['drm-driver'] ?? Sentinel::UNAVAILABLE,
            'engines' => $engines,
            'capacity' => $capacity,
            'memory' => $regions === [] ? Sentinel::UNMEASURED_INT : array_sum($regions),
        ];
    }

    /**
     * @param array<string, array{0: int, 1: int}> $old
     * @param array<string, array{0: int, 1: int}> $new
     * @param array<string, int>                   $capacity
     * @return array<string, float>
     */
    private static function rates(array $old, array $new, array $capacity, float $seconds): array
    {
        $rates = [];
        foreach ($new as $engine => [$busy, $total]) {
            if (!isset($old[$engine])) {
                continue;
            }
            [$busyBefore, $totalBefore] = $old[$engine];
            $delta = $busy - $busyBefore;
            if ($delta < 0) {
                continue; // counter reset
            }
            if ($total > 0) {
                $span = $total - $totalBefore; // xe: GPU timestamp cycles
            } else {
                $span = $seconds * 1e9;
            }
            if ($span <= 0) {
                continue;
            }
            $rates[$engine] = max(0.0, min(100.0, $delta * 100.0 / $span / ($capacity[$engine] ?? 1)));
        }

        return $rates;
    }

    /** "1234 KiB" / "5 MiB" / "4096" → bytes; garbage → UNMEASURED_INT. */
    private static function bytes(string $value): int
    {
        if (preg_match('/^(\d+)\s*(KiB|MiB|GiB)?$/', $value, $m) !== 1) {
            return Sentinel::UNMEASURED_INT;
        }
        $scale = ['' => 1, 'KiB' => 1024, 'MiB' => 1048576, 'GiB' => 1073741824][$m[2] ?? ''];

        return (int) $m[1] * $scale;
    }
}
