# sugarcraft/candy-metrics

## Metadata
- **URL:** https://github.com/sugarcraft/candy-metrics
- **Language:** PHP 8.3+
- **License:** MIT
- **Package:** `sugarcraft/candy-metrics`
- **Namespace:** `SugarCraft\Metrics`
- **Status:** 🟢 v1 ready

## Description

Lightweight telemetry primitives for SugarCraft / CandyWish servers. Provides counters, gauges, histograms, up-down counters, and async instruments with pluggable backends (in-memory, JSON stream, StatsD UDP, Prometheus textfile, multi-backend fanout). Includes a drop-in middleware for SSH session metrics that wires into the CandyWish server stack.

---

## Feature List

### Metric Instruments
- **Counter** — Monotonically accumulating integer/float (connection counts, error counts)
- **Gauge** — Instantaneous value that replaces on set (queue depth, RSS memory)
- **Histogram** — Distribution of samples (latency, payload size) with configurable/classic bucket boundaries
- **UpDownCounter** — Synchronous counter supporting positive and negative increments (active connections, queue size)
- **AsyncCounter** — Asynchronous monotonic counter observed at collection time via callback (JVM GC counts, DB pool size)
- **AsyncGauge** — Asynchronous non-monotonic gauge observed at collection time via callback (memory usage, queue depth)

### Backend Implementations
- **InMemoryBackend** — Accumulator for tests; counters add up, gauges hold latest, histograms keep all samples
- **JsonStreamBackend** — Newline-delimited JSON emitter to file/stderr/socket; one event per line with ts/kind/name/value/tags
- **StatsdBackend** — UDP datagrams in etsy/DogStatsD wire format; supports `dogstatsd: false` for legacy tag-free mode
- **PrometheusFileBackend** — Atomic rewrite of Prometheus textfile collector file; 14 classic cumulative `le` buckets +Inf, proper TYPE/HELP lines, label escaping, flock serialization
- **MultiBackend** — Fanout to multiple backends simultaneously (e.g. live StatsD + JSON audit trail)

### Registry Facade
- **Central Registry** — Application-facing facade forwarding to Backend; backends are swappable config, call sites stay agnostic
- **Descriptor Registration** — DTO for metric name/help/type/labelKeys; enables early TYPE/HELP pre-emission before samples recorded
- **time() Helper** — Returns a closure that records elapsed wall-clock seconds as a histogram
- **withTags()** — Returns a child registry with pre-merged default tags (merged on top of existing defaults)
- **Cardinality Management** — Tracks per-metric label-value combinations; FIFO eviction when exceeding limit (default 10,000); prevents memory exhaustion from unbounded high-cardinality labels
- **deleteLabelValues()** — Manual eviction for explicit cleanup (session teardown)

### Middleware
- **SessionMetrics** — CandyWish middleware emitting session telemetry: `wish.session.connect` (counter), `wish.session.duration` (histogram), `wish.session.error` (counter with exception label). Tags: `user`, `term`, plus optional `extraTags` callable for custom labels (client subnet, geo, build version).

### Internationalization
- **Lang facade** — Extends `SugarCraft\Core\I18n\Lang` with `metrics` namespace; 19 supported locales (en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar)

---

## Source Tree

```
candy-metrics/
├── composer.json
├── phpunit.xml
├── README.md
├── CALIBER_LEARNINGS.md
├── .vhs/
│   ├── showcase.tape
│   └── showcase.gif
├── examples/
│   ├── showcase.php               — counter/gauge/histogram primitives, InMemoryBackend
│   └── wish-with-metrics.php        — CandyWish + CandyMetrics integration
├── lang/
│   ├── en.php (source of truth)
│   └── [17 other locales]
└── src/
    ├── Backend.php                 — Interface: counter/gauge/histogram/upDownCounter/asyncCounter/asyncGauge
    ├── Descriptor.php               — Registration DTO: name/help/type/labelKeys
    ├── Registry.php                 — Application facade with cardinality tracking
    ├── Lang.php                    — i18n facade extending SugarCraft\Core\I18n\Lang
    ├── Instrument/
    │   ├── AsyncCounter.php        — Async monotonic counter via callback
    │   ├── AsyncGauge.php          — Async instantaneous gauge via callback
    │   └── UpDownCounter.php      — Sync up/down counter with add(amount, tags)
    ├── Backend/
    │   ├── InMemoryBackend.php    — Test accumulator, key = "name|tag1=v1|..."
    │   ├── JsonStreamBackend.php  — NDJSON emitter, target = path/resource/null (stderr)
    │   ├── StatsdBackend.php       — UDP emitter, dogstatsd mode, silent drop on failure
    │   ├── PrometheusFileBackend.php — Atomic .prom file, 14 classic buckets, flock rename
    │   └── MultiBackend.php        — Fanout to N backends
    └── Middleware/
        └── SessionMetrics.php      — wish.Middleware: connect/duration/error per session
```

---

## Metric Type Analysis

### Counter (`Registry::counter()`)
**Behavior:** Monotonically accumulating. Each `counter(name, value, tags)` adds `value` to the running sum for that name+label combination.

**Implementation detail (InMemoryBackend):**
```php
// src/Backend/InMemoryBackend.php:34-38
public function counter(string $name, float $value, array $tags = []): void
{
    $key = $this->key($name, $tags);
    $this->counters[$key] = ($this->counters[$key] ?? 0.0) + $value;
}
```

**Key property:** `key()` sorts tags alphabetically, so `['a'=>'x','b'=>'y']` and `['b'=>'y','a'=>'x']` accumulate into the same bucket. The `|` delimiter separates name from tag pairs, and `=` separates tag key from value.

**Cardinality tracking:** Registry wraps every emit in `trackCardinality()` which checks if this is a new (name, tag-set) combination and evicts the oldest via FIFO when over limit.

### Gauge (`Registry::gauge()`)
**Behavior:** Instantaneous value. Each `gauge(name, value, tags)` replaces the previous value.

**Implementation detail (InMemoryBackend):**
```php
// src/Backend/InMemoryBackend.php:40-43
public function gauge(string $name, float $value, array $tags = []): void
{
    $this->gauges[$this->key($name, $tags)] = $value;
}
```

**Use cases:** Queue depth, memory usage, temperature — values that jump around and don't have a natural accumulation semantics.

### Histogram (`Registry::histogram()`)
**Behavior:** Records a sample into the distribution. Backends decide how to aggregate.

**PrometheusFileBackend bucket accumulation:**
```php
// src/Backend/PrometheusFileBackend.php:69-88
public function histogram(string $name, float $value, array $tags = []): void
{
    $key = $this->key($name, $tags);
    $buckets = [];
    foreach (self::BUCKETS as $b) {
        $buckets[(string) $b] = 0;
    }
    $buckets['+Inf'] = 0;
    $h = $this->histograms[$key] ?? ['count' => 0, 'sum' => 0.0, 'buckets' => $buckets];
    $h['count']++;
    $h['sum'] += $value;
    foreach (self::BUCKETS as $b) {
        if ($value <= $b) {
            $h['buckets'][(string) $b]++;
        }
    }
    // +Inf bucket always gets the sample
    $h['buckets']['+Inf']++;
    $this->histograms[$key] = $h;
}
```

**Classic 14 buckets:** `0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 25.0, 50.0, 100.0`. Buckets are **cumulative** — a sample that falls in `le=0.1` is also counted in `le=0.25`, `le=0.5`, etc. up to `+Inf` which equals total count. This is the Prometheus client library v2 semantics.

**InMemoryBackend:** stores all samples as `list<float>` — no aggregation, keeps raw distribution.

**JSONStreamBackend:** emits one `{"kind":"histogram",...}` line per sample — no bucketing, consumer aggregates.

**StatsdBackend:** emits `|h` (histogram type) —DogStatsD supports timing histograms natively.

### UpDownCounter (`Registry::upDownCounter()` + `Instrument\UpDownCounter`)
**Behavior:** Supports positive and negative increments. Use for values that naturally go up and down (active connections, items in queue).

```php
// src/Backend/InMemoryBackend.php:50-54
public function upDownCounter(string $name, float $amount, array $tags = []): void
{
    $key = $this->key($name, $tags);
    $this->upDownCounters[$key] = ($this->upDownCounters[$key] ?? 0.0) + $amount;
}
```

The `Instrument\UpDownCounter` wrapper provides a fluent API:
```php
$connCounter = $reg->upDownCounter('server.active_connections', ['host' => $host]);
$connCounter->add(1);    // connection opened
$connCounter->add(-1);    // connection closed
```

### AsyncCounter (`Registry::asyncCounter()` + `Instrument\AsyncCounter`)
**Behavior:** Value is owned by external system, read at collection time via callback. Each `observe()` invokes the closure and records the result.

```php
// src/Instrument/AsyncCounter.php:38-41
public function observe(): void
{
    $this->registry->asyncCounter($this->name, ($this->callback)(), $this->tags);
}
```

**Cardinaltiy note:** AsyncCounter tracks cardinality on `observe()` calls, not on callback value — the external value doesn't affect cardinality.

### AsyncGauge (`Registry::asyncGauge()` + `Instrument\AsyncGauge`)
**Behavior:** Non-monotonic instantaneous reading from external system (memory, queue depth, temperature). Unlike AsyncCounter, the value is not expected to be monotonically increasing.

**OpenTelemetry alignment:** Both AsyncCounter and AsyncGauge mirror `opentelemetry.io/api/metrics` async instrument semantics. The callback is called once per `observe()` invocation, not continuously.

---

## Backend Comparison

| Backend | Counter | Gauge | Histogram | upDown | asyncCounter | asyncGauge | Export Format |
|---------|---------|-------|----------|--------|-------------|------------|---------------|
| **InMemoryBackend** | accumulates | replaces | keeps all samples | accumulates | replaces | replaces | PHP arrays (for tests) |
| **JsonStreamBackend** | emit | emit | emit | emit | emit | emit | NDJSON (`{"ts","kind","name","value","tags"}`) |
| **StatsdBackend** | `name:val|c` | `name:val|g` | `name:val|h` | `name:val|g` | `name:val|c` | `name:val|g` | UDP datagrams |
| **PrometheusFileBackend** | accumulates | replaces | 14 buckets + count/sum | accumulates | replaces | replaces | Prometheus textfile `.prom` |
| **MultiBackend** | fanout | fanout | fanout | fanout | fanout | fanout | delegates to all children |

### StatsD Backend Wire Format

```
counter:   hits:1|c                        (DogStatsD: + |#route:/x,env:prod)
gauge:     queue_depth:42|g
histogram: lat:0.005|h
```

**dogstatsd=false mode** drops the `|#tag:value,...` segment for legacy etsy StatsD servers that reject tags.

### Prometheus Textfile Format

```
# HELP http_request_duration HTTP request duration in seconds
# TYPE http_request_duration histogram
http_request_duration_bucket{method="GET",route="/api",le="0.005"} 0
http_request_duration_bucket{method="GET",route="/api",le="0.01"} 1
...
http_request_duration_bucket{method="GET",route="/api",le="+Inf"} 5
http_request_duration_count{method="GET",route="/api"} 5
http_request_duration_sum{method="GET",route="/api"} 0.342

# TYPE hits counter
hits{method="GET",route="/api"} 42
```

**Atomicity:** `flush()` writes to `<path>.tmp`, acquires `LOCK_EX`, truncates, writes, flushes, releases lock, then `rename()` over original. Concurrent writers serialize via flock.

---

## Middleware Integration

### SessionMetrics (`src/Middleware/SessionMetrics.php`)

Implements `SugarCraft\Wish\Middleware` (signature: `handle(Context, Session, callable): void`).

**Metrics emitted per session:**

| Metric | Type | Tags |
|--------|------|------|
| `wish.session.connect` | counter (+1) | `user`, `term` |
| `wish.session.duration` | histogram (seconds) | `user`, `term` |
| `wish.session.error` | counter (+1) | `user`, `term`, `exception` |

**Flow:**
1. Merges tags: `user` + `term` from session + optional `extraTags` callable
2. Increments `wish.session.connect` BEFORE calling `$next`
3. Starts wall-clock timer
4. Calls `$next($ctx, $session)`
5. If throw: increments `wish.session.error` with `exception` label, re-throws
6. `finally`: stops timer, records duration histogram

```php
// src/Middleware/SessionMetrics.php:42-59
public function handle(Context $ctx, Session $session, callable $next)
{
    $tags = ['user' => $session->user, 'term' => $session->term];
    if ($this->extraTags !== null) {
        $tags = array_merge($tags, ($this->extraTags)($session));
    }
    $this->registry->counter('wish.session.connect', 1.0, $tags);
    $stop = $this->registry->time('wish.session.duration', $tags);

    try {
        $next($ctx, $session);
    } catch (\Throwable $e) {
        $this->registry->counter('wish.session.error', 1.0, $tags + ['exception' => $e::class]);
        throw $e;
    } finally {
        $stop();
    }
}
```

**CandyWish integration:**
```php
// examples/wish-with-metrics.php
Server::new()
    ->use(new Logger())
    ->use(new SessionMetrics($registry))
    ->use(new class implements \SugarCraft\Wish\Middleware {
        public function handle(Session $s, callable $next): void {
            echo "Hello, {$s->user}!\n";
            $next($s);
        }
    })
    ->serve();
```

---

## Labels/Tags Handling

**Tag representation:** `array<string,string>` — associative array of key-value pairs.

**Key canonicalization:** Tags are sorted alphabetically by key before computing the storage key (`ksort($tags)`). This ensures `['a'=>'x','b'=>'y']` and `['b'=>'y','a'=>'x']` produce identical keys.

**Tag merging:** `withTags()` creates a child registry where default tags are merged under `array_merge($this->defaultTags, $tags)`. Call-site tags override defaults (later array entries win).

**Tag key construction (InMemoryBackend):**
```php
// src/Backend/InMemoryBackend.php:113-124
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
```

**Prometheus label escaping (PrometheusFileBackend):**
```php
// src/Backend/PrometheusFileBackend.php:189-192
private static function escapeLabel(string $s): string
{
    return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $s);
}
```

**StatsD tag format:** `|#k1:v1,k2:v2` for DogStatsD mode. Empty tags produce no `|#...` segment.

---

## Aggregation Strategies

### InMemoryBackend
- **Counter:** Accumulates via addition. Key is unique per (name, tag-set).
- **Gauge:** Replace. Last writer wins.
- **Histogram:** Appends to `list<float>`. No aggregation — keeps raw samples for exact distribution analysis.
- **UpDownCounter:** Accumulates (positive and negative).
- **AsyncCounter/AsyncGauge:** Replace (last observed value wins).

### PrometheusFileBackend
- **Counter:** Accumulates across `flush()` calls — persists in memory between flushes.
- **Gauge:** Replaces on each set.
- **Histogram:** Accumulates count/sum/buckets in memory; `flush()` emits all accumulated buckets. Counter accumulation means short-lived processes can write incremental data to the textfile between flushes without losing data.
- **UpDownCounter:** Accumulates.

### JSONStreamBackend
No aggregation — one NDJSON line per emit. Consumer (Logstash, etc.) does aggregation.

### StatsdBackend
No aggregation — one UDP packet per emit. StatsD server (Carbon/Graphite, Datadog Agent, etc.) does aggregation server-side.

---

## Export Formats

### NDJSON (JsonStreamBackend)
```json
{"ts":"2026-05-02T16:30:00+00:00","kind":"counter","name":"hits","value":1,"tags":{"route":"/x"}}
{"ts":"2026-05-02T16:30:01+00:00","kind":"histogram","name":"lat","value":0.042,"tags":{}}
```

### etsy StatsD / DogStatsD
```
hits:1|c|#route:/x,env:prod
queue_depth:42|g
lat:0.042|h|#route:/x
```

### Prometheus Textfile
```
# HELP lat Request latency in seconds
# TYPE lat histogram
lat_bucket{le="0.005"} 0
lat_bucket{le="0.01"} 2
...
lat_bucket{le="+Inf"} 100
lat_count 100
lat_sum 4.200000
# TYPE hits counter
hits 42
```

---

## Test Coverage

**41 tests** across:
- `RegistryTest` — counter accumulation, gauge replacement, histogram appends, time() closure, withTags(), tag merging, key canonicalization
- `CardinalityTest` — FIFO eviction, per-metric independence, DeleteLabelValues, 10,000 default limit, async/sync instrument cardinality
- `DescriptorTest` — valid types, empty name/help/type validation, empty label key rejection
- `HistogramBucketsTest` — bucket accumulation, bucket order, +Inf equality, tag labels
- `SessionMetricsTest` — connect/duration/error recording, exception propagation, extraTags callable
- `AsyncCounterTest` / `AsyncGaugeTest` — callback invocation, value updating, tags, name/help accessors
- `UpDownCounterTest` — increment/decrement, negative values, tags
- `Backend/InMemoryBackendTest` — (implicit via other tests)
- `Backend/StatsdBackendTest` — counter/histogram/gauge wire format, legacy mode drops tags, integer formatting
- `Backend/JsonStreamBackendTest` — line count, kind/name/value/tags extraction, invalid target rejection
- `Backend/MultiBackendTest` — fanout to all children, empty multi is no-op
- `Backend/PrometheusFileBackendTest` — counter/gauge/histogram emission, label rendering, escaping, atomic flush

---

## Upstream Comparison

### charmbracelet/promwish (primary upstream)

| Aspect | promwish (Go) | candy-metrics (PHP) |
|--------|---------------|---------------------|
| **Metric types** | CounterVec only (sessions_created, sessions_finished, sessions_duration_seconds) | Counter, Gauge, Histogram, UpDownCounter, AsyncCounter, AsyncGauge |
| **Duration representation** | Cumulative counter (total seconds) | Proper histogram with 14 classic bucket boundaries |
| **Labels** | `command` only | Arbitrary tags via `array<string,string>` |
| **Backend** | HTTP + Prometheus registerer only | InMemory, JSON, StatsD UDP, Prometheus textfile, MultiBackend fanout |
| **Cardinality management** | None — unbounded | FIFO eviction at 10,000 per metric |
| **Session metadata** | `command` label extracted via `CommandFn` | `user`, `term`, `exception` labels |
| **Async instruments** | None | AsyncCounter, AsyncGauge with callback |
| **Export protocol** | Prometheus scrape via HTTP | Prometheus textfile, StatsD UDP, NDJSON, in-memory |
| **Middleware interface** | `wish.Middleware` | `SugarCraft\Wish\Middleware` |
| **Atomic flush** | N/A (HTTP scrape) | File rename via flock |
| **Descriptor/HELP pre-emit** | Via Prometheus registration | Via `Registry::register(Descriptor)` |
| **Graceful shutdown** | Signal handling for HTTP server | Destructor calls `flush()` automatically |

### charmbracelet/wish (session middleware context)

The `SessionMetrics` middleware bridges `candy-metrics` into the `candy-wish` SSH server. This mirrors how `promwish` bridges `charmbracelet/promwish` into `charmbracelet/wish`.

Key differences in session metadata:
- **promwish:** only `command` label via `CommandFn`
- **candy-metrics:** `user`, `term` always; `exception` on error; `extraTags` callable for arbitrary additional labels (client subnet, geo, build version)

### Additional Third-Party Metric/Telemetry Repos

No other metric or telemetry-specific repos are mapped in `repo_map.md`. Observability-related mentions appear in:
- **charmbracelet/soft-serve** — Prometheus metrics via `promauto` counters for auth attempts
- **charmbracelet/catwalk** — Built-in request counting via `promwish`
- **charmbracelet/confettysh** — Prometheus metrics on separate port via `promwish`

None of these are telemetry libraries themselves — they are consumers of `promwish`.

---

## Strengths

1. **Multi-backend flexibility** — Single metrics API with pluggable backends for different environments (dev/test: InMemory; prod: StatsD or Prometheus textfile; debugging: NDJSON to stderr). No lock-in to a single export format.

2. **Proper histogram semantics** — Uses cumulative classic bucket boundaries (`le`) matching Prometheus client library v2 semantics. Enables `histogram_quantile()` queries in Prometheus. In-memory backend keeps raw samples for exact distribution.

3. **Cardinality management** — The FIFO eviction at 10,000 label combinations per metric prevents memory exhaustion from unbounded high-cardinality labels (e.g. per-request `request_id`). This is a real production concern the Go original doesn't address.

4. **OpenTelemetry alignment** — AsyncCounter and AsyncGauge mirror the OpenTelemetry async instrument API. Callbacks are invoked at collection time, not continuously. This makes the library compatible with OpenTelemetry SDK collecting cycles.

5. **Descriptor pre-registration** — `Registry::register(Descriptor)` enables early TYPE/HELP emission before samples arrive. Required for Prometheus textfile collector to show metric metadata immediately on first scrape.

6. **Atomic Prometheus flush** — `flock(LOCK_EX)` + rename ensures concurrent writers don't corrupt the textfile and scrapers never see partial writes.

7. **Rich session middleware** — `SessionMetrics` includes exception labels, extensible via `extraTags` callable, and composes naturally into the CandyWish middleware stack.

8. **Comprehensive i18n** — 18 locale files, proper `Lang` facade extending `SugarCraft\Core\I18n\Lang`.

9. **Well-tested** — 41 tests covering all instruments, all backends, cardinality, descriptors, and middleware integration.

---

## Weaknesses / Gaps

1. **No HTTP metrics server** — Unlike Go's `promwish` which starts an HTTP server on a port to serve `/metrics` for Prometheus scraping, `candy-metrics` requires an external component (node_exporter textfile collector, StatsD server, or similar) to expose metrics. There is no built-in PHP HTTP server for metrics.

2. **No summary type** — `Descriptor` accepts `summary` as a valid type but no backend implements it. The `summary` quantile estimation approach (pre-computed percentiles) is different from histograms and requires different handling.

3. **No atomic multi-counter** — Counters in `PrometheusFileBackend` accumulate across `flush()` calls (good for incremental updates), but if a process crashes between flushes, data is lost. The Go Prometheus client handles this via transactional isolation.

4. **No OpenTelemetry export backend** — No backend emits in OTLP format. The library could benefit from a `OtlpBackend` that sends via HTTP/Protobuf to an OTEL collector.

5. **Histogram buckets are hardcoded** — The 14 classic buckets are defined as a private constant in `PrometheusFileBackend`. There's no API to configure custom bucket boundaries. Some use cases need p99.99 boundaries or latency-specific ranges.

6. **No push gateway support** — The Prometheus ecosystem has a `pushgateway` for ephemeral jobs that can't be scraped. `candy-metrics` has no `PushGatewayBackend`.

7. **StatsD silent drop on failure** — UDP writes use `@fwrite()` to suppress failures. This is intentional ("telemetry that crashes the host process is worse than missing telemetry") but means misconfigured backends fail silently.

8. **MultiBackend failure propagation** — `MultiBackend` exceptions propagate to the caller. A single failing backend (e.g. StatsD unreachable) can crash the metrics call site. No isolation between backends.

9. **No exemplar/tracing integration** — OpenTelemetry exemplars allow linking metrics to traces. No backend stores or emits trace IDs alongside metric samples.

---

## SugarCraft Ecosystem Position

### Dependency Chain
- `candy-metrics` depends on `candy-core` (base TUI primitives)
- `candy-metrics` is a peer of `candy-wish` (SSH server); `SessionMetrics` middleware bridges them

### Related Libraries
- `candy-log` — Logging primitives (observability sibling — different axis)
- `candy-core` — Provides `SugarCraft\Core\I18n\Lang` which `candy-metrics\Lang` extends
- `candy-wish` — SSH server whose middleware stack `SessionMetrics` integrates into

### Dependency Order in Monorepo
`candy-core` → `candy-sprinkles` → `honey-bounce` → `candy-zone` → `sugar-bits` → leaf libs including `candy-metrics`

---

## Notable Implementation Details

### Time Helper Closure
```php
// src/Registry.php:138-146
public function time(string $name, array $tags = []): callable
{
    $start = microtime(true);
    return function () use ($name, $tags, $start): float {
        $elapsed = microtime(true) - $start;
        $this->histogram($name, $elapsed, $tags);
        return $elapsed;
    };
}
```
Returns a closure that records elapsed seconds as a histogram AND returns the float for direct use. Pattern mirrors OpenTelemetry's `RecordBatch` API.

### Cardinality FIFO Eviction
```php
// src/Registry.php:203-218
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
```
Uses `reset()` + `key()` to get the **first** inserted array key — PHP's internal array iteration order is insertion-ordered for string keys, making this a correct FIFO queue without explicit timestamp tracking.

### Integer Formatting for StatsD
```php
// src/Backend/StatsdBackend.php:88-94
private static function fmt(float $v): string
{
    if ($v === floor($v) && abs($v) < 1e15) {
        return (string) (int) $v;
    }
    return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
}
```
Strips unnecessary trailing zeros from floats — `5.0` becomes `5`, `0.005` stays `0.005`, preserving StatsD wire efficiency.

### Label Canonicalization
```php
// src/Registry.php:227-238
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
```
Sorted keys ensure stable string keys regardless of insertion order — identical tag sets produce identical cache keys.

### Lang Facade Pattern
```php
// src/Lang.php:18-22
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'metrics';
    protected const DIR = __DIR__ . '/../lang';
}
```
Extends `SugarCraft\Core\I18n\Lang` with per-library namespace. All translation lookups automatically use the `metrics` namespace. Source of truth is `lang/en.php`.

---

## Files and Line References

| File | Lines | Purpose |
|------|-------|---------|
| `src/Backend.php` | 63 | Core interface — 6 metric types |
| `src/Descriptor.php` | 45 | Registration DTO |
| `src/Registry.php` | 239 | Facade + cardinality |
| `src/Instrument/AsyncCounter.php` | 52 | Async monotonic counter |
| `src/Instrument/AsyncGauge.php` | 52 | Async instantaneous gauge |
| `src/Instrument/UpDownCounter.php` | 43 | Sync up/down counter |
| `src/Backend/InMemoryBackend.php` | 125 | Test accumulator |
| `src/Backend/JsonStreamBackend.php` | 86 | NDJSON exporter |
| `src/Backend/StatsdBackend.php` | 95 | UDP StatsD emitter |
| `src/Backend/PrometheusFileBackend.php` | 201 | Atomic Prometheus textfile |
| `src/Backend/MultiBackend.php` | 70 | Fanout wrapper |
| `src/Middleware/SessionMetrics.php` | 60 | Wish middleware |
| `src/Lang.php` | 22 | i18n facade |
| `lang/en.php` | 19 | Translations source of truth |
| `tests/RegistryTest.php` | 83 | Core facade tests |
| `tests/CardinalityTest.php` | 129 | FIFO eviction tests |
| `tests/DescriptorTest.php` | 75 | DTO validation |
| `tests/HistogramBucketsTest.php` | 117 | Bucket accumulation |
| `tests/Middleware/SessionMetricsTest.php` | 76 | Middleware integration |
| `tests/AsyncCounterTest.php` | 52 | Async counter |
| `tests/AsyncGaugeTest.php` | 52 | Async gauge |
| `tests/UpDownCounterTest.php` | 63 | UpDown counter |
| `tests/Backend/StatsdBackendTest.php` | 67 | StatsD wire format |
| `tests/Backend/JsonStreamBackendTest.php` | 40 | NDJSON format |
| `tests/Backend/PrometheusFileBackendTest.php` | 88 | Prometheus format |
| `tests/Backend/MultiBackendTest.php` | 39 | Fanout behavior |

---

*Report generated: 2026-05-27*
