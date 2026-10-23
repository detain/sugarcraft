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
 * The accumulator arrays are bounded: each metric family may hold at
 * most `maxSeriesPerFamily` distinct series (default 10 000 — the same
 * budget the {@see \SugarCraft\Metrics\Registry} enforces on
 * cardinality); creating a new series beyond the cap FIFO-drops that
 * family's oldest series. {@see remove()} and {@see clear()} drop
 * accumulated state from memory, so the next flush no longer emits the
 * removed series.
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
 * Atomicity: each flush() writes its body to a unique per-writer temp
 * file (`<path>.tmp.<pid>-<entropy>`), then `rename()`s it over `<path>`.
 * Concurrent writers never share the staging file and readers never see
 * a half-written body; simultaneous flushes resolve last-writer-wins,
 * which is the textfile-collector contract.
 */
final class PrometheusFileBackend implements Backend
{
    /** Classic Prometheus histogram bucket boundaries. */
    public const DEFAULT_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 25.0, 50.0, 100.0];

    /** NUL byte separator between metric name and label block. Chosen because it cannot appear in sanitized Prometheus metric names. */
    private const KEY_SEPARATOR = "\0";

    /** Default per-family series budget; mirrors the Registry cardinality default. */
    public const DEFAULT_MAX_SERIES_PER_FAMILY = 10_000;

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
     * @param int $maxSeriesPerFamily Distinct series each metric family may accumulate
     *                                before the oldest is FIFO-dropped. Must be >= 1.
     */
    public function __construct(
        private readonly string $path,
        ?array $buckets = null,
        private readonly int $maxSeriesPerFamily = self::DEFAULT_MAX_SERIES_PER_FAMILY,
    ) {
        if ($maxSeriesPerFamily < 1) {
            throw new \InvalidArgumentException(
                'maxSeriesPerFamily must be at least 1, got ' . $maxSeriesPerFamily . '.',
            );
        }
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
        if (!isset($this->counters[$key])) {
            $this->enforceFamilyCap($this->counters, $this->dirtyCounters, $name, $key);
        }
        $this->counters[$key] = ($this->counters[$key] ?? 0.0) + $value;
        $this->dirtyCounters[$key] = true;
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        if (!isset($this->gauges[$key])) {
            $this->enforceFamilyCap($this->gauges, $this->dirtyGauges, $name, $key);
        }
        $this->gauges[$key] = $value;
        $this->dirtyGauges[$key] = true;
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        if (!isset($this->histograms[$key])) {
            $this->enforceFamilyCap($this->histograms, $this->dirtyHistograms, $name, $key);
        }
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

    /**
     * Does a storage key belong to the given sanitized metric family?
     * Bare keys (no labels) equal the family name exactly; labelled keys
     * start with `name . KEY_SEPARATOR`.
     */
    private static function keyInFamily(string $key, string $family): bool
    {
        return $key === $family || str_starts_with($key, $family . self::KEY_SEPARATOR);
    }

    /**
     * Bound the accumulator arrays: when a NEW series would push its
     * family past maxSeriesPerFamily, drop the family's oldest series
     * (insertion order — PHP preserves it for string keys that are never
     * re-assigned after unset). Called before writing a key that does not
     * exist yet; existing series never trigger a scan of the map itself.
     *
     * @param array<string,mixed> $accumulator
     * @param array<string,bool> $dirty
     */
    private function enforceFamilyCap(array &$accumulator, array &$dirty, string $name, string $newKey): void
    {
        $family = self::sanitizeName($name);
        $count = 0;
        $oldest = null;
        foreach ($accumulator as $key => $_) {
            if (!self::keyInFamily($key, $family)) {
                continue;
            }
            $oldest ??= $key;
            ++$count;
        }
        if ($count >= $this->maxSeriesPerFamily && $oldest !== null) {
            unset($accumulator[$oldest], $dirty[$oldest]);
        }
    }

    public function upDownCounter(string $name, float $amount, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        if (!isset($this->upDownCounters[$key])) {
            $this->enforceFamilyCap($this->upDownCounters, $this->dirtyUpDownCounters, $name, $key);
        }
        $this->upDownCounters[$key] = ($this->upDownCounters[$key] ?? 0.0) + $amount;
        $this->dirtyUpDownCounters[$key] = true;
    }

    public function asyncCounter(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        if (!isset($this->asyncCounters[$key])) {
            $this->enforceFamilyCap($this->asyncCounters, $this->dirtyAsyncCounters, $name, $key);
        }
        $this->asyncCounters[$key] = $value;
        $this->dirtyAsyncCounters[$key] = true;
    }

    public function asyncGauge(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        if (!isset($this->asyncGauges[$key])) {
            $this->enforceFamilyCap($this->asyncGauges, $this->dirtyAsyncGauges, $name, $key);
        }
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

        // Snapshot dirty flag maps before clearing, so the non-dirty loops can
        // skip already-emitted keys with an O(1) isset() lookup instead of an
        // O(n) in_array() scan over an array_keys() list — the maps are already
        // keyed by the storage key, so no rebuild is needed.
        $dirtyCounterMap = $this->dirtyCounters;
        $dirtyGaugeMap = $this->dirtyGauges;
        $dirtyHistogramMap = $this->dirtyHistograms;
        $dirtyUpDownCounterMap = $this->dirtyUpDownCounters;
        $dirtyAsyncCounterMap = $this->dirtyAsyncCounters;
        $dirtyAsyncGaugeMap = $this->dirtyAsyncGauges;

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
            if (isset($dirtyCounterMap[$key])) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'counter') . $this->emitMetricLine($name, $labels, $val, 'counter');
        }
        foreach ($this->upDownCounters as $key => $val) {
            if (isset($dirtyUpDownCounterMap[$key])) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'gauge') . $this->emitMetricLine($name, $labels, $val, 'gauge');
        }
        foreach ($this->asyncCounters as $key => $val) {
            if (isset($dirtyAsyncCounterMap[$key])) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'counter') . $this->emitMetricLine($name, $labels, $val, 'counter');
        }
        foreach ($this->asyncGauges as $key => $val) {
            if (isset($dirtyAsyncGaugeMap[$key])) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'gauge') . $this->emitMetricLine($name, $labels, $val, 'gauge');
        }
        foreach ($this->gauges as $key => $val) {
            if (isset($dirtyGaugeMap[$key])) {
                continue;
            }
            [$name, $labels] = self::splitKey($key);
            $body .= $emitType($name, 'gauge') . $this->emitMetricLine($name, $labels, $val, 'gauge');
        }
        foreach ($this->histograms as $key => $h) {
            if (isset($dirtyHistogramMap[$key])) {
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
            if (isset($this->descriptorEmittedForFlush[$name]) === false) {
                $body .= "# HELP {$name} " . self::escapeHelp($descriptor->help) . "\n";
                $body .= "# TYPE {$name} {$descriptor->type}\n";
            }
        }

        // Unique per-writer temp name: two processes flushing the same path
        // must never share the staging file. A shared `<path>.tmp` meant one
        // writer's rename could steal the file mid-flight (or truncate the
        // other's half-written body), and the loser then died on a spurious
        // rename_failed(ENOENT). flock still guards the write phase of THIS
        // staging file; rename(2) publishes atomically, so a reader only ever
        // sees a complete body — last writer wins, which is exactly the
        // textfile-collector contract.
        $tmp = $this->path . '.tmp.' . uniqid((string) getmypid() . '-', true);
        $dir = dirname($tmp);
        // fopen() raises an E_WARNING *before* returning false when its
        // parent cannot host the file (missing dir, parent is a file,
        // unwritable dir). The RuntimeException below is this door's documented
        // contract, so probe the parent first and only reach fopen() when
        // success is expected; the `=== false` check stays to close the
        // probe-vs-open race with the same refusal.
        $fh = is_dir($dir) && is_writable($dir) ? fopen($tmp, 'c+') : false;
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
        if (@rename($tmp, $this->path) === false) {
            if (is_file($tmp)) {
                unlink($tmp); // the unique name would otherwise leak one file per failure
            }
            throw new \RuntimeException(Lang::t('prom.rename_failed', ['tmp' => $tmp, 'dest' => $this->path]));
        }
    }

    /**
     * Drop one accumulated series (name + label set) from the backend's
     * in-memory state. The key is derived exactly like the write path,
     * so this reclaims what counter()/gauge()/histogram()/... stored —
     * including the Registry's cardinality-eviction forward, which
     * arrives with the merged tag set. The next flush() simply no longer
     * emits the removed series; the textfile itself is rewritten whole
     * on every flush, so no stale line survives.
     *
     * @param array<string,string> $tags
     */
    public function remove(string $name, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        unset(
            $this->counters[$key], $this->dirtyCounters[$key],
            $this->gauges[$key], $this->dirtyGauges[$key],
            $this->histograms[$key], $this->dirtyHistograms[$key],
            $this->upDownCounters[$key], $this->dirtyUpDownCounters[$key],
            $this->asyncCounters[$key], $this->dirtyAsyncCounters[$key],
            $this->asyncGauges[$key], $this->dirtyAsyncGauges[$key],
        );
    }

    /**
     * Reset the backend: forget every accumulated series, dirty flag,
     * and registered descriptor. The next flush() writes an empty
     * textfile (the file itself is not unlinked — deleting the target
     * from under a watching node_exporter is a deployment decision, not
     * a metrics-library one).
     */
    public function clear(): void
    {
        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
        $this->upDownCounters = [];
        $this->asyncCounters = [];
        $this->asyncGauges = [];
        $this->dirtyCounters = [];
        $this->dirtyGauges = [];
        $this->dirtyHistograms = [];
        $this->dirtyUpDownCounters = [];
        $this->dirtyAsyncCounters = [];
        $this->dirtyAsyncGauges = [];
        $this->descriptors = [];
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
        if (isset($this->typeEmittedForFlush[$name]) === false) {
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
