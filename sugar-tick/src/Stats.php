<?php

declare(strict_types=1);

namespace CandyCore\Tick;

/**
 * Pure-state aggregator — folds a list of heartbeats into the
 * dashboard's projection: totals per project, totals per language,
 * and a per-day timeline (seconds / day) for the active range.
 *
 * No I/O, no caching — every call re-folds. Keeps the unit tests
 * deterministic and the dashboard simple to wire up.
 */
final class Stats
{
    /**
     * @param list<Heartbeat> $beats
     * @param list<\DateTimeImmutable> $days   chronological
     */
    public function __construct(
        public readonly array $beats,
        public readonly array $days,
    ) {}

    /**
     * @param list<Heartbeat> $beats
     */
    public static function compute(array $beats, \DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        $days = [];
        $cur = $from->setTime(0, 0);
        $end = $to->setTime(0, 0);
        while ($cur <= $end) {
            $days[] = $cur;
            $cur    = $cur->modify('+1 day');
        }
        return new self($beats, $days);
    }

    /** @return array<string,int>  project → seconds (sorted desc) */
    public function perProject(): array
    {
        $bucket = [];
        foreach ($this->beats as $b) {
            $bucket[$b->project] = ($bucket[$b->project] ?? 0) + $b->duration;
        }
        arsort($bucket);
        return $bucket;
    }

    /** @return array<string,int>  language → seconds (sorted desc) */
    public function perLanguage(): array
    {
        $bucket = [];
        foreach ($this->beats as $b) {
            $bucket[$b->language] = ($bucket[$b->language] ?? 0) + $b->duration;
        }
        arsort($bucket);
        return $bucket;
    }

    /** @return list<int>  one bucket per day in `$days`, in seconds */
    public function timeline(): array
    {
        $out = array_fill(0, count($this->days), 0);
        foreach ($this->beats as $b) {
            $stamp = (new \DateTimeImmutable('@' . $b->time))->setTime(0, 0);
            foreach ($this->days as $i => $day) {
                if ($stamp == $day) {
                    $out[$i] += $b->duration;
                    break;
                }
            }
        }
        return $out;
    }

    public function totalSeconds(): int
    {
        $n = 0;
        foreach ($this->beats as $b) $n += $b->duration;
        return $n;
    }

    public static function formatHours(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        return sprintf('%dh %02dm', $h, $m);
    }
}
