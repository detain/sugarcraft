<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Aggregation;

use DateTimeImmutable;

/**
 * Groups a list of timestamped values into time buckets, applying an
 * aggregation function to each bucket.
 *
 * @see https://en.wikipedia.org/wiki/Temporal_database#Binning
 *
 * Mirrors timeseriesbinner from influxdb/influxql.
 */
final class BucketByTime
{
    /** @var list<array{ts: int, value: int|float}> */
    private array $points = [];

    private function __construct(
        public readonly int $intervalSeconds,
        public readonly \Closure $aggregator,
        public readonly int $offsetSeconds = 0,
    ) {
        if ($intervalSeconds <= 0) {
            throw new \InvalidArgumentException('Bucket interval must be positive');
        }
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function sum(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::create($intervalSeconds, fn(array $v) => array_sum(array_column($v, 'value')), $offsetSeconds)
            ->bucket($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function mean(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::create($intervalSeconds, function (array $v): float {
            $vals = array_column($v, 'value');
            return count($vals) === 0 ? 0.0 : array_sum($vals) / count($vals);
        }, $offsetSeconds)->bucket($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function min(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::create($intervalSeconds, fn(array $v) => min(array_column($v, 'value')), $offsetSeconds)
            ->bucket($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function max(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::create($intervalSeconds, fn(array $v) => max(array_column($v, 'value')), $offsetSeconds)
            ->bucket($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function first(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::create($intervalSeconds, fn(array $v) => $v[0]['value'] ?? null, $offsetSeconds)
            ->bucket($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function last(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::create($intervalSeconds, fn(array $v) => end($v)['value'] ?? null, $offsetSeconds)
            ->bucket($timestampedValues);
    }

    public static function create(int $intervalSeconds, ?\Closure $aggregator = null, int $offsetSeconds = 0): self
    {
        $aggregator ??= fn(array $v): int|float => array_sum(array_column($v, 'value'));
        return new self($intervalSeconds, $aggregator, $offsetSeconds);
    }

    public static function new(int $intervalSeconds, ?\Closure $aggregator = null, int $offsetSeconds = 0): self
    {
        return self::create($intervalSeconds, $aggregator, $offsetSeconds);
    }

    /**
     * Add a single timestamped point.
     */
    public function add(int $timestamp, int|float $value): self
    {
        $clone = clone $this;
        $clone->points[] = ['ts' => $timestamp, 'value' => $value];
        return $clone;
    }

    /**
     * Add multiple timestamped points at once.
     *
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public function addMany(array $timestampedValues): self
    {
        $clone = clone $this;
        foreach ($timestampedValues as $pt) {
            $clone->points[] = $pt;
        }
        return $clone;
    }

    /**
     * Compute bucketed aggregates from all added points.
     *
     * @return list<array{ts: int, value: int|float}>
     */
    public function compute(): array
    {
        return $this->bucket($this->points);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     * @return list<array{ts: int, value: int|float}>
     */
    public function bucket(array $timestampedValues): array
    {
        if ($timestampedValues === []) {
            return [];
        }

        /** @var array<int, list<array{ts: int, value: int|float}>> */
        $buckets = [];

        foreach ($timestampedValues as $pt) {
            // Align timestamp to bucket boundary with optional offset
            $bucketTs = (int) (floor(($pt['ts'] - $this->offsetSeconds) / $this->intervalSeconds) * $this->intervalSeconds + $this->offsetSeconds);
            $buckets[$bucketTs][] = $pt;
        }

        ksort($buckets);

        $result = [];
        foreach ($buckets as $ts => $group) {
            $result[] = [
                'ts' => $ts,
                'value' => ($this->aggregator)($group),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{ts: int, value: int|float}>
     */
    public function toTimeSeries(): array
    {
        return $this->compute();
    }
}
