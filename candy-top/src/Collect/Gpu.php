<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\Gpu\Backend;
use SugarCraft\Top\Collect\Gpu\DrmScan;
use SugarCraft\Top\Collect\Gpu\Settled;
use SugarCraft\Top\Collect\Gpu\SmiProcess;

use function React\Promise\resolve;

/**
 * Best-effort NVIDIA GPU stats via an `nvidia-smi` shell-out.
 *
 * Mirrors aristocratos/btop Gpu::collect in spirit only: btop dlopen()s
 * libnvidia-ml / ROCm SMI, which PHP cannot do without FFI (plan §1
 * non-goal). sugar-dash SystemModule's nvidia-smi query is the precedent
 * for the memoized-absent law, refined here:
 *  - before any query has succeeded, every `nvidia-smi` candidate is tried
 *    in order (each PATH hit, then the WSL2 / container-toolkit locations —
 *    btop #1869: on WSL2 a distro `nvidia-utils` binary that cannot reach
 *    the driver shadows the working /usr/lib/wsl/lib one), and on each
 *    candidate the extended query first, then the base query (an older
 *    driver rejects an unknown field with exit 2 — that must not read as
 *    "no GPU"). Only when every candidate × query fails without a timeout
 *    is the GPU memoized absent — a GPU-less host never spawns again;
 *  - a timeout ends the sweep at once: a binary that hangs reached a driver
 *    and the driver hung, and every other candidate talks to that same
 *    driver, so trying them would cost N × (TIMEOUT + REAP_WAIT) per sample
 *    and leave up to N stuck children. The sweep is retried from the top
 *    after the backoff below;
 *  - the first working (binary, query) pair is pinned — until it fails
 *    PIN_MAX_FAILURES times in a row without a timeout (the binary was
 *    removed or replaced by a driver upgrade, which a timeout never
 *    indicates: a hang means the binary reached the driver). Then the pin
 *    is dropped and, at the next due query (the backoff still applies),
 *    the full candidate × query sweep runs again — over a freshly
 *    discovered candidate list when new() discovered it. A host that ever
 *    answered still never goes absent: a failed re-sweep only backs off;
 *  - a timeout is always transient, and so is any failure once a query has
 *    succeeded (a driver reload or GPU reset on a host that HAS a GPU): it
 *    yields an empty snapshot and the next query is pushed back
 *    exponentially (interval × 2^n, capped at 2^5), so a wedged driver costs
 *    one bounded wait per backoff window, not per tick;
 *  - a fork+exec per frame tick is too expensive to pay at the cpu/mem
 *    cadence, so real queries are spaced at least $interval seconds apart
 *    on the injected clock; samples in between return the last snapshot.
 *
 * The child ({@see SmiProcess}) is spawned with an argv array (no shell),
 * only when a candidate binary exists and is executable, bounded by a
 * deadline, SIGKILLed on overrun and reaped without blocking. Two runners
 * share it: {@see sample()} uses the blocking one (startup probe, tests),
 * {@see sampleAsync()} the loop-driven one, so the shared GPU feed never
 * stalls the UI while nvidia-smi runs. The whole query cycle (sweep, pin,
 * compute-apps, pmon) is ONE promise chain over whichever runner is used;
 * with a blocking runner every link settles synchronously. Both runners are
 * injectable so tests never spawn.
 *
 * The per-process `--query-compute-apps` query is opt-in (withProcesses()):
 * it is a second spawn per interval, and a driver that answers the device
 * query but hangs on the apps one would otherwise block every interval. It
 * keeps its own consecutive-timeout counter — each timeout pushes it back
 * interval × 2^n, and after APPS_MAX_TIMEOUTS in a row it is switched off for
 * APPS_RETRY_AFTER seconds before one more try — and never touches the
 * device schedule. Once opted in, a third spawn, `nvidia-smi pmon -c 1 -s
 * um` (~0.25 s on skynet2's 4 GPUs), adds per-process utilization (btop
 * #1552's nvmlDeviceGetProcessUtilization, without NVML) and the graphics
 * processes compute-apps omits, on its own backoff (see queryProcesses()).
 *
 * As the NVIDIA {@see Backend} of {@see Gpu\Accelerators} it is the
 * first slice of the merged device list (btop Gpu::collect order).
 */
final class Gpu implements Backend
{
    /**
     * The base query every nvidia-smi since the 2010s answers. `name` is
     * last on purpose: it is the only free-text column and may contain
     * commas ("NVIDIA A100-SXM4-40GB, MIG 1g.5gb"), so the parser splits a
     * fixed number of columns and keeps the tail whole.
     */
    public const array QUERY = ['index', 'utilization.gpu', 'memory.used', 'memory.total', 'temperature.gpu', 'power.draw', 'name'];

    /**
     * Everything btop's gpu box shows that nvidia-smi can answer, verified
     * valid on driver 595.91.07 (prompt_kit/findings/nvidia-smi-skynet2.md).
     * `uuid` maps --query-compute-apps rows to a device index; `name`
     * stays last.
     */
    public const array QUERY_EXTENDED = [
        'index', 'utilization.gpu', 'memory.used', 'memory.total', 'temperature.gpu', 'power.draw',
        'utilization.memory', 'utilization.encoder', 'utilization.decoder', 'power.limit',
        'clocks.gr', 'clocks.mem', 'clocks.max.gr', 'clocks.max.mem', 'fan.speed', 'pstate',
        'pcie.link.gen.current', 'pcie.link.width.current', 'temperature.memory', 'uuid', 'name',
    ];

    /** Per-process GPU memory (btop #1552 source); process_name is omitted — ProcList owns names. */
    public const array APPS_QUERY = ['pid', 'gpu_uuid', 'used_memory'];

    /** Per-process utilization: one pmon sample, utilization + memory groups. */
    public const array PMON_ARGS = ['pmon', '-c', '1', '-s', 'um'];

    /**
     * Locations tried after every PATH hit: the WSL2 driver-store binary
     * (btop #1869 analog) and the usual distro / NVIDIA container toolkit
     * paths for a PATH that was stripped (cron, systemd units).
     */
    public const array FALLBACK_BINARIES = ['/usr/lib/wsl/lib/nvidia-smi', '/usr/bin/nvidia-smi', '/usr/local/nvidia/bin/nvidia-smi'];

    public const float DEFAULT_INTERVAL = 5.0;

    private const int MAX_BACKOFF_EXPONENT = 5;

    /** Consecutive compute-apps timeouts after which the query is switched off for APPS_RETRY_AFTER. */
    public const int APPS_MAX_TIMEOUTS = 3;

    /** Seconds a switched-off compute-apps query waits before it is tried again. */
    public const float APPS_RETRY_AFTER = 600.0;

    /** Consecutive pmon timeouts after which pmon alone is switched off for APPS_RETRY_AFTER. */
    public const int PMON_MAX_TIMEOUTS = 3;

    /** Consecutive non-timeout pmon failures after which pmon is memoized unsupported. */
    public const int PMON_MAX_FAILURES = 3;

    /** Consecutive non-timeout failures of the pinned (binary, query) after which the sweep re-runs. */
    public const int PIN_MAX_FAILURES = 3;

    /**
     * @param \Closure(list<string>): (array{0: GpuOutcome, 1: string}|PromiseInterface<array{0: GpuOutcome, 1: string}>) $runner argv → [outcome, stdout], blocking
     * @param (\Closure(list<string>): (array{0: GpuOutcome, 1: string}|PromiseInterface<array{0: GpuOutcome, 1: string}>))|null $asyncRunner
     *        the runner sampleAsync() uses; null = $runner
     * @param \Closure(): float $clock monotonic seconds
     * @param list<string> $candidates binaries tried in order until one answers
     * @param list<list<string>> $queries query-gpu field lists tried in order per candidate
     * @param list<string>|null $pinnedQuery
     */
    private function __construct(
        private readonly \Closure $runner,
        private readonly \Closure $clock,
        private readonly float $interval,
        private readonly bool $absent,
        private readonly ?float $nextQueryAt,
        private readonly int $timeouts,
        private readonly GpuSnapshot $last,
        private readonly bool $everSucceeded,
        private readonly array $candidates,
        private readonly array $queries,
        private readonly ?string $pinnedBinary,
        private readonly ?array $pinnedQuery,
        private readonly bool $processes,
        private readonly int $appsTimeouts,
        private readonly float $appsNextAt,
        private readonly int $pinFailures = 0,
        private readonly bool $discovered = false,
        private readonly int $pmonTimeouts = 0,
        private readonly float $pmonNextAt = -INF,
        private readonly int $pmonFailures = 0,
        private readonly ?\Closure $asyncRunner = null,
    ) {
    }

    /**
     * @param (\Closure(list<string>): array{0: GpuOutcome, 1: string})|null $runner defaults to a bounded, blocking
     *        nvidia-smi child ({@see SmiProcess::run()}); when given, sampleAsync() uses it too unless
     *        $asyncRunner is also given
     * @param (\Closure(list<string>): PromiseInterface<array{0: GpuOutcome, 1: string}>)|null $asyncRunner
     *        defaults (with $runner null) to the loop-driven child ({@see SmiProcess::launch()})
     * @param (\Closure(): float)|null $clock defaults to hrtime-based monotonic seconds
     * @param list<string>|null $candidates binaries to try; null discovers them (see candidates())
     * @param list<list<string>>|null $queries field lists to try per candidate; null = [QUERY_EXTENDED, QUERY]
     * @param bool $processes also run --query-compute-apps after each successful device query
     *                        (only possible when the pinned query carries `uuid`); off by default,
     *                        see withProcesses()
     */
    public static function new(
        ?\Closure $runner = null,
        ?\Closure $clock = null,
        float $interval = self::DEFAULT_INTERVAL,
        ?array $candidates = null,
        ?array $queries = null,
        bool $processes = false,
        ?\Closure $asyncRunner = null,
    ): self {
        $discovered = $candidates === null;
        if ($runner === null) {
            $runner = self::nvidiaSmi(...);
            $asyncRunner ??= self::nvidiaSmiAsync(...);
        }
        $candidates = array_values($candidates ?? self::candidates());
        $queries = array_values(array_filter($queries ?? [self::QUERY_EXTENDED, self::QUERY], static fn (array $q): bool => $q !== []));

        return new self(
            $runner,
            $clock ?? static fn (): float => hrtime(true) / 1e9,
            max(0.0, $interval),
            $candidates === [] || $queries === [],
            null,
            0,
            new GpuSnapshot([]),
            false,
            $candidates,
            $queries,
            null,
            null,
            $processes,
            0,
            -INF,
            0,
            $discovered,
            asyncRunner: $asyncRunner,
        );
    }

    /**
     * Opt in to (or out of) the per-process --query-compute-apps spawn; a
     * fresh opt-in also clears its timeout backoff and makes the next
     * sample query at once (unless the device query is backing off), so a
     * consumer that just started wanting per-process rows — the proc box's
     * `g` / gpu sort on the shared feed — gets them now, not one interval
     * later.
     */
    public function withProcesses(bool $on = true): self
    {
        $fresh = $on && !$this->processes;

        return new self(
            $this->runner,
            $this->clock,
            $this->interval,
            $this->absent,
            $fresh && $this->timeouts === 0 ? null : $this->nextQueryAt,
            $this->timeouts,
            $this->last,
            $this->everSucceeded,
            $this->candidates,
            $this->queries,
            $this->pinnedBinary,
            $this->pinnedQuery,
            $on,
            $on && !$this->processes ? 0 : $this->appsTimeouts,
            $on && !$this->processes ? -INF : $this->appsNextAt,
            $this->pinFailures,
            $this->discovered,
            $on && !$this->processes ? 0 : $this->pmonTimeouts,
            $on && !$this->processes ? -INF : $this->pmonNextAt,
            $on && !$this->processes ? 0 : $this->pmonFailures,
            $this->asyncRunner,
        );
    }

    public function processesEnabled(): bool
    {
        return $this->processes;
    }

    /**
     * Every executable `nvidia-smi` on $PATH in PATH order, then each
     * existing FALLBACK_BINARIES entry, de-duplicated by real path. When
     * none exists the bare command name is returned so the runner reports
     * Absent through the ordinary path.
     *
     * @param string|null $path a PATH string; null reads the environment
     * @param list<string>|null $fallbacks null = FALLBACK_BINARIES
     * @return list<string>
     */
    public static function candidates(?string $path = null, ?array $fallbacks = null): array
    {
        $found = [];
        $seen = [];
        $add = static function (string $file) use (&$found, &$seen): void {
            if (!is_file($file) || !is_executable($file)) {
                return;
            }
            $real = realpath($file) ?: $file;
            if (!isset($seen[$real])) {
                $seen[$real] = true;
                $found[] = $file;
            }
        };
        foreach (explode(PATH_SEPARATOR, $path ?? (string) getenv('PATH')) as $dir) {
            if ($dir !== '') {
                $add(rtrim($dir, '/') . '/nvidia-smi');
            }
        }
        foreach ($fallbacks ?? self::FALLBACK_BINARIES as $file) {
            $add($file);
        }

        return $found === [] ? ['nvidia-smi'] : $found;
    }

    /**
     * One blocking sample (the injected or default blocking runner).
     *
     * @return array{0: GpuSnapshot, 1: self}
     */
    public function sample(): array
    {
        return Settled::value($this->sampleWith($this->runner));
    }

    /**
     * The same sample over the loop-driven runner: resolves when the
     * spawns are done, without blocking the loop thread meanwhile. Between
     * queries (the interval, a backoff, a memoized absence) it is already
     * settled with the last snapshot.
     *
     * @return PromiseInterface<array{0: GpuSnapshot, 1: self}>
     */
    public function sampleAsync(): PromiseInterface
    {
        return $this->sampleWith($this->asyncRunner ?? $this->runner);
    }

    /**
     * @param \Closure(list<string>): mixed $runner
     * @return PromiseInterface<array{0: GpuSnapshot, 1: self}>
     */
    private function sampleWith(\Closure $runner): PromiseInterface
    {
        if ($this->absent) {
            return resolve([$this->last, $this]);
        }
        $now = ($this->clock)();
        if ($this->nextQueryAt !== null && $now < $this->nextQueryAt) {
            return resolve([$this->last, $this]);
        }
        $run = static fn (array $argv): PromiseInterface => resolve($runner($argv));

        if ($this->pinnedBinary !== null && $this->pinnedQuery !== null) {
            $binary = $this->pinnedBinary;
            $query = $this->pinnedQuery;

            return $run(self::argv($binary, $query))->then(function (array $result) use ($now, $binary, $query, $run): PromiseInterface|array {
                [$outcome, $output] = $result;
                $devices = $outcome === GpuOutcome::Ok ? self::parse($output, $query) : [];
                if ($devices !== []) {
                    return $this->succeed($now, $binary, $query, $devices, $run);
                }
                [$empty, $next] = $this->backoff($now);
                if ($outcome === GpuOutcome::Timeout) {
                    return [$empty, $next->with(pinFailures: 0)];
                }
                if ($this->pinFailures + 1 >= self::PIN_MAX_FAILURES) {
                    return [$empty, $next->with(unpin: true)];
                }

                return [$empty, $next->with(pinFailures: $this->pinFailures + 1)];
            });
        }

        return $this->sweep($now, $run, 0);
    }

    /**
     * Candidate × query pair `$at` onwards, in order, until one answers.
     *
     * @param \Closure(list<string>): PromiseInterface $run
     * @return PromiseInterface<array{0: GpuSnapshot, 1: self}>
     */
    private function sweep(float $now, \Closure $run, int $at): PromiseInterface
    {
        $perBinary = \count($this->queries);
        if ($at >= \count($this->candidates) * $perBinary) {
            if (!$this->everSucceeded) {
                $empty = new GpuSnapshot([]);

                return resolve([$empty, $this->with(absent: true, timeouts: 0, last: $empty)]);
            }

            return resolve($this->backoff($now));
        }
        $binary = $this->candidates[intdiv($at, $perBinary)];
        $query = $this->queries[$at % $perBinary];

        return $run(self::argv($binary, $query))->then(function (array $result) use ($now, $run, $at, $binary, $query): PromiseInterface|array {
            [$outcome, $output] = $result;
            if ($outcome === GpuOutcome::Timeout) {
                // A driver was reached and hung; every other candidate
                // and query would hang on it too. Stop here and back off.
                return $this->backoff($now);
            }
            $devices = $outcome === GpuOutcome::Ok ? self::parse($output, $query) : [];
            if ($devices !== []) {
                return $this->succeed($now, $binary, $query, $devices, $run);
            }

            return $this->sweep($now, $run, $at + 1);
        });
    }

    public function absent(): bool
    {
        return $this->absent;
    }

    /** Backend: nvidia-smi needs no DRM scan; the cadence above still applies. */
    public function poll(DrmScan $scan): array
    {
        return $this->sample();
    }

    public function vendor(): GpuVendor
    {
        return GpuVendor::Nvidia;
    }

    public function kind(): AcceleratorKind
    {
        return AcceleratorKind::Gpu;
    }

    public function needsDrmScan(): bool
    {
        return false;
    }

    /** The proprietary driver publishes no drm-* fdinfo keys (#1552 guard). */
    public function drmClients(): bool
    {
        return false;
    }

    /** The pinned binary that answered; null before the first success and after PIN_MAX_FAILURES unpins it. */
    public function binary(): ?string
    {
        return $this->pinnedBinary;
    }

    /**
     * @param list<string> $query
     * @param list<GpuDevice> $devices
     * @param \Closure(list<string>): PromiseInterface $run
     * @return PromiseInterface<array{0: GpuSnapshot, 1: self}>
     */
    private function succeed(float $now, string $binary, array $query, array $devices, \Closure $run): PromiseInterface
    {
        $none = resolve([null, [$this->appsTimeouts, $this->appsNextAt], [$this->pmonTimeouts, $this->pmonNextAt, $this->pmonFailures]]);
        $processes = $this->processes && in_array('uuid', $query, true)
            ? $this->queryProcesses($now, $binary, $devices, $run)
            : $none;

        return $processes->then(fn (array $p): array => $this->succeeded($now, $binary, $query, $devices, ...$p));
    }

    /**
     * @param list<string> $query
     * @param list<GpuDevice> $devices
     * @param list<GpuProcess>|null $processes
     * @param array{0: int, 1: float} $apps
     * @param array{0: int, 1: float, 2: int} $pmon
     * @return array{0: GpuSnapshot, 1: self}
     */
    private function succeeded(float $now, string $binary, array $query, array $devices, ?array $processes, array $apps, array $pmon): array
    {
        $snapshot = new GpuSnapshot($devices, $processes);

        return [$snapshot, $this->with(
            nextQueryAt: $now + $this->interval,
            timeouts: 0,
            last: $snapshot,
            everSucceeded: true,
            pinnedBinary: $binary,
            pinnedQuery: $query,
            appsTimeouts: $apps[0],
            appsNextAt: $apps[1],
            pmon: $pmon,
            pinFailures: 0,
        )];
    }

    /**
     * A transient failure: empty snapshot, next query pushed back
     * interval × 2^n (n capped).
     *
     * @return array{0: GpuSnapshot, 1: self}
     */
    private function backoff(float $now): array
    {
        $empty = new GpuSnapshot([]);
        $timeouts = $this->timeouts + 1;
        $backoff = $this->interval * (2 ** min($timeouts, self::MAX_BACKOFF_EXPONENT));

        return [$empty, $this->with(nextQueryAt: $now + $backoff, timeouts: $timeouts, last: $empty)];
    }

    /**
     * The per-process spawns, each on its own schedule; neither touches the
     * device state — processes are just "not measured" when both fail.
     *
     * compute-apps (memory): a timeout backs only it off — interval × 2^n,
     * then APPS_RETRY_AFTER once APPS_MAX_TIMEOUTS are consecutive — and
     * skips pmon for this cycle (the driver just hung once; a second spawn
     * would double the wait). Any other failure clears its backoff and pmon
     * still runs: its rows carry fb memory too.
     *
     * pmon (utilization): its own timeout counter with the same law
     * (PMON_MAX_TIMEOUTS, then APPS_RETRY_AFTER), so a pmon that hangs never
     * disables the working memory query. PMON_MAX_FAILURES consecutive
     * non-timeout failures (exit != 0, no header — a driver whose pmon is
     * unsupported, e.g. vGPU/WSL) memoize it off until the next fresh
     * withProcesses() opt-in. Worst case per interval: one bounded timeout.
     *
     * @param list<GpuDevice> $devices
     * @param \Closure(list<string>): PromiseInterface $run
     * @return PromiseInterface<array{0: list<GpuProcess>|null, 1: array{0: int, 1: float}, 2: array{0: int, 1: float, 2: int}}>
     *         [processes, apps [timeouts, next at], pmon [timeouts, next at, failures]]
     */
    private function queryProcesses(float $now, string $binary, array $devices, \Closure $run): PromiseInterface
    {
        $byUuid = [];
        $uuidByIndex = [];
        foreach ($devices as $device) {
            $byUuid[$device->uuid] = $device->index;
            $uuidByIndex[$device->index] = $device->uuid;
        }
        $pmon = [$this->pmonTimeouts, $this->pmonNextAt, $this->pmonFailures];

        // [run pmon?, processes, apps state]
        $apps = $now < $this->appsNextAt
            ? resolve([true, null, [$this->appsTimeouts, $this->appsNextAt]])
            : $run([
                $binary,
                '--query-compute-apps=' . implode(',', self::APPS_QUERY),
                '--format=csv,noheader,nounits',
            ])->then(function (array $result) use ($now, $byUuid): array {
                [$outcome, $output] = $result;
                if ($outcome === GpuOutcome::Timeout) {
                    $n = min($this->appsTimeouts + 1, self::APPS_MAX_TIMEOUTS);
                    $wait = $n >= self::APPS_MAX_TIMEOUTS ? self::APPS_RETRY_AFTER : $this->interval * (2 ** $n);

                    return [false, null, [$n, $now + $wait]];
                }

                return [true, $outcome === GpuOutcome::Ok ? self::parseProcesses($output, $byUuid) : null, [0, -INF]];
            });

        return $apps->then(function (array $step) use ($now, $binary, $run, $pmon, $uuidByIndex): PromiseInterface|array {
            [$continue, $processes, $apps] = $step;
            if (!$continue || $this->pmonFailures >= self::PMON_MAX_FAILURES || $now < $this->pmonNextAt) {
                return [$processes, $apps, $pmon];
            }

            return $run([$binary, ...self::PMON_ARGS])->then(function (array $result) use ($now, $processes, $apps, $uuidByIndex): array {
                [$outcome, $output] = $result;
                $rows = $outcome === GpuOutcome::Ok ? self::parsePmon($output) : null;
                if ($outcome === GpuOutcome::Timeout) {
                    $n = min($this->pmonTimeouts + 1, self::PMON_MAX_TIMEOUTS);
                    $wait = $n >= self::PMON_MAX_TIMEOUTS ? self::APPS_RETRY_AFTER : $this->interval * (2 ** $n);

                    return [$processes, $apps, [$n, $now + $wait, $this->pmonFailures]];
                }
                if ($rows === null) {
                    return [$processes, $apps, [0, -INF, $this->pmonFailures + 1]];
                }

                return [self::mergePmon($processes ?? [], $rows, $uuidByIndex), $apps, [0, -INF, 0]];
            });
        });
    }

    /** False once pmon was memoized unsupported (PMON_MAX_FAILURES non-timeout failures in a row). */
    public function pmonSupported(): bool
    {
        return $this->pmonFailures < self::PMON_MAX_FAILURES;
    }

    /**
     * `nvidia-smi pmon` rows, columns located by the first `#` header line
     * (drivers add columns — jpg, ofa, ccpm — between versions). "-" in a
     * percent column is an idle process (0.0); a missing column is
     * UNMEASURED.
     *
     * @return list<array{gpu: int, pid: int, sm: float, mem: float, enc: float, dec: float, fb: int}>|null
     *         null when no header line was found (not pmon output)
     */
    private static function parsePmon(string $output): ?array
    {
        $columns = null;
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line[0] === '#') {
                $names = preg_split('/\s+/', trim(substr($line, 1))) ?: [];
                if ($columns === null && in_array('pid', $names, true)) {
                    $columns = array_flip($names);
                }

                continue;
            }
            if ($columns === null || !isset($columns['gpu'], $columns['pid'])) {
                continue;
            }
            $cols = preg_split('/\s+/', $line) ?: [];
            $gpu = $cols[$columns['gpu']] ?? '';
            $pid = $cols[$columns['pid']] ?? '';
            if (!ctype_digit($gpu) || !ctype_digit($pid)) {
                continue;
            }
            $pct = static function (string $name) use ($columns, $cols): float {
                $v = isset($columns[$name]) ? ($cols[$columns[$name]] ?? '') : '';

                return $v === '-' ? 0.0 : (is_numeric($v) ? (float) $v : Sentinel::UNMEASURED);
            };
            $fb = isset($columns['fb']) ? ($cols[$columns['fb']] ?? '') : '';
            $rows[] = [
                'gpu' => (int) $gpu,
                'pid' => (int) $pid,
                'sm' => $pct('sm'),
                'mem' => $pct('mem'),
                'enc' => $pct('enc'),
                'dec' => $pct('dec'),
                'fb' => is_numeric($fb) ? self::mib($fb) : Sentinel::UNMEASURED_INT,
            ];
        }

        return $columns === null ? null : $rows;
    }

    /**
     * pmon utilization onto the compute-apps rows (matched by pid + GPU);
     * a pmon-only row (a graphics process) is appended with pmon's fb.
     *
     * @param list<GpuProcess> $processes
     * @param list<array{gpu: int, pid: int, sm: float, mem: float, enc: float, dec: float, fb: int}> $pmon
     * @param array<int, string> $uuidByIndex
     * @return list<GpuProcess>
     */
    private static function mergePmon(array $processes, array $pmon, array $uuidByIndex): array
    {
        $at = [];
        foreach ($processes as $i => $p) {
            $at[$p->pid . '|' . $p->gpuIndex] = $i;
        }
        foreach ($pmon as $row) {
            $key = $row['pid'] . '|' . $row['gpu'];
            $known = isset($uuidByIndex[$row['gpu']]);
            if (isset($at[$key])) {
                $p = $processes[$at[$key]];
                $processes[$at[$key]] = new GpuProcess($p->pid, $p->gpuIndex, $p->gpuUuid, $p->usedMemory, $row['sm'], $row['mem'], $row['enc'], $row['dec']);

                continue;
            }
            $at[$key] = count($processes);
            $processes[] = new GpuProcess(
                $row['pid'],
                $known ? $row['gpu'] : -1,
                $known ? $uuidByIndex[$row['gpu']] : Sentinel::UNAVAILABLE,
                $row['fb'],
                $row['sm'],
                $row['mem'],
                $row['enc'],
                $row['dec'],
            );
        }

        return $processes;
    }

    /**
     * @param array<string, int> $byUuid gpu uuid → device index
     * @return list<GpuProcess>
     */
    private static function parseProcesses(string $output, array $byUuid): array
    {
        $processes = [];
        foreach (explode("\n", trim($output)) as $line) {
            $cols = array_map('trim', explode(',', $line));
            if (count($cols) !== count(self::APPS_QUERY) || !ctype_digit($cols[0]) || $cols[1] === '') {
                continue; // "No running processes found" and blank lines
            }
            $processes[] = new GpuProcess(
                (int) $cols[0],
                $byUuid[$cols[1]] ?? -1,
                self::text($cols[1]),
                self::mib($cols[2]),
            );
        }

        return $processes;
    }

    /**
     * @param list<string> $query
     * @return list<string>
     */
    private static function argv(string $binary, array $query): array
    {
        return [$binary, '--query-gpu=' . implode(',', $query), '--format=csv,noheader,nounits'];
    }

    /**
     * @param list<string> $query field names, `name` last
     * @return list<GpuDevice>
     */
    private static function parse(string $output, array $query): array
    {
        $devices = [];
        $columns = count($query);
        foreach (explode("\n", trim($output)) as $line) {
            $cols = array_map('trim', explode(',', $line, $columns));
            if (count($cols) !== $columns) {
                continue;
            }
            $f = array_combine($query, $cols);
            if (!ctype_digit($f['index'] ?? '') || ($f['name'] ?? '') === '') {
                continue;
            }
            $float = static fn (string $key): float => isset($f[$key]) && is_numeric($f[$key]) ? (float) $f[$key] : Sentinel::UNMEASURED;
            $int = static fn (string $key): int => isset($f[$key]) && ctype_digit($f[$key]) ? (int) $f[$key] : Sentinel::UNMEASURED_INT;
            $pstate = $f['pstate'] ?? '';
            $devices[] = new GpuDevice(
                (int) $f['index'],
                self::text($f['name']),
                $float('utilization.gpu'),
                self::mib($f['memory.used'] ?? ''),
                self::mib($f['memory.total'] ?? ''),
                $float('temperature.gpu'),
                $float('power.draw'),
                $float('utilization.memory'),
                $float('power.limit'),
                $float('clocks.gr'),
                $float('clocks.mem'),
                $float('clocks.max.gr'),
                $float('clocks.max.mem'),
                $float('fan.speed'),
                preg_match('/^P\d{1,2}$/', $pstate) === 1 ? $pstate : Sentinel::UNAVAILABLE,
                $int('pcie.link.gen.current'),
                $int('pcie.link.width.current'),
                $float('temperature.memory'),
                $float('utilization.encoder'),
                $float('utilization.decoder'),
                isset($f['uuid']) ? self::text($f['uuid']) : Sentinel::UNAVAILABLE,
                GpuVendor::Nvidia,
                AcceleratorKind::Gpu,
            );
        }

        return $devices;
    }

    /** nvidia-smi reports memory in MiB with nounits; "[N/A]" → sentinel. */
    private static function mib(string $v): int
    {
        return is_numeric($v) ? (int) round((float) $v * 1048576) : Sentinel::UNMEASURED_INT;
    }

    /**
     * Free text printed to the terminal, whitelisted to printable ASCII:
     * C0, DEL and C1 controls (raw 0x80–0x9F bytes and their UTF-8 form
     * U+0080–U+009F, e.g. the 8-bit CSI U+009B) are all dropped, as is any
     * other non-ASCII byte — GPU names and UUIDs are ASCII. N/A markers fold
     * to the sentinel.
     */
    private static function text(string $v): string
    {
        $v = trim((string) preg_replace('/[^\x20-\x7e]/', '', $v));

        return $v === '' || preg_match('/^\[?(N\/A|Not Supported|Unknown Error)\]?$/i', $v) === 1 ? Sentinel::UNAVAILABLE : $v;
    }

    /**
     * @param list<string>|null $pinnedQuery
     * @param array{0: int, 1: float, 2: int}|null $pmon [timeouts, next at, failures]
     */
    private function with(
        ?bool $absent = null,
        ?float $nextQueryAt = null,
        ?int $timeouts = null,
        ?GpuSnapshot $last = null,
        ?bool $everSucceeded = null,
        ?string $pinnedBinary = null,
        ?array $pinnedQuery = null,
        ?int $appsTimeouts = null,
        ?float $appsNextAt = null,
        ?array $pmon = null,
        ?int $pinFailures = null,
        bool $unpin = false,
    ): self {
        // Unpinning re-discovers the candidates (when new() did) so a
        // binary moved by a driver upgrade is found at its new path.
        $candidates = $unpin && $this->discovered ? array_values(self::candidates()) : $this->candidates;

        return new self(
            $this->runner,
            $this->clock,
            $this->interval,
            $absent ?? $this->absent,
            $nextQueryAt ?? $this->nextQueryAt,
            $timeouts ?? $this->timeouts,
            $last ?? $this->last,
            $everSucceeded ?? $this->everSucceeded,
            $candidates,
            $this->queries,
            $unpin ? null : ($pinnedBinary ?? $this->pinnedBinary),
            $unpin ? null : ($pinnedQuery ?? $this->pinnedQuery),
            $this->processes,
            $appsTimeouts ?? $this->appsTimeouts,
            $appsNextAt ?? $this->appsNextAt,
            $unpin ? 0 : ($pinFailures ?? $this->pinFailures),
            $this->discovered,
            $pmon[0] ?? $this->pmonTimeouts,
            $pmon[1] ?? $this->pmonNextAt,
            $pmon[2] ?? $this->pmonFailures,
            $this->asyncRunner,
        );
    }

    /**
     * The default blocking runner.
     *
     * @param list<string> $argv
     * @return array{0: GpuOutcome, 1: string}
     */
    private static function nvidiaSmi(array $argv): array
    {
        $argv = self::executable($argv);

        return $argv === null ? [GpuOutcome::Absent, ''] : SmiProcess::run($argv);
    }

    /**
     * The default loop-driven runner.
     *
     * @param list<string> $argv
     * @return PromiseInterface<array{0: GpuOutcome, 1: string}>
     */
    private static function nvidiaSmiAsync(array $argv): PromiseInterface
    {
        $argv = self::executable($argv);

        return $argv === null ? resolve([GpuOutcome::Absent, '']) : SmiProcess::launch($argv);
    }

    /**
     * `$argv` with argv[0] resolved to an executable: a discovered
     * candidate is an absolute path; a bare name goes through PATH.
     *
     * @param list<string> $argv
     * @return list<string>|null null when there is no such executable
     */
    private static function executable(array $argv): ?array
    {
        $binary = str_contains($argv[0], '/')
            ? (is_file($argv[0]) && is_executable($argv[0]) ? $argv[0] : null)
            : self::which($argv[0]);
        if ($binary === null) {
            return null;
        }
        $argv[0] = $binary;

        return $argv;
    }

    private static function which(string $command): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $command) && is_executable($dir . '/' . $command)) {
                return $dir . '/' . $command;
            }
        }

        return null;
    }
}
