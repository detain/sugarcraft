<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Aggregation;

/**
 * Resamples a list of timestamped values to a new target cadence
 * (upsampling or downsampling) using linear interpolation or
 * nearest-value selection.
 *
 * Mirrors pandas.DataFrame.resample + scipy.signal.resample.
 */
final class Resample
{
    /** @var list<array{ts: int, value: int|float}> */
    private array $points = [];

    private function __construct(
        public readonly int $targetIntervalSeconds,
        public readonly int $startTimestamp = 0,
    ) {}

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

    public static function create(int $targetIntervalSeconds, int $startTimestamp = 0): self
    {
        return new self($targetIntervalSeconds, $startTimestamp);
    }

    public static function new(int $targetIntervalSeconds, int $startTimestamp = 0): self
    {
        return self::create($targetIntervalSeconds, $startTimestamp);
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
                // Advance bucket to include this point
                $bucketStart = $bucketEnd;
                while ($pt['ts'] >= $bucketStart + $this->targetIntervalSeconds) {
                    $bucketStart += $this->targetIntervalSeconds;
                }
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
                while ($pt['ts'] >= $bucketStart + $this->targetIntervalSeconds) {
                    $bucketStart += $this->targetIntervalSeconds;
                }
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

        usort($timestampedValues, fn(array $a, array $b) => $a['ts'] <=> $b['ts']);

        $result = [];
        $inputIdx = 0;
        $currTs = $this->startTimestamp > 0 ? $this->startTimestamp : $timestampedValues[0]['ts'];
        $endTs = $timestampedValues[count($timestampedValues) - 1]['ts'];

        while ($currTs <= $endTs) {
            // Find the two input points that bracket currTs
            while ($inputIdx < count($timestampedValues) - 1
                && $timestampedValues[$inputIdx + 1]['ts'] <= $currTs) {
                $inputIdx++;
            }

            $p1 = $timestampedValues[$inputIdx];
            $p2 = $timestampedValues[min($inputIdx + 1, count($timestampedValues) - 1)];

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

        usort($timestampedValues, fn(array $a, array $b) => $a['ts'] <=> $b['ts']);

        $result = [];
        $currTs = $this->startTimestamp > 0 ? $this->startTimestamp : $timestampedValues[0]['ts'];
        $endTs = $timestampedValues[count($timestampedValues) - 1]['ts'];

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
