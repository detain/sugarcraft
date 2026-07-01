<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Backend;
use SugarCraft\Metrics\Descriptor;
use SugarCraft\Metrics\Util;

/**
 * In-memory accumulator. Counters add up, gauges hold the latest
 * value, histograms keep every sample. Mostly used for tests and
 * for fanning out to multiple "live" backends via
 * {@see MultiBackend}.
 *
 * The bucket key is `name|tag1=v1|tag2=v2|...` with tags sorted
 * by key, so identical (name, tags) tuples accumulate into the
 * same bucket regardless of caller insertion order.
 */
final class InMemoryBackend implements Backend
{
    /** @var array<string,float> */
    private array $counters = [];
    /** @var array<string,float> */
    private array $gauges = [];
    /** @var array<string,list<float>> */
    private array $histograms = [];
    /** @var array<string,float> */
    private array $upDownCounters = [];
    /** @var array<string,float> */
    private array $asyncCounters = [];
    /** @var array<string,float> */
    private array $asyncGauges = [];

    public function counter(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->counters[$key] = ($this->counters[$key] ?? 0.0) + $value;
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $this->gauges[$this->key($name, $tags)] = $value;
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $this->histograms[$this->key($name, $tags)][] = $value;
    }

    public function upDownCounter(string $name, float $amount, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->upDownCounters[$key] = ($this->upDownCounters[$key] ?? 0.0) + $amount;
    }

    public function asyncCounter(string $name, float $value, array $tags = []): void
    {
        $this->asyncCounters[$this->key($name, $tags)] = $value;
    }

    public function asyncGauge(string $name, float $value, array $tags = []): void
    {
        $this->asyncGauges[$this->key($name, $tags)] = $value;
    }

    // In-memory backend has no concept of pre-emitted metadata.
    public function describe(Descriptor $descriptor): void
    {
        // No-op: in-memory accumulation does not benefit from TYPE/HELP lines.
    }

    // In-memory backend stores data inline; flush is a no-op.
    public function flush(): void
    {
        // No-op: data is already in memory and persists for the lifetime of the object.
    }

    /** @return array<string,float> */
    public function counters(): array { return $this->counters; }
    /** @return array<string,float> */
    public function gauges(): array { return $this->gauges; }
    /** @return array<string,list<float>> */
    public function histograms(): array { return $this->histograms; }
    /** @return array<string,float> */
    public function upDownCounters(): array { return $this->upDownCounters; }
    /** @return array<string,float> */
    public function asyncCounters(): array { return $this->asyncCounters; }
    /** @return array<string,float> */
    public function asyncGauges(): array { return $this->asyncGauges; }

    public function counterValue(string $name, array $tags = []): float
    {
        return $this->counters[$this->key($name, $tags)] ?? 0.0;
    }

    public function gaugeValue(string $name, array $tags = []): ?float
    {
        return $this->gauges[$this->key($name, $tags)] ?? null;
    }

    /** @return list<float> */
    public function histogramValues(string $name, array $tags = []): array
    {
        return $this->histograms[$this->key($name, $tags)] ?? [];
    }

    public function upDownCounterValue(string $name, array $tags = []): float
    {
        return $this->upDownCounters[$this->key($name, $tags)] ?? 0.0;
    }

    public function asyncCounterValue(string $name, array $tags = []): float
    {
        return $this->asyncCounters[$this->key($name, $tags)] ?? 0.0;
    }

    public function asyncGaugeValue(string $name, array $tags = []): ?float
    {
        return $this->asyncGauges[$this->key($name, $tags)] ?? null;
    }

    /**
     * Reset all accumulated metrics to zero.
     * Useful for long-running processes or test cleanup.
     */
    public function clear(): void
    {
        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
        $this->upDownCounters = [];
        $this->asyncCounters = [];
        $this->asyncGauges = [];
    }

    /**
     * Remove a specific metric series by name and tags.
     * Removes from whichever accumulator(s) hold a value for that key.
     *
     * @param array<string,string> $tags
     */
    public function remove(string $name, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        unset(
            $this->counters[$key],
            $this->gauges[$key],
            $this->histograms[$key],
            $this->upDownCounters[$key],
            $this->asyncCounters[$key],
            $this->asyncGauges[$key],
        );
    }

    /**
     * Build a storage key for this backend's accumulator arrays.
     * Format: `name|k1=v1|k2=v2` with tags sorted by key.
     * This format is for in-memory storage accumulation (not cardinality tracking).
     *
     * @param array<string,string> $tags
     */
    private function key(string $name, array $tags): string
    {
        if ($tags === []) {
            return $name;
        }
        return $name . '|' . Util::tagKey($tags);
    }
}
