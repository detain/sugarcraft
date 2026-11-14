<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Lang;
use SugarCraft\Metrics\Backend;
use SugarCraft\Metrics\Descriptor;
use SugarCraft\Metrics\Util;

/**
 * In-memory accumulator. Counters add up, gauges hold the latest
 * value, histograms keep a bounded reservoir of samples. Mostly used
 * for tests and for fanning out to multiple "live" backends via
 * {@see MultiBackend}.
 *
 * The bucket key is `name|tag1=v1|tag2=v2|...` with tags sorted
 * by key, so identical (name, tags) tuples accumulate into the
 * same bucket regardless of caller insertion order.
 *
 * Two independent memory bounds exist, and neither is complete alone:
 *
 * - {@see $maxSamplesPerKey} caps retained histogram SAMPLES per series
 *   via reservoir sampling (Algorithm R): a flood of `histogram()`
 *   calls on one key can never grow that series' memory past the cap,
 *   while keeping a uniform random sample of the whole stream.
 * - {@see $maxSeries} caps the NUMBER of distinct series (0 = unbounded,
 *   the default). Tag-key cardinality — not sample volume — is what
 *   grows unboundedly under hostile input, and the reservoir alone does
 *   not bound it. Set the cap here, or route writes through a
 *   Registry cardinality limit, to keep the total footprint bounded.
 */
final class InMemoryBackend implements Backend
{
    /** @var array<string,float> */
    private array $counters = [];
    /** @var array<string,float> */
    private array $gauges = [];
    /** @var array<string,list<float>> */
    private array $histograms = [];
    /**
     * Total observations seen per histogram key (not just the stored
     * count) — drives reservoir replacement once the cap is reached.
     *
     * @var array<string,int>
     */
    private array $histogramSeen = [];
    /** @var array<string,float> */
    private array $upDownCounters = [];
    /** @var array<string,float> */
    private array $asyncCounters = [];
    /** @var array<string,float> */
    private array $asyncGauges = [];
    /**
     * Insertion-ordered set of every series key currently held (key => true).
     * Drives {@see $maxSeries} eviction: `array_key_first` is O(1) oldest.
     *
     * @var array<string,true>
     */
    private array $seriesOrder = [];

    /** @var array<string,string> sugar-metrics-015: raw metric name → nameToken fold (pure per-name memo, rides clear()). */
    private array $nameTokens = [];

    /**
     * @param int $maxSamplesPerKey Upper bound on retained histogram samples
     *                              per (name, tags) series. Must be >= 1.
     * @param int $maxSeries          Upper bound on distinct series across all
     *                              accumulators; oldest evicted first. 0 (the
     *                              default) means unbounded.
     */
    /**
     * House-idiom factory (sugar-metrics-014): `InMemoryBackend::new()`.
     */
    public static function new(int $maxSamplesPerKey = 4096, int $maxSeries = 0): self
    {
        return new self($maxSamplesPerKey, $maxSeries);
    }

    public function __construct(
        private readonly int $maxSamplesPerKey = 4096,
        private readonly int $maxSeries = 0,
    ) {
        if ($this->maxSamplesPerKey < 1) {
            throw new \InvalidArgumentException(Lang::t('memory.max_samples', ['value' => (string) $this->maxSamplesPerKey]));
        }
        if ($this->maxSeries < 0) {
            throw new \InvalidArgumentException(Lang::t('memory.max_series', ['value' => (string) $this->maxSeries]));
        }
    }

    /**
     * Register a series key for cardinality accounting and evict the
     * oldest series while the configured cap is exceeded. Reads
     * (counterValue & friends) never call this — only writes create
     * series. Eviction is insertion-order FIFO with NO bump-on-touch: the
     * victim is the oldest first-seen series regardless of heat, so
     * hot-series loss under label churn is by design, not an LRU slip
     * (008; the Registry::trackCardinality() twin shares the semantics —
     * an LRU retrofit is owner appetite, this sentence is the honesty).
     */
    private function retainSeries(string $key): void
    {
        if (isset($this->seriesOrder[$key])) {
            return; // Existing series keeps its original insertion rank.
        }
        $this->seriesOrder[$key] = true;
        if ($this->maxSeries === 0) {
            return;
        }
        while (count($this->seriesOrder) > $this->maxSeries) {
            $oldest = array_key_first($this->seriesOrder);
            /** @var string $oldest — non-empty array, so array_key_first is int|string; keys here are strings */
            $this->dropSeries((string) $oldest);
        }
    }

    private function dropSeries(string $key): void
    {
        unset(
            $this->counters[$key],
            $this->gauges[$key],
            $this->histograms[$key],
            $this->histogramSeen[$key],
            $this->upDownCounters[$key],
            $this->asyncCounters[$key],
            $this->asyncGauges[$key],
            $this->seriesOrder[$key],
        );
    }

    public function counter(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->retainSeries($key);
        $this->counters[$key] = ($this->counters[$key] ?? 0.0) + $value;
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->retainSeries($key);
        $this->gauges[$key] = $value;
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->retainSeries($key);
        $seen = ($this->histogramSeen[$key] ?? 0) + 1;
        $this->histogramSeen[$key] = $seen;

        if ($seen <= $this->maxSamplesPerKey) {
            // Reservoir not yet full: keep every sample in arrival order.
            $this->histograms[$key][] = $value;
            return;
        }

        // Reservoir full: Algorithm R — replace a uniformly-random slot with
        // probability maxSamplesPerKey/seen so the retained set stays a
        // uniform random sample of the whole stream. Array length is pinned
        // at the cap, so memory can't grow further under a sample flood.
        // mt_rand (not random_int): statistical sampling quality is identical
        // over the same inclusive [0, seen-1] range, and the CSPRNG is a
        // several-fold per-call tax exactly on the flood path the reservoir
        // exists to guard (finding 046). Bounds are load-bearing — the draw
        // must stay inside the slot range — pinned by
        // testPostCapReservoirDrawStaysInBoundsAndKeepsReplacing.
        $j = mt_rand(0, $seen - 1);
        if ($j < $this->maxSamplesPerKey) {
            $this->histograms[$key][$j] = $value;
        }
    }

    public function upDownCounter(string $name, float $amount, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->retainSeries($key);
        $this->upDownCounters[$key] = ($this->upDownCounters[$key] ?? 0.0) + $amount;
    }

    public function asyncCounter(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->retainSeries($key);
        $this->asyncCounters[$key] = $value;
    }

    public function asyncGauge(string $name, float $value, array $tags = []): void
    {
        $key = $this->key($name, $tags);
        $this->retainSeries($key);
        $this->asyncGauges[$key] = $value;
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

    /**
     * Structured read surface (sugar-metrics-022): every accumulated series
     * as its own row, with the internal storage key decoded back into the
     * faithful name + tags the caller emitted. The raw maps above stay for
     * zero-copy access, but they are keyed by the {@see self::key()}
     * grammar — consumers parsing `name|k=v` by hand re-implement the
     * escaper wrong exactly when a payload carries a separator (an adversar-
     * ial name like 'x|y=1' is indistinguishable from a fold once you split
     * naively), and histogram consumers re-implement the reservoir aggre-
     * gation. Additive: no existing behavior moves.
     *
     * Row shape: name is the emitted name, byte-faithful (this is the con-
     * sumer-side twin of the finding-013 fold guard); tags are in storage
     * (ksort-sorted) order; value is the accumulated scalar — for the
     * histogram kind it is the SUM over the retained reservoir (count and
     * mean ride {@see self::histogramSummary()}).
     *
     * @return list<array{name:string,kind:string,tags:array<string,string>,value:float}>
     */
    public function snapshot(): array
    {
        $rows = [];
        foreach ([
            'counter' => $this->counters,
            'gauge' => $this->gauges,
            'histogram' => $this->histograms,
            'upDownCounter' => $this->upDownCounters,
            'asyncCounter' => $this->asyncCounters,
            'asyncGauge' => $this->asyncGauges,
        ] as $kind => $map) {
            foreach ($map as $key => $sample) {
                [$name, $tags] = self::decodeKey((string) $key);
                $rows[] = [
                    'name' => $name,
                    'kind' => $kind,
                    'tags' => $tags,
                    'value' => $kind === 'histogram'
                        ? array_sum($sample)
                        : (float) $sample,
                ];
            }
        }
        return $rows;
    }

    /**
     * Aggregate view of one histogram series, or null when the series is
     * absent. count/sum/mean describe the RETAINED RESERVOIR (bounded at
     * maxSamplesPerKey); observed is the total number of samples ever seen
     * for the series, so post-cap the two axes are honestly distinct — a
     * reservoir mean is not a stream mean, and pretending otherwise would
     * just move the consumer-boilerplate problem inside the library.
     *
     * @param array<string,string> $tags
     * @return ?array{name:string,tags:array<string,string>,count:int,observed:int,sum:float,mean:float}
     */
    public function histogramSummary(string $name, array $tags = []): ?array
    {
        $key = $this->key($name, $tags);
        if (!isset($this->histograms[$key])) {
            return null;
        }
        $samples = $this->histograms[$key];
        $count = count($samples);
        $sum = array_sum($samples);
        return [
            'name' => $name,
            'tags' => $tags,
            'count' => $count,
            'observed' => $this->histogramSeen[$key] ?? $count,
            'sum' => $sum,
            'mean' => $count > 0 ? $sum / $count : 0.0,
        ];
    }

    /**
     * Invert {@see self::key()} exactly: split on UNESCAPED separators only
     * (backslash leads an escape pair and is skipped as a unit), then un-
     * escape each token. The round-trip property snapshot needs is
     * decodeKey(key($name, $tags)) === [$name, $tags] for every input,
     * adversarial ones included — pinned on the fold twins from finding 013.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private static function decodeKey(string $key): array
    {
        $parts = self::splitUnescaped($key, '|');
        $name = self::decodeToken((string) array_shift($parts));
        $tags = [];
        foreach ($parts as $pair) {
            $kv = self::splitUnescaped($pair, '=');
            $tags[self::decodeToken($kv[0])] = self::decodeToken($kv[1] ?? '');
        }
        return [$name, $tags];
    }

    /**
     * @return list<string>
     */
    private static function splitUnescaped(string $subject, string $separator): array
    {
        $out = [];
        $buffer = '';
        $length = strlen($subject);
        for ($i = 0; $i < $length; $i++) {
            $char = $subject[$i];
            if ($char === '\\' && $i + 1 < $length) {
                // An escape pair rides together; its second byte is payload.
                $buffer .= $char . $subject[++$i];
                continue;
            }
            if ($char === $separator) {
                $out[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $out[] = $buffer;
        return $out;
    }

    private static function decodeToken(string $token): string
    {
        return (string) preg_replace_callback(
            '/\\\\([\\\\|=0])/',
            static fn(array $m): string => ['\\' => '\\', '|' => '|', '=' => '=', '0' => "\0"][$m[1]],
            $token
        );
    }

    /**
     * @return float the accumulated value; a NEVER-COUNTED counter reads
     *               0.0 — indistinguishable from one that counted zero
     *               (007: polarity stated rather than flipped, the flip
     *               being a gated behavior change this doc deliberately
     *               does not take).
     */
    public function counterValue(string $name, array $tags = []): float
    {
        return $this->counters[$this->key($name, $tags)] ?? 0.0;
    }

    /**
     * @return ?float null means "series absent" — the one accessor whose
     *               unknown/zero split is representable (gauge 0.0 is a
     *               meaningful last write); see 007 for the asymmetry map.
     */
    public function gaugeValue(string $name, array $tags = []): ?float
    {
        return $this->gauges[$this->key($name, $tags)] ?? null;
    }

    /** @return list<float> */
    public function histogramValues(string $name, array $tags = []): array
    {
        return $this->histograms[$this->key($name, $tags)] ?? [];
    }

    /** @return float 0.0 for an absent series (007: counter-family polarity — see counterValue()). */
    public function upDownCounterValue(string $name, array $tags = []): float
    {
        return $this->upDownCounters[$this->key($name, $tags)] ?? 0.0;
    }

    /** @return float 0.0 for an absent series (007: counter-family polarity — see counterValue()). */
    public function asyncCounterValue(string $name, array $tags = []): float
    {
        return $this->asyncCounters[$this->key($name, $tags)] ?? 0.0;
    }

    /** @return ?float null means series absent (007: gauge-family polarity — see gaugeValue()). */
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
        // sugar-metrics-015: the fold memo is pure, but it rides the
        // instance — drop it with the ledger.
        $this->nameTokens = [];
        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
        $this->histogramSeen = [];
        $this->upDownCounters = [];
        $this->asyncCounters = [];
        $this->asyncGauges = [];
        $this->seriesOrder = [];
    }

    /** Configured upper bound on retained histogram samples per series. */
    public function maxSamplesPerKey(): int
    {
        return $this->maxSamplesPerKey;
    }

    /** Configured upper bound on distinct series (0 = unbounded). */
    public function maxSeries(): int
    {
        return $this->maxSeries;
    }

    /** Current number of distinct series held. */
    public function seriesCount(): int
    {
        return count($this->seriesOrder);
    }

    /**
     * Remove a specific metric series by name and tags.
     * Removes from whichever accumulator(s) hold a value for that key.
     *
     * @param array<string,string> $tags
     */
    public function remove(string $name, array $tags = []): void
    {
        $this->dropSeries($this->key($name, $tags));
    }

    /**
     * Build a storage key for this backend's accumulator arrays.
     * Format: `name|k1=v1|k2=v2` with BOTH the name segment and every tag
     * key/value escaped by the same escaper ({@see Util::nameToken()} /
     * {@see Util::tagKey()}), tags sorted by key — so a separator inside a
     * tag payload cannot forge a second pair AND an adversarial raw name
     * like 'x|y=1' can never impersonate the fold of 'x' + ['y' => '1']
     * (finding 013; the tag-side sibling is
     * testLookalikeTagSetsDoNotCollapseIntoOneAccumulator). This format is
     * for in-memory storage accumulation (not cardinality tracking).
     *
     * @param array<string,string> $tags
     */
    private function key(string $name, array $tags): string
    {
        // sugar-metrics-015: the name segment is a pure per-name fold and
        // metric names are process-lifetime constants — memoized. The tag
        // segment must run per emit anyway: it indexes the accumulator
        // arrays. The cache rides the instance lifetime (clear() drops it);
        // it stores one short string per distinct raw name, the same axis
        // the accumulator keys already cover.
        $token = $this->nameTokens[$name] ??= Util::nameToken($name);
        if ($tags === []) {
            return $token;
        }
        return $token . '|' . Util::tagKey($tags);
    }
}
