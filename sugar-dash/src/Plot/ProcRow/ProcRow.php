<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\ProcRow;

/**
 * One process as the proc-list row composer sees it — only the fields
 * btop's normal (non-tree) row prints or colors by.
 *
 * `memPercent` drives both the mem color and, when `memLabel` is null,
 * btop's percent label; a caller in btop's `proc_mem_bytes` mode formats
 * the byte count itself and passes it as `memLabel` (the color still
 * follows the percent, as in btop).
 *
 * Mirrors aristocratos/btop Proc::proc_info (src/btop_shared.hpp) — the
 * pid / name / cmd / threads / user / cpu_p / mem subset Proc::draw reads.
 */
final class ProcRow
{
    private function __construct(
        public readonly int $pid,
        public readonly string $name,
        public readonly string $cmd,
        public readonly int $threads,
        public readonly string $user,
        public readonly float $cpu,
        public readonly float $memPercent,
        public readonly ?string $memLabel,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on a non-finite cpu or mem percent
     */
    public static function new(
        int $pid,
        string $name,
        string $cmd = '',
        int $threads = 1,
        string $user = '',
        float $cpu = 0.0,
        float $memPercent = 0.0,
        ?string $memLabel = null,
    ): self {
        if (!is_finite($cpu) || !is_finite($memPercent)) {
            throw new \InvalidArgumentException('ProcRow cpu and memPercent must be finite');
        }
        return new self($pid, $name, $cmd, $threads, $user, $cpu, $memPercent, $memLabel);
    }
}
