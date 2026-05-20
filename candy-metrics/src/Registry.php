<?php

declare(strict_types=1);

namespace SugarCraft\Metrics;

/**
 * Application-facing facade. Hand a {@see Backend} in, then call
 * {@see counter()} / {@see gauge()} / {@see histogram()} from
 * application code; the registry forwards each call to the
 * backend.
 *
 * The split is intentional — backend swap is a config concern,
 * call sites stay backend-agnostic. This is the standard
 * Prometheus-client / StatsD-client shape.
 *
 * The registry also offers a {@see time()} helper that returns a
 * closure to call when the timed operation finishes, recording
 * the elapsed seconds as a histogram.
 *
 * `withTags()` returns a new registry whose every emit is
 * pre-tagged — useful for SSH session middleware that wants to
 * stamp every metric with `user`/`client_addr` without threading
 * those tags through every call site.
 *
 * When a metric accumulates too many unique label-value
 * combinations (cardinality explosion), the oldest combination
 * is evicted to stay within the configured limit.
 */
final class Registry
{
    /** @var array<string,string> */
    private array $defaultTags;

    /** @var array<string, Descriptor> */
    private array $descriptors = [];

    /** @var array<string, array<string>> */
    private array $labelValueCache = [];

    /**
     * Maximum unique label-value combinations allowed per metric.
     * When exceeded, {@see deleteLabelValues()} evicts the oldest entry.
     */
    private int $cardinalityLimit;

    /**
     * @param array<string,string> $defaultTags
     */
    public function __construct(
        private readonly Backend $backend,
        array $defaultTags = [],
        int $cardinalityLimit = 10000,
    ) {
        $this->defaultTags = $defaultTags;
        $this->cardinalityLimit = $cardinalityLimit;
    }

    /**
     * Register a metric descriptor for early TYPE/HELP emission.
     */
    public function register(Descriptor $descriptor): void
    {
        $this->descriptors[$descriptor->name] = $descriptor;
    }

    public function counter(string $name, float $value = 1.0, array $tags = []): void
    {
        $this->backend->counter($name, $value, $this->mergeTags($tags));
        $this->trackCardinality($name, $tags);
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $this->backend->gauge($name, $value, $this->mergeTags($tags));
        $this->trackCardinality($name, $tags);
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $this->backend->histogram($name, $value, $this->mergeTags($tags));
        $this->trackCardinality($name, $tags);
    }

    /**
     * Add a positive or negative increment to a synchronous up-down counter.
     *
     * @param array<string,string> $tags
     */
    public function upDownCounter(string $name, float $amount, array $tags = []): void
    {
        $this->backend->upDownCounter($name, $amount, $this->mergeTags($tags));
        $this->trackCardinality($name, $tags);
    }

    /**
     * Record an observation from an asynchronous counter callback.
     *
     * @param array<string,string> $tags
     */
    public function asyncCounter(string $name, float $value, array $tags = []): void
    {
        $this->backend->asyncCounter($name, $value, $this->mergeTags($tags));
        $this->trackCardinality($name, $tags);
    }

    /**
     * Record an observation from an asynchronous gauge callback.
     *
     * @param array<string,string> $tags
     */
    public function asyncGauge(string $name, float $value, array $tags = []): void
    {
        $this->backend->asyncGauge($name, $value, $this->mergeTags($tags));
        $this->trackCardinality($name, $tags);
    }

    /**
     * Start a wall-clock timer. Returns a closure — when invoked,
     * it records the elapsed seconds as a histogram under `$name`.
     *
     * ```php
     * $stop = $registry->time('handler.duration');
     * doExpensiveThing();
     * $stop();
     * ```
     *
     * Or capture the closure to record once on success:
     *
     * ```php
     * $stop = $registry->time('handler.duration', ['route' => '/x']);
     * try { ... } finally { $stop(); }
     * ```
     *
     * @param array<string,string> $tags
     * @return callable(): float
     */
    public function time(string $name, array $tags = []): callable
    {
        $start = microtime(true);
        return function () use ($name, $tags, $start): float {
            $elapsed = microtime(true) - $start;
            $this->histogram($name, $elapsed, $tags);
            return $elapsed;
        };
    }

    /**
     * Returns a child registry whose every emit is pre-tagged
     * with `$tags` (merged on top of the existing defaults).
     *
     * @param array<string,string> $tags
     */
    public function withTags(array $tags): self
    {
        return new self($this->backend, array_merge($this->defaultTags, $tags), $this->cardinalityLimit);
    }

    public function backend(): Backend
    {
        return $this->backend;
    }

    /**
     * Number of unique label-value combinations currently tracked
     * for `$name`.
     */
    public function cardinality(string $name): int
    {
        return count($this->labelValueCache[$name] ?? []);
    }

    /**
     * Evict the oldest recorded label combination for `$name`
     * when the cardinality limit is exceeded.
     *
     * This is called automatically on every emit; backends MAY
     * also call it directly to reclaim memory for stale label sets.
     *
     * @param array<string,string> $tags
     */
    public function deleteLabelValues(string $name, array $tags = []): void
    {
        $key = $this->tagKey($tags);
        unset($this->labelValueCache[$name][$key]);
    }

    /**
     * @param array<string,string> $tags
     * @return array<string,string>
     */
    private function mergeTags(array $tags): array
    {
        return $tags === [] ? $this->defaultTags : array_merge($this->defaultTags, $tags);
    }

    /**
     * Track a label-value combination and evict the oldest when
     * the per-metric cardinality limit is exceeded.
     *
     * @param array<string,string> $tags
     */
    private function trackCardinality(string $name, array $tags): void
    {
        $merged = $this->mergeTags($tags);
        $key = $this->tagKey($merged);
        if (isset($this->labelValueCache[$name][$key])) {
            return;
        }
        if (!isset($this->labelValueCache[$name])) {
            $this->labelValueCache[$name] = [];
        }
        $this->labelValueCache[$name][$key] = true;
        if (count($this->labelValueCache[$name]) > $this->cardinalityLimit) {
            reset($this->labelValueCache[$name]);
            $oldestKey = key($this->labelValueCache[$name]);
            unset($this->labelValueCache[$name][$oldestKey]);
        }
    }

    /**
     * Build a stable string key from a sorted tag array so identical
     * (name, tags) tuples share a cardinality slot.
     *
     * @param array<string,string> $tags
     */
    private function tagKey(array $tags): string
    {
        if ($tags === []) {
            return '';
        }
        ksort($tags);
        $parts = [];
        foreach ($tags as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        return implode('|', $parts);
    }
}
