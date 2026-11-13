<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Aggregation;

use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Support\Finite;

/**
 * Resamples a list of timestamped values to a new target cadence
 * (upsampling or downsampling) using linear interpolation or
 * nearest-value selection.
 *
 * Mirrors pandas.DataFrame.resample + scipy.signal.resample.
 */
final class Resample
{
    /**
     * Hard ceiling on the number of rows an upsample may build, mirroring
     * the {@see \SugarCraft\Charts\Canvas\Graph::MAX_TARGET_TICKS}
     * belt-guard precedent: the walk increment is caller-chosen, so a
     * two-point series spanning a decade at interval=1 second is a
     * 315-million-row allocation unless something says "stop" first.
     * Callers who genuinely want big output raise it via
     * {@see self::withMaxPoints()}.
     */
    public const MAX_RESAMPLE_POINTS = 100_000;

    /** @var list<array{ts: int, value: int|float}> */
    private array $points = [];

    private function __construct(
        public readonly int $targetIntervalSeconds,
        public readonly int $startTimestamp = 0,
        public readonly ?int $maxPoints = null,
    ) {
        // The bucket-advance and upsample walks increment by this interval;
        // zero or negative would spin their while loops forever. Siblings
        // MovingAverage/BucketByTime already guard their interval doors.
        if ($targetIntervalSeconds <= 0) {
            throw new \InvalidArgumentException('Target interval must be > 0, got ' . $targetIntervalSeconds);
        }
    }

    /**
     * Downsample by picking the last value in each target bucket.
     *
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     * @param int $targetIntervalSeconds Desired output interval in seconds
     * @return list<array{ts: int, value: int|float}>
     */
    public static function last(int $targetIntervalSeconds, array $timestampedValues = [], int $startTimestamp = 0): array
    {
        return (new self($targetIntervalSeconds, $startTimestamp))
            ->downsampleLast($timestampedValues);
    }

    /**
     * Downsample by picking the mean of values in each target bucket.
     *
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     * @param int $targetIntervalSeconds Desired output interval in seconds
     * @return list<array{ts: int, value: float}>
     */
    public static function mean(int $targetIntervalSeconds, array $timestampedValues = [], int $startTimestamp = 0): array
    {
        return (new self($targetIntervalSeconds, $startTimestamp))
            ->downsampleMean($timestampedValues);
    }

    /**
     * Upsample by linearly interpolating between known points.
     *
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     * @param int $targetIntervalSeconds Desired output interval in seconds
     * @return list<array{ts: int, value: float}>
     */
    public static function linear(int $targetIntervalSeconds, array $timestampedValues = [], int $startTimestamp = 0): array
    {
        return (new self($targetIntervalSeconds, $startTimestamp))
            ->upsampleLinear($timestampedValues);
    }

    /**
     * Upsample by nearest-value selection (zero-order hold).
     *
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     * @param int $targetIntervalSeconds Desired output interval in seconds
     * @return list<array{ts: int, value: int|float}>
     */
    public static function nearest(int $targetIntervalSeconds, array $timestampedValues = [], int $startTimestamp = 0): array
    {
        return (new self($targetIntervalSeconds, $startTimestamp))
            ->upsampleNearest($timestampedValues);
    }

    /**
     * Canonical factory — mirrors the SugarCraft `::new()` convention.
     */
    public static function new(int $targetIntervalSeconds, int $startTimestamp = 0, ?int $maxPoints = null): self
    {
        return new self($targetIntervalSeconds, $startTimestamp, $maxPoints);
    }

    /**
     * Raise (or lower) the {@see MAX_RESAMPLE_POINTS} ceiling for this
     * instance's upsample passes. Must be > 0; the default (null) applies
     * the class ceiling.
     */
    public function withMaxPoints(?int $maxPoints): self
    {
        if ($maxPoints !== null && $maxPoints <= 0) {
            throw new \InvalidArgumentException(Lang::t('resample.max_points_positive', [
                'given' => (string) $maxPoints,
            ]));
        }
        return new self($this->targetIntervalSeconds, $this->startTimestamp, $maxPoints);
    }

    private function effectiveCap(): int
    {
        return $this->maxPoints ?? self::MAX_RESAMPLE_POINTS;
    }

    /**
     * Row-count estimate an upsample walk from $firstTs to $lastTs would
     * emit at the target interval (both endpoints inclusive).
     */
    private function projectedRows(int $firstTs, int $lastTs): int
    {
        return intdiv($lastTs - $firstTs, $this->targetIntervalSeconds) + 1;
    }

    private function assertWithinCap(int $rows): void
    {
        if ($rows > $this->effectiveCap()) {
            throw new \RuntimeException(Lang::t('resample.output_cap', [
                'points' => (string) $rows,
                'cap' => (string) $this->effectiveCap(),
            ]));
        }
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
     * Row-shape door (sugar-charts-028): validate the shape once, at
     * ingestion, and normalise [ts,value] tuples to the canonical
     * ['ts'=>..,'value'=>..] form the maths paths read. A malformed row
     * becomes a domain error naming its position instead of an
     * undefined-array-key warning that leaks into the suite (failOnWarning)
     * and a TypeError from deep inside a private pass.
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
                    // canonical form, stored untouched (typed pairs keep their bytes)
                } else {
                    throw new \InvalidArgumentException(Lang::t('resample.row_shape', [
                        'index' => (string) $index,
                        'given' => get_debug_type($row),
                    ]));
                }
            } else {
                throw new \InvalidArgumentException(Lang::t('resample.row_shape', [
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
     * Auto-detect whether to upsample or downsample based on ratio.
     *
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     * @return list<array{ts: int, value: int|float}>
     */
    public function resample(array $timestampedValues): array
    {
        if ($timestampedValues === []) {
            return [];
        }

        $timestampedValues = self::normalized($timestampedValues);

        // Determine dominant direction by examining average spacing
        $totalInterval = $timestampedValues[count($timestampedValues) - 1]['ts'] - $timestampedValues[0]['ts'];
        $avgInputInterval = count($timestampedValues) > 1 ? $totalInterval / (count($timestampedValues) - 1) : $totalInterval;

        if ($avgInputInterval < $this->targetIntervalSeconds) {
            return $this->downsampleLast($timestampedValues);
        }

        return $this->upsampleLinear($timestampedValues);
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     * @return list<array{ts: int, value: int|float}>
     */
    private function downsampleLast(array $timestampedValues): array
    {
        if ($timestampedValues === []) {
            return [];
        }

        $timestampedValues = self::normalized($timestampedValues);
        usort($timestampedValues, fn(array $a, array $b) => $a['ts'] <=> $b['ts']);

        $result = [];
        $bucketStart = $this->startTimestamp;
        $lastInBucket = null;

        foreach ($timestampedValues as $pt) {
            $bucketEnd = $bucketStart + $this->targetIntervalSeconds;
            if ($pt['ts'] < $bucketEnd) {
                $lastInBucket = $pt;
            } else {
                if ($lastInBucket !== null) {
                    $result[] = ['ts' => $bucketStart, 'value' => $lastInBucket['value']];
                }
                // Advance bucket to include this point (sugar-charts-040):
                // jump straight to the containing bucket arithmetically —
                // the old per-empty-bucket walk turned a sparse range
                // (timestamps a epoch apart at interval=1) into billions
                // of pointless iterations. Same landing bucket: start is
                // the largest aligned offset with pt < start + interval.
                $bucketStart = $bucketEnd;
                $bucketStart += intdiv($pt['ts'] - $bucketStart, $this->targetIntervalSeconds) * $this->targetIntervalSeconds;
                $lastInBucket = $pt;
            }
        }

        if ($lastInBucket !== null) {
            $result[] = ['ts' => $bucketStart, 'value' => $lastInBucket['value']];
        }

        return $result;
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     * @return list<array{ts: int, value: float}>
     */
    private function downsampleMean(array $timestampedValues): array
    {
        if ($timestampedValues === []) {
            return [];
        }

        $timestampedValues = self::normalized($timestampedValues);
        usort($timestampedValues, fn(array $a, array $b) => $a['ts'] <=> $b['ts']);

        $result = [];
        $bucketStart = $this->startTimestamp;
        $bucketVals = [];
        $bucketTs = null;

        foreach ($timestampedValues as $pt) {
            $bucketEnd = $bucketStart + $this->targetIntervalSeconds;
            if ($pt['ts'] < $bucketEnd) {
                $bucketVals[] = $pt['value'];
                if ($bucketTs === null) {
                    $bucketTs = $pt['ts'];
                }
            } else {
                if ($bucketVals !== []) {
                    $result[] = ['ts' => $bucketStart, 'value' => array_sum($bucketVals) / count($bucketVals)];
                }
                $bucketStart = $bucketEnd;
                // (sugar-charts-040) arithmetic bucket jump, see the
                // matching note in downsampleLast().
                $bucketStart += intdiv($pt['ts'] - $bucketStart, $this->targetIntervalSeconds) * $this->targetIntervalSeconds;
                $bucketVals = [$pt['value']];
                $bucketTs = $pt['ts'];
            }
        }

        if ($bucketVals !== []) {
            $result[] = ['ts' => $bucketStart, 'value' => array_sum($bucketVals) / count($bucketVals)];
        }

        return $result;
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     * @return list<array{ts: int, value: float}>
     */
    private function upsampleLinear(array $timestampedValues): array
    {
        if (count($timestampedValues) < 2) {
            return $timestampedValues;
        }

        $timestampedValues = self::normalized($timestampedValues);
        usort($timestampedValues, fn(array $a, array $b) => $a['ts'] <=> $b['ts']);

        $result = [];
        $inputIdx = 0;
        $count = count($timestampedValues);
        $currTs = $this->startTimestamp > 0 ? $this->startTimestamp : $timestampedValues[0]['ts'];
        $endTs = $timestampedValues[$count - 1]['ts'];

        // (sugar-charts-040) estimate before allocating: the walk below is
        // exactly projectedRows() long, so a caller-tiny interval over a
        // huge span is caught here instead of in the OOM killer.
        if ($endTs >= $currTs) {
            $this->assertWithinCap($this->projectedRows($currTs, $endTs));
        }

        while ($currTs <= $endTs) {
            // Find the two input points that bracket currTs
            while ($inputIdx < $count - 1
                && $timestampedValues[$inputIdx + 1]['ts'] <= $currTs) {
                $inputIdx++;
            }

            $p1 = $timestampedValues[$inputIdx];
            $p2 = $timestampedValues[min($inputIdx + 1, $count - 1)];

            if ($p1['ts'] === $p2['ts']) {
                $interpolated = $p1['value'];
            } else {
                $t = ($currTs - $p1['ts']) / ($p2['ts'] - $p1['ts']);
                $interpolated = $p1['value'] + $t * ($p2['value'] - $p1['value']);
            }

            $result[] = ['ts' => $currTs, 'value' => $interpolated];
            $currTs += $this->targetIntervalSeconds;
        }

        return $result;
    }

    /**
     * @param list<array{ts: int, value: int|float}> $timestampedValues
     * @return list<array{ts: int, value: int|float}>
     */
    private function upsampleNearest(array $timestampedValues): array
    {
        if ($timestampedValues === []) {
            return [];
        }

        $timestampedValues = self::normalized($timestampedValues);
        usort($timestampedValues, fn(array $a, array $b) => $a['ts'] <=> $b['ts']);

        $result = [];
        $currTs = $this->startTimestamp > 0 ? $this->startTimestamp : $timestampedValues[0]['ts'];
        $endTs = $timestampedValues[count($timestampedValues) - 1]['ts'];

        // (sugar-charts-040) same pre-allocation estimate as upsampleLinear.
        if ($endTs >= $currTs) {
            $this->assertWithinCap($this->projectedRows($currTs, $endTs));
        }

        $i = 0;
        $count = count($timestampedValues);
        while ($currTs <= $endTs) {
            while (
                $i + 1 < $count
                && abs($timestampedValues[$i + 1]['ts'] - $currTs) < abs($timestampedValues[$i]['ts'] - $currTs)
            ) {
                $i++;
            }
            $result[] = ['ts' => $currTs, 'value' => $timestampedValues[$i]['value']];
            $currTs += $this->targetIntervalSeconds;
        }

        return $result;
    }

    /**
     * @return list<array{ts: int, value: int|float}>
     */
    public function toTimeSeries(): array
    {
        return $this->resample($this->points);
    }
}
