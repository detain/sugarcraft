<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Backend;

use CandyCore\Metrics\Backend;

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

    /** @return array<string,float> */
    public function counters(): array { return $this->counters; }
    /** @return array<string,float> */
    public function gauges(): array { return $this->gauges; }
    /** @return array<string,list<float>> */
    public function histograms(): array { return $this->histograms; }

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

    /**
     * @param array<string,string> $tags
     */
    private function key(string $name, array $tags): string
    {
        if ($tags === []) {
            return $name;
        }
        ksort($tags);
        $parts = [];
        foreach ($tags as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        return $name . '|' . implode('|', $parts);
    }
}
