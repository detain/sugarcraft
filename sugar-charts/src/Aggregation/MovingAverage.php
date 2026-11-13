<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Aggregation;

use SugarCraft\Charts\Support\Finite;

/**
 * Computes a simple (unweighted) moving average over a list of values.
 * Optionally produces a SMA for a second list at the same cadence (e.g.
 * close prices bucketed by time, then smoothed).
 *
 * Designed after pandas.DataFrame.rolling(mean) semantics — a SugarCraft
 * extension: no surveyed Go charting library ships an aggregation module
 * (research record: Aggregation missing across the board); the second
 * upstream this docblock used to cite never resolved to a real package.
 */
final class MovingAverage
{
    /** @var list<int|float> */
    private array $values = [];

    private function __construct(
        public readonly int $windowSize,
        public readonly bool $centered = false,
    ) {
        self::assertWindow($windowSize);
    }

    /**
     * Shared validator (sugar-charts-029): every window-bearing entry
     * point — ctor, simple(), centered(), new(), ema() — routes through
     * this door. ema() previously skipped it entirely, so windowSize 0 or
     * negative produced a default alpha of 2/(w+1) outside (0,1] and the
     * recurrence silently diverged.
     */
    private static function assertWindow(int $windowSize): void
    {
        if ($windowSize <= 0) {
            throw new \InvalidArgumentException('Window size must be > 0, got ' . $windowSize);
        }
    }

    /**
     * Smoothing-factor door (sugar-charts-029): an EMA weight must lie in
     * (0, 1]; 0 freezes the seed, negatives flip sign every step.
     */
    private static function assertAlpha(float $alpha): void
    {
        if ($alpha <= 0.0 || $alpha > 1.0) {
            throw new \InvalidArgumentException('EMA alpha must be in (0, 1], got ' . $alpha);
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

    /**
     * Canonical factory — mirrors the SugarCraft `::new()` convention.
     */
    public static function new(int $windowSize, bool $centered = false): self
    {
        return new self($windowSize, $centered);
    }

    /**
     * Append a single value.
     *
     * @throws \InvalidArgumentException when $value is NaN or ±INF
     */
    public function add(int|float $value): self
    {
        Finite::assert($value);
        $clone = clone $this;
        $clone->values[] = $value;
        return $clone;
    }

    /**
     * Append multiple values at once.
     *
     * @param list<int|float> $values
     *
     * @throws \InvalidArgumentException on the first non-finite value
     */
    public function addMany(array $values): self
    {
        Finite::assertAll($values);
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
        $w = $this->windowSize;

        if ($this->centered) {
            // sugar-charts-041: one running sum instead of a fresh slice
            // per position (O(n) rather than O(n*w)); the 0.0 pad rule at
            // the un-windowed edges is preserved exactly.
            $half = intdiv($w, 2);
            $first = $half;
            $last = $n - 1 - $half;
            $sum = 0.0;
            for ($i = 0; $i < $n; $i++) {
                if ($i < $first || $i > $last) {
                    $result[] = 0.0;
                    continue;
                }
                if ($i === $first) {
                    $sum = (float) array_sum(array_slice($this->values, 0, $w));
                } else {
                    $sum += $this->values[$i + $half];
                    $sum -= $this->values[$i - $half - 1];
                }
                $result[] = $sum / $w;
            }
            return $result;
        }

        // trailing window: seed once, then add-entering/subtract-leaving.
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $this->values[$i];
            if ($i < $w - 1) {
                $result[] = 0.0;
                continue;
            }
            if ($i >= $w) {
                $sum -= $this->values[$i - $w];
            }
            $result[] = $sum / $w;
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
        self::assertWindow($windowSize);

        if ($values === []) {
            return [];
        }

        Finite::assertAll($values);
        $alpha ??= 2.0 / ($windowSize + 1);
        self::assertAlpha($alpha);
        $ema = (float) $values[0];
        $result = [$ema];
        $valuesCount = count($values);

        for ($i = 1; $i < $valuesCount; $i++) {
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
