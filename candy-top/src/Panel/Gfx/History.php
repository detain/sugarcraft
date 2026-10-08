<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gfx;

/**
 * Named, capped sample series — btop's `deque<long long>` histories
 * (`cpu_percent[field]`, `core_percent[n]`, `temp[n]`, `mem.percent[name]`),
 * one immutable value per panel.
 *
 * btop #1008 lives here: a sample that could not be measured (any negative
 * value — the collectors' UNMEASURED sentinel) REPEATS the last good value
 * instead of being pushed, so the graph keeps scrolling without a 0 %
 * dip or 100 % spike and every readout keeps showing the last reading
 * instead of `n/a`. A series that has never had a good value stays empty.
 * btop rounds every percentage to `long long` before pushing; so does this.
 */
final class History
{
    /**
     * @param array<string, list<int>> $series
     */
    private function __construct(
        private readonly array $series,
    ) {
    }

    public static function new(): self
    {
        return new self([]);
    }

    /** Append `$value` (or repeat the last good one when it is negative) and keep the newest `$cap`. */
    public function push(string $key, int|float $value, int $cap): self
    {
        $series = $this->series[$key] ?? [];
        if ($value < 0 || (is_float($value) && !is_finite($value))) {
            if ($series === []) {
                return $this;
            }
            $value = $series[count($series) - 1];
        }
        $series[] = (int) round($value);
        $cap = max(1, $cap);
        if (count($series) > $cap) {
            $series = array_slice($series, -$cap);
        }
        $all = $this->series;
        $all[$key] = $series;

        return new self($all);
    }

    /** @return list<int> oldest first; empty when never measured */
    public function series(string $key): array
    {
        return $this->series[$key] ?? [];
    }

    /** The newest value, null when the series never had a good one. */
    public function last(string $key): ?int
    {
        $s = $this->series[$key] ?? [];

        return $s === [] ? null : $s[count($s) - 1];
    }

    public function has(string $key): bool
    {
        return ($this->series[$key] ?? []) !== [];
    }

    /**
     * Only the named series — btop erases a disk's deques with the disk
     * when it unmounts, so a remount starts a fresh history.
     *
     * @param list<string> $keys
     */
    public function only(array $keys): self
    {
        return new self(array_intersect_key($this->series, array_flip($keys)));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->series);
    }
}
