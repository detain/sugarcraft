<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

use SugarCraft\Top\Collect\ProcDetail;

/**
 * The detailed view's memory — btop's `Proc::detailed` (detailed_pid,
 * cpu_percent / mem_bytes deques, first_mem, parent, status, last entry).
 *
 *  - `entry`: the last sample of the pid (kept when it dies — btop shows
 *    the dead process greyed out with status "Dead");
 *  - `cpu`: per-sample cpu % of ONE core clamped to 0..100 (btop multiplies
 *    cpu_p by the core count unless proc_per_core) — the 7-row graph;
 *  - `mem`: resident bytes per sample — the one-row graph;
 *  - `firstMem`: the mem graph's ceiling, re-seeded to min(2 × mem, total)
 *    whenever mem leaves [firstMem / 4, firstMem × 2];
 *  - `parent`: the parent's name, looked up once;
 *  - `extra`: the last {@see ProcDetail} (cwd #1546, elapsed, io totals).
 *
 * Mirrors aristocratos/btop Proc::_collect_details (src/linux/btop_collect.cpp).
 */
final class DetailState
{
    /** btop trims both deques to the box width; this bounds them. */
    public const HISTORY = 512;

    /**
     * @param list<int> $cpu
     * @param list<int> $mem
     */
    private function __construct(
        public readonly int $pid,
        public readonly ?ProcEntry $entry,
        public readonly bool $alive,
        public readonly array $cpu,
        public readonly array $mem,
        public readonly int $firstMem,
        public readonly string $parent,
        public readonly ?ProcDetail $extra,
    ) {
    }

    public static function open(int $pid, ?ProcEntry $entry): self
    {
        return new self($pid, $entry, $entry !== null, [], [], -1, '', null);
    }

    /**
     * Fold one fresh sample in. `$entry` null = the pid is gone ("Dead").
     *
     * @param int $cores multiplier for the graph when proc_per_core is off
     */
    public function observe(?ProcEntry $entry, ?ProcDetail $extra, int $cores, bool $perCore, int $memTotal, string $parent): self
    {
        if ($entry === null) {
            return new self($this->pid, $this->entry, false, $this->cpu, $this->mem, $this->firstMem, $this->parent, $this->extra);
        }
        $pct = $perCore ? $entry->cpu : $entry->cpu * max(1, $cores);
        $cpu = array_slice([...$this->cpu, (int) max(0, min(100, round($pct)))], -self::HISTORY);
        $mem = array_slice([...$this->mem, $entry->mem], -self::HISTORY);
        $first = $this->firstMem;
        $now = $entry->mem;
        if ($first === -1 || $first < intdiv($now, 2) || $first > $now * 4) {
            $first = $memTotal > 0 ? min($now * 2, $memTotal) : $now * 2;
        }

        return new self(
            $this->pid,
            $entry,
            true,
            $cpu,
            $mem,
            $first,
            $this->parent !== '' ? $this->parent : $parent,
            $extra ?? $this->extra,
        );
    }

    /** btop proc_states: the one-letter state spelled out (a Lang key suffix). */
    public function status(): string
    {
        if (!$this->alive || $this->entry === null) {
            return 'dead';
        }

        return match ($this->entry->process->state) {
            'R' => 'running',
            'S' => 'sleeping',
            'D' => 'waiting',
            'Z' => 'zombie',
            'T' => 'stopped',
            't' => 'tracing',
            'X', 'x' => 'dead',
            'K' => 'wakekill',
            'P' => 'parked',
            default => 'unknown',
        };
    }
}
