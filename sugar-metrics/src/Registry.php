<?php

declare(strict_types=1);

namespace SugarCraft\Metrics;

use SugarCraft\Metrics\Instrument\AsyncCounter;
use SugarCraft\Metrics\Instrument\AsyncGauge;
use SugarCraft\Metrics\Instrument\UpDownCounter;

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
 * those tags through every call site. A child carries its OWN
 * cardinality budget and does not inherit registered descriptors
 * (register on each instance whose emissions need the TYPE/HELP).
 *
 * When a metric accumulates too many unique label-value
 * combinations (cardinality explosion), the oldest combination
 * is evicted to stay within the configured limit — and the evicted
 * series is also removed from the backend, so the bounded cache
 * keeps the (otherwise unbounded) backend from growing without
 * limit under a tag flood.
 *
 * What the limit does NOT bound: the SET OF METRIC NAMES (every
 * distinct name is unbounded memory in the cache and the backend —
 * never interpolate raw user input into a metric name), and a
 * `withTags()` child gets its own full per-name budget rather than
 * sharing the parent's. The backend may additionally bound series
 * per family (PrometheusFileBackend::maxSeriesPerFamily) and
 * samples per series (InMemoryBackend::maxSamplesPerKey); StatsD
 * holds no local state at all.
 */
final class Registry
{
    /** @var array<string,string> */
    private readonly array $defaultTags;

    /**
     * Precomputed cardinality key for {@see $defaultTags}, so the common
     * `$tags === []` emit path skips re-sorting/joining the default tags
     * on every call.
     */
    private readonly string $defaultTagsKey;

    /** @var array<string, Descriptor> */
    private array $descriptors = [];

    /**
     * Per-metric cardinality cache: metric name → (tag-key → merged tags).
     * The value holds the merged tag set so an evicted series can be removed
     * from the backend by rebuilding its storage key.
     *
     * @var array<string, array<string, array<string,string>>>
     */
    private array $labelValueCache = [];

    /**
     * Maximum unique label-value combinations allowed per metric.
     * When exceeded, {@see trackCardinality()} evicts the oldest entry
     * and reclaims that series from the backend.
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
        if ($cardinalityLimit < 1) {
            // A limit of 0 or less would evict every series the moment it
            // is created: trackCardinality() inserts, sees count > limit,
            // and drops the entry (plus its backend reclaim) on each emit —
            // the registry would silently record nothing. Refuse at the door.
            throw new \InvalidArgumentException(Lang::t('registry.cardinality_limit', ['limit' => (string) $cardinalityLimit]));
        }
        $this->defaultTags = $defaultTags;
        $this->defaultTagsKey = Util::tagKey($defaultTags);
        $this->cardinalityLimit = $cardinalityLimit;
    }

    /** The per-metric label-combination budget (constructor argument). */
    public function cardinalityLimit(): int
    {
        return $this->cardinalityLimit;
    }

    /**
     * Publish the backend's current state (no-op for stream/socket
     * backends that emit as you record). Long-lived servers driving a
     * textfile backend MUST call this on a schedule — the file is only
     * rewritten inside flush(), and nothing in the library arms a timer
     * on the host's event loop.
     */
    public function flush(): void
    {
        $this->backend->flush();
    }

    /**
     * Register a metric descriptor for early TYPE/HELP emission.
     * Re-registering the identical (name, type, help, labelKeys) is a
     * no-op; a SECOND, DIFFERENT descriptor for the same name throws —
     * silently keeping the first would let a copy-pasted registration
     * pin stale TYPE/HELP onto an evolving metric. labelKeys are compared
     * in order (the DTO stores the list as given): a reordering is a
     * different declaration and conflicts like any other change.
     */
    public function register(Descriptor $descriptor): void
    {
        // The instrument verbs never construct a Descriptor, so this door
        // is the only place a DTO-carried name can be re-checked: refuse
        // invalid names (unserialized or otherwise forged past the
        // constructor) so folding aliases cannot be minted here at all.
        // Sanitize-fold aliases are refused downstream by the backend
        // fold-collision map, not by this check.
        if (preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/D', $descriptor->name) !== 1) {
            throw new \InvalidArgumentException(Lang::t('descriptor.name_invalid', ['name' => Util::displaySafe($descriptor->name)]));
        }
        // Keyed by the RAW name: two distinct raw names folding to the same
        // sanitized key pass this map (and every per-Registry ledger); the
        // Prometheus backend's descriptor fold map is what refuses them.
        $existing = $this->descriptors[$descriptor->name] ?? null;
        if ($existing !== null) {
            if ($existing->type === $descriptor->type && $existing->help === $descriptor->help
                && $existing->labelKeys === $descriptor->labelKeys) {
                return;
            }
            throw new \RuntimeException(Lang::t('registry.register_conflict', [
                'name'     => Util::displaySafe($descriptor->name),
                'existing' => Util::displaySafe($existing->type . ': ' . $existing->help),
                'incoming' => Util::displaySafe($descriptor->type . ': ' . $descriptor->help),
            ]));
        }
        $this->descriptors[$descriptor->name] = $descriptor;
        $this->backend->describe($descriptor);
    }

    public function counter(string $name, float $value = 1.0, array $tags = []): void
    {
        // A monotonic counter only accumulates: a negative add would drive
        // the stored total backwards and (on the textfile backend) emit a
        // COUNTER sample that violates its own TYPE. Refuse the misuse.
        $this->refuseNegative($name, $value, 'counter');
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
        // Histogram observations feed cumulative `le` bucket counts; a
        // negative sample lands below every classic (non-negative) bucket
        // boundary and would be counted ONLY by +Inf — a sum that moves
        // while no visible bucket does. Refuse at the door.
        $this->refuseNegative($name, $value, 'histogram');
        $this->backend->histogram($name, $value, $this->mergeTags($tags));
        $this->trackCardinality($name, $tags);
    }

    /**
     * @param array<string,string> $tags
     */
    private function refuseNegative(string $name, float $value, string $kind): void
    {
        if ($value < 0) {
            throw new \RuntimeException(Lang::t('registry.negative_monotonic', [
                'name'  => Util::displaySafe($name),
                'value' => Util::displaySafe((string) $value),
                'kind'  => $kind,
            ]));
        }
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
     * Factory: create a new UpDownCounter instrument for repeated add() calls.
     *
     * @param array<string,string> $tags Static tags applied on every add() call.
     */
    public function newUpDownCounter(string $name, string $help = '', array $tags = []): Instrument\UpDownCounter
    {
        return new Instrument\UpDownCounter($this, $name, $help, $tags);
    }

    /**
     * Factory: create a new AsyncCounter instrument. Nothing collects on a
     * schedule — call $instrument->observe() yourself (typically from your
     * own periodic tick or a shutdown hook) to sample the callback now.
     *
     * @param \Closure(): float $callback Called on every observe() to obtain the current value.
     * @param array<string,string> $tags Static tags attached to observations.
     */
    public function newAsyncCounter(string $name, string $help, \Closure $callback, array $tags = []): Instrument\AsyncCounter
    {
        return new Instrument\AsyncCounter($this, $name, $help, $callback, $tags);
    }

    /**
     * Factory: create a new AsyncGauge instrument. Nothing collects on a
     * schedule — call $instrument->observe() yourself to sample the
     * callback (an exporter's scrape cadence lives in the host, not here).
     *
     * @param \Closure(): float $callback Called on every observe() to obtain the current value.
     * @param array<string,string> $tags Static tags attached to observations.
     */
    public function newAsyncGauge(string $name, string $help, \Closure $callback, array $tags = []): Instrument\AsyncGauge
    {
        return new Instrument\AsyncGauge($this, $name, $help, $callback, $tags);
    }

    /**
     * Start an elapsed-time timer. Returns a closure — when invoked,
     * it records the elapsed seconds as a histogram under `$name`.
     * The pair reads the monotonic clock (`hrtime()`), not wall time:
     * a system clock step (NTP correction, manual set) can never turn
     * a duration negative in a `finally` arm that rethrows.
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
     * The recording is exactly-once: a second invocation (success path
     * plus a `finally` that also fires) returns the elapsed seconds but
     * does NOT sample the histogram again.
     *
     * @param array<string,string> $tags
     * @return callable(): float
     */
    public function time(string $name, array $tags = []): callable
    {
        $start = hrtime(true);
        $done = false;
        return function () use ($name, $tags, $start, &$done): float {
            // Monotonic nanoseconds → seconds. A CLOCK_REALTIME pair (what
            // microtime reads give) stepped back between the two samples
            // yields a negative elapsed, which histogram() refuses — an
            // exception raised inside the caller's finally arm.
            $elapsed = (hrtime(true) - $start) / 1e9;
            if ($done === false) {
                $done = true;
                $this->histogram($name, $elapsed, $tags);
            }
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
     * Drop one label-combination entry from the cardinality tracker.
     *
     * `$tags` are the call-site tags — the registry's default tags are
     * merged in first, because the tracker keys entries by the MERGED
     * set that the emit path stored. Backend series are NOT touched by
     * this method; use {@see remove()} for a full reclaim.
     *
     * @param array<string,string> $tags
     */
    public function deleteLabelValues(string $name, array $tags = []): void
    {
        unset($this->labelValueCache[$name][$this->tagKey($this->mergeTags($tags))]);
    }

    /**
     * Remove a specific metric series from the backend and the
     * cardinality cache. Useful for long-running processes or
     * testing cleanup. Backends that keep no retained state
     * (StatsD, JSON stream) treat the removal as a no-op; the
     * tracker entry is always dropped.
     *
     * `$tags` are the call-site tags; the default tags are merged in
     * once here so both the backend key and the tracker key see the
     * same label set the emit path used.
     *
     * @param array<string,string> $tags
     */
    public function remove(string $name, array $tags = []): void
    {
        $merged = $this->mergeTags($tags);
        $this->backend->remove($name, $merged);
        unset($this->labelValueCache[$name][$this->tagKey($merged)]);
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
     * the per-metric cardinality limit is exceeded. Eviction is
     * insertion-order FIFO with NO bump-on-touch — an isset cache hit
     * returns early without moving the entry, so the victim is the oldest
     * first-seen combination regardless of heat; hot-series loss under
     * churn is by design (008; mirrors InMemoryBackend::retainSeries()).
     *
     * Eviction also removes the evicted series from the backend, so a
     * flood of unique tag combinations can't grow backend memory without
     * bound — the cache is capped, but the backend is not. The merged tags
     * are stored as the cache value precisely so the backend key can be
     * rebuilt at eviction time.
     *
     * @param array<string,string> $tags
     */
    private function trackCardinality(string $name, array $tags): void
    {
        // Fast path for the no-tags emit: reuse the precomputed default key
        // instead of re-sorting/joining the default tags every call.
        if ($tags === []) {
            $merged = $this->defaultTags;
            $key = $this->defaultTagsKey;
        } else {
            $merged = array_merge($this->defaultTags, $tags);
            $key = $this->tagKey($merged);
        }
        if (isset($this->labelValueCache[$name][$key])) {
            return;
        }
        if (isset($this->labelValueCache[$name]) === false) {
            $this->labelValueCache[$name] = [];
        }
        $this->labelValueCache[$name][$key] = $merged;
        if (count($this->labelValueCache[$name]) > $this->cardinalityLimit) {
            $oldestMergedTags = reset($this->labelValueCache[$name]);
            $oldestKey = key($this->labelValueCache[$name]);
            unset($this->labelValueCache[$name][$oldestKey]);
            // Evicting from the bounded cache alone leaks the series in the
            // unbounded backend — reclaim it so a flood of unique tag combos
            // can't grow backend memory without limit (DoS).
            $this->backend->remove($name, $oldestMergedTags);
        }
    }

    /**
     * Build a stable tag-only string key for cardinality tracking.
     * Returns sorted, backslash-escaped `k=v|k2=v2` (see
     * {@see Util::tagKey()}; no metric name prefix) so separators inside
     * tag payloads cannot forge additional pairs.
     * Distinct from InMemoryBackend::key() which includes the metric name
     * and uses a different separator for storage accumulation.
     *
     * @param array<string,string> $tags
     */
    private function tagKey(array $tags): string
    {
        return Util::tagKey($tags);
    }
}
