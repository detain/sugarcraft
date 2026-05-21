<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Report;

use SugarCraft\Tick\Heartbeat;

/**
 * Detects untracked time gaps between heartbeats.
 */
final class GapsReport
{
    /**
     * @param array<Heartbeat> $heartbeats
     * @param int $minGapSeconds minimum gap to report (default 5 minutes = 300)
     */
    public function __construct(
        private readonly array $heartbeats,
        private readonly int $minGapSeconds = 300,
    ) {}

    /**
     * @return list<array{start: int, end: int, gapSeconds: int}>
     */
    public function gaps(): array
    {
        if (count($this->heartbeats) < 2) {
            return [];
        }

        $sorted = $this->heartbeats;
        usort($sorted, static fn(Heartbeat $a, Heartbeat $b): int => $a->time <=> $b->time);

        $result = [];
        for ($i = 1; $i < count($sorted); $i++) {
            $prev = $sorted[$i - 1];
            $curr = $sorted[$i];
            $gap = $curr->time - ($prev->time + $prev->duration);
            if ($gap >= $this->minGapSeconds) {
                $result[] = [
                    'start' => $prev->time + $prev->duration,
                    'end' => $curr->time,
                    'gapSeconds' => $gap,
                ];
            }
        }
        return $result;
    }

    public function totalUntrackedSeconds(): int
    {
        return array_sum(array_column($this->gaps(), 'gapSeconds'));
    }
}
