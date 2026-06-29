<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Aggregation;

/**
 * Computes a simple (unweighted) moving average over a list of values.
 * Optionally produces a SMA for a second list at the same cadence (e.g.
 * close prices bucketed by time, then smoothed).
 *
 * Mirrors pandas.DataFrame.rolling.mean / emcli/sma.
 */
final class MovingAverage
{
    /** @var list<int|float> */
    private array $values = [];

    private function __construct(
        public readonly int $windowSize,
        public readonly bool $centered = false,
    ) {
        if ($windowSize <= 0) {
            throw new \InvalidArgumentException('Window size must be positive');
        }
    }

    /**
     * @param list<int|float> $values
     * @return list<float>
     */
    public static function simple(int $windowSize, array $values = []): array
    {
        return (new self($windowSize))->addMany($values)->computeSimple();
    }

    /**
     * @param list<int|float> $values
     * @return list<float>
     */
    public static function centered(int $windowSize, array $values = []): array
    {
        return (new self($windowSize, true))->addMany($values)->computeSimple();
    }

    public static function create(int $windowSize, bool $centered = false): self
    {
        return new self($windowSize, $centered);
    }

    public static function new(int $windowSize, bool $centered = false): self
    {
        return self::create($windowSize, $centered);
    }

    /**
     * Append a single value.
     */
    public function add(int|float $value): self
    {
        $clone = clone $this;
        $clone->values[] = $value;
        return $clone;
    }

    /**
     * Append multiple values at once.
     *
     * @param list<int|float> $values
     */
    public function addMany(array $values): self
    {
        $clone = clone $this;
        foreach ($values as $v) {
            $clone->values[] = $v;
        }
        return $clone;
    }

    /**
     * Compute simple moving averages for all complete windows.
     * The first ($windowSize - 1) positions return 0.0 when not enough
     * data is available for a full window.
     *
     * @return list<float>
     */
    public function computeSimple(): array
    {
        $n = count($this->values);
        if ($n === 0) {
            return [];
        }

        $result = [];
        for ($i = 0; $i < $n; $i++) {
            if ($this->centered) {
                $half = intdiv($this->windowSize, 2);
                $start = $i - $half;
                $end = $i + $half;
                // Pad edges where the window extends past array bounds
                if ($start < 0 || $end >= $n) {
                    $result[] = 0.0;
                    continue;
                }
                $window = array_slice($this->values, $start, $this->windowSize);
            } else {
                if ($i < $this->windowSize - 1) {
                    $result[] = 0.0;
                    continue;
                }
                $window = array_slice($this->values, $i - $this->windowSize + 1, $this->windowSize);
            }
            $result[] = array_sum($window) / count($window);
        }

        return $result;
    }

    /**
     * Compute exponential moving average iteratively.
     * The smoothing factor alpha defaults to 2/($windowSize+1),
     * the standard SMA-linked EMA decay.
     *
     * @param list<int|float> $values
     * @param float|null $alpha Smoothing factor 0..1; null = 2/(windowSize+1)
     * @return list<float>
     */
    public static function ema(int $windowSize, array $values = [], ?float $alpha = null): array
    {
        if ($values === []) {
            return [];
        }

        $alpha ??= 2.0 / ($windowSize + 1);
        $ema = (float) $values[0];
        $result = [$ema];

        for ($i = 1; $i < count($values); $i++) {
            $ema = $alpha * (float) $values[$i] + (1 - $alpha) * $ema;
            $result[] = $ema;
        }

        return $result;
    }

    /**
     * Return the raw input values (for chaining).
     *
     * @return list<int|float>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Reset accumulated values.
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->values = [];
        return $clone;
    }
}
