<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One container in the ctr box — btop PR #1873 `Ctr::ctr_info`.
 *
 *  - `engine` / `name` / `path`: from the cgroup ({@see ContainerRef});
 *    `name` is the short id until a docker name replaces it, already
 *    restricted to a terminal-safe alphabet; `path` is the container
 *    root's raw cgroup path (the identity key, never printed);
 *  - `procs`: processes seen in it this sample;
 *  - `cpu`: btop `cpu_p` — percent of the whole machine, or of one core
 *    with proc_per_core (the cgroup v2 `usage_usec` delta, else the sum of
 *    its processes' cpu);
 *  - `mem`: bytes — cgroup v2 `memory.current` minus `inactive_file`
 *    (what `docker stats` shows), else the sum of its processes' RSS;
 *  - `memLimit`: `memory.max` in bytes, 0 when unlimited or unreadable;
 *  - `cpuTime`: the last `usage_usec` read, 0 when never read;
 *  - `history`: btop `cpu_percent`, percent of TOTAL cpu power (0-100),
 *    oldest first, capped at the terminal width.
 */
final class ContainerInfo
{
    /** @param list<int> $history */
    public function __construct(
        public readonly string $engine,
        public readonly string $name,
        public readonly string $path,
        public readonly int $procs = 0,
        public readonly float $cpu = 0.0,
        public readonly int $mem = 0,
        public readonly int $memLimit = 0,
        public readonly int $cpuTime = 0,
        public readonly array $history = [],
    ) {
    }

    /** A container first seen in `$ref` (btop parse_cgroup's ctr_info). */
    public static function of(ContainerRef $ref): self
    {
        return new self($ref->engine, $ref->name, $ref->cgroupPath);
    }

    public function withName(string $name): self
    {
        return $this->mutate(['name' => $name]);
    }

    /** The per-sample totals btop resets and re-sums from the processes. */
    public function withTotals(int $procs, float $cpu, int $mem, int $memLimit = 0): self
    {
        return $this->mutate(['procs' => $procs, 'cpu' => $cpu, 'mem' => $mem, 'memLimit' => $memLimit]);
    }

    public function withCpu(float $cpu, int $cpuTime): self
    {
        return $this->mutate(['cpu' => $cpu, 'cpuTime' => $cpuTime]);
    }

    public function withMem(int $mem): self
    {
        return $this->mutate(['mem' => $mem]);
    }

    public function withMemLimit(int $memLimit): self
    {
        return $this->mutate(['memLimit' => $memLimit]);
    }

    /** Push one cpu-graph value, dropping the oldest past `$cap` (btop pop_front while > Term::width). */
    public function withHistoryPoint(int $percent, int $cap): self
    {
        $history = [...$this->history, max(0, min(100, $percent))];
        $over = \count($history) - max(1, $cap);

        return $this->mutate(['history' => $over > 0 ? \array_slice($history, $over) : $history]);
    }

    /** @param array<string, mixed> $changes */
    private function mutate(array $changes): self
    {
        $v = array_replace(get_object_vars($this), $changes);

        return new self($v['engine'], $v['name'], $v['path'], $v['procs'], $v['cpu'], $v['mem'], $v['memLimit'], $v['cpuTime'], $v['history']);
    }
}
