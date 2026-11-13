<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Report;

use SugarCraft\CodeTime\Heartbeat;

/**
 * Detects untracked time gaps between heartbeats.
 */
final class GapsReport
{
    /**
     * @param array<Heartbeat> $heartbeats
     * @param int $minGapSeconds minimum gap to report; the 300 default is the
     *                           library's choice, and the CLI exposes it as
     *                           the reachable `gaps --min=<secs>` knob (067)
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

        // A running covered horizon, not the previous beat's own end: an
        // attribution heartbeat (60 s from a beat seconds after a long one)
        // sits INSIDE a still-open window, and a pair-wise walk would report
        // the overlap as untracked time that was being tracked. The horizon
        // only ever moves forward, so a gap start never lands inside a window.
        $result = [];
        $sortedCount = count($sorted);
        $coveredUntil = $sorted[0]->time + $sorted[0]->duration;
        for ($i = 1; $i < $sortedCount; $i++) {
            $curr = $sorted[$i];
            $gap = $curr->time - $coveredUntil;
            if ($gap >= $this->minGapSeconds) {
                $result[] = [
                    'start' => $coveredUntil,
                    'end' => $curr->time,
                    'gapSeconds' => $gap,
                ];
            }
            $coveredUntil = max($coveredUntil, $curr->time + $curr->duration);
        }
        return $result;
    }

    public function totalUntrackedSeconds(): int
    {
        return array_sum(array_column($this->gaps(), 'gapSeconds'));
    }
}
