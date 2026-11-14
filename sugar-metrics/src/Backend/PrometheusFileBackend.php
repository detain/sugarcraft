<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Lang;
use SugarCraft\Metrics\Backend;
use SugarCraft\Metrics\Descriptor;
use SugarCraft\Metrics\Util;

/**
 * Mirrors charmbracelet/promwish textfile collector output path
 * (the node_exporter --collector.textfile.directory pattern).
 *
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
 * Collision guards: distinct raw inputs that sanitize to the same name
 * or the same label set are refused loudly, never merged — a silent
 * fold makes {@see remove()} ambiguous (wiping one caller's series when
 * the other asked for its twin) and lets a forged label key produce a
 * duplicate `label="..."` that makes the whole exposition
 * unparseable. The fold memory covers every path: the emit ledger
 * (raw facts) and the TYPE/HELP descriptor ledger share it, so write,
 * describe() and remove() all refuse the same twins. Likewise flush()
 * refuses to publish a body containing a
 * duplicate sample identity (two kinds racing for one name, or a
 * histogram's derived `*_count` colliding with a real family): one
 * poisoned line would make node_exporter reject the ENTIRE file, so the
 * failure is surfaced as a RuntimeException at the writer instead.
 * Emit-time refusals go further (sugar-metrics-054/058): a name or label
 * key that sanitizes to empty is refused at the storage-key chokepoint,
 * and a second kind writing the same series is refused at the call site,
 * so a permanently poisoned accumulator entry cannot be constructed at
 * all and the whole-replaced file self-heals on the next flush.
 *
 * Durability: flush() verifies every write syscall, fsyncs the staging
 * file before publishing it via rename(2), fsyncs the parent directory
 * (best-effort) after it, and deletes the staging file on any failure,
 * so a returned success means a collector scraping now reads complete
 * bytes from disk. Staging names end in `.tmp` (the collector's
 * version-independent ignore rule) and every successful publish sweeps
 * crash-leaked residue of its own pattern past a grace window. The
 * publish mode is 0600 by default — chmod on the staging
 * path (exclusive create) before the rename, so the process umask never decides exposure;
 * the `fileMode` constructor argument opens it up for a reader as
 * another user.
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

    /** Default publish mode (sugar-metrics-029): owner read/write only, umask-independent. */
    public const DEFAULT_FILE_MODE = 0600;

    /** Bounded x+ retries per flush (sugar-metrics-028): a taken staging name is retried, never reused. */
    private const STAGING_ATTEMPTS = 8;

    /** Crash-leaked staging residue younger than this is left alone (sugar-metrics-055). */
    private const STAGING_GRACE_SECONDS = 3600;

    private int $fileMode;

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
    /** @var bool exposition changed outside the per-series dirty arrays —
     * descriptor registration or series reclaim (flush-gate input, 024) */
    private bool $dirtyExposition = false;
    /** @var array<string,bool> Dirty flags per async-gauge key. */
    private array $dirtyAsyncGauges = [];

    /** @var array<string,bool> Tracks TYPE/HELP emitted per family within one flush() cycle. */
    private array $typeEmittedForFlush = [];

    /** @var array<string,bool> Tracks descriptor-emitted per family within one flush() cycle. */
    private array $descriptorEmittedForFlush = [];

    /** @var array<string,array{0:string,1:array<string,string>}> First raw (name, tags) seen per storage key — the collision detector's memory. */
    private array $rawFacts = [];

    /** @var array<string,string> First raw name claiming each descriptor key — the describe() twin of {@see $rawFacts}. */
    private array $rawDescriptors = [];

    /** @var array<string,string> sugar-metrics-015: raw metric name → sanitized token (pure fold, memoized per instance). */
    private array $nameTokens = [];

    /** @var array<string,string> sugar-metrics-015: raw label key → sanitized token (pure fold; the histogram `le` gate and the storage-key build share it, collapsing the 025 double-pay). */
    private array $labelTokens = [];

    /** @var array<string,array<string,true>> Insertion-ordered key index per sanitized family — makes the series cap O(1). */
    private array $familyKeys = [];

    /**
     * @param string $path     Path to the Prometheus textfile.
     * @param list<float>|null $buckets Histogram bucket upper bounds. Defaults to DEFAULT_BUCKETS.
     *                                  Must be finite and strictly increasing (the exposition's
     *                                  cumulative `le` lines and the `value <= $b` accumulation
     *                                  both assume ascending order; duplicates and out-of-order
     *                                  bounds silently corrupt every bucket count).
     * @param int $maxSeriesPerFamily Distinct series each metric family may accumulate
     *                                before the oldest is FIFO-dropped. Must be >= 1.
     * @param int|null $fileMode      Publish mode for the textfile (sugar-metrics-029):
     *                                applied via chmod on the staging path (x+ create) before the
     *                                rename, so the process umask never decides exposure.
     *                                Null means DEFAULT_FILE_MODE (0600); pass e.g. 0644
     *                                when the exporter reads as another user. Must be a
     *                                0-0777 permission mask.
     */
    /**
     * House-idiom factory (sugar-metrics-014): `PrometheusFileBackend::new($path)`.
     */
    public static function new(string $path, ?array $buckets = null, int $maxSeriesPerFamily = self::DEFAULT_MAX_SERIES_PER_FAMILY, ?int $fileMode = null): self
    {
        return new self($path, $buckets, $maxSeriesPerFamily, $fileMode);
    }

    public function __construct(
        private readonly string $path,
        ?array $buckets = null,
        private readonly int $maxSeriesPerFamily = self::DEFAULT_MAX_SERIES_PER_FAMILY,
        ?int $fileMode = null,
    ) {
        if ($maxSeriesPerFamily < 1) {
            throw new \InvalidArgumentException(Lang::t('prom.max_series', ['limit' => (string) $maxSeriesPerFamily]));
        }
        if ($fileMode !== null && ($fileMode < 0 || $fileMode > 0777)) {
            throw new \InvalidArgumentException(Lang::t('prom.file_mode', ['mode' => decoct($fileMode)]));
        }
        $this->fileMode = $fileMode ?? self::DEFAULT_FILE_MODE;
        $this->buckets = $buckets ?? self::DEFAULT_BUCKETS;
        $previous = -\INF;
        foreach ($this->buckets as $index => $boundary) {
            if (is_finite($boundary) === false || $boundary <= $previous) {
                throw new \InvalidArgumentException(Lang::t('prom.buckets_invalid', ['index' => (string) $index]));
            }
            $previous = $boundary;
        }
    }

    /** The per-family series budget this backend enforces (constructor argument). */
    public function maxSeriesPerFamily(): int
    {
        return $this->maxSeriesPerFamily;
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
        $this->refuseKindSwitch($key, 'counter', $this->counters);
        if (!isset($this->counters[$key])) {
            $this->ensureSpace($this->counters, $this->dirtyCounters, $key);
        }
        $this->counters[$key] = ($this->counters[$key] ?? 0.0) + $value;
        $this->dirtyCounters[$key] = true;
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->refuseKindSwitch($key, 'gauge', $this->gauges);
        if (!isset($this->gauges[$key])) {
            $this->ensureSpace($this->gauges, $this->dirtyGauges, $key);
        }
        $this->gauges[$key] = $value;
        $this->dirtyGauges[$key] = true;
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        // The bucket lines carry a reserved `le` label; a user tag that
        // sanitizes to `le` would ride the block twice (and the second
        // occurrence wins in some parsers) — refuse at the door.
        // sugar-metrics-025: the check now rides the shared per-key memo
        // the storage-key build fills, so this loop is hash lookups on the
        // steady path instead of a fresh preg sweep per observation.
        foreach ($tags as $k => $_) {
            if (($this->labelTokens[(string) $k] ??= self::sanitizeKey((string) $k)) === 'le') {
                throw new \RuntimeException(Lang::t('prom.reserved_label', ['name' => Util::displaySafe($name)]));
            }
        }
        $key = $this->key($name, $tags);
        $this->refuseKindSwitch($key, 'histogram', $this->histograms);
        if (!isset($this->histograms[$key])) {
            $this->ensureSpace($this->histograms, $this->dirtyHistograms, $key);
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
     * Make room for a NEW series: when its family is at
     * maxSeriesPerFamily, drop the family's oldest series (insertion
     * order — PHP preserves it for string keys that are never re-assigned
     * after unset). The per-family index is written through in place — a
     * new-series add never copies the family, so this is O(1) in series per
     * family (an earlier read-copy-local/reassign shape paid a
     * copy-on-write separation of the whole index per write); the eviction
     * also clears the collision detector's records and the family index
     * for the dropped key, so a later raw that folds onto the freed slot
     * is measured against its own first sighting, not a ghost.
     *
     * @param array<string,mixed> $accumulator
     * @param array<string,bool> $dirty
     */
    private function ensureSpace(array &$accumulator, array &$dirty, string $key): void
    {
        [$family] = self::splitKey($key);
        if (!isset($this->familyKeys[$family])) {
            $this->familyKeys[$family] = [];
        }
        if (count($this->familyKeys[$family]) >= $this->maxSeriesPerFamily) {
            $oldest = array_key_first($this->familyKeys[$family]);
            if ($oldest !== null) {
                unset($accumulator[$oldest], $dirty[$oldest], $this->rawFacts[$oldest], $this->familyKeys[$family][$oldest]);
            }
        }
        $this->familyKeys[$family][$key] = true;
    }

    /**
     * Forget one storage key from every side table (collision records,
     * family index). The accumulator/dirty unsets are call-site specific.
     */
    private function evictSideTables(string $key): void
    {
        unset($this->rawFacts[$key]);
        [$family] = self::splitKey($key);
        if (isset($this->familyKeys[$family][$key])) {
            unset($this->familyKeys[$family][$key]);
            if ($this->familyKeys[$family] === []) {
                unset($this->familyKeys[$family]);
            }
        }
    }

    public function upDownCounter(string $name, float $amount, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->refuseKindSwitch($key, 'up/down counter', $this->upDownCounters);
        if (!isset($this->upDownCounters[$key])) {
            $this->ensureSpace($this->upDownCounters, $this->dirtyUpDownCounters, $key);
        }
        $this->upDownCounters[$key] = ($this->upDownCounters[$key] ?? 0.0) + $amount;
        $this->dirtyUpDownCounters[$key] = true;
    }

    public function asyncCounter(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->refuseKindSwitch($key, 'async counter', $this->asyncCounters);
        if (!isset($this->asyncCounters[$key])) {
            $this->ensureSpace($this->asyncCounters, $this->dirtyAsyncCounters, $key);
        }
        $this->asyncCounters[$key] = $value;
        $this->dirtyAsyncCounters[$key] = true;
    }

    public function asyncGauge(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->refuseKindSwitch($key, 'async gauge', $this->asyncGauges);
        if (!isset($this->asyncGauges[$key])) {
            $this->ensureSpace($this->asyncGauges, $this->dirtyAsyncGauges, $key);
        }
        $this->asyncGauges[$key] = $value;
        $this->dirtyAsyncGauges[$key] = true;
    }

    public function describe(Descriptor $descriptor): void
    {
        $raw       = $descriptor->name;
        $sanitized = self::sanitizeName($raw);
        // Internal assertion (MET-DESCRIBE, 001): a valid Prometheus name is
        // a fold fixed point, so after the Descriptor gate and the Registry
        // door this leg is unreachable — kept because the class docblock
        // promises the guard and a forged DTO must never key the ledger
        // under its fold alias.
        if ($sanitized !== $raw) {
            throw new \InvalidArgumentException(Lang::t('descriptor.name_invalid', ['name' => Util::displaySafe($raw)]));
        }
        // Descriptor ownership mirrors the emit path's raw facts: the FIRST
        // raw name to claim a sanitized key owns it, and a different raw
        // name folding onto the occupied key is refused — sampled or not,
        // even when nothing has ever been emitted for the family.
        $owner = $this->rawDescriptors[$sanitized] ?? null;
        if ($owner !== null && $owner !== $raw) {
            throw new \RuntimeException(Lang::t('prom.name_collision', [
                'first'  => Util::displaySafe(self::describeRaw($owner, [])),
                'second' => Util::displaySafe(self::describeRaw($raw, [])),
                'folded' => Util::displaySafe($sanitized),
            ]));
        }
        // The descriptor may not claim a key an emitted series already owns
        // under a different raw name — this is the found wedge: counter('a-b')
        // followed by register(Descriptor('a_b', …, 'gauge')) silently pinned
        // `# TYPE a_b gauge` over the twin's samples.
        $emitted = $this->rawFacts[$sanitized] ?? null;
        if ($emitted !== null && $emitted[0] !== $raw) {
            throw new \RuntimeException(Lang::t('prom.name_collision', [
                'first'  => Util::displaySafe(self::describeRaw($emitted[0], $emitted[1])),
                'second' => Util::displaySafe(self::describeRaw($raw, [])),
                'folded' => Util::displaySafe($sanitized),
            ]));
        }
        $this->rawDescriptors[$sanitized] = $raw;
        // Store keyed by sanitized name so flush() can look up by the same key
        // that is used when emitting TYPE/HELP headers.
        $this->descriptors[$sanitized] = $descriptor;
        // A freshly declared family changes the exposition even with no
        // samples, so it is dirty for the flush gate (sugar-metrics-024).
        $this->dirtyExposition = true;
    }

    public function flush(): void
    {
        // The dirty flags gate the write (sugar-metrics-024): a timer-flushed
        // idle process used to pay a rebuild + fsync + atomic rename of
        // byte-identical content on every tick because nothing read the flags
        // it maintained. An idle flush now returns untouched when nothing has
        // changed since the last write AND the published file still exists —
        // its disappearance is the consumer's reset signal, and the next
        // flush republishes the full state even with no new emits. A backend
        // that recorded nothing still publishes once (file-missing leg), so
        // the file always materialises for watching collectors.
        if (\file_exists($this->path)
            && !$this->dirtyExposition
            && $this->dirtyCounters === []
            && $this->dirtyGauges === []
            && $this->dirtyHistograms === []
            && $this->dirtyUpDownCounters === []
            && $this->dirtyAsyncCounters === []
            && $this->dirtyAsyncGauges === []) {
            return;
        }

        $body = '';

        // Reset per-flush emission tracking.
        $this->typeEmittedForFlush = [];
        $this->descriptorEmittedForFlush = [];

        // Exposition integrity: a Prometheus text file may not carry the
        // same sample identity (line name + label set) twice, and one
        // duplicate makes node_exporter reject the ENTIRE file. Two races
        // need guards with different reach. Name-vs-kind first: a histogram
        // declares family `x` but emits only the derived lines `x_bucket`,
        // `x_count` and `x_sum`, so a real metric of the same BASE name
        // shares no line identity with it — the per-line check below cannot
        // see that clash, yet the published file puts derived lines under
        // the other kind's TYPE and Prometheus rejects it. Refuse before
        // building the body. The per-line check then catches what the
        // base-name scan cannot: a histogram's derived `*_bucket`/`*_count`/
        // `*_sum` lines colliding with real families of that derived name.
        // Fail loud, publish nothing.
        // MET-SPLITKEY (048): split every key exactly ONCE per flush — the
        // guard scan and the emit loops share one local [$family, $labels]
        // map instead of each paying their own strpos + two substr per
        // series. The map is flush-scoped (accumulators can gain series
        // between writes), and bytes are unchanged by construction: splitKey
        // is a pure function of the key.
        $splits = [];
        $nonHistogramKindNames = [];
        foreach ($this->counters as $key => $_) {
            $nonHistogramKindNames[($splits[$key] ??= self::splitKey($key))[0]] = 'counter';
        }
        foreach ($this->upDownCounters as $key => $_) {
            $nonHistogramKindNames[($splits[$key] ??= self::splitKey($key))[0]] = 'up/down counter';
        }
        foreach ($this->asyncCounters as $key => $_) {
            $nonHistogramKindNames[($splits[$key] ??= self::splitKey($key))[0]] = 'async counter';
        }
        foreach ($this->asyncGauges as $key => $_) {
            $nonHistogramKindNames[($splits[$key] ??= self::splitKey($key))[0]] = 'async gauge';
        }
        foreach ($this->gauges as $key => $_) {
            $nonHistogramKindNames[($splits[$key] ??= self::splitKey($key))[0]] = 'gauge';
        }
        foreach ($this->histograms as $key => $_) {
            $family = ($splits[$key] ??= self::splitKey($key))[0];
            if (isset($nonHistogramKindNames[$family])) {
                throw new \RuntimeException(Lang::t('prom.two_kinds', [
                    'name' => Util::displaySafe($family),
                    'kind' => $nonHistogramKindNames[$family],
                ]));
            }
        }
        $seen = [];
        $guard = static function (string $lineName, string $labels) use (&$seen): void {
            $id = $lineName . "\1" . $labels;
            if (isset($seen[$id])) {
                throw new \RuntimeException(Lang::t('prom.duplicate_series', [
                    'name'   => Util::displaySafe($lineName),
                    'labels' => Util::displaySafe($labels),
                ]));
            }
            $seen[$id] = true;
        };

        // The textfile is rewritten whole on every WRITE, so each series is
        // emitted exactly once from its accumulator (insertion order). The
        // dirty flags gate that write above (sugar-metrics-024) and are reset
        // here, on the path that actually published.
        foreach ($this->counters as $key => $val) {
            [$name, $labels] = $splits[$key] ?? self::splitKey($key);
            $guard($name, $labels);
            $body .= $this->headerFor($name, 'counter') . $this->metricLine($name, $labels, $val);
        }
        foreach ($this->upDownCounters as $key => $val) {
            [$name, $labels] = $splits[$key] ?? self::splitKey($key);
            $guard($name, $labels);
            $body .= $this->headerFor($name, 'gauge') . $this->metricLine($name, $labels, $val);
        }
        foreach ($this->asyncCounters as $key => $val) {
            [$name, $labels] = $splits[$key] ?? self::splitKey($key);
            $guard($name, $labels);
            $body .= $this->headerFor($name, 'counter') . $this->metricLine($name, $labels, $val);
        }
        foreach ($this->asyncGauges as $key => $val) {
            [$name, $labels] = $splits[$key] ?? self::splitKey($key);
            $guard($name, $labels);
            $body .= $this->headerFor($name, 'gauge') . $this->metricLine($name, $labels, $val);
        }
        foreach ($this->gauges as $key => $val) {
            [$name, $labels] = $splits[$key] ?? self::splitKey($key);
            $guard($name, $labels);
            $body .= $this->headerFor($name, 'gauge') . $this->metricLine($name, $labels, $val);
        }
        foreach ($this->histograms as $key => $h) {
            [$name, $labels] = $splits[$key] ?? self::splitKey($key);
            $body .= $this->headerFor($name, 'histogram');
            // Bucket lines carry a derived identity: the user `le` refusal
            // in histogram() plus the duplicate-series guard below keep the
            // derived family names from racing real metrics of the same name.
            foreach ($this->buckets as $b) {
                $bucketLabels = self::buildBucketLabels($labels, (string) $b);
                $guard($name . '_bucket', $bucketLabels);
                $body .= "{$name}_bucket" . $bucketLabels . ' ' . $h['buckets'][(string) $b] . "\n";
            }
            $infLabels = self::buildBucketLabels($labels, '+Inf');
            $guard($name . '_bucket', $infLabels);
            $body .= "{$name}_bucket" . $infLabels . ' ' . $h['buckets']['+Inf'] . "\n";
            $guard($name . '_count', $labels);
            $body .= "{$name}_count{$labels} {$h['count']}\n";
            $guard($name . '_sum', $labels);
            $body .= "{$name}_sum{$labels} " . self::fmt($h['sum']) . "\n";
        }

        // --- Un-sampled descriptors: declare families the file does not
        // carry samples for. HELP/TYPE without samples is legal exposition
        // and how a textfile consumer learns a family exists and is idle.
        foreach ($this->descriptors as $name => $descriptor) {
            if (isset($this->descriptorEmittedForFlush[$name])) {
                continue;
            }
            $body .= "# HELP {$name} " . self::escapeHelp($descriptor->help) . "\n";
            $body .= "# TYPE {$name} {$descriptor->type}\n";
        }

        $this->dirtyCounters = [];
        $this->dirtyGauges = [];
        $this->dirtyHistograms = [];
        $this->dirtyUpDownCounters = [];
        $this->dirtyAsyncCounters = [];
        $this->dirtyAsyncGauges = [];
        $this->dirtyExposition = false;

        // Staging lifecycle (sugar-metrics-028/029/055/056/057 — one sweep):
        //  * name: `<path>.<pid>-<entropy>.tmp` — ending in `.tmp` is node_exporter's
        //    version-independent ignore rule for textfile directories, so an
        //    in-flight (or crash-residual) staging file is invisible to the
        //    collector regardless of its parsing filters;
        //  * create: `x+` (O_EXCL) fails closed on a taken name and the writer
        //    retries with a fresh entropy draw — the old `c+` open on a
        //    clock-derived uniqid name would publish an attacker-planted or
        //    crash-residual file's bytes verbatim;
        //  * mode: chmod on the staging path (just x+-created) before the rename, so the
        //    published file's exposure is decided here, not by the process
        //    umask ($fileMode opens it up for a reader in another user);
        //  * no flock: per-writer-unique names make contention impossible;
        //    rename(2) alone carries the atomic-publish guarantee;
        //  * durability: fsync before the rename (bytes survive a crash
        //    between staging and publish), then best-effort fsync of the
        //    parent directory (the rename link itself survives power loss);
        //  * residue: every successful publish sweeps stale files matching
        //    this class's own staging pattern past a generous grace window —
        //    names are per-writer-unique and this class is the only writer,
        //    so a deleted stale cannot be a live flush in flight.
        $dir = dirname($this->path);
        $tmp = $this->path . '.' . getmypid() . '-.tmp';
        $fh = false;
        // fopen() raises an E_WARNING *before* returning false when its parent
        // cannot host the file (missing dir, parent is a file, unwritable dir)
        // or when a planted name is taken (x+). The RuntimeException below is
        // this door's documented contract; a taken name is retried in bounded
        // attempts, an unhostable parent is probed first so a real refusal
        // never emits a diagnostic.
        if (is_dir($dir) && is_writable($dir)) {
            for ($attempt = 0; $attempt < self::STAGING_ATTEMPTS; $attempt++) {
                $candidate = $this->path . '.' . getmypid() . '-' . random_int(0, PHP_INT_MAX) . '.tmp';
                $tmp       = $candidate;
                $fh        = @fopen($candidate, 'x+');
                if ($fh !== false) {
                    break;
                }
            }
        }
        if ($fh === false) {
            throw new \RuntimeException(Lang::t('prom.cannot_open', ['path' => Util::displaySafe($tmp)]));
        }
        // PHP core ships no fchmod(); chmod on the staging PATH is the
        // equivalent here — x+ just created this exact inode exclusively, so
        // the path provably still refers to the handle we opened.
        if (@chmod($tmp, $this->fileMode) === false) {
            fclose($fh);
            @unlink($tmp);
            throw new \RuntimeException(Lang::t('prom.chmod_failed', ['path' => Util::displaySafe($tmp)]));
        }
        $failure = null;
        try {
            // Every write syscall reports failure — a discarded fwrite
            // return (disk full: short write) used to publish a truncated
            // body under a good name. The x+ staging file starts empty and
            // needs no truncation. fsync BEFORE the rename so a success
            // means the bytes survive a crash between staging and publish.
            $written = $body === '' ? 0 : fwrite($fh, $body);
            if ($written !== strlen($body)) {
                $failure = 'fwrite';
            } elseif (fflush($fh) === false) {
                $failure = 'fflush';
            } elseif (fsync($fh) === false) {
                $failure = 'fsync';
            }
        } finally {
            fclose($fh);
        }
        if ($failure !== null) {
            @unlink($tmp); // a failed staging body must not leak one file per flush
            throw new \RuntimeException(Lang::t('prom.write_incomplete', [
                'path'   => Util::displaySafe($tmp),
                'reason' => $failure,
            ]));
        }
        if (@rename($tmp, $this->path) === false) {
            if (is_file($tmp)) {
                unlink($tmp); // the unique name would otherwise leak one file per failure
            }
            throw new \RuntimeException(Lang::t('prom.rename_failed', [
                'tmp'  => Util::displaySafe($tmp),
                'dest' => Util::displaySafe($this->path),
            ]));
        }
        // Best-effort: publish the rename into the directory itself. A failure
        // here does not undo the success — the body is durably reachable until
        // a hard power loss steps on the unflushed directory entry.
        $dirh = @fopen($dir, 'r');
        if ($dirh !== false) {
            @fsync($dirh);
            fclose($dirh);
        }
        // Reclaim crash-leaked staging residue (a SIGKILL between create and
        // rename can leave one behind — possibly a COMPLETE body, which an
        // old collector without the .tmp rule would parse as a duplicate
        // family and sink the whole textfile directory).
        foreach (glob($this->path . '.*.tmp') ?: [] as $stale) {
            $age = is_file($stale) ? @filemtime($stale) : false;
            if ($age !== false && $age !== null && time() - $age > self::STAGING_GRACE_SECONDS) {
                @unlink($stale);
            }
        }
    }

    /**
     * Drop one accumulated series (name + label set) from the backend's
     * in-memory state, plus its collision-detector records and family
     * index entry. The storage key is derived exactly like the write path,
     * so this reclaims what counter()/gauge()/histogram()/... stored —
     * including the Registry's cardinality-eviction forward, which arrives
     * with the merged tag set. The next flush() simply no longer emits the
     * removed series; the textfile itself is rewritten whole on every
     * flush, so no stale line survives. remove() consults the same
     * collision record the write path keeps: a call whose raw (name, tags)
     * is NOT the identity recorded under that folded key would reach
     * through the fold and silently wipe an innocent twin, so it is
     * refused exactly like a colliding write; the honest reclaim drops the
     * record with the series, freeing the fold for the twin to claim later.
     *
     * @param array<string,string> $tags
     */
    public function remove(string $name, array $tags = []): void
    {
        $key = $this->storageKey($name, $tags);
        if (isset($this->rawFacts[$key])) {
            // The reclaim path guards the fold exactly like the write path:
            // reaching an occupied key under a DIFFERENT raw identity is
            // the silent innocent-twin wipe the collision detector exists
            // to refuse, not a removal to honour.
            $this->refuseImpostor($name, $tags, $key);
        }
        if ($tags === []) {
            // Symmetry (MET-DESCRIBE): the descriptor ledger is consulted on
            // the reclaim path just like on describe() — a tagless remove()
            // whose fold lands on a family whose descriptor is owned by a
            // different raw name is a twin wipe and is refused.
            $owner = $this->rawDescriptors[$key] ?? null;
            if ($owner !== null && $owner !== $name) {
                throw new \RuntimeException(Lang::t('prom.name_collision', [
                    'first'  => Util::displaySafe(self::describeRaw($owner, [])),
                    'second' => Util::displaySafe(self::describeRaw($name, [])),
                    'folded' => Util::displaySafe($key),
                ]));
            }
        }
        unset(
            $this->counters[$key], $this->dirtyCounters[$key],
            $this->gauges[$key], $this->dirtyGauges[$key],
            $this->histograms[$key], $this->dirtyHistograms[$key],
            $this->upDownCounters[$key], $this->dirtyUpDownCounters[$key],
            $this->asyncCounters[$key], $this->dirtyAsyncCounters[$key],
            $this->asyncGauges[$key], $this->dirtyAsyncGauges[$key],
        );
        $this->evictSideTables($key);
        // Reclaim changes what the next exposition must say (the series
        // disappears) — the per-key dirty unsets above are no longer
        // sufficient for the flush gate (sugar-metrics-024).
        $this->dirtyExposition = true;
    }

    /**
     * Reset the backend: forget every accumulated series, dirty flag,
     * collision record, family index entry, and registered descriptor.
     * The next flush() writes an empty textfile (the file itself is not
     * unlinked — deleting the target from under a watching node_exporter
     * is a deployment decision, not a metrics-library one).
     */
    public function clear(): void
    {
        // sugar-metrics-015: the per-token fold caches are pure functions
        // of their raws, but they ride the instance lifetime — drop them
        // with the ledger so a cleared backend grows nothing.
        $this->nameTokens = [];
        $this->labelTokens = [];
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
        // The cleared state must reach the file even under the flush gate —
        // every per-key flag was just emptied (sugar-metrics-024).
        $this->dirtyExposition = true;
        $this->descriptors = [];
        $this->rawFacts = [];
        $this->rawDescriptors = [];
        $this->familyKeys = [];
    }

    /**
     * Build the storage key for one (name, tags) series and run the
     * collision guards (see key()/sanitizeFold() for the refusal shapes).
     */
    private function key(string $name, array $tags): string
    {
        $key = $this->storageKey($name, $tags);
        if (isset($this->rawFacts[$key])) {
            $this->refuseImpostor($name, $tags, $key);
            return $key;
        }
        $this->rawFacts[$key] = [$name, $tags];
        return $key;
    }

    /**
     * sugar-metrics-058: refuse creating a series under a storage key
     * already held by a DIFFERENT kind map. Without this, counter('x')
     * then gauge('x') both accumulate and every later flush dies on the
     * generic $seen guard with an unattributed duplicate_series — a caller
     * bug that must surface once, at the call site, naming both kinds
     * (same posture as the histogram `le` refusal).
     *
     * @param array<array-key, mixed> $own this verb's own accumulator
     */
    private function refuseKindSwitch(string $key, string $ownKind, array $own): void
    {
        if (isset($own[$key])) {
            return; // same-kind continuation — not a switch
        }
        $others = [
            'counter'         => $this->counters,
            'gauge'           => $this->gauges,
            'histogram'       => $this->histograms,
            'up/down counter' => $this->upDownCounters,
            'async counter'   => $this->asyncCounters,
            'async gauge'     => $this->asyncGauges,
        ];
        foreach ($others as $kind => $map) {
            if ($kind !== $ownKind && isset($map[$key])) {
                throw new \RuntimeException(Lang::t('prom.kind_switch', [
                    'name'   => Util::displaySafe(self::splitKey($key)[0]),
                    'first'  => Util::displaySafe($kind),
                    'second' => Util::displaySafe($ownKind),
                ]));
            }
        }
    }

    /**
     * Refuse an incoming (name, tags) whose fold has landed on a storage
     * key already owned by a different raw identity. One refusal shape is
     * shared by the write path (key()) and the reclaim path (remove()).
     *
     * @param array<string,string> $tags
     */
    private function refuseImpostor(string $name, array $tags, string $key): void
    {
        [$firstRaw, $firstTags] = $this->rawFacts[$key];
        // storageKey ksorts the labels, so tag insertion order cannot
        // change the series identity; compare the pairs in canonical
        // order (order-strict !== refused one series re-published).
        $firstCanonical = $firstTags;
        ksort($firstCanonical);
        $nowCanonical = $tags;
        ksort($nowCanonical);
        if ($firstRaw !== $name || $firstCanonical !== $nowCanonical) {
            throw new \RuntimeException(Lang::t('prom.name_collision', [
                'first'  => Util::displaySafe(self::describeRaw($firstRaw, $firstTags)),
                'second' => Util::displaySafe(self::describeRaw($name, $tags)),
                'folded' => Util::displaySafe(self::sanitizeFold($name, $tags)),
            ]));
        }
    }

    /**
     * Pure (name, tags) → storage key, shared by the guarded write path
     * and remove() so both derive the identical key. The NUL separator
     * cannot collide with the label block: sanitizeName cannot emit NUL.
     *
     * @param array<string,string> $tags
     */
    private function storageKey(string $name, array $tags): string
    {
        // sugar-metrics-015: both sweep families are PURE per-token folds —
        // a metric name or a label key derives the same sanitized token on
        // every emit for the instance lifetime, so each folds memoizes.
        // Per-emit work that genuinely depends on the (variable) tag
        // VALUES — escapeLabel sweeps and the ksort/implode join — stays
        // in-line. A poisoned token is a deterministic function of its
        // raw, so the cached '' below still trips the 054 refusals on
        // every attempt; nothing state-dependent is cached, which removes
        // the stale-memo class entirely. clear() drops the caches with
        // the ledger; growth is bounded by distinct names / label keys,
        // the same axes the exposition ledger already retains per family.
        $sanitized = $this->nameTokens[$name] ??= self::sanitizeName($name);
        // sugar-metrics-054: a name or label key that sanitizes to '' would
        // publish `# TYPE  counter` / ` 5` — node_exporter rejects the ENTIRE
        // file on that parse error, and rewrite-whole-on-flush makes the
        // poison permanent. Refuse at the chokepoint so no accumulator can
        // ever hold such a series (same message family as Descriptor's gates).
        if ($sanitized === '') {
            throw new \RuntimeException(Lang::t('prom.name_empty', ['name' => Util::displaySafe($name)]));
        }
        if ($tags === []) {
            return $sanitized;
        }
        $sorted = $tags;
        ksort($sorted);
        $parts = [];
        foreach ($sorted as $k => $v) {
            $labelKey = $this->labelTokens[(string) $k] ??= self::sanitizeKey((string) $k);
            if ($labelKey === '') {
                throw new \RuntimeException(Lang::t('prom.label_key_empty', [
                    'key'  => Util::displaySafe((string) $k),
                    'name' => Util::displaySafe($sanitized),
                ]));
            }
            $parts[] = $labelKey . '="' . self::escapeLabel((string) $v) . '"';
        }
        return $sanitized . self::KEY_SEPARATOR . '{' . implode(',', $parts) . '}';
    }

    /**
     * Human-readable form of one raw (name, tags) for collision messages.
     *
     * @param array<string,string> $tags
     */
    private static function describeRaw(string $name, array $tags): string
    {
        if ($tags === []) {
            return $name;
        }
        $parts = [];
        foreach ($tags as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return $name . '{' . implode(' ', $parts) . '}';
    }

    /**
     * The exposition-facing rendering of a raw (name, tags) — the shape
     * two distinct raws must never share.
     *
     * @param array<string,string> $tags
     */
    private static function sanitizeFold(string $name, array $tags): string
    {
        $folded = self::sanitizeName($name);
        if ($tags === []) {
            return $folded;
        }
        $sorted = $tags;
        ksort($sorted);
        $parts = [];
        foreach ($sorted as $k => $v) {
            $parts[] = self::sanitizeKey((string) $k) . '=' . $v;
        }
        return $folded . '{' . implode(',', $parts) . '}';
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
     *
     * The fold is many-to-one by design (`a.b` and `a-b` both land on
     * `a_b`); the ambiguity is handled by the collision detector in
     * key(), which refuses the second distinct raw rather than merging
     * it into the first series' slot.
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
     * prefixes with `_` if the first character is a digit. Many-to-one
     * like sanitizeName(); folded-key collisions are refused at key().
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
     * The spec requires `\\` → `\\` and `\<newline>` → `\\n`; every other
     * byte the line-oriented parser could trip over (remaining C0/DEL, the
     * C1 codepoints, TAB) is then swept to `_` so one hostile help string
     * cannot split or truncate the exposition file.
     */
    private static function escapeHelp(string $s): string
    {
        return self::sweepControl(str_replace(["\\", "\n"], ["\\\\", "\\n"], $s));
    }

    /**
     * Escape a label VALUE. Spec escapes are `\\`, `\"` and `\n`; like
     * escapeHelp() the rest of the control alphabet is swept afterwards,
     * which keeps `{le="..."}`-style structure forgeable-free.
     */
    private static function escapeLabel(string $s): string
    {
        return self::sweepControl(str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $s));
    }

    /**
     * Byte-oriented control sweep (see StatsdBackend::wireToken for why
     * the C1 range is matched via its UTF-8 encoding instead of raw
     * bytes — a raw [\x80-\x9F] class shreds valid multi-byte text).
     * Assumes the spec escapes were applied FIRST, so no byte this
     * touches carries escape meaning.
     *
     * After the control pass the string is REPAIRED, never rejected
     * (sugar-metrics-031): the exposition format mandates UTF-8, and one
     * invalid byte (a latin-1 username, a truncated sequence) is enough
     * for a strict textfile consumer to reject the whole file — the same
     * all-series blackout the format guards exist to prevent. Malformed
     * bytes become U+FFFD, the same visible-stand-in policy sugar-core
     * Sanitize::repairUtf8 applies; well-formed UTF-8 passes byte-identical.
     */
    private static function sweepControl(string $s): string
    {
        // 0x0A is already the "\\n" sequence by now; the C0 class still
        // names it for belt-and-braces against callers bypassing escape*().
        $s = (string) \preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', '_', $s);
        if (!\mb_check_encoding($s, 'UTF-8')) {
            $prev = \mb_substitute_character();
            \mb_substitute_character(0xFFFD);
            $s = (string) \mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            \mb_substitute_character($prev);
        }
        return $s;
    }

    /**
     * Build a Prometheus label block for a histogram bucket by appending
     * the `le` (less-than-or-equal) label to an existing label block.
     *
     * The bucket bounds are formatted with the same canonical numeric
     * rules as sample values; `le` is stored as the (string) cast of the
     * boundary (1.0 → "1"), which matches the DEFAULT_BUCKETS goldens and
     * avoids ini-locale decimal separators (strval is locale-independent).
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
     * TYPE/HELP header for one family, emitted at most once per flush
     * cycle. This is the single header machinery (the previous flush()
     * closure and emitMetricLine() carried near-identical copies).
     *
     * A registered descriptor wins over the inferred kind: descriptors are
     * how a sample-only family also declares HELP, and how an idle family
     * is announced (see Descriptor). The textfile collector does NOT
     * require TYPE/HELP for families that carry samples — descriptors
     * declare metadata and idle families, they are not mandatory.
     */
    private function headerFor(string $name, string $inferredType): string
    {
        if (isset($this->typeEmittedForFlush[$name])) {
            return '';
        }
        $this->typeEmittedForFlush[$name] = true;
        if (isset($this->descriptors[$name])) {
            $d = $this->descriptors[$name];
            $this->descriptorEmittedForFlush[$name] = true;
            return "# HELP {$name} " . self::escapeHelp($d->help) . "\n"
                . "# TYPE {$name} {$d->type}\n";
        }
        return "# TYPE {$name} {$inferredType}\n";
    }

    private function metricLine(string $name, string $labels, float $value): string
    {
        $line = "{$name}{$labels} " . self::fmt($value);
        // sugar-metrics-054 belt-and-braces: every publishable sample line
        // must carry a valid metric identifier and a token a Prometheus
        // parser accepts (finite number, NaN, or the canonical +/-Inf).
        // Unreachable while the emit chokepoints hold — repair is
        // impossible at this point, so refuse to publish and name the line.
        if (preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{[^{}]*\})? ([-+.eE0-9]+|NaN|\+Inf|-Inf)$/D', $line) !== 1) {
            throw new \RuntimeException(Lang::t('prom.bad_line', ['line' => Util::displaySafe($line)]));
        }

        return $line . "\n";
    }

    private static function fmt(float $v): string
    {
        // sprintf('%.6f', -INF) is "INF" — sign silently flips and the
        // token is not what Prometheus parsers accept. Spell non-finite
        // with the canonical exposition forms.
        $special = Util::nonFiniteToken($v);
        if ($special !== null) {
            return $special;
        }
        if ($v === floor($v) && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        // %.6f collapses sub-microsecond durations to "0.000000" and prints
        // six digits of noise past 1e15; exponent form keeps both honest.
        if (Util::needsExponentForm($v)) {
            return sprintf('%.6e', $v);
        }
        return sprintf('%.6f', $v);
    }
}
