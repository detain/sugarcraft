<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Backend;

use CandyCore\Metrics\Backend;

/**
 * Atomically rewrites a Prometheus textfile-collector file
 * containing the current state of all metrics. Designed for the
 * "node_exporter --collector.textfile.directory" pattern: short-
 * lived processes write a `.prom` file that the long-running
 * exporter scrapes.
 *
 * Counter values accumulate across `flush()` calls, gauges hold
 * their last set value, histograms expose `_count` and `_sum`
 * (no buckets — those belong in a real client lib; this is the
 * lightweight diagnostic build).
 *
 * Call {@see flush()} manually after a batch, or rely on the
 * destructor (which calls it automatically).
 *
 * Atomicity: write to `<path>.tmp`, then `rename()` over `<path>`.
 * Concurrent writers serialise via `flock(LOCK_EX)` on the temp
 * file.
 */
final class PrometheusFileBackend implements Backend
{
    /** @var array<string,float> */
    private array $counters = [];
    /** @var array<string,float> */
    private array $gauges = [];
    /** @var array<string,array{count:int,sum:float}> */
    private array $histograms = [];

    public function __construct(private readonly string $path)
    {}

    public function __destruct()
    {
        try {
            $this->flush();
        } catch (\Throwable) {
            // Destructors must not throw; failed flushes are dropped.
        }
    }

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
        $key = $this->key($name, $tags);
        $h = $this->histograms[$key] ?? ['count' => 0, 'sum' => 0.0];
        $h['count']++;
        $h['sum'] += $value;
        $this->histograms[$key] = $h;
    }

    public function flush(): void
    {
        $body = '';
        foreach ($this->counters as $key => $val) {
            [$name, $labels] = self::splitKey($key);
            $body .= "# TYPE {$name} counter\n{$name}{$labels} " . self::fmt($val) . "\n";
        }
        foreach ($this->gauges as $key => $val) {
            [$name, $labels] = self::splitKey($key);
            $body .= "# TYPE {$name} gauge\n{$name}{$labels} " . self::fmt($val) . "\n";
        }
        foreach ($this->histograms as $key => $h) {
            [$name, $labels] = self::splitKey($key);
            $body .= "# TYPE {$name} summary\n";
            $body .= "{$name}_count{$labels} {$h['count']}\n";
            $body .= "{$name}_sum{$labels} " . self::fmt($h['sum']) . "\n";
        }

        $tmp = $this->path . '.tmp';
        $fh = fopen($tmp, 'c+');
        if ($fh === false) {
            throw new \RuntimeException("prometheus textfile: cannot open {$tmp}");
        }
        try {
            flock($fh, LOCK_EX);
            ftruncate($fh, 0);
            fwrite($fh, $body);
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        if (!@rename($tmp, $this->path)) {
            throw new \RuntimeException("prometheus textfile: rename failed: {$tmp} -> {$this->path}");
        }
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
            $parts[] = $k . '="' . self::escapeLabel((string) $v) . '"';
        }
        return $name . "\0{" . implode(',', $parts) . '}';
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function splitKey(string $key): array
    {
        $pos = strpos($key, "\0");
        if ($pos === false) {
            return [$key, ''];
        }
        return [substr($key, 0, $pos), substr($key, $pos + 1)];
    }

    private static function escapeLabel(string $s): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $s);
    }

    private static function fmt(float $v): string
    {
        if ($v === floor($v) && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return sprintf('%.6f', $v);
    }
}
