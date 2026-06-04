<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\History;

use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Query historical snapshots and compute rate statistics.
 *
 * Allows rate analysis over multi-hour windows by reading two
 * boundary snapshots from the store and computing per-second deltas.
 */
final class HistoryQuery
{
    public function __construct(
        private readonly HistoryStoreInterface $store,
    ) {}

    /**
     * Retrieve snapshots within a Unix-timestamp range (inclusive).
     *
     * Preserves sub-second precision by building DateTimeImmutable with
     * both integer seconds and microsecond parts, avoiding truncation
     * that would drop the most-recent boundary record.
     *
     * @return array<StatusSnapshot>
     */
    public function query(float $sinceTs, float $untilTs): array
    {
        $since = self::floatToDateTimeImmutable($sinceTs);
        $until = self::floatToDateTimeImmutable($untilTs);
        return $this->store->query($since, $until);
    }

    /**
     * Build a DateTimeImmutable from a float epoch while preserving microseconds.
     */
    private static function floatToDateTimeImmutable(float $ts): \DateTimeImmutable
    {
        $sec = (int) $ts;
        $usec = (int) (($ts - $sec) * 1_000_000);
        return \DateTimeImmutable::createFromFormat('U u', "{$sec} {$usec}") ?: new \DateTimeImmutable();
    }

    /**
     * Retrieve snapshots from a given timestamp up to now.
     *
     * Uses microtime(true) for the until-bound to preserve sub-second precision.
     *
     * @return array<StatusSnapshot>
     */
    public function querySince(float $sinceTs): array
    {
        $nowTs = microtime(true);
        return $this->query($sinceTs, $nowTs);
    }

    /**
     * Compute the per-second rate of a variable over a time range.
     *
     * Uses the first and last snapshots in the range to compute:
     *   rate = (lastValue - firstValue) / (lastTs - firstTs)
     *
     * Returns null if the variable is absent from either boundary snapshot,
     * if either value is non-numeric, or if the time range is zero.
     */
    public function getRate(string $variable, float $sinceTs, float $untilTs): ?float
    {
        $snapshots = $this->query($sinceTs, $untilTs);

        if (\count($snapshots) < 2) {
            return null;
        }

        $first = $snapshots[0];
        $last = $snapshots[\count($snapshots) - 1];

        $firstVal = $first->getFloat($variable);
        $lastVal = $last->getFloat($variable);

        if ($firstVal === null || $lastVal === null) {
            return null;
        }

        $elapsed = $last->ts - $first->ts;
        if ($elapsed <= 0) {
            return null;
        }

        $delta = $lastVal - $firstVal;
        if ($delta < 0) {
            $delta = 0;
        }

        return $delta / $elapsed;
    }
}
