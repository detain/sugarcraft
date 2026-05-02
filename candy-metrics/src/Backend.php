<?php

declare(strict_types=1);

namespace CandyCore\Metrics;

/**
 * Pluggable metrics emit target.
 *
 * The {@see Registry} forwards every counter / gauge / histogram
 * update to the active backend; backends decide how to persist or
 * forward those samples (StatsD UDP, Prometheus textfile, JSON
 * stream, in-memory for tests, etc.).
 *
 * `tags` is an associative array of dimensional labels. Backends
 * that don't support labels (StatsD legacy mode) flatten them
 * into the metric name; backends that do (Prometheus, DogStatsD)
 * pass them through.
 */
interface Backend
{
    /**
     * Increment a monotonic counter.
     *
     * @param array<string,string> $tags
     */
    public function counter(string $name, float $value, array $tags = []): void;

    /**
     * Set an instantaneous gauge value.
     *
     * @param array<string,string> $tags
     */
    public function gauge(string $name, float $value, array $tags = []): void;

    /**
     * Record a single sample for a histogram / timer.
     *
     * @param array<string,string> $tags
     */
    public function histogram(string $name, float $value, array $tags = []): void;
}
