<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Aggregation;

use DateTimeImmutable;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Support\Finite;

/**
 * Groups a list of timestamped values into time buckets, applying an
 * aggregation function to each bucket.
 *
 * @see https://en.wikipedia.org/wiki/Temporal_database#Binning
 *
 * Designed after windowed time-binning semantics — a SugarCraft
 * extension: the Influx-lineage package this docblock used to name was
 * never resolvable, so the class stands on its own (the Wikipedia
 * temporal-database binning cite above is the honest anchor).
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
            throw new \InvalidArgumentException(Lang::t('bucketbytime.interval_positive', ['given' => (string) $intervalSeconds]));
        }
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function sum(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::new($intervalSeconds, fn(array $v) => array_sum(array_column($v, 'value')), $offsetSeconds)
            ->bucket($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function mean(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::new($intervalSeconds, function (array $v): float {
            $vals = array_column($v, 'value');
            return count($vals) === 0 ? 0.0 : array_sum($vals) / count($vals);
        }, $offsetSeconds)->bucket($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function min(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::new($intervalSeconds, fn(array $v) => min(array_column($v, 'value')), $offsetSeconds)
            ->bucket($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function max(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::new($intervalSeconds, fn(array $v) => max(array_column($v, 'value')), $offsetSeconds)
            ->bucket($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function first(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::new($intervalSeconds, fn(array $v) => $v[0]['value'] ?? null, $offsetSeconds)
            ->bucket($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    public static function last(int $intervalSeconds, array $timestampedValues = [], int $offsetSeconds = 0): array
    {
        return self::new($intervalSeconds, fn(array $v) => end($v)['value'] ?? null, $offsetSeconds)
            ->bucket($timestampedValues);
    }

    /**
     * Canonical factory — mirrors the SugarCraft `::new()` convention.
     */
    public static function new(int $intervalSeconds, ?\Closure $aggregator = null, int $offsetSeconds = 0): self
    {
        $aggregator ??= fn(array $v): int|float => array_sum(array_column($v, 'value'));
        return new self($intervalSeconds, $aggregator, $offsetSeconds);
    }

        /**
     * Add a single timestamped point.
     *
     * @throws \InvalidArgumentException when $value is NaN or ±INF
     */
    public function add(int $timestamp, int|float $value): self
    {
        Finite::assert($value);
        $clone = clone $this;
        $clone->points[] = ['ts' => $timestamp, 'value' => $value];
        return $clone;
    }

    /**
     * Add multiple timestamped points at once.
     *
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     *
     * @throws \InvalidArgumentException on the first non-finite value
     */
    public function addMany(array $timestampedValues): self
    {
        $timestampedValues = self::normalized($timestampedValues);
        $clone = clone $this;
        foreach ($timestampedValues as $pt) {
            $clone->points[] = $pt;
        }
        return $clone;
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     */
    private static function assertPoints(array $timestampedValues): void
    {
        foreach ($timestampedValues as $pt) {
            Finite::assert($pt['value']);
        }
    }

    /**
     * Row-shape door (sugar-charts-028): see Resample::normalized for the
     * contract — shape validated once here, [ts,value] tuples canonicalised,
     * malformed rows throw a domain error naming the position.
     *
     * @param array<array-key, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private static function normalized(array $rows): array
    {
        $out = [];
        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $keys = array_keys($row);
                if ($keys === [0, 1] && is_numeric($row[0]) && is_numeric($row[1])) {
                    $row = ['ts' => $row[0], 'value' => $row[1]];
                } elseif (isset($row['ts']) && isset($row['value']) && is_numeric($row['ts'])) {
                    // canonical form, stored untouched
                } else {
                    throw new \InvalidArgumentException(Lang::t('bucket.row_shape', [
                        'index' => (string) $index,
                        'given' => get_debug_type($row),
                    ]));
                }
            } else {
                throw new \InvalidArgumentException(Lang::t('bucket.row_shape', [
                    'index' => (string) $index,
                    'given' => get_debug_type($row),
                ]));
            }
            Finite::assert($row['value']);
            $out[] = $row;
        }
        return $out;
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

        $timestampedValues = self::normalized($timestampedValues);

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
