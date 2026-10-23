<?php

declare(strict_types=1);

namespace SugarCraft\Metrics;

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
 *
 * Trust boundary: names and tags are treated as potentially hostile.
 * The Registry's cardinality keys backslash-escape the `|` and `=`
 * separators ({@see Util::tagKey()}) so lookalike tag sets never
 * collapse, and line-oriented backends (StatsD, Prometheus textfile)
 * normalise control bytes at their emit chokepoints. Callers should
 * still bound cardinality at the source — see
 * {@see SessionMetrics::normalizeTag()} and the Registry's
 * `cardinalityLimit`.
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

    /**
     * Add a positive or negative increment to a synchronous up-down counter.
     *
     * @param array<string,string> $tags
     */
    public function upDownCounter(string $name, float $amount, array $tags = []): void;

    /**
     * Record an observation from an asynchronous counter callback.
     *
     * @param array<string,string> $tags
     */
    public function asyncCounter(string $name, float $value, array $tags = []): void;

    /**
     * Record an observation from an asynchronous gauge callback.
     *
     * @param array<string,string> $tags
     */
    public function asyncGauge(string $name, float $value, array $tags = []): void;

    /**
     * Receive a metric descriptor for dialects that support pre-emitted
     * TYPE/HELP metadata (e.g. Prometheus textfile collector).
     * Backends that do not support this may implement as a no-op.
     */
    public function describe(Descriptor $descriptor): void;

    /**
     * Flush all pending metric samples to the persistent backend.
     *
     * For backends that accumulate samples in memory before writing
     * (e.g. Prometheus textfile), this writes all pending samples to
     * durable storage. For transient backends (StatsD UDP), this may
     * be a no-op.
     *
     * @throws \RuntimeException if the flush operation fails
     */
    public function flush(): void;

    /**
     * Remove a specific metric series by name and tags.
     * No-op for backends that do not support removal.
     *
     * @param array<string,string> $tags
     */
    public function remove(string $name, array $tags = []): void;

    /**
     * Reset all accumulated metrics to zero / empty state.
     * No-op for backends that do not support clearing.
     */
    public function clear(): void;
}
