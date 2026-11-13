<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The live container collector — btop PR #1873 `Ctr::collect` (Linux).
 *
 * Every sample:
 *  1. {@see group()}: the processes are summed per container (by the
 *     cgroup tag ProcList already reads once per process lifetime);
 *     containers with no live process left are dropped;
 *  2. only when a NEW docker container appeared, one `GET /containers/json`
 *     on the docker socket ({@see DockerSocket}) turns short ids into
 *     names — a name already resolved is kept, so a `docker rename` shows
 *     after a restart, as in btop;
 *  3. sort by name;
 *  4. cgroup v2 figures replace the process sums when readable:
 *     `cpu.stat` usage_usec delta over the wall time since the previous
 *     sample, `memory.current` minus `memory.stat` inactive_file,
 *     `memory.max` (0 for "max"). cgroup v1, or an unreadable file, keeps
 *     the sum of the processes (btop's fallback).
 *
 * VMs (beyond btop, whose parse_cgroup never lists a `machine-qemu*`
 * scope): every libvirt/KVM guest is one entry, engine Vm::ENGINE
 * ("kvm", as the proc box's `[kvm:name]` tag), grouped by its machined
 * scope (`machine.slice/machine-qemu\x2dN\x2d<name>.scope` — the qemu
 * process itself sits in the `libvirt/emulator` child) and named by the
 * domain name: the emulator's `-name guest=` when seen, else the scope's
 * own name with the systemd escapes decoded. The scope's cgroup v2 files
 * are read exactly as a container's; a guest's memory.max is normally
 * "max", so the limit falls back to its configured memory (`-m`, already
 * parsed from the cmdline once per process lifetime — no libvirt call).
 * A bare (non-libvirt) qemu owns no cgroup and is not listed; ctr_show_vms
 * off ({@see withVms()}) restores btop's containers-only box.
 *
 * Not Linux (FreeBSD): {@see disabled()} — btop's box exists on every
 * platform but only Linux fills it; no cgroup or socket read is tried.
 *
 * Pure except for the reads inside collect(), which only ever runs in a
 * Cmd. Never throws.
 */
final class Containers implements ContainerCollector
{
    public const CGROUP_ROOT = Cgroup::DEFAULT_V2_ROOT;

    /** Reuse a good /containers/json reply this long (candy-top-034). */
    private const DOCKER_TTL_US = 5_000_000;

    /** First retry gap after a failed exchange; doubles per failure… */
    private const DOCKER_BACKOFF_BASE_US = 1_000_000;

    /** …capped here. */
    private const DOCKER_BACKOFF_MAX_US = 30_000_000;

    /**
     * @param list<ContainerInfo> $current
     * @param \Closure(): string  $docker the `/containers/json` reply ("" when unreachable)
     * @param \Closure(): int     $clock  monotonic microseconds
     * @param array{response: string, at: int, fails: int}|null $dockerCache
     *        last reply and when it was fetched (candy-top-034 coalescing)
     */
    private function __construct(
        private readonly string $root,
        private readonly \Closure $docker,
        private readonly \Closure $clock,
        private readonly bool $enabled,
        private readonly array $current,
        private readonly int $oldTime,
        private readonly bool $vms = true,
        private readonly ?array $dockerCache = null,
    ) {
    }

    /**
     * @param ?\Closure(): string $docker defaults to {@see DockerSocket::fetch()}
     * @param ?\Closure(): int    $clock  defaults to hrtime in microseconds
     *
     * `$root` null means "this host": the cgroup v2 hierarchy is resolved
     * from /proc/mounts (hybrid hosts keep working, candy-top-057).
     */
    public static function new(?string $root = null, ?\Closure $docker = null, ?\Closure $clock = null): self
    {
        return new self(
            rtrim($root ?? Cgroup::v2Root(Paths::system()), '/'),
            $docker ?? static fn (): string => DockerSocket::fetch(),
            $clock ?? static fn (): int => intdiv(hrtime(true), 1000),
            true,
            [],
            0,
        );
    }

    /** The non-Linux collector: always an empty box, no reads. */
    public static function disabled(): self
    {
        return new self('', static fn (): string => '', static fn (): int => 0, false, [], 0);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function rebased(): self
    {
        return new self(
            $this->root,
            $this->docker,
            $this->clock,
            $this->enabled,
            array_map(static fn (ContainerInfo $c): ContainerInfo => $c->withCpu($c->cpu, 0), $this->current),
            0,
            $this->vms,
            $this->dockerCache,
        );
    }

    public function withVms(bool $vms): self
    {
        return $vms === $this->vms
            ? $this
            : new self($this->root, $this->docker, $this->clock, $this->enabled, $this->current, $this->oldTime, $vms, $this->dockerCache);
    }

    public function vms(): bool
    {
        return $this->vms;
    }

    public function due(int $minWindowUs): bool
    {
        return $this->enabled && ($this->oldTime === 0 || ($this->clock)() - $this->oldTime >= $minWindowUs);
    }

    public function collect(array $processes, int $memTotal, int $cores, bool $perCore, int $historyCap): array
    {
        $cores = max(1, $cores);
        if (!$this->enabled) {
            return [new ContainerSnapshot([], $memTotal, $cores, 'disabled'), $this];
        }
        $now = ($this->clock)();
        [$ctrs, $newDocker] = self::group($this->current, $processes, $this->vms);
        $cache = $this->dockerCache;
        if ($newDocker) {
            [$response, $cache] = $this->dockerFetch($now);
            if ($response !== '') {
                $ctrs = self::named($ctrs, $response);
            }
        }
        $ctrs = self::sorted($ctrs);

        $out = [];
        foreach ($ctrs as $c) {
            $dir = $this->root . $c->path;
            if (!self::safePath($c->path)) {
                $out[] = self::limited($c)->withHistoryPoint(self::graphPercent($c->cpu, $perCore, $cores), $historyCap);

                continue;
            }
            $usage = self::cpuUsage(Read::file($dir . '/cpu.stat'));
            if ($usage !== null) {
                $cpu = $c->cpu;
                if ($c->cpuTime > 0 && $usage >= $c->cpuTime && $now > $this->oldTime) {
                    $cpu = 100.0 * ($usage - $c->cpuTime) / ($now - $this->oldTime) / ($perCore ? 1 : $cores);
                }
                $c = $c->withCpu($cpu, $usage);
            }
            $current = self::integer(Read::file($dir . '/memory.current'));
            if ($current !== null) {
                $inactive = self::statValue(Read::file($dir . '/memory.stat'), 'inactive_file');
                $c = $c->withMem($current - min($current, $inactive ?? 0));
            }
            // Set on every read, "max" included (0): a limit lifted since the
            // last sample must not stick and block a VM's -m fallback.
            $max = Read::file($dir . '/memory.max');
            if ($max !== null) {
                $c = $c->withMemLimit(self::integer($max) ?? 0);
            }
            $out[] = self::limited($c)->withHistoryPoint(self::graphPercent($c->cpu, $perCore, $cores), $historyCap);
        }

        return [
            // An empty box says why: an engine on the host but nothing in it
            // is "none"; no engine process at all is "unavailable" (candy-top-097).
            new ContainerSnapshot($out, $memTotal, $cores, $out === []
                ? (self::engineRunning($processes) ? 'none' : 'unavailable')
                : null),
            new self($this->root, $this->docker, $this->clock, true, $out, $now, $this->vms, $cache),
        ];
    }

    /** A container engine daemon on the host? (dockerd/containerd/podman/crio.) */
    private static function engineRunning(array $processes): bool
    {
        foreach ($processes as $p) {
            if (preg_match('/^(dockerd|containerd|podman|crio)/', $p->name) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * One socket exchange per window, not per churn sample (candy-top-034):
     * a wedged or paused daemon used to cost a blocking ≤1 s read plus an
     * 8 MiB name-scan on the UI thread EVERY time a new docker cgroup
     * appeared — input, clock and render all froze with it. A fresh reply
     * is reused inside the window; a failing one backs off 1 s, 2 s, 4 s …
     * up to half a minute, and the last good bytes keep labelling the
     * names meanwhile. (The finding's full non-blocking loop exchange is
     * the alternative arm; coalescing is the shipped one.)
     *
     * @return array{0: string, 1: array{response: string, at: int, fails: int}}
     */
    private function dockerFetch(int $now): array
    {
        $cache = $this->dockerCache;
        if ($cache !== null) {
            $gap = $cache['fails'] === 0
                ? self::DOCKER_TTL_US
                : min(self::DOCKER_BACKOFF_MAX_US, self::DOCKER_BACKOFF_BASE_US * (2 ** min(10, $cache['fails'])));
            if ($now - $cache['at'] < $gap) {
                return [$cache['response'], $cache];
            }
        }

        $response = ($this->docker)();
        if ($response === '' && $cache !== null && $cache['response'] !== '') {
            // Backoff keeps labelling with the last good reply.
            return [$cache['response'], ['response' => $cache['response'], 'at' => $now, 'fails' => $cache['fails'] + 1]];
        }

        return [$response, ['response' => $response, 'at' => $now, 'fails' => $response === '' ? ($cache['fails'] ?? 0) + 1 : 0]];
    }

    /**
     * A VM without a memory.max (normally "max") is limited by its
     * configured guest memory, so the detail meter reads against the RAM
     * the domain was given rather than the host's MemTotal.
     */
    public static function limited(ContainerInfo $c): ContainerInfo
    {
        return $c->memLimit === 0 && $c->configuredMem() > 0 ? $c->withMemLimit($c->configuredMem()) : $c;
    }

    /**
     * btop Ctr::collect's grouping: reset every known container's totals,
     * sum the live processes into their container (a new one is appended),
     * drop containers left without a process. Processes without a cgroup
     * path or dead ('X') are skipped; so are VM processes when `$vms` is
     * off (btop) — on, a libvirt guest groups by its scope like a
     * container, and the first process carrying the emulator's `guest=`
     * name and facts names the entry (a helper such as swtpm only knows
     * the scope's name).
     *
     * @param list<ContainerInfo> $current
     * @param list<Process>       $processes
     * @return array{0: list<ContainerInfo>, 1: bool} [containers, a new docker container appeared]
     */
    public static function group(array $current, array $processes, bool $vms = true): array
    {
        $byPath = [];
        $totals = [];
        foreach ($current as $c) {
            if (!$vms && $c->isVm()) {
                continue;
            }
            $byPath[$c->path] = $c;
            $totals[$c->path] = [0, 0.0, 0];
        }
        $newDocker = false;
        foreach ($processes as $p) {
            $ref = $p->container;
            if ($ref === null || ($ref->isVm() && !$vms) || $ref->cgroupPath === '' || $p->state === 'X') {
                continue;
            }
            $path = $ref->cgroupPath;
            if (!isset($byPath[$path])) {
                $byPath[$path] = ContainerInfo::of($ref);
                $totals[$path] = [0, 0.0, 0];
                $newDocker = $newDocker || $ref->engine === 'docker';
            } elseif ($ref->vm !== null && $ref->vm->guestName !== '' && ($known = $byPath[$path])->vm?->guestName !== $ref->vm->guestName) {
                $byPath[$path] = $known->withName($ref->name)->withVm($ref->vm);
            }
            $totals[$path][0]++;
            $totals[$path][1] += max(0.0, $p->cpu);
            $totals[$path][2] += max(0, $p->mem);
        }
        $out = [];
        foreach ($byPath as $path => $c) {
            [$procs, $cpu, $mem] = $totals[$path];
            if ($procs > 0) {
                $out[] = $c->withTotals($procs, $cpu, $mem);
            }
        }

        return [$out, $newDocker];
    }

    /**
     * Docker names for every docker container still showing its short id
     * (btop: `c.path.contains(c.name)`).
     *
     * @param list<ContainerInfo> $ctrs
     * @return list<ContainerInfo>
     */
    public static function named(array $ctrs, string $response): array
    {
        return array_map(static function (ContainerInfo $c) use ($response): ContainerInfo {
            if ($c->engine !== 'docker' || !str_contains($c->path, $c->name)) {
                return $c;
            }
            $name = DockerSocket::name($response, $c->name);

            return $name === '' ? $c : $c->withName($name);
        }, $ctrs);
    }

    /**
     * Sorted by name, byte order (btop rng::sort by ctr_info::name); PHP's
     * sort is stable, so equal names keep their first-seen order.
     *
     * @param list<ContainerInfo> $ctrs
     * @return list<ContainerInfo>
     */
    public static function sorted(array $ctrs): array
    {
        usort($ctrs, static fn (ContainerInfo $a, ContainerInfo $b): int => strcmp($a->name, $b->name));

        return $ctrs;
    }

    /** btop: `clamp(llround(cpu_p / (per_core ? coreCount : 1)), 0, 100)` — percent of total cpu power. */
    public static function graphPercent(float $cpu, bool $perCore, int $cores): int
    {
        return (int) max(0, min(100, round($cpu / ($perCore ? max(1, $cores) : 1))));
    }

    /** `usage_usec` from cpu.stat — btop reads only its first `key value` pair. */
    public static function cpuUsage(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $tokens = preg_split('/\s+/', trim($raw), 3) ?: [];
        if (($tokens[0] ?? '') !== 'usage_usec' || !isset($tokens[1]) || !ctype_digit($tokens[1])) {
            return null;
        }

        return (int) $tokens[1];
    }

    /**
     * A `key value` line's value from a flat-keyed cgroup file
     * (memory.stat) — one anchored match, not a split per line: with
     * dozens of guests this runs for every one of them each tick.
     */
    public static function statValue(?string $raw, string $key): ?int
    {
        if ($raw === null || preg_match('/^' . preg_quote($key, '/') . '[ \t]+(\d+)[ \t]*$/m', $raw, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /** A single-integer cgroup file; null for "max", empty or unreadable. */
    public static function integer(?string $raw): ?int
    {
        $raw = trim($raw ?? '');

        return $raw !== '' && ctype_digit($raw) && \strlen($raw) <= 19 ? (int) $raw : null;
    }

    /**
     * Only an absolute path without `..` segments or NUL is joined onto the
     * cgroup root: it came from /proc/[pid]/cgroup, and a delegated cgroup
     * name is chosen by whoever runs inside the container.
     */
    private static function safePath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_contains($path, "\0")
            && !\in_array('..', explode('/', $path), true);
    }
}
