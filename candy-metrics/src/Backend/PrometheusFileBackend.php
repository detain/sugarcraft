<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Lang;
use SugarCraft\Metrics\Backend;
use SugarCraft\Metrics\Descriptor;

/**
 * Atomically rewrites a Prometheus textfile-collector file
 * containing the current state of all metrics. Designed for the
 * "node_exporter --collector.textfile.directory" pattern: short-
 * lived processes write a `.prom` file that the long-running
 * exporter scrapes.
 *
 * Counter values accumulate across `flush()` calls, gauges hold
 * their last set value, histograms emit the full set of bucket
 * lines (`*_bucket{le="..."}`) plus `_count` and `_sum`.
 *
 * **Always call {@see flush()} explicitly** for guaranteed delivery
 * and error visibility. The destructor calls flush() but silently
 * drops all errors (failed rename/permission issues are swallowed
 * in __destruct to avoid throwing from destructors). If you need
 * to know whether flush succeeded, call it explicitly and catch
 * the RuntimeException.
 *
 * **summary descriptors**: the "summary" TYPE is accepted and emits
 * a `# HELP` / `# TYPE` header, but quantile lines and exemplars
 * are not rendered (no `observe()` path exists for summary in this
 * library). This is consistent with the OpenTelemetry API shape.
 *
 * Atomicity: write to `<path>.tmp`, then `rename()` over `<path>`.
 * Concurrent writers serialise via `flock(LOCK_EX)` on the temp
 * file.
 */
final class PrometheusFileBackend implements Backend
{
    /** Classic Prometheus histogram bucket boundaries. */
    public const DEFAULT_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 25.0, 50.0, 100.0];

    /** NUL byte separator between metric name and label block. Chosen because it cannot appear in sanitized Prometheus metric names. */
    private const KEY_SEPARATOR = "\0";

    /** @var list<float> */
    private array $buckets;

    /** @var array<string,float> */
    private array $counters = [];
    /** @var array<string,float> */
    private array $gauges = [];
    /** @var array<string,array{count:int,sum:float,buckets:array<string,int>}> */
    private array $histograms = [];
    /** @var array<string,float> */
    private array $upDownCounters = [];
    /** @var array<string,float> */
    private array $asyncCounters = [];
    /** @var array<string,float> */
    private array $asyncGauges = [];

    /** @var array<string, Descriptor> Metric descriptors indexed by sanitized name. */
    private array $descriptors = [];

    /** @var array<string,bool> Dirty flags per counter key — true means metric changed since last flush. */
    private array $dirtyCounters = [];
    /** @var array<string,bool> Dirty flags per gauge key. */
    private array $dirtyGauges = [];
    /** @var array<string,bool> Dirty flags per histogram key. */
    private array $dirtyHistograms = [];
    /** @var array<string,bool> Dirty flags per up-down-counter key. */
    private array $dirtyUpDownCounters = [];
    /** @var array<string,bool> Dirty flags per async-counter key. */
    private array $dirtyAsyncCounters = [];
    /** @var array<string,bool> Dirty flags per async-gauge key. */
    private array $dirtyAsyncGauges = [];

    /** @var array<string,bool> Tracks TYPE/HELP emitted per family within one flush() cycle. */
    private array $typeEmittedForFlush = [];

    /** @var array<string,bool> Tracks descriptor-emitted per family within one flush() cycle. */
    private array $descriptorEmittedForFlush = [];

    /**
     * @param string $path     Path to the Prometheus textfile.
     * @param list<float>|null $buckets Histogram bucket upper bounds. Defaults to DEFAULT_BUCKETS.
     */
    public function __construct(private readonly string $path, ?array $buckets = null)
    {
        $this->buckets = $buckets ?? self::DEFAULT_BUCKETS;
    }

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
        $this->dirtyCounters[$key] = true;
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->gauges[$key] = $value;
        $this->dirtyGauges[$key] = true;
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $h = $this->histograms[$key] ?? ['count' => 0, 'sum' => 0.0, 'buckets' => $this->emptyBuckets()];
        $h['count']++;
        $h['sum'] += $value;
        foreach ($this->buckets as $b) {
            if ($value <= $b) {
                $h['buckets'][(string) $b]++;
            }
        }
        // +Inf bucket always gets the sample
        $h['buckets']['+Inf']++;
        $this->histograms[$key] = $h;
        $this->dirtyHistograms[$key] = true;
    }

    /** Returns a fresh zeroed bucket array for use in new histogram series. */
    private function emptyBuckets(): array
    {
        $buckets = [];
        foreach ($this->buckets as $b) {
            $buckets[(string) $b] = 0;
        }
        $buckets['+Inf'] = 0;
        return $buckets;
    }

    public function upDownCounter(string $name, float $amount, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->upDownCounters[$key] = ($this->upDownCounters[$key] ?? 0.0) + $amount;
        $this->dirtyUpDownCounters[$key] = true;
    }

    public function asyncCounter(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->asyncCounters[$key] = $value;
        $this->dirtyAsyncCounters[$key] = true;
    }

    public function asyncGauge(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->asyncGauges[$key] = $value;
        $this->dirtyAsyncGauges[$key] = true;
    }

    public function describe(Descriptor $descriptor): void
    {
        // Store keyed by sanitized name so flush() can look up by the same key
        // that is used when emitting TYPE/HELP headers.
        $this->descriptors[self::sanitizeName($descriptor->name)] = $descriptor;
    }

    public function flush(): void
    {
        $body = '';

        // Only process sampled metrics if something changed since last flush.
        // This avoids re-serializing unchanged metrics and unnecessary file I/O.
        $hasDirty = !(
            $this->dirtyCounters === []
            && $this->dirtyGauges === []
            && $this->dirtyHistograms === []
            && $this->dirtyUpDownCounters === []
            && $this->dirtyAsyncCounters === []
            && $this->dirtyAsyncGauges === []
        );
        // Reset per-flush TYPE/HELP emission tracking.
        $this->typeEmittedForFlush = [];
        $this->descriptorEmittedForFlush = [];

        // Helper: emit TYPE/HELP header for a metric family if not already emitted.
        // Uses instance state ($this->typeEmittedForFlush, $this->descriptorEmittedForFlush)
        // so changes are visible to both the closure and emitMetricLine().
        $emitType = function (string $name, string $inferredType) use (&$body): string {
            if (isset($this->typeEmittedForFlush[$name])) {
                return '';
            }
            $this->typeEmittedForFlush[$name] = true;
            if (isset($this->descriptors[$name])) {
                $d = $this->descriptors[$name];
                $result = "# HELP {$name} " . self::escapeHelp($d->help) . "\n";
                $result .= "# TYPE {$name} {$d->type}\n";
                $this->descriptorEmittedForFlush[$name] = true;
                return $result;
            }
            return "# TYPE {$name} {$inferredType}\n";
        };

        // --- Dirty counters: emit HELP+TYPE once per family, then sample lines for dirty keys ---
        foreach ($this->dirtyCounters as $key => $_) {
            $val = $this->counters[$key] ?? 0.0;
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'counter') . $this->emitMetricLine($name, $labels, $val, 'counter');
        }
        foreach ($this->dirtyUpDownCounters as $key => $_) {
            $val = $this->upDownCounters[$key] ?? 0.0;
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'gauge') . $this->emitMetricLine($name, $labels, $val, 'gauge');
        }
        foreach ($this->dirtyAsyncCounters as $key => $_) {
            $val = $this->asyncCounters[$key] ?? 0.0;
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'counter') . $this->emitMetricLine($name, $labels, $val, 'counter');
        }
        foreach ($this->dirtyAsyncGauges as $key => $_) {
            $val = $this->asyncGauges[$key] ?? 0.0;
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'gauge') . $this->emitMetricLine($name, $labels, $val, 'gauge');
        }
        foreach ($this->dirtyGauges as $key => $_) {
            $val = $this->gauges[$key] ?? 0.0;
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'gauge') . $this->emitMetricLine($name, $labels, $val, 'gauge');
        }
        foreach ($this->dirtyHistograms as $key => $_) {
            $h = $this->histograms[$key] ?? ['count' => 0, 'sum' => 0.0, 'buckets' => $this->emptyBuckets()];
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'histogram');
            // Bucket lines
            foreach ($this->buckets as $b) {
                $body .= "{$name}_bucket" . self::buildBucketLabels($labels, (string) $b) . ' ' . $h['buckets'][(string) $b] . "\n";
            }
            $body .= "{$name}_bucket" . self::buildBucketLabels($labels, '+Inf') . ' ' . $h['buckets']['+Inf'] . "\n";
            $body .= "{$name}_count{$labels} {$h['count']}\n";
            $body .= "{$name}_sum{$labels} " . self::fmt($h['sum']) . "\n";
        }

        // Snapshot dirty keys before clearing, so non-dirty loop can skip them.
        $dirtyCounterKeys = array_keys($this->dirtyCounters);
        $dirtyGaugeKeys = array_keys($this->dirtyGauges);
        $dirtyHistogramKeys = array_keys($this->dirtyHistograms);
        $dirtyUpDownCounterKeys = array_keys($this->dirtyUpDownCounters);
        $dirtyAsyncCounterKeys = array_keys($this->dirtyAsyncCounters);
        $dirtyAsyncGaugeKeys = array_keys($this->dirtyAsyncGauges);

        // Clear dirty flags now that we've processed them.
        $this->dirtyCounters = [];
        $this->dirtyGauges = [];
        $this->dirtyHistograms = [];
        $this->dirtyUpDownCounters = [];
        $this->dirtyAsyncCounters = [];
        $this->dirtyAsyncGauges = [];

        // If there are non-dirty metrics, we must still emit their full state so the
        // Prometheus textfile collector sees complete data. Emit all non-dirty metrics
        // that were not already covered by the dirty iterations above.
        foreach ($this->counters as $key => $val) {
            if (in_array($key, $dirtyCounterKeys, true)) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'counter') . $this->emitMetricLine($name, $labels, $val, 'counter');
        }
        foreach ($this->upDownCounters as $key => $val) {
            if (in_array($key, $dirtyUpDownCounterKeys, true)) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'gauge') . $this->emitMetricLine($name, $labels, $val, 'gauge');
        }
        foreach ($this->asyncCounters as $key => $val) {
            if (in_array($key, $dirtyAsyncCounterKeys, true)) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'counter') . $this->emitMetricLine($name, $labels, $val, 'counter');
        }
        foreach ($this->asyncGauges as $key => $val) {
            if (in_array($key, $dirtyAsyncGaugeKeys, true)) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'gauge') . $this->emitMetricLine($name, $labels, $val, 'gauge');
        }
        foreach ($this->gauges as $key => $val) {
            if (in_array($key, $dirtyGaugeKeys, true)) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'gauge') . $this->emitMetricLine($name, $labels, $val, 'gauge');
        }
        foreach ($this->histograms as $key => $h) {
            if (in_array($key, $dirtyHistogramKeys, true)) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'histogram');
            foreach ($this->buckets as $b) {
                $body .= "{$name}_bucket" . self::buildBucketLabels($labels, (string) $b) . ' ' . $h['buckets'][(string) $b] . "\n";
            }
            $body .= "{$name}_bucket" . self::buildBucketLabels($labels, '+Inf') . ' ' . $h['buckets']['+Inf'] . "\n";
            $body .= "{$name}_count{$labels} {$h['count']}\n";
            $body .= "{$name}_sum{$labels} " . self::fmt($h['sum']) . "\n";
        }

        // --- Un-sampled descriptors: emit HELP + TYPE for descriptors with no samples ---
        foreach ($this->descriptors as $name => $descriptor) {
            if (!isset($this->descriptorEmittedForFlush[$name])) {
                $body .= "# HELP {$name} " . self::escapeHelp($descriptor->help) . "\n";
                $body .= "# TYPE {$name} {$descriptor->type}\n";
            }
        }

        $tmp = $this->path . '.tmp';
        $fh = fopen($tmp, 'c+');
        if ($fh === false) {
            throw new \RuntimeException(Lang::t('prom.cannot_open', ['path' => $tmp]));
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
            throw new \RuntimeException(Lang::t('prom.rename_failed', ['tmp' => $tmp, 'dest' => $this->path]));
        }
    }

    /**
     * PrometheusFileBackend does not support in-process metric removal;
     * metrics are persisted to the textfile on each flush().
     *
     * @param array<string,string> $tags
     */
    public function remove(string $name, array $tags = []): void
    {
        // No-op: Prometheus textfile backend accumulates to a file;
        // to remove a metric series, delete the file and restart.
    }

    /**
     * PrometheusFileBackend does not support in-process clearing;
     * call clear() on the Registry's in-memory view if needed.
     */
    public function clear(): void
    {
        // No-op: Prometheus textfile backend persists all metrics.
    }

    /**
     * @param array<string,string> $tags
     */
    private function key(string $name, array $tags): string
    {
        $name = self::sanitizeName($name);
        if ($tags === []) {
            return $name;
        }
        ksort($tags);
        $parts = [];
        foreach ($tags as $k => $v) {
            $parts[] = self::sanitizeKey($k) . '="' . self::escapeLabel((string) $v) . '"';
        }
        return $name . self::KEY_SEPARATOR . "{" . implode(',', $parts) . '}';
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function splitKey(string $key): array
    {
        $pos = strpos($key, self::KEY_SEPARATOR);
        if ($pos === false) {
            return [$key, ''];
        }
        return [substr($key, 0, $pos), substr($key, $pos + 1)];
    }

    /**
     * Sanitize a metric name to match Prometheus's allowed charset
     * `[a-zA-Z_:][a-zA-Z0-9_:]*`. Replaces illegal chars with `_` and
     * prefixes with `_` if the first character is a digit.
     */
    private static function sanitizeName(string $name): string
    {
        $s = preg_replace('/[^a-zA-Z0-9_:]/', '_', $name);
        if ($s === null) {
            return $name;
        }
        // First char must not be a digit
        if ($s !== '' && is_numeric($s[0])) {
            $s = '_' . $s;
        }
        return $s;
    }

    /**
     * Sanitize a label key to match Prometheus's allowed charset
     * `[a-zA-Z_][a-zA-Z0-9_]*`. Replaces illegal chars with `_` and
     * prefixes with `_` if the first character is a digit.
     */
    private static function sanitizeKey(string $key): string
    {
        $s = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
        if ($s === null) {
            return $key;
        }
        // First char must not be a digit
        if ($s !== '' && is_numeric($s[0])) {
            $s = '_' . $s;
        }
        return $s;
    }

    /**
     * Escape text for inclusion in a # HELP line.
     * Prometheus requires only `\\` → `\\` and `\<newline>` → `\\n`.
     * Quotation marks are NOT escaped in HELP text (unlike label values).
     */
    private static function escapeHelp(string $s): string
    {
        return str_replace(["\\", "\n"], ["\\\\", "\\n"], $s);
    }

    private static function escapeLabel(string $s): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $s);
    }

    /**
     * Build a Prometheus label block for a histogram bucket by appending
     * the `le` (less-than-or-equal) label to an existing label block.
     *
     * @param string $labels Empty string '' or a label block like '{foo="bar"}'
     * @param string $le     The bucket upper bound, e.g. '1.0' or '+Inf'
     */
    private static function buildBucketLabels(string $labels, string $le): string
    {
        if ($labels === '') {
            return '{le="' . $le . '"}';
        }
        // Strip trailing '}' and append the le label.
        return substr($labels, 0, -1) . ',le="' . $le . '"}';
    }

    /**
     * Emit TYPE/HELP header (if not yet emitted in this flush cycle)
     * and return the formatted metric sample line.
     *
     * @param string $name         Sanitized metric name
     * @param string $labels       Label block e.g. '{foo="bar"}' or ''
     * @param float  $value        Metric value
     * @param string $defaultType Inferred type when no descriptor is registered
     */
    private function emitMetricLine(string $name, string $labels, float $value, string $defaultType): string
    {
        if (!isset($this->typeEmittedForFlush[$name])) {
            $this->typeEmittedForFlush[$name] = true;
            if (isset($this->descriptors[$name])) {
                $d = $this->descriptors[$name];
                $result = "# HELP {$name} " . self::escapeHelp($d->help) . "\n";
                $result .= "# TYPE {$name} {$d->type}\n";
                $this->descriptorEmittedForFlush[$name] = true;
            } else {
                $result = "# TYPE {$name} {$defaultType}\n";
            }
        } else {
            $result = '';
        }
        return $result . "{$name}{$labels} " . self::fmt($value) . "\n";
    }

    private static function fmt(float $v): string
    {
        if ($v === floor($v) && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return sprintf('%.6f', $v);
    }
}
